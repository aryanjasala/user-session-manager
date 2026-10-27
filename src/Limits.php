<?php
/**
 * Session limits and lifetimes at login.
 *
 * @package AryanX\Axle\UserSessionManager
 */

namespace AryanX\Axle\UserSessionManager;

defined( 'ABSPATH' ) || exit;

/**
 * Applies the per-role policy when someone signs in.
 *
 * - Lifetime: the auth_cookie_expiration filter sets how long a new session lasts.
 * - Block mode: the authenticate filter refuses a login on the login form when
 *   the user already has as many sessions as allowed.
 * - Evict mode: after the new session is created, the oldest ones are signed out
 *   until the user is back at the limit.
 *
 * Idle sessions never count towards the limit; they are signed out first.
 */
final class Limits implements Service {

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
	 * Whether this request is a wp_signon() login, the kind that creates a session.
	 *
	 * @var bool
	 */
	private bool $signing_on = false;

	/**
	 * Sets up the limits.
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
		// wp_signon() fires this just before it checks the password. XML-RPC and other
		// password checks that create no session do not, so they are never blocked.
		add_action( 'wp_authenticate', array( $this, 'start_sign_on' ) );
		add_filter( 'authenticate', array( $this, 'block_over_limit' ), 100 );
		add_action( 'set_logged_in_cookie', array( $this, 'after_login' ), 10, 6 );
		add_filter( 'auth_cookie_expiration', array( $this, 'lifetime' ), 20, 3 );
	}

	/**
	 * Marks this request as a login that will create a session.
	 */
	public function start_sign_on(): void {
		$this->signing_on = true;
	}

	/**
	 * Refuses a login over the limit, in block mode.
	 *
	 * @param \WP_User|\WP_Error|null $user Result of the password check.
	 * @return \WP_User|\WP_Error|null
	 */
	public function block_over_limit( $user ) {
		if ( ! $this->signing_on || ! $user instanceof \WP_User || ! $this->sessions->is_supported() ) {
			return $user;
		}

		$max = $this->settings->max_sessions( $user );
		if ( $max < 1 || 'block' !== $this->settings->limit_mode( $user ) ) {
			return $user;
		}

		$active = count( $this->active_sessions( $user ) );
		if ( $active < $max ) {
			return $user;
		}

		/**
		 * Fires when a login is refused because the user has as many sessions as allowed.
		 *
		 * @param \WP_User $user   The user.
		 * @param int      $max    Sessions allowed.
		 * @param int      $active Sessions the user has.
		 */
		do_action( 'user_session_manager_login_blocked', $user, $max, $active );

		return new \WP_Error( 'user_session_manager_limit', $this->blocked_message( $user, $max, $active ) );
	}

	/**
	 * The message the login form shows when a login is refused.
	 *
	 * The limit and the real count are stated apart: a user can have more
	 * sessions than the limit when the limit was lowered after they signed in.
	 *
	 * @param \WP_User $user   User.
	 * @param int      $max    Sessions allowed.
	 * @param int      $active Sessions the user has now.
	 * @return string
	 */
	public function blocked_message( \WP_User $user, int $max, int $active = 0 ): string {
		$active  = max( $max, $active );
		$default = sprintf(
			/* translators: %s: number of devices allowed. */
			_n( 'This account can be signed in on %s device at a time.', 'This account can be signed in on %s devices at a time.', $max, 'user-session-manager' ),
			number_format_i18n( $max )
		) . ' ' . sprintf(
			/* translators: %s: number of sessions the account has now. */
			_n( 'It already has %s active session.', 'It already has %s active sessions.', $active, 'user-session-manager' ),
			number_format_i18n( $active )
		) . ' ' . _n(
			'Sign out there first, or ask a site administrator to end that session.',
			'Sign out on another device first, or ask a site administrator to end a session.',
			$active,
			'user-session-manager'
		);

		/**
		 * Filters the message shown when a login is refused over the session limit.
		 *
		 * @param string   $message Message. Basic HTML is allowed.
		 * @param \WP_User $user    The user.
		 * @param int      $max     Sessions allowed.
		 * @param int      $active  Sessions the user has now.
		 */
		$message = (string) apply_filters( 'user_session_manager_blocked_login_message', $default, $user, $max, $active );

		return '' !== trim( $message ) ? wp_kses_post( $message ) : $default;
	}

	/**
	 * After a login: shortens the IP when asked to, applies the limit, and announces the session.
	 *
	 * @param string $cookie     Logged-in cookie value.
	 * @param int    $expire     Cookie expiry.
	 * @param int    $expiration Session expiry.
	 * @param int    $user_id    User ID.
	 * @param string $scheme     Cookie scheme.
	 * @param string $token      Session token.
	 */
	public function after_login( $cookie, $expire, $expiration, $user_id, $scheme, $token = '' ): void {
		if ( '' === (string) $token || ! $this->sessions->is_supported() ) {
			return;
		}

		$user     = get_userdata( (int) $user_id );
		$verifier = Sessions::verifier( (string) $token );
		$session  = $user ? $this->sessions->get( $user->ID, $verifier ) : null;
		// A cookie re-issued for an older session is not a new login.
		if ( ! $user || null === $session || (int) ( $session['login'] ?? 0 ) < time() - MINUTE_IN_SECONDS ) {
			return;
		}

		$ip     = isset( $session['ip'] ) && is_string( $session['ip'] ) ? $session['ip'] : '';
		$stored = $this->sessions->storable_ip( $ip, $user->ID );
		if ( $stored !== $ip ) {
			$session['ip'] = $stored;
			$this->sessions->update( $user->ID, $verifier, $session );
		}

		if ( ! $this->enforce_limit( $user, $verifier, $session ) ) {
			return;
		}

		/**
		 * Fires after a user signed in and their new session was saved.
		 *
		 * @param \WP_User $user    The user.
		 * @param array    $session Session data: expiration, ip, ua, login, last_seen and handle.
		 */
		do_action( 'user_session_manager_session_started', $user, $session + array( 'handle' => $this->sessions->handle( $user->ID, $verifier ) ) );
	}

	/**
	 * Keeps a user at their session limit after a new session was created.
	 *
	 * In evict mode the oldest sessions are signed out. In block mode the login
	 * form already refused the login, so a new session over the limit came from
	 * a login that skipped the form (a custom or social login calling
	 * wp_set_auth_cookie()): that new session is ended instead.
	 *
	 * @param \WP_User $user     User.
	 * @param string   $verifier The new session.
	 * @param array    $session  The new session's data.
	 * @return bool Whether the new session survived.
	 */
	public function enforce_limit( \WP_User $user, string $verifier, array $session ): bool {
		$max = $this->settings->max_sessions( $user );
		if ( $max < 1 ) {
			return true;
		}

		$others = $this->active_sessions( $user );
		unset( $others[ $verifier ] );
		$over = count( $others ) + 1 - $max;
		if ( $over < 1 ) {
			return true;
		}

		if ( 'block' === $this->settings->limit_mode( $user ) ) {
			$this->sessions->forget( $user->ID, $verifier );
			/** This action is documented in src/Limits.php */
			do_action( 'user_session_manager_login_blocked', $user, $max, count( $others ) );
			return false;
		}

		uasort(
			$others,
			static function ( array $a, array $b ): int {
				return (int) ( $a['login'] ?? 0 ) <=> (int) ( $b['login'] ?? 0 );
			}
		);

		$new = $session + array( 'handle' => $this->sessions->handle( $user->ID, $verifier ) );
		foreach ( array_slice( $others, 0, $over, true ) as $old_verifier => $old ) {
			$this->sessions->forget( $user->ID, (string) $old_verifier );

			/**
			 * Fires after an old session was signed out to make room for a new login.
			 *
			 * @param int   $user_id The user.
			 * @param array $evicted The signed-out session: expiration, ip, ua, login, last_seen and handle.
			 * @param array $new     The new session.
			 */
			do_action( 'user_session_manager_session_evicted', $user->ID, $old + array( 'handle' => $this->sessions->handle( $user->ID, (string) $old_verifier ) ), $new );
		}

		return true;
	}

	/**
	 * Sets how long a new session lasts, from the user's roles.
	 *
	 * @param int  $length   Seconds WordPress would use.
	 * @param int  $user_id  User ID.
	 * @param bool $remember Whether "Remember Me" was ticked.
	 * @return int
	 */
	public function lifetime( $length, $user_id = 0, $remember = false ): int {
		$user = get_userdata( (int) $user_id );

		return $user ? $this->settings->lifetime( $user, (bool) $remember, (int) $length ) : (int) $length;
	}

	/**
	 * A user's sessions, after signing out the idle ones.
	 *
	 * @param \WP_User $user User.
	 * @return array<string, array>
	 */
	private function active_sessions( \WP_User $user ): array {
		$sessions = $this->sessions->for_user( $user->ID );
		$minutes  = $this->settings->idle_timeout( $user );
		$now      = time();

		foreach ( $sessions as $verifier => $session ) {
			if ( Sessions::is_idle( $session, $minutes, $now ) ) {
				$this->sessions->forget( $user->ID, (string) $verifier );
				unset( $sessions[ $verifier ] );
				Activity::announce_idle( $user->ID, $session + array( 'handle' => $this->sessions->handle( $user->ID, (string) $verifier ) ), 'login' );
			}
		}

		return $sessions;
	}
}
