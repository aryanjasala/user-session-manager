<?php
/**
 * Shared login detection.
 *
 * @package AryanX\Axle\UserSessionManager
 */

namespace AryanX\Axle\UserSessionManager;

use AryanX\Axle\UserSessionManager\Admin\SessionsPage;

defined( 'ABSPATH' ) || exit;

/**
 * Flags accounts that look shared by several people.
 *
 * The check runs when someone signs in and each time "last active" is saved
 * for a session (at most every 5 minutes), on that user's sessions only. When
 * SharedLoginDetector finds something, the account is flagged in the
 * `user_session_manager_shared` user meta with the evidence and a copy of the
 * sessions behind it, so the screens can still say which sessions, when and
 * where after those sessions end. The flag is dropped after 7 days without new
 * evidence.
 *
 * What else happens is set per role: just the flag, an email to the user and
 * the site administrator, or signing out every session but the newest. The
 * action only runs when the evidence involves a session not seen in an earlier
 * flag, so a shared account is not emailed every 5 minutes. A login is never
 * refused on this guess.
 */
final class SharedLogins implements Service {

	public const META    = 'user_session_manager_shared';
	public const ACTIONS = array( 'flag', 'notify', 'sign_out_others' );

	private const MAX_HANDLES = 50;

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
	 * Flags read in this request, by user ID.
	 *
	 * @var array<int, array|null>
	 */
	private array $cache = array();

	/**
	 * Sets up detection.
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
		add_action( 'user_session_manager_session_started', array( $this, 'on_login' ), 20, 2 );
		add_action( 'user_session_manager_session_active', array( $this, 'on_activity' ) );
	}

	/**
	 * Checks a user right after they signed in.
	 *
	 * @param \WP_User $user    User.
	 * @param array    $session The new session, with its handle.
	 */
	public function on_login( \WP_User $user, $session = array() ): void {
		$this->check( $user, (string) ( ( (array) $session )['handle'] ?? '' ) );
	}

	/**
	 * Checks a user after one of their sessions was used.
	 *
	 * @param int $user_id User ID.
	 */
	public function on_activity( $user_id ): void {
		$user = get_userdata( (int) $user_id );
		if ( $user ) {
			$this->check( $user );
		}
	}

	/**
	 * Looks at a user's sessions, flags the account when they look shared, and acts on new evidence.
	 *
	 * @param \WP_User $user   User.
	 * @param string   $newest Handle of the session that just started, kept when only the newest stays.
	 * @return array|null The flag when this check found something new, otherwise null.
	 */
	public function check( \WP_User $user, string $newest = '' ): ?array {
		if ( ! $this->sessions->is_supported() || $this->is_exempt( $user ) ) {
			return null;
		}

		$rows = $this->sessions->list_for_user( $user->ID );
		if ( count( $rows ) < 2 ) {
			return null;
		}

		foreach ( $rows as $index => $row ) {
			$rows[ $index ]['network'] = SharedLoginDetector::network( $row['ip'] );
			$rows[ $index ]['country'] = $this->country( $row['ip'], $user->ID );
		}

		$now      = time();
		$evidence = SharedLoginDetector::detect( $rows, $this->rules( $user ), $now );
		if ( ! $evidence ) {
			return null;
		}

		$old     = $this->get( $user->ID );
		$handles = array_values( array_unique( array_merge( ...array_column( $evidence, 'handles' ) ) ) );
		$new     = array_diff( $handles, $old['handles'] ?? array() );
		if ( ! $new && $old && $now - $old['updated'] < HOUR_IN_SECONDS ) {
			return null;
		}

		$snapshot = array();
		foreach ( $rows as $row ) {
			if ( in_array( $row['handle'], $handles, true ) ) {
				$snapshot[ $row['handle'] ] = array(
					'device'      => $row['device'],
					'device_type' => $row['device_type'],
					'ip'          => $row['ip'],
					'country'     => $row['country'],
					'login'       => $row['login'],
					'last_seen'   => $row['last_seen'],
				);
			}
		}

		$action = $new ? $this->action( $user, $evidence ) : (string) ( $old['action'] ?? 'flag' );
		$flag   = array(
			'detected'   => (int) ( $old['detected'] ?? $now ),
			'updated'    => $now,
			'action'     => $action,
			'evidence'   => $evidence,
			'sessions'   => $snapshot,
			'handles'    => array_slice( array_values( array_unique( array_merge( $handles, $old['handles'] ?? array() ) ) ), 0, self::MAX_HANDLES ),
			'signed_out' => $new ? 0 : (int) ( $old['signed_out'] ?? 0 ),
		);
		$this->save( $user->ID, $flag );

		if ( ! $new ) {
			return null;
		}

		$sessions = array();
		foreach ( $snapshot as $handle => $session ) {
			$sessions[] = array( 'handle' => $handle ) + $session;
		}

		/**
		 * Fires when an account looks shared by several people, once per new session in the evidence.
		 *
		 * @param \WP_User $user     The user.
		 * @param array[]  $sessions The sessions behind it: handle, device, device_type, ip, country, login and last_seen.
		 * @param array[]  $evidence What was found: type (networks, countries, same_type or devices), count, limit, handles and time.
		 */
		do_action( 'user_session_manager_shared_login_detected', $user, $sessions, $evidence );

		if ( 'notify' === $action ) {
			$this->notify( $user, $flag );
		} elseif ( 'sign_out_others' === $action ) {
			$flag['signed_out'] = $this->sign_out_others( $user->ID, $newest );
			$this->save( $user->ID, $flag );
		}

		return $flag;
	}

	/**
	 * The flag of a user, or null. A flag older than the rules allow is deleted.
	 *
	 * @param int $user_id User ID.
	 * @return array|null detected, updated, action, evidence, sessions, handles and signed_out.
	 */
	public function get( int $user_id ): ?array {
		if ( array_key_exists( $user_id, $this->cache ) ) {
			return $this->cache[ $user_id ];
		}

		$flag = $user_id > 0 ? get_user_meta( $user_id, self::META, true ) : '';
		if ( ! is_array( $flag ) || empty( $flag['evidence'] ) || ! is_array( $flag['evidence'] ) ) {
			$this->cache[ $user_id ] = null;
			return null;
		}

		$user = get_userdata( $user_id );
		$days = $user ? $this->rules( $user )['keep_days'] : SharedLoginDetector::RULES['keep_days'];
		if ( (int) ( $flag['updated'] ?? 0 ) < time() - $days * DAY_IN_SECONDS ) {
			$this->forget( $user_id );
			return null;
		}

		$flag = array(
			'detected'   => (int) ( $flag['detected'] ?? 0 ),
			'updated'    => (int) ( $flag['updated'] ?? 0 ),
			'action'     => in_array( $flag['action'] ?? '', self::ACTIONS, true ) ? (string) $flag['action'] : 'flag',
			'evidence'   => array_values( array_filter( $flag['evidence'], 'is_array' ) ),
			'sessions'   => is_array( $flag['sessions'] ?? null ) ? $flag['sessions'] : array(),
			'handles'    => is_array( $flag['handles'] ?? null ) ? array_map( 'strval', $flag['handles'] ) : array(),
			'signed_out' => (int) ( $flag['signed_out'] ?? 0 ),
		);

		$this->cache[ $user_id ] = $flag;

		return $flag;
	}

	/**
	 * Handles of the sessions behind a user's flag.
	 *
	 * @param int $user_id User ID.
	 * @return string[]
	 */
	public function handles( int $user_id ): array {
		$flag = $this->get( $user_id );

		return $flag ? array_keys( $flag['sessions'] ) : array();
	}

	/**
	 * Removes a user's flag.
	 *
	 * @param int $user_id User ID.
	 * @return bool Whether there was one.
	 */
	public function forget( int $user_id ): bool {
		$had = is_array( get_user_meta( $user_id, self::META, true ) );
		delete_user_meta( $user_id, self::META );
		$this->cache[ $user_id ] = null;

		return $had;
	}

	/**
	 * Every flagged account, most recently flagged first.
	 *
	 * @return array<int, array> User ID => flag.
	 */
	public function flagged(): array {
		$args = array(
			'fields'       => 'ID',
			'number'       => $this->sessions->max_users(),
			'orderby'      => 'ID',
			'order'        => 'ASC',
			'count_total'  => false,
			// Only the few flagged accounts have this key.
			'meta_key'     => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_compare' => 'EXISTS',
		);
		if ( is_multisite() ) {
			// Users and their sessions are network-wide.
			$args['blog_id'] = 0;
		}
		$query = new \WP_User_Query( $args );

		$flags = array();
		foreach ( array_map( 'intval', (array) $query->get_results() ) as $user_id ) {
			$flag = $this->get( $user_id );
			if ( $flag ) {
				$flags[ $user_id ] = $flag;
			}
		}

		uasort(
			$flags,
			static function ( array $a, array $b ): int {
				return $b['updated'] <=> $a['updated'];
			}
		);

		return $flags;
	}

	/**
	 * The rules for a user.
	 *
	 * @param \WP_User $user User.
	 * @return array<string, int>
	 */
	public function rules( \WP_User $user ): array {
		/**
		 * Filters the rules that decide when an account looks shared. 0 turns a check off.
		 *
		 * @param array    $rules {
		 *     @type int $overlap               Seconds between two sessions' last activity that still counts as the same time. Default 900.
		 *     @type int $max_networks          Networks (IPv4 /24, IPv6 /48) allowed at the same time. Default 2.
		 *     @type int $max_countries         Countries allowed at the same time, when a filter supplies them. Default 1.
		 *     @type int $max_networks_per_type Networks one kind of device (computer, phone, tablet) may be used from at the same time. Default 1.
		 *     @type int $window                Seconds counted back for max_devices. Default 86400.
		 *     @type int $max_devices           Different devices (browser and system) allowed within the window. Default 4.
		 *     @type int $keep_days             Days a flag is kept after the last evidence. Default 7.
		 * }
		 * @param \WP_User $user  The user.
		 */
		return SharedLoginDetector::sanitize_rules( apply_filters( 'user_session_manager_shared_login_rules', SharedLoginDetector::RULES, $user ) );
	}

	/**
	 * Whether a user is never checked.
	 *
	 * @param \WP_User $user User.
	 * @return bool
	 */
	public function is_exempt( \WP_User $user ): bool {
		/**
		 * Filters whether a user is left out of shared login detection, such as a shared kiosk account.
		 *
		 * @param bool     $exempt True when one of the user's roles is set to "Do not check".
		 * @param \WP_User $user   The user.
		 */
		return (bool) apply_filters( 'user_session_manager_shared_login_exempt', 'off' === $this->settings->shared_mode_for( $user ), $user );
	}

	/**
	 * What to do about an account that looks shared.
	 *
	 * @param \WP_User $user     User.
	 * @param array[]  $evidence What was found.
	 * @return string flag, notify or sign_out_others.
	 */
	public function action( \WP_User $user, array $evidence ): string {
		$mode = $this->settings->shared_mode_for( $user );

		/**
		 * Filters what happens when an account looks shared.
		 *
		 * @param string   $action   flag (only the badge), notify (also email the user and the site administrator) or sign_out_others (sign out every session but the newest). Anything else falls back to flag.
		 * @param \WP_User $user     The user.
		 * @param array[]  $evidence What was found.
		 */
		$action = apply_filters( 'user_session_manager_shared_login_action', in_array( $mode, self::ACTIONS, true ) ? $mode : 'flag', $user, $evidence );

		return in_array( $action, self::ACTIONS, true ) ? (string) $action : 'flag';
	}

	/**
	 * The country of an IP address, when a filter knows it.
	 *
	 * @param string $ip      Address as stored, maybe shortened.
	 * @param int    $user_id User.
	 * @return string Two-letter code or name. Empty when unknown.
	 */
	public function country( string $ip, int $user_id ): string {
		if ( '' === $ip ) {
			return '';
		}

		/**
		 * Filters the country an IP address is in. The plugin calls no geolocation service: return one from your own lookup.
		 *
		 * @param string $country Default empty, which leaves countries out of the check.
		 * @param string $ip      The address as stored, shortened when the site stores shortened addresses.
		 * @param int    $user_id The user.
		 */
		return sanitize_text_field( (string) apply_filters( 'user_session_manager_shared_login_country', '', $ip, $user_id ) );
	}

	/**
	 * One piece of evidence in words.
	 *
	 * @param array $evidence Evidence from SharedLoginDetector::detect().
	 * @return string
	 */
	public static function evidence_text( array $evidence ): string {
		$count = (int) ( $evidence['count'] ?? 0 );
		$limit = (int) ( $evidence['limit'] ?? 0 );
		switch ( $evidence['type'] ?? '' ) {
			case 'networks':
				/* translators: 1: number of networks, 2: number allowed. */
				$text = sprintf( _n( 'Used from %1$s network at the same time, more than the %2$s allowed.', 'Used from %1$s different networks at the same time, more than the %2$s allowed.', $count, 'user-session-manager' ), number_format_i18n( $count ), number_format_i18n( $limit ) );
				break;
			case 'countries':
				$text = sprintf(
					/* translators: 1: number of countries, 2: list of countries. */
					_n( 'Used from %1$s country at the same time: %2$s.', 'Used from %1$s countries at the same time: %2$s.', $count, 'user-session-manager' ),
					number_format_i18n( $count ),
					implode( ', ', array_map( 'strval', (array) ( $evidence['countries'] ?? array() ) ) )
				);
				break;
			case 'same_type':
				$type = (string) ( $evidence['device_type'] ?? '' );
				if ( 'mobile' === $type ) {
					/* translators: %s: number of phones. */
					$text = sprintf( _n( '%s phone on a different network was used at the same time.', '%s phones on different networks were used at the same time.', $count, 'user-session-manager' ), number_format_i18n( $count ) );
				} elseif ( 'tablet' === $type ) {
					/* translators: %s: number of tablets. */
					$text = sprintf( _n( '%s tablet on a different network was used at the same time.', '%s tablets on different networks were used at the same time.', $count, 'user-session-manager' ), number_format_i18n( $count ) );
				} else {
					/* translators: %s: number of computers. */
					$text = sprintf( _n( '%s computer on a different network was used at the same time.', '%s computers on different networks were used at the same time.', $count, 'user-session-manager' ), number_format_i18n( $count ) );
				}
				break;
			case 'devices':
				$text = sprintf(
					/* translators: 1: number of devices, 2: time span such as "1 day", 3: number allowed. */
					_n( '%1$s different device was used within %2$s, more than the %3$s allowed.', '%1$s different devices were used within %2$s, more than the %3$s allowed.', $count, 'user-session-manager' ),
					number_format_i18n( $count ),
					human_time_diff( 0, max( 1, (int) ( $evidence['window'] ?? DAY_IN_SECONDS ) ) ),
					number_format_i18n( $limit )
				);
				break;
			default:
				$text = __( 'The sessions look like more than one person.', 'user-session-manager' );
		}

		/**
		 * Filters how one piece of shared login evidence reads on the screens and in the emails.
		 *
		 * @param string $text     The sentence.
		 * @param array  $evidence type, count, limit, handles and time, plus device_type, countries or window.
		 */
		$filtered = (string) apply_filters( 'user_session_manager_shared_login_evidence_text', $text, $evidence );

		return '' !== trim( $filtered ) ? $filtered : $text;
	}

	/**
	 * Emails the user and the site administrator about a flag.
	 *
	 * @param \WP_User $user User.
	 * @param array    $flag The flag.
	 */
	private function notify( \WP_User $user, array $flag ): void {
		$site  = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$lines = implode(
			"\n",
			array_map(
				static function ( array $evidence ): string {
					return '- ' . self::evidence_text( $evidence );
				},
				$flag['evidence']
			)
		);
		$admin = (string) ( is_multisite() ? get_site_option( 'admin_email' ) : get_option( 'admin_email' ) );

		$emails = array(
			'user'  => array(
				'to'      => $user->user_email,
				/* translators: %s: site name. */
				'subject' => sprintf( __( '[%s] Your account was used from several places at once', 'user-session-manager' ), $site ),
				'message' => sprintf(
					/* translators: 1: user's display name, 2: site name, 3: what was found, one per line, 4: profile URL. */
					__(
						'Hi %1$s,

Your account on %2$s was just used in a way that suggests more than one person signs in with it:

%3$s

If this was all you, there is nothing to do. If you shared your password, or you do not recognise a device, change your password and sign out the sessions you do not know on your profile:
%4$s',
						'user-session-manager'
					),
					$user->display_name,
					$site,
					$lines,
					get_dashboard_url( $user->ID, 'profile.php' ) . '#usm-sessions'
				),
				'headers' => array(),
			),
			'admin' => array(
				'to'      => $admin !== $user->user_email ? $admin : '',
				/* translators: 1: site name, 2: user login. */
				'subject' => sprintf( __( '[%1$s] The account %2$s may be shared', 'user-session-manager' ), $site, $user->user_login ),
				'message' => sprintf(
					/* translators: 1: user login, 2: user's email address, 3: site name, 4: what was found, one per line, 5: URL of the user's sessions. */
					__(
						'The account %1$s (%2$s) on %3$s was just used in a way that suggests more than one person signs in with it:

%4$s

Nothing was blocked. See the sessions and sign them out here:
%5$s',
						'user-session-manager'
					),
					$user->user_login,
					$user->user_email,
					$site,
					$lines,
					SessionsPage::url( array( 'user_id' => $user->ID ) )
				),
				'headers' => array(),
			),
		);

		foreach ( $emails as $audience => $email ) {
			/**
			 * Filters an email about an account that looks shared. Return false, or an empty `to`, to skip it.
			 *
			 * @param array|false $email    to, subject, message and headers, as wp_mail() takes them.
			 * @param \WP_User    $user     The user whose account looks shared.
			 * @param array       $flag     The flag: detected, updated, action, evidence and sessions.
			 * @param string      $audience user (the account's owner) or admin (the site administrator).
			 */
			$email = apply_filters( 'user_session_manager_shared_login_email', $email, $user, $flag, $audience );
			if ( ! is_array( $email ) || empty( $email['to'] ) ) {
				continue;
			}

			// One email per audience, only when the evidence involves a new session.
			wp_mail( $email['to'], (string) ( $email['subject'] ?? '' ), (string) ( $email['message'] ?? '' ), $email['headers'] ?? array() ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail
		}
	}

	/**
	 * Signs out every session of a user except the newest login.
	 *
	 * The session that just started is the newest. Otherwise it is the latest
	 * login, and between logins in the same second the most recently used one.
	 *
	 * @param int    $user_id User ID.
	 * @param string $handle  Handle of the session that just started, or empty.
	 * @return int Sessions ended.
	 */
	private function sign_out_others( int $user_id, string $handle ): int {
		$keep = '';
		$best = array( -1, -1 );
		foreach ( $this->sessions->for_user( $user_id ) as $verifier => $session ) {
			if ( '' !== $handle && hash_equals( $this->sessions->handle( $user_id, (string) $verifier ), $handle ) ) {
				$keep = (string) $verifier;
				break;
			}
			$rank = array( (int) ( $session['login'] ?? 0 ), (int) ( $session['last_seen'] ?? 0 ) );
			if ( $rank > $best ) {
				$best = $rank;
				$keep = (string) $verifier;
			}
		}

		return '' === $keep ? 0 : $this->sessions->revoke_others( $user_id, $keep, 'shared' );
	}

	/**
	 * Saves a flag.
	 *
	 * @param int   $user_id User ID.
	 * @param array $flag    Flag.
	 */
	private function save( int $user_id, array $flag ): void {
		update_user_meta( $user_id, self::META, $flag );
		$this->cache[ $user_id ] = $flag;
	}
}
