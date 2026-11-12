<?php
/**
 * "This wasn't me": the link in the new device email.
 *
 * @package AryanX\Axle\UserSessionManager
 */

namespace AryanX\Axle\UserSessionManager;

defined( 'ABSPATH' ) || exit;

/**
 * Lets the owner of an account answer a sign-in alert with one click.
 *
 * The new device email carries a link. Opening it shows a page that says what
 * will happen, and nothing changes until the person presses the button on it,
 * so mail scanners that open links do no harm. Pressing the button signs the
 * session out (or every session, a setting), makes the password stop working
 * so the next sign-in goes through "Lost your password?", emails a reset link
 * to the address on the account, and tells the site administrators.
 *
 * The link holds a random token. Only a keyed hash of it is stored, bound to
 * the user and to the one session it was sent for, and removed the moment it
 * is used. Every answer to a wrong, expired or used link is the same page, and
 * repeated wrong guesses from one address are slowed down.
 */
final class NotMe implements Service {

	public const ACTION = 'user_session_manager_not_me';
	public const META   = 'user_session_manager_not_me';
	public const EVENTS = 'user_session_manager_not_me_events';

	private const MAX_LINKS   = 5;
	private const MAX_EVENTS  = 50;
	private const TOKEN_BYTES = 24;

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
	 * Sets up the feature.
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
		add_action( 'login_form_' . self::ACTION, array( $this, 'page' ) );
	}

	/**
	 * Makes a link for the session a new device email is about.
	 *
	 * @param \WP_User $user    The account.
	 * @param array    $session The new session, with its handle.
	 * @return string The link, or an empty string when the feature is off.
	 */
	public function create_link( \WP_User $user, array $session ): string {
		if ( ! $this->settings->not_me_enabled() || empty( $session['handle'] ) ) {
			return '';
		}

		$token = bin2hex( random_bytes( self::TOKEN_BYTES ) );
		$now   = time();
		$links = $this->live_links( $user->ID, $now );

		$links[ $this->hash( $token ) ] = array(
			'handle'  => (string) $session['handle'],
			'created' => $now,
			'expires' => $now + $this->settings->not_me_lifetime(),
			'device'  => UserAgent::parse( isset( $session['ua'] ) && is_string( $session['ua'] ) ? $session['ua'] : '' )['label'],
			'login'   => (int) ( $session['login'] ?? $now ),
		);
		// Keep the newest few: an old email's link stops working once five newer ones exist.
		uasort(
			$links,
			static function ( array $a, array $b ): int {
				return $b['created'] <=> $a['created'];
			}
		);
		update_user_meta( $user->ID, self::META, array_slice( $links, 0, self::MAX_LINKS, true ) );

		return add_query_arg(
			array(
				'action'  => self::ACTION,
				'usm_key' => $user->ID . '.' . $token,
			),
			network_site_url( 'wp-login.php', 'login' )
		);
	}

	/**
	 * Forgets a user's links.
	 *
	 * @param int $user_id User ID.
	 * @return bool Whether there was anything to forget.
	 */
	public function forget_links( int $user_id ): bool {
		$had = is_array( get_user_meta( $user_id, self::META, true ) );
		delete_user_meta( $user_id, self::META );

		return $had;
	}

	/**
	 * Finds the link a key belongs to.
	 *
	 * @param string $key "<user ID>.<token>".
	 * @return array{user: \WP_User, hash: string, link: array}|null Null for anything that is not a live link.
	 */
	public function lookup( string $key ): ?array {
		if ( ! preg_match( '/^(\d{1,10})\.([0-9a-f]{' . ( self::TOKEN_BYTES * 2 ) . '})$/', $key, $match ) ) {
			return null;
		}

		$user = get_userdata( (int) $match[1] );
		if ( ! $user ) {
			return null;
		}

		$wanted = $this->hash( $match[2] );
		$found  = null;
		// Every stored hash is compared, so the time taken does not say which one matched.
		foreach ( $this->live_links( $user->ID, time() ) as $hash => $link ) {
			if ( hash_equals( (string) $hash, $wanted ) ) {
				$found = array(
					'user' => $user,
					'hash' => (string) $hash,
					'link' => $link,
				);
			}
		}

		return $found;
	}

	/**
	 * Uses a link: ends the session, retires the password, sends the reset link and tells the administrators.
	 *
	 * @param array $found The result of lookup().
	 * @return array{user: int, time: int, device: string, ip: string, scope: string, ended: int, reset: bool} The report.
	 */
	public function redeem( array $found ): array {
		$user = $found['user'];
		$link = $found['link'];

		// Single use: the link is gone before anything else happens.
		$links = $this->live_links( $user->ID, time() );
		unset( $links[ $found['hash'] ] );
		if ( $links ) {
			update_user_meta( $user->ID, self::META, $links );
		} else {
			delete_user_meta( $user->ID, self::META );
		}

		$scope   = $this->settings->not_me_scope( $user );
		$session = $this->sessions->find( (string) $link['handle'] );
		$session = null !== $session && $session['user_id'] === $user->ID ? $session : null;
		$ip      = null !== $session && isset( $session['session']['ip'] ) && is_string( $session['session']['ip'] ) ? $this->sessions->storable_ip( $session['session']['ip'], $user->ID ) : '';

		if ( 'all' === $scope ) {
			$ended = $this->sessions->sign_out_everywhere( $user->ID, 'not_me' );
		} else {
			$ended = null !== $session && $this->sessions->revoke( $user->ID, $session['verifier'], 'not_me' ) ? 1 : 0;
		}

		self::retire_password( $user->ID );
		$reset = $this->send_reset_email( $user );

		$report = array(
			'user'   => $user->ID,
			'time'   => time(),
			'device' => (string) $link['device'],
			'ip'     => $ip,
			'scope'  => $scope,
			'ended'  => $ended,
			'reset'  => $reset,
		);
		$this->log( $report );

		/**
		 * Fires after someone pressed "This wasn't me" for a sign-in.
		 *
		 * @param \WP_User $user   The account.
		 * @param array    $report user, time, device, ip, scope (all or session), ended (sessions signed out) and reset (whether the reset email was sent).
		 */
		do_action( 'user_session_manager_not_me_reported', $user, $report );

		$this->alert_administrators( $user, $report );

		return $report;
	}

	/**
	 * Makes a password stop working, so the next sign-in has to go through a reset.
	 *
	 * @param int $user_id User ID.
	 */
	public static function retire_password( int $user_id ): void {
		wp_set_password( wp_generate_password( 64, true, true ), $user_id );
	}

	/**
	 * Recent reports, newest first.
	 *
	 * @param int $user_id Only this user. 0 for everyone.
	 * @param int $days    Only reports this many days back. 0 for all that are kept.
	 * @return array[] Each with user, time, device, ip, scope, ended and reset.
	 */
	public function events( int $user_id = 0, int $days = 0 ): array {
		$events = is_multisite() ? get_site_option( self::EVENTS, array() ) : get_option( self::EVENTS, array() );
		$events = is_array( $events ) ? $events : array();
		$cutoff = $days > 0 ? time() - $days * DAY_IN_SECONDS : 0;

		return array_values(
			array_filter(
				$events,
				static function ( $event ) use ( $user_id, $cutoff ): bool {
					return is_array( $event ) && ( $user_id < 1 || (int) ( $event['user'] ?? 0 ) === $user_id ) && (int) ( $event['time'] ?? 0 ) >= $cutoff;
				}
			)
		);
	}

	/**
	 * Removes a user's reports.
	 *
	 * @param int $user_id User ID.
	 * @return bool Whether there was anything to remove.
	 */
	public function forget_events( int $user_id ): bool {
		$all  = $this->events();
		$kept = array_values(
			array_filter(
				$all,
				static function ( array $event ) use ( $user_id ): bool {
					return (int) $event['user'] !== $user_id;
				}
			)
		);
		if ( count( $kept ) === count( $all ) ) {
			return false;
		}

		$this->store_events( $kept );

		return true;
	}

	/**
	 * Handles wp-login.php?action=user_session_manager_not_me: shows the page, or acts on the button.
	 */
	public function page(): void {
		nocache_headers();
		if ( ! headers_sent() ) {
			// The key is in the address, so it must not travel in a Referer header.
			header( 'Referrer-Policy: no-referrer' );
			header( 'X-Robots-Tag: noindex, nofollow' );
		}

		// phpcs:disable WordPress.Security.NonceVerification -- The key in the request is the credential: a random, single-use token.
		$post = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'];
		$key  = isset( $_REQUEST['usm_key'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['usm_key'] ) ) : '';
		$sure = $post && ! empty( $_POST['usm_confirm'] );
		// phpcs:enable WordPress.Security.NonceVerification

		if ( $this->is_limited() ) {
			status_header( 429 );
			$this->render( __( 'Too many tries', 'user-session-manager' ), '<p class="message">' . esc_html__( 'Too many attempts from this network. Wait a few minutes and open the link from your email again.', 'user-session-manager' ) . '</p>' );
			exit;
		}

		$found = $this->settings->not_me_enabled() ? $this->lookup( $key ) : null;
		if ( null === $found ) {
			$this->count_failure();
			status_header( 404 );
			$this->render( __( 'This link no longer works', 'user-session-manager' ), '<p class="message">' . esc_html__( 'This link has expired, or it has already been used. If you still think someone else is using your account, sign in and change your password, or ask a site administrator for help.', 'user-session-manager' ) . '</p>' );
			exit;
		}

		if ( ! $sure ) {
			$this->render_confirm( $found, $key );
			exit;
		}

		$report = $this->redeem( $found );
		$text   = $report['reset']
			? __( 'Done. We signed the account out and its password no longer works. We sent a link to the email address on the account so you can choose a new one. A site administrator has been told.', 'user-session-manager' )
			: __( 'Done. We signed the account out and its password no longer works. We could not send the email, so use "Lost your password?" on the sign-in page to choose a new one. A site administrator has been told.', 'user-session-manager' );
		$this->render( __( 'The account is safe', 'user-session-manager' ), '<p class="message">' . esc_html( $text ) . '</p><p><a href="' . esc_url( wp_login_url() ) . '">' . esc_html__( 'Go to the sign-in page', 'user-session-manager' ) . '</a></p>' );
		exit;
	}

	/**
	 * Prints the confirm page. Showing it changes nothing.
	 *
	 * @param array  $found The result of lookup().
	 * @param string $key   The key from the address.
	 */
	private function render_confirm( array $found, string $key ): void {
		$link  = $found['link'];
		$scope = $this->settings->not_me_scope( $found['user'] );
		$site  = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );

		$what = 'all' === $scope
			? __( 'We will sign the account out on every device, including this one.', 'user-session-manager' )
			: __( 'We will sign that device out.', 'user-session-manager' );

		ob_start();
		?>
		<p class="message">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: site name, 2: browser and system such as "Chrome on Windows", 3: time. */
					__( 'Someone signed in to an account on %1$s from %2$s at %3$s. Was that you?', 'user-session-manager' ),
					$site,
					(string) $link['device'],
					wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $link['login'] )
				)
			);
			?>
		</p>
		<p><?php esc_html_e( 'If it was you, close this page. Nothing has changed.', 'user-session-manager' ); ?></p>
		<p><strong><?php esc_html_e( 'If it was not you, press the button. This is what happens:', 'user-session-manager' ); ?></strong></p>
		<p><?php echo esc_html( $what ); ?></p>
		<p><?php esc_html_e( 'The current password stops working. You choose a new one from a link we email to the address on the account.', 'user-session-manager' ); ?></p>
		<p><?php esc_html_e( 'A site administrator is told.', 'user-session-manager' ); ?></p>
		<form method="post" action="<?php echo esc_url( add_query_arg( 'action', self::ACTION, network_site_url( 'wp-login.php', 'login' ) ) ); ?>">
			<input type="hidden" name="usm_key" value="<?php echo esc_attr( $key ); ?>">
			<input type="hidden" name="usm_confirm" value="1">
			<p class="submit"><button type="submit" class="button button-primary button-large"><?php esc_html_e( 'This wasn\'t me, secure the account', 'user-session-manager' ); ?></button></p>
		</form>
		<?php
		$this->render( __( 'Was this you?', 'user-session-manager' ), (string) ob_get_clean() );
	}

	/**
	 * Prints a page in the login screen's frame.
	 *
	 * @param string $title Page title.
	 * @param string $body  HTML for the page, already escaped.
	 */
	private function render( string $title, string $body ): void {
		login_header( $title );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every caller escapes what it builds.
		login_footer();
	}

	/**
	 * Slows down someone guessing keys: too many wrong ones from one address.
	 *
	 * @return bool
	 */
	private function is_limited(): bool {
		/**
		 * Filters how many wrong "This wasn't me" links one network address may open in 15 minutes.
		 *
		 * @param int $attempts Default 10. At least 3.
		 */
		$max = max( 3, (int) apply_filters( 'user_session_manager_not_me_attempts', 10 ) );

		return (int) get_transient( $this->limit_key() ) >= $max;
	}

	/**
	 * Counts one wrong key.
	 */
	private function count_failure(): void {
		set_transient( $this->limit_key(), (int) get_transient( $this->limit_key() ) + 1, 15 * MINUTE_IN_SECONDS );
	}

	/**
	 * Transient name for the caller's address.
	 *
	 * @return string
	 */
	private function limit_key(): string {
		// Only a counter key, hashed with a salt: the address is never stored or shown.
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : ''; // phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__

		return 'usm_nm_' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ), 0, 32 );
	}

	/**
	 * Keyed hash of a token.
	 *
	 * @param string $token Raw token.
	 * @return string
	 */
	private function hash( string $token ): string {
		return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
	}

	/**
	 * A user's links that have not expired.
	 *
	 * @param int $user_id User ID.
	 * @param int $now     Current time.
	 * @return array<string, array>
	 */
	private function live_links( int $user_id, int $now ): array {
		$stored = get_user_meta( $user_id, self::META, true );
		$links  = array();
		foreach ( is_array( $stored ) ? $stored : array() as $hash => $link ) {
			if ( is_array( $link ) && (int) ( $link['expires'] ?? 0 ) > $now ) {
				$links[ (string) $hash ] = $link;
			}
		}

		return $links;
	}

	/**
	 * Emails the account a link to choose a new password.
	 *
	 * @param \WP_User $user The account.
	 * @return bool Whether the email was sent.
	 */
	private function send_reset_email( \WP_User $user ): bool {
		$key = get_password_reset_key( $user );
		if ( is_wp_error( $key ) ) {
			return false;
		}

		$site  = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$url   = network_site_url( 'wp-login.php?action=rp&key=' . $key . '&login=' . rawurlencode( $user->user_login ), 'login' );
		$email = array(
			'to'      => $user->user_email,
			/* translators: %s: site name. */
			'subject' => sprintf( __( '[%s] Choose a new password', 'user-session-manager' ), $site ),
			'message' => sprintf(
				/* translators: 1: site name, 2: link to choose a new password. */
				__(
					'Someone reported a sign-in to your account on %1$s as "This wasn\'t me". We signed the account out and its password no longer works.

Choose a new password here:
%2$s

If you did not report it, ask a site administrator for help.',
					'user-session-manager'
				),
				$site,
				$url
			),
			'headers' => array(),
		);

		/**
		 * Filters the email with the link to choose a new password. Return false, or an empty `to`, to skip it.
		 *
		 * @param array|false $email to, subject, message and headers, as wp_mail() takes them.
		 * @param \WP_User    $user  The account.
		 */
		$email = apply_filters( 'user_session_manager_not_me_reset_email', $email, $user );
		if ( ! is_array( $email ) || empty( $email['to'] ) ) {
			return false;
		}

		return (bool) wp_mail( $email['to'], (string) ( $email['subject'] ?? '' ), (string) ( $email['message'] ?? '' ), $email['headers'] ?? array() ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail
	}

	/**
	 * Tells the site administrators.
	 *
	 * @param \WP_User $user   The account.
	 * @param array    $report The report.
	 */
	private function alert_administrators( \WP_User $user, array $report ): void {
		$default = array_filter( array( (string) ( is_multisite() ? get_site_option( 'admin_email' ) : get_option( 'admin_email' ) ) ) );

		/**
		 * Filters who is emailed when someone presses "This wasn't me".
		 *
		 * @param string[] $recipients Email addresses. Default the site administration address.
		 * @param \WP_User $user       The account.
		 * @param array    $report     The report.
		 */
		$recipients = apply_filters( 'user_session_manager_not_me_recipients', $default, $user, $report );
		$recipients = array_values( array_filter( array_map( 'sanitize_email', array_map( 'strval', (array) $recipients ) ) ) );
		if ( ! $recipients ) {
			return;
		}

		$site    = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$message = sprintf(
			/* translators: 1: user login, 2: site name, 3: browser and system, 4: IP address, 5: number of sessions signed out, 6: link to the sessions screen. */
			__(
				'The owner of the account "%1$s" on %2$s said a sign-in was not them.

Device: %3$s
IP address: %4$s
Sessions signed out: %5$d

The password of the account no longer works and a reset link was sent to its owner. You can see the report on the Sessions screen:
%6$s',
				'user-session-manager'
			),
			$user->user_login,
			$site,
			'' !== $report['device'] ? $report['device'] : __( 'unknown', 'user-session-manager' ),
			'' !== $report['ip'] ? $report['ip'] : __( 'unknown', 'user-session-manager' ),
			$report['ended'],
			is_multisite() ? network_admin_url( 'users.php?page=user-sessions' ) : admin_url( 'users.php?page=user-sessions' )
		);
		$email = array(
			'to'      => $recipients,
			/* translators: 1: site name, 2: user login. */
			'subject' => sprintf( __( '[%1$s] "This wasn\'t me" report for %2$s', 'user-session-manager' ), $site, $user->user_login ),
			'message' => $message,
			'headers' => array(),
		);

		/**
		 * Filters the email to the administrators. Return false, or an empty `to`, to skip it.
		 *
		 * @param array|false $email  to (an array of addresses), subject, message and headers.
		 * @param \WP_User    $user   The account.
		 * @param array       $report The report.
		 */
		$email = apply_filters( 'user_session_manager_not_me_alert_email', $email, $user, $report );
		if ( ! is_array( $email ) || empty( $email['to'] ) ) {
			return;
		}

		wp_mail( $email['to'], (string) ( $email['subject'] ?? '' ), (string) ( $email['message'] ?? '' ), $email['headers'] ?? array() ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail
	}

	/**
	 * Adds a report to the log.
	 *
	 * @param array $report The report.
	 */
	private function log( array $report ): void {
		$events = $this->events();
		array_unshift( $events, $report );

		/**
		 * Filters how many "This wasn't me" reports are kept.
		 *
		 * @param int $max Default 50. Kept between 5 and 500.
		 */
		$max = max( 5, min( 500, (int) apply_filters( 'user_session_manager_not_me_log_limit', self::MAX_EVENTS ) ) );
		$this->store_events( array_slice( $events, 0, $max ) );
	}

	/**
	 * Saves the log. Not autoloaded.
	 *
	 * @param array[] $events Reports.
	 */
	private function store_events( array $events ): void {
		if ( is_multisite() ) {
			update_site_option( self::EVENTS, $events );
		} else {
			update_option( self::EVENTS, $events, false );
		}
	}
}
