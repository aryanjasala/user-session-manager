<?php
/**
 * The message on the login screen that says why you were signed out.
 *
 * @package AryanX\Axle\UserSessionManager
 */

namespace AryanX\Axle\UserSessionManager;

defined( 'ABSPATH' ) || exit;

/**
 * Tells a person why they landed on the login screen.
 *
 * The browser that lost its session still sends the old login cookie. When
 * WordPress refuses that cookie, this looks the session up in the history by
 * the cookie's own token, and only a browser that really held the session can
 * match it. It then sets a short-lived cookie, and the login screen reads and
 * clears it. Someone who never held the session has no token that matches, so
 * they see nothing and learn nothing.
 */
final class LoginNotice implements Service {

	private const LIFETIME = 300;

	/**
	 * History.
	 *
	 * @var History
	 */
	private History $history;

	/**
	 * Session store.
	 *
	 * @var Sessions
	 */
	private Sessions $sessions;

	/**
	 * Whether this request already looked at a refused cookie.
	 *
	 * @var bool
	 */
	private bool $looked = false;

	/**
	 * Sets up the notice.
	 *
	 * @param History  $history  History.
	 * @param Sessions $sessions Session store.
	 */
	public function __construct( History $history, Sessions $sessions ) {
		$this->history  = $history;
		$this->sessions = $sessions;
	}

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_action( 'auth_cookie_bad_session_token', array( $this, 'on_refused_cookie' ) );
		add_action( 'auth_cookie_expired', array( $this, 'on_refused_cookie' ) );
		add_filter( 'wp_login_errors', array( $this, 'add_message' ) );
	}

	/**
	 * Name of the short-lived cookie.
	 *
	 * @return string
	 */
	public static function cookie_name(): string {
		return 'usm_ended_' . ( defined( 'COOKIEHASH' ) ? COOKIEHASH : md5( (string) get_option( 'siteurl' ) ) );
	}

	/**
	 * WordPress refused a login cookie: remembers why, when the history knows.
	 *
	 * @param mixed $elements Cookie parts as an array: username, expiration, token, hmac and scheme.
	 */
	public function on_refused_cookie( $elements ): void {
		if ( $this->looked || ! is_array( $elements ) || empty( $elements['username'] ) || empty( $elements['token'] ) ) {
			return;
		}
		$this->looked = true;

		$user = get_user_by( 'login', (string) $elements['username'] );
		if ( ! $user ) {
			return;
		}

		$entry = $this->history->find( $user->ID, $this->sessions->handle( $user->ID, Sessions::verifier( (string) $elements['token'] ) ) );
		if ( null === $entry ) {
			return;
		}

		$now    = time();
		$reason = (string) ( $entry['reason'] ?? '' );
		$when   = (int) ( $entry['end'] ?? 0 );
		if ( '' === $reason && $now >= (int) ( $entry['expires'] ?? 0 ) ) {
			$reason = 'expired';
			$when   = (int) $entry['expires'];
		}
		if ( '' === $reason || 'signed_out' === $reason || $when < 1 ) {
			return;
		}

		$this->set_cookie( $reason . '.' . $when, $now + self::LIFETIME );
	}

	/**
	 * Adds the message to the login screen, once.
	 *
	 * @param mixed $errors Login screen errors and messages, a WP_Error.
	 * @return mixed
	 */
	public function add_message( $errors ) {
		$name = self::cookie_name();
		if ( ! isset( $_COOKIE[ $name ] ) || ! $errors instanceof \WP_Error ) {
			return $errors;
		}

		// The login screen is never cached, and the cookie is read once and cleared.
		$value = sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		$this->set_cookie( '', time() - YEAR_IN_SECONDS );

		if ( ! preg_match( '/^([a-z_]+)\.(\d{9,11})$/', $value, $match ) ) {
			return $errors;
		}

		$text = self::message( $match[1], (int) $match[2] );
		if ( '' !== $text ) {
			$errors->add( 'user_session_manager_ended', $text, 'message' );
		}

		return $errors;
	}

	/**
	 * The sentence for a reason and a time.
	 *
	 * @param string $reason One of History::REASONS.
	 * @param int    $when   When the session ended.
	 * @return string Empty for the person's own sign out, an unknown reason or a time that is not recent.
	 */
	public static function message( string $reason, int $when ): string {
		$now = time();
		if ( ! in_array( $reason, History::REASONS, true ) || 'signed_out' === $reason || $when > $now + MINUTE_IN_SECONDS || $when < $now - 30 * DAY_IN_SECONDS ) {
			return '';
		}

		$time = $when > $now - DAY_IN_SECONDS
			? wp_date( (string) get_option( 'time_format' ), $when )
			: wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $when );

		$texts = array(
			/* translators: %s: time, such as 14:02. */
			'expired'      => __( 'Your session ended because it reached its time limit, at %s. Sign in again to carry on.', 'user-session-manager' ),
			/* translators: %s: time, such as 14:02. */
			'idle'         => __( 'You were signed out because you were inactive for a while, at %s. Sign in again to carry on.', 'user-session-manager' ),
			/* translators: %s: time, such as 14:02. */
			'other_device' => __( 'You were signed out because you signed out this device from another device, at %s.', 'user-session-manager' ),
			/* translators: %s: time, such as 14:02. */
			'admin'        => __( 'You were signed out by a site administrator, at %s.', 'user-session-manager' ),
			/* translators: %s: time, such as 14:02. */
			'limit'        => __( 'You were signed out because this account signed in on another device, at %s. The account can only be signed in on a limited number of devices.', 'user-session-manager' ),
			/* translators: %s: time, such as 14:02. */
			'not_me'       => __( 'You were signed out because a sign-in to this account was reported as "This wasn\'t me", at %s. Use "Lost your password?" to choose a new password.', 'user-session-manager' ),
			/* translators: %s: time, such as 14:02. */
			'password'     => __( 'You were signed out because the password of this account changed, at %s. Sign in with the new password.', 'user-session-manager' ),
			/* translators: %s: time, such as 14:02. */
			'shared'       => __( 'You were signed out because this account looked like it was used on more than one device at once, at %s.', 'user-session-manager' ),
		);
		$text  = sprintf( $texts[ $reason ], $time );

		/**
		 * Filters the message on the login screen that says why someone was signed out.
		 *
		 * @param string $text   The message.
		 * @param string $reason expired, idle, other_device, admin, limit, not_me, password or shared.
		 * @param int    $when   When the session ended.
		 */
		$filtered = (string) apply_filters( 'user_session_manager_signed_out_message', $text, $reason, $when );

		return '' !== trim( $filtered ) ? $filtered : $text;
	}

	/**
	 * Sets or clears the cookie.
	 *
	 * @param string $value   Value, or empty to clear.
	 * @param int    $expires Expiry time.
	 */
	private function set_cookie( string $value, int $expires ): void {
		if ( '' === $value ) {
			unset( $_COOKIE[ self::cookie_name() ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		}
		if ( headers_sent() ) {
			return;
		}

		// Set only on the request that is redirected to the login screen, for the browser that held the session.
		setcookie( // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie
			self::cookie_name(),
			$value,
			array(
				'expires'  => $expires,
				'path'     => defined( 'COOKIEPATH' ) ? COOKIEPATH : '/',
				'domain'   => defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}
}
