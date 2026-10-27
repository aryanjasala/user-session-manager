<?php
/**
 * "Last active" tracking and the idle timeout.
 *
 * @package AryanX\Axle\UserSessionManager
 */

namespace AryanX\Axle\UserSessionManager;

defined( 'ABSPATH' ) || exit;

/**
 * Records when each session was last used, and signs out sessions left idle.
 *
 * "Last active" lives inside the session itself, as `last_seen`, and is saved
 * at most once per interval (5 minutes by default) so a busy session does not
 * write to the database on every request. The idle check runs while WordPress
 * works out who the visitor is, so an idle session is refused on the very
 * request that comes back to it, before anything else runs as that user.
 */
final class Activity implements Service {

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
	 * Set while the idle check runs, so a filter that asks for the current user cannot loop back into it.
	 *
	 * @var bool
	 */
	private bool $checking = false;

	/**
	 * Sets up tracking.
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
		add_filter( 'attach_session_information', array( $this, 'stamp_new_session' ), 10, 2 );
		// After the cookie (10) and application password (20) checks.
		add_filter( 'determine_current_user', array( $this, 'end_idle_session' ), 30 );
		add_action( 'init', array( $this, 'track' ), 1 );
	}

	/**
	 * A new session starts out active.
	 *
	 * @param array $session Extra session data.
	 * @param int   $user_id User ID.
	 * @return array
	 */
	public function stamp_new_session( $session, $user_id ): array {
		$session              = (array) $session;
		$session['last_seen'] = time();

		return $session;
	}

	/**
	 * Refuses a session that was idle for longer than its user's idle timeout.
	 *
	 * @param int|false $user_id User found so far.
	 * @return int|false
	 */
	public function end_idle_session( $user_id ) {
		if ( ! $user_id || $this->checking || did_action( 'application_password_did_authenticate' ) || ! $this->sessions->is_supported() ) {
			return $user_id;
		}

		$this->checking = true;
		try {
			$found = $this->cookie_session( (int) $user_id );
			if ( null === $found ) {
				return $user_id;
			}

			if ( ! Sessions::is_idle( $found['session'], $this->settings->idle_timeout( $found['user'] ), time() ) ) {
				return $user_id;
			}

			$this->sessions->forget( $found['user']->ID, $found['verifier'] );
			self::announce_idle( $found['user']->ID, $found['session'] + array( 'handle' => $this->sessions->handle( $found['user']->ID, $found['verifier'] ) ), 'request' );

			return false;
		} finally {
			$this->checking = false;
		}
	}

	/**
	 * Saves "last active" for the session this request was made with.
	 */
	public function track(): void {
		$user_id = get_current_user_id();
		if ( ! $user_id || ! $this->sessions->is_supported() || ! $this->should_track( $user_id ) ) {
			return;
		}

		$verifier = $this->sessions->current_verifier();
		$session  = '' === $verifier ? null : $this->sessions->get( $user_id, $verifier );
		if ( null === $session ) {
			return;
		}

		$now     = time();
		$changed = false;
		if ( $now - (int) ( $session['last_seen'] ?? 0 ) >= $this->settings->last_seen_interval() ) {
			$session['last_seen'] = $now;
			$changed              = true;
		}

		// Shorten an address stored before "store shortened IP addresses" was turned on.
		$ip     = isset( $session['ip'] ) && is_string( $session['ip'] ) ? $session['ip'] : '';
		$stored = $this->sessions->storable_ip( $ip, $user_id );
		if ( $stored !== $ip ) {
			$session['ip'] = $stored;
			$changed       = true;
		}

		if ( $changed ) {
			$this->sessions->update( $user_id, $verifier, $session );
		}
	}

	/**
	 * Fires the idle action.
	 *
	 * @param int    $user_id User ID.
	 * @param array  $session Session data with its handle.
	 * @param string $context request, login or prune.
	 */
	public static function announce_idle( int $user_id, array $session, string $context ): void {
		/**
		 * Fires after a session was signed out for sitting idle.
		 *
		 * @param int    $user_id User the session belonged to.
		 * @param array  $session Session data: expiration, ip, ua, login, last_seen and handle.
		 * @param string $context request (the idle session came back), login (found while the user signed in again) or prune (the hourly clean-up).
		 */
		do_action( 'user_session_manager_idle_session_ended', $user_id, $session, $context );
	}

	/**
	 * Whether this request counts as activity.
	 *
	 * @param int $user_id Current user.
	 * @return bool
	 */
	private function should_track( int $user_id ): bool {
		// The Heartbeat API polls every minute from any open admin tab. Counting it would keep a forgotten tab active forever.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only reads which Ajax action this is.
		$heartbeat = wp_doing_ajax() && isset( $_POST['action'] ) && 'heartbeat' === sanitize_key( wp_unslash( $_POST['action'] ) );

		/**
		 * Filters whether a request counts as activity for "last active" and the idle timeout.
		 *
		 * @param bool $track   Default true, except for cron and Heartbeat requests.
		 * @param int  $user_id The current user.
		 */
		return (bool) apply_filters( 'user_session_manager_track_request', ! $heartbeat && ! wp_doing_cron(), $user_id );
	}

	/**
	 * The session behind the login cookie, when it belongs to the user WordPress found.
	 *
	 * @param int $user_id User found so far.
	 * @return array{user: \WP_User, verifier: string, session: array}|null
	 */
	private function cookie_session( int $user_id ): ?array {
		$cookie = wp_parse_auth_cookie( '', 'logged_in' );
		if ( ! is_array( $cookie ) || empty( $cookie['token'] ) || empty( $cookie['username'] ) ) {
			return null;
		}

		$user = get_userdata( $user_id );
		if ( ! $user || $user->user_login !== $cookie['username'] ) {
			return null;
		}

		$verifier = Sessions::verifier( (string) $cookie['token'] );
		$session  = $this->sessions->get( $user_id, $verifier );

		return null === $session ? null : array(
			'user'     => $user,
			'verifier' => $verifier,
			'session'  => $session,
		);
	}
}
