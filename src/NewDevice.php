<?php
/**
 * The new device email.
 *
 * @package AryanX\Axle\UserSessionManager
 */

namespace AryanX\Axle\UserSessionManager;

defined( 'ABSPATH' ) || exit;

/**
 * Emails a user when they sign in from a browser and IP address pair not seen
 * on their account in the last 30 days.
 *
 * Sessions are deleted when they end, so the plugin keeps its own short memory
 * per user: a keyed hash of each browser, system and IP address, with the
 * browser label and when it was last seen. The IP address itself is not kept.
 * The first login the plugin sees for a user only fills that memory, so turning
 * the email on does not send one to everyone.
 */
final class NewDevice implements Service {

	public const META = 'user_session_manager_devices';

	private const MAX_DEVICES = 50;

	/**
	 * Saved settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Session store.
	 *
	 * @var Sessions
	 */
	private Sessions $sessions;

	/**
	 * Sets up the email.
	 *
	 * @param Settings $settings Saved settings.
	 * @param Sessions $sessions Session store.
	 */
	public function __construct( Settings $settings, Sessions $sessions ) {
		$this->settings = $settings;
		$this->sessions = $sessions;
	}

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_action( 'user_session_manager_session_started', array( $this, 'check' ), 10, 2 );
	}

	/**
	 * Remembers the device of a new session and emails the user when it is new.
	 *
	 * @param \WP_User $user    User.
	 * @param array    $session The new session.
	 */
	public function check( \WP_User $user, array $session ): void {
		if ( ! $this->settings->get( 'new_device_email' ) ) {
			return;
		}

		$now     = time();
		$stored  = get_user_meta( $user->ID, self::META, true );
		$first   = ! is_array( $stored );
		$devices = $first ? array() : $this->recent( (array) ( $stored['devices'] ?? array() ), $now );

		if ( $first ) {
			foreach ( $this->sessions->for_user( $user->ID ) as $other ) {
				$devices[ $this->fingerprint( $other ) ] = array(
					'label' => $this->label( $other ),
					'seen'  => (int) ( $other['last_seen'] ?? $other['login'] ?? $now ),
				);
			}
		}

		$fingerprint             = $this->fingerprint( $session );
		$known                   = isset( $devices[ $fingerprint ] );
		$devices[ $fingerprint ] = array(
			'label' => $this->label( $session ),
			'seen'  => $now,
		);

		uasort(
			$devices,
			static function ( array $a, array $b ): int {
				return $b['seen'] <=> $a['seen'];
			}
		);
		update_user_meta(
			$user->ID,
			self::META,
			array(
				'since'   => $first ? $now : (int) ( $stored['since'] ?? $now ),
				'devices' => array_slice( $devices, 0, $this->max_devices(), true ),
			)
		);

		if ( $first || $known ) {
			return;
		}

		/**
		 * Fires when a user signs in from a browser and IP address pair not seen on their account recently.
		 *
		 * @param \WP_User $user    The user.
		 * @param array    $session The new session: expiration, ip, ua, login, last_seen and handle.
		 */
		do_action( 'user_session_manager_new_device_login', $user, $session );

		$this->send( $user, $session );
	}

	/**
	 * The devices remembered for a user, newest first.
	 *
	 * @param int $user_id User ID.
	 * @return array[] Each with label and seen.
	 */
	public function devices( int $user_id ): array {
		$stored = get_user_meta( $user_id, self::META, true );

		return is_array( $stored ) ? array_values( $this->recent( (array) ( $stored['devices'] ?? array() ), time() ) ) : array();
	}

	/**
	 * Forgets every device of a user.
	 *
	 * @param int $user_id User ID.
	 * @return bool Whether there was anything to forget.
	 */
	public function forget( int $user_id ): bool {
		$had = is_array( get_user_meta( $user_id, self::META, true ) );
		delete_user_meta( $user_id, self::META );

		return $had;
	}

	/**
	 * Sends the email.
	 *
	 * @param \WP_User $user    User.
	 * @param array    $session The new session.
	 */
	private function send( \WP_User $user, array $session ): void {
		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$ip   = isset( $session['ip'] ) && is_string( $session['ip'] ) && '' !== $session['ip'] ? $session['ip'] : __( 'unknown', 'user-session-manager' );

		$email = array(
			'to'      => $user->user_email,
			/* translators: %s: site name. */
			'subject' => sprintf( __( '[%s] New sign-in to your account', 'user-session-manager' ), $site ),
			'message' => sprintf(
				/* translators: 1: user's display name, 2: site name, 3: number of days, 4: browser and system, 5: IP address, 6: date and time, 7: profile URL. */
				__(
					'Hi %1$s,

Someone just signed in to your account on %2$s from a browser or network not seen on your account in the last %3$d days.

Device: %4$s
IP address: %5$s
Time: %6$s

If this was you, there is nothing to do. If it was not, change your password and sign out the sessions you do not recognise on your profile:
%7$s',
					'user-session-manager'
				),
				$user->display_name,
				$site,
				$this->settings->device_days(),
				$this->label( $session ),
				$ip,
				wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) ( $session['login'] ?? time() ) ),
				get_dashboard_url( $user->ID, 'profile.php' ) . '#usm-sessions'
			),
			'headers' => array(),
		);

		/**
		 * Filters the new device email. Return false, or an empty `to`, to skip it.
		 *
		 * @param array|false $email   to, subject, message and headers, as wp_mail() takes them.
		 * @param \WP_User    $user    The user.
		 * @param array       $session The new session.
		 */
		$email = apply_filters( 'user_session_manager_new_device_email', $email, $user, $session );
		if ( ! is_array( $email ) || empty( $email['to'] ) ) {
			return;
		}

		// One email per login from a new device, never in bulk.
		wp_mail( $email['to'], (string) ( $email['subject'] ?? '' ), (string) ( $email['message'] ?? '' ), $email['headers'] ?? array() ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail
	}

	/**
	 * Keyed hash of a session's browser, system and IP address.
	 *
	 * @param array $session Session data.
	 * @return string
	 */
	private function fingerprint( array $session ): string {
		$parsed = UserAgent::parse( isset( $session['ua'] ) && is_string( $session['ua'] ) ? $session['ua'] : '' );
		$ip     = isset( $session['ip'] ) && is_string( $session['ip'] ) ? $session['ip'] : '';

		return substr( hash_hmac( 'sha256', strtolower( $parsed['browser'] . '|' . $parsed['os'] ) . '|' . $ip, wp_salt( 'auth' ) ), 0, 32 );
	}

	/**
	 * "Chrome on macOS" for a session.
	 *
	 * @param array $session Session data.
	 * @return string
	 */
	private function label( array $session ): string {
		return UserAgent::parse( isset( $session['ua'] ) && is_string( $session['ua'] ) ? $session['ua'] : '' )['label'];
	}

	/**
	 * Devices seen within the memory window.
	 *
	 * @param array $devices Fingerprint => label and seen.
	 * @param int   $now     Current time.
	 * @return array
	 */
	private function recent( array $devices, int $now ): array {
		$cutoff = $now - $this->settings->device_days() * DAY_IN_SECONDS;

		return array_filter(
			$devices,
			static function ( $device ) use ( $cutoff ): bool {
				return is_array( $device ) && (int) ( $device['seen'] ?? 0 ) >= $cutoff;
			}
		);
	}

	/**
	 * Most devices remembered per user.
	 *
	 * @return int
	 */
	private function max_devices(): int {
		/**
		 * Filters how many browser and IP address pairs are remembered per user.
		 *
		 * @param int $max Default 50.
		 */
		return max( 1, min( 500, (int) apply_filters( 'user_session_manager_device_limit', self::MAX_DEVICES ) ) );
	}
}
