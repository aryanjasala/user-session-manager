<?php
/**
 * Personal data export, erase and the privacy policy text.
 *
 * @package AryanX\Axle\UserSessionManager
 */

namespace AryanX\Axle\UserSessionManager;

defined( 'ABSPATH' ) || exit;

/**
 * Sessions hold an IP address and a browser string, which are personal data.
 *
 * The exporter lists a user's sessions, remembered devices and any shared login
 * flag. The eraser signs the user out everywhere and forgets both.
 */
final class Privacy implements Service {

	public const ID = 'user-session-manager';

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
	 * Device memory.
	 *
	 * @var NewDevice
	 */
	private NewDevice $devices;

	/**
	 * Shared login flags.
	 *
	 * @var SharedLogins
	 */
	private SharedLogins $shared;

	/**
	 * Sets up the privacy tools.
	 *
	 * @param Settings     $settings Saved settings.
	 * @param Sessions     $sessions Session store.
	 * @param NewDevice    $devices  Device memory.
	 * @param SharedLogins $shared   Shared login flags.
	 */
	public function __construct( Settings $settings, Sessions $sessions, NewDevice $devices, SharedLogins $shared ) {
		$this->settings = $settings;
		$this->sessions = $sessions;
		$this->devices  = $devices;
		$this->shared   = $shared;
	}

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'add_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'add_eraser' ) );
		add_action( 'admin_init', array( $this, 'policy_text' ) );
	}

	/**
	 * Registers the exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public function add_exporter( array $exporters ): array {
		$exporters[ self::ID ] = array(
			'exporter_friendly_name' => __( 'Login sessions', 'user-session-manager' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Registers the eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public function add_eraser( array $erasers ): array {
		$erasers[ self::ID ] = array(
			'eraser_friendly_name' => __( 'Login sessions', 'user-session-manager' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Exports the sessions and remembered devices of the user with an email address.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, from 1. Everything fits on one.
	 * @return array{data: array, done: bool}
	 */
	public function export( $email, $page = 1 ): array {
		$user = get_user_by( 'email', (string) $email );
		$data = array();

		if ( $user ) {
			$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
			foreach ( $this->sessions->list_for_user( $user->ID ) as $index => $row ) {
				$data[] = array(
					'group_id'    => 'user-session-manager-sessions',
					'group_label' => __( 'Login sessions', 'user-session-manager' ),
					'item_id'     => 'user-session-manager-session-' . $index,
					'data'        => array(
						array(
							'name'  => __( 'Device', 'user-session-manager' ),
							'value' => $row['device'],
						),
						array(
							'name'  => __( 'Browser string', 'user-session-manager' ),
							'value' => $row['ua'],
						),
						array(
							'name'  => __( 'IP address', 'user-session-manager' ),
							'value' => $row['ip'],
						),
						array(
							'name'  => __( 'Signed in', 'user-session-manager' ),
							'value' => $row['login'] ? wp_date( $format, $row['login'] ) : '',
						),
						array(
							'name'  => __( 'Last active', 'user-session-manager' ),
							'value' => $row['last_seen'] ? wp_date( $format, $row['last_seen'] ) : '',
						),
						array(
							'name'  => __( 'Expires', 'user-session-manager' ),
							'value' => wp_date( $format, $row['expiration'] ),
						),
					),
				);
			}

			foreach ( $this->devices->devices( $user->ID ) as $index => $device ) {
				$data[] = array(
					'group_id'    => 'user-session-manager-devices',
					'group_label' => __( 'Remembered devices', 'user-session-manager' ),
					'item_id'     => 'user-session-manager-device-' . $index,
					'data'        => array(
						array(
							'name'  => __( 'Device', 'user-session-manager' ),
							'value' => (string) $device['label'],
						),
						array(
							'name'  => __( 'Last seen', 'user-session-manager' ),
							'value' => wp_date( $format, (int) $device['seen'] ),
						),
					),
				);
			}

			$flag = $this->shared->get( $user->ID );
			foreach ( $flag ? $flag['sessions'] : array() as $handle => $session ) {
				$data[] = array(
					'group_id'    => 'user-session-manager-shared',
					'group_label' => __( 'Sessions that looked shared', 'user-session-manager' ),
					'item_id'     => 'user-session-manager-shared-' . $handle,
					'data'        => array(
						array(
							'name'  => __( 'Reason', 'user-session-manager' ),
							'value' => implode( ' ', array_map( array( SharedLogins::class, 'evidence_text' ), $flag['evidence'] ) ),
						),
						array(
							'name'  => __( 'Device', 'user-session-manager' ),
							'value' => (string) ( $session['device'] ?? '' ),
						),
						array(
							'name'  => __( 'IP address', 'user-session-manager' ),
							'value' => (string) ( $session['ip'] ?? '' ),
						),
						array(
							'name'  => __( 'Country', 'user-session-manager' ),
							'value' => (string) ( $session['country'] ?? '' ),
						),
						array(
							'name'  => __( 'Last active', 'user-session-manager' ),
							'value' => ! empty( $session['last_seen'] ) ? wp_date( $format, (int) $session['last_seen'] ) : '',
						),
					),
				);
			}
		}

		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Signs the user with an email address out everywhere and forgets their devices.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, from 1. Everything is done on the first.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public function erase( $email, $page = 1 ): array {
		$user     = get_user_by( 'email', (string) $email );
		$messages = array();
		$removed  = false;

		if ( $user ) {
			$ended   = $this->sessions->count( $user->ID ) > 0 ? $this->sessions->sign_out_everywhere( $user->ID, 'privacy' ) : 0;
			$forgot  = $this->devices->forget( $user->ID );
			$flagged = $this->shared->forget( $user->ID );
			$removed = $ended > 0 || $forgot || $flagged;
			if ( $ended > 0 ) {
				$messages[] = sprintf(
					/* translators: %d: number of sessions. */
					_n( 'Signed the user out of %d session.', 'Signed the user out of %d sessions.', $ended, 'user-session-manager' ),
					$ended
				);
			}
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => $messages,
			'done'           => true,
		);
	}

	/**
	 * Suggested text for Settings > Privacy.
	 */
	public function policy_text(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$ip = $this->settings->get( 'mask_ip' )
			? __( 'a shortened IP address (the last part set to zero)', 'user-session-manager' )
			: __( 'the IP address', 'user-session-manager' );

		$text = sprintf(
			/* translators: 1: "the IP address" or "a shortened IP address", 2: number of days, 3: number of days. */
			__( 'When you sign in, WordPress keeps a record of the session: %1$s and browser it came from, when it started, when it was last used and when it ends. The record is deleted when you sign out or the session ends. You can see your sessions on your profile and sign any of them out. If new sign-in emails are on, a scrambled fingerprint of each browser and IP address you sign in from is kept for %2$d days, so we can tell you about a sign-in from a new device. If your sessions look like more than one person uses your account, for example two computers on different networks at the same time, a copy of those sessions (browser, IP address and times) is kept with the reason for %3$d days after the last time it happened.', 'user-session-manager' ),
			$ip,
			$this->settings->device_days(),
			SharedLoginDetector::RULES['keep_days']
		);

		wp_add_privacy_policy_content( __( 'User Session Manager', 'user-session-manager' ), '<p>' . esc_html( $text ) . '</p>' );
	}
}
