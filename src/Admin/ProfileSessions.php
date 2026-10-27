<?php
/**
 * Profile > Your sessions.
 *
 * @package AryanX\Axle\UserSessionManager
 */

namespace AryanX\Axle\UserSessionManager\Admin;

use AryanX\Axle\UserSessionManager\Access;
use AryanX\Axle\UserSessionManager\Service;
use AryanX\Axle\UserSessionManager\Sessions;
use const AryanX\Axle\UserSessionManager\PLUGIN_FILE;
use const AryanX\Axle\UserSessionManager\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Lists a user's sessions on their profile, with a sign out link for each.
 *
 * The profile screen is one big form, so the links go to admin-post.php with
 * their own nonce instead of nesting forms. No JavaScript needed.
 */
final class ProfileSessions implements Service {

	public const ACTION = 'user_session_manager';
	public const ANCHOR = 'usm-sessions';

	/**
	 * Session store.
	 *
	 * @var Sessions
	 */
	private Sessions $sessions;

	/**
	 * Sets up the section.
	 *
	 * @param Sessions $sessions Session store.
	 */
	public function __construct( Sessions $sessions ) {
		$this->sessions = $sessions;
	}

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_action( 'show_user_profile', array( $this, 'render' ), 1 );
		add_action( 'edit_user_profile', array( $this, 'render' ), 1 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
		add_action( 'network_admin_notices', array( $this, 'notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );
	}

	/**
	 * Styles on the profile screens.
	 *
	 * @param string $hook_suffix Screen.
	 */
	public function enqueue_styles( $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, array( 'profile.php', 'user-edit.php' ), true ) ) {
			return;
		}

		wp_enqueue_style( 'user-session-manager', plugins_url( 'assets/admin.css', PLUGIN_FILE ), array(), VERSION );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The user being edited, read-only.
		$user_id = 'profile.php' === $hook_suffix ? get_current_user_id() : ( isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0 );
		if ( $this->hides_core_row( $user_id ) ) {
			wp_add_inline_style( 'user-session-manager', '.user-sessions-wrap{display:none}' );
		}
	}

	/**
	 * Whether core's own "Sessions" row is hidden on a profile, since this section does the same and more.
	 *
	 * @param int $user_id User being edited.
	 * @return bool
	 */
	public function hides_core_row( int $user_id ): bool {
		if ( $user_id < 1 || ! $this->sessions->is_supported() || ! Access::can_manage( $user_id ) ) {
			return false;
		}

		/**
		 * Filters whether core's "Log Out Everywhere Else" row is hidden on profiles that show the sessions table.
		 *
		 * @param bool $hide    Default true.
		 * @param int  $user_id User being edited.
		 */
		return (bool) apply_filters( 'user_session_manager_hide_core_sessions', true, $user_id );
	}

	/**
	 * A link that runs a task through admin-post.php.
	 *
	 * @param string $task     revoke, sign_out_others or sign_out.
	 * @param string $target   Handle or user ID.
	 * @param string $redirect Where to come back to.
	 * @return string URL.
	 */
	public static function url( string $task, string $target, string $redirect ): string {
		$key = 'revoke' === $task ? 'handle' : 'user';

		return wp_nonce_url(
			add_query_arg(
				array(
					'action'      => self::ACTION,
					'task'        => $task,
					$key          => $target,
					'redirect_to' => rawurlencode( $redirect ),
				),
				admin_url( 'admin-post.php' )
			),
			self::nonce_action( $task, $target )
		);
	}

	/**
	 * Nonce action for one task on one target.
	 *
	 * @param string $task   Task.
	 * @param string $target Handle or user ID.
	 * @return string
	 */
	private static function nonce_action( string $task, string $target ): string {
		return self::ACTION . '_' . $task . '_' . $target;
	}

	/**
	 * Prints the sessions section.
	 *
	 * @param mixed $user User being edited, a WP_User.
	 */
	public function render( $user ): void {
		if ( ! $user instanceof \WP_User || ! Access::can_manage( $user->ID ) ) {
			return;
		}

		$own  = get_current_user_id() === $user->ID;
		$rows = $this->sessions->list_for_user( $user->ID );
		$back = $own ? self_admin_url( 'profile.php' ) : add_query_arg( 'user_id', $user->ID, self_admin_url( 'user-edit.php' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only shows the question; the sign out itself has a nonce.
		$confirm = isset( $_GET['usm_confirm'] ) && 'sign_out' === sanitize_key( wp_unslash( $_GET['usm_confirm'] ) );
		?>
		<div class="usm-profile">
		<h2 id="<?php echo esc_attr( self::ANCHOR ); ?>"><?php echo $own ? esc_html__( 'Your sessions', 'user-session-manager' ) : esc_html__( 'Sessions', 'user-session-manager' ); ?></h2>
		<?php if ( ! $this->sessions->is_supported() ) : ?>
			<p><?php esc_html_e( 'Another plugin stores sessions outside WordPress user data, so they cannot be listed here.', 'user-session-manager' ); ?></p>
			</div>
			<?php
			return;
		endif;
		?>
		<p class="description">
			<?php
			echo $own
				? esc_html__( 'Every browser signed in to your account. Signing one out logs it out the next time it loads a page.', 'user-session-manager' )
				: esc_html__( 'Every browser signed in to this account. Signing one out logs it out the next time it loads a page.', 'user-session-manager' );
			?>
		</p>
		<?php if ( ! $rows ) : ?>
			<p class="usm-empty"><?php esc_html_e( 'No active sessions.', 'user-session-manager' ); ?></p>
		<?php else : ?>
			<table class="widefat striped usm-sessions">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Device', 'user-session-manager' ); ?></th>
						<th scope="col"><?php esc_html_e( 'IP address', 'user-session-manager' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Signed in', 'user-session-manager' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Last active', 'user-session-manager' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Expires', 'user-session-manager' ); ?></th>
						<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'user-session-manager' ); ?></span></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr class="usm-session<?php echo $row['current'] ? ' is-current' : ''; ?>" data-handle="<?php echo esc_attr( $row['handle'] ); ?>">
							<td class="usm-device-cell"><?php echo wp_kses( Display::device( $row ), Display::ALLOWED ); ?></td>
							<td class="usm-ip" data-colname="<?php esc_attr_e( 'IP address', 'user-session-manager' ); ?>"><?php echo esc_html( '' !== $row['ip'] ? $row['ip'] : __( 'Unknown', 'user-session-manager' ) ); ?></td>
							<td data-colname="<?php esc_attr_e( 'Signed in', 'user-session-manager' ); ?>"><?php echo wp_kses( Display::time( $row['login'] ), Display::ALLOWED ); ?></td>
							<td data-colname="<?php esc_attr_e( 'Last active', 'user-session-manager' ); ?>"><?php echo wp_kses( Display::time( $row['last_seen'] ), Display::ALLOWED ); ?></td>
							<td data-colname="<?php esc_attr_e( 'Expires', 'user-session-manager' ); ?>"><?php echo wp_kses( Display::time( $row['expiration'] ), Display::ALLOWED ); ?></td>
							<td class="usm-actions">
								<?php if ( ! $row['current'] ) : ?>
									<a class="button button-small" href="<?php echo esc_url( self::url( 'revoke', $row['handle'], $back ) ); ?>"><?php esc_html_e( 'Sign out', 'user-session-manager' ); ?></a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="usm-bulk">
				<?php if ( $own && count( $rows ) > 1 ) : ?>
					<a class="button" href="<?php echo esc_url( self::url( 'sign_out_others', (string) $user->ID, $back ) ); ?>"><?php esc_html_e( 'Sign out all other sessions', 'user-session-manager' ); ?></a>
				<?php elseif ( ! $own && ! $confirm ) : ?>
					<a class="button" href="<?php echo esc_url( add_query_arg( 'usm_confirm', 'sign_out', $back ) . '#' . self::ANCHOR ); ?>"><?php esc_html_e( 'Sign out everywhere', 'user-session-manager' ); ?></a>
				<?php endif; ?>
				<?php if ( ! $own && Access::can_view_all() ) : ?>
					<a href="<?php echo esc_url( SessionsPage::url( array( 'user_id' => $user->ID ) ) ); ?>"><?php esc_html_e( 'All sessions', 'user-session-manager' ); ?></a>
				<?php endif; ?>
			</p>
			<?php if ( ! $own && $confirm ) : ?>
				<div class="usm-confirm usm-confirm-inline" role="group" aria-labelledby="usm-confirm-title">
					<p id="usm-confirm-title"><strong>
						<?php
						/* translators: %s: user login. */
						echo esc_html( sprintf( __( 'Sign out %s everywhere?', 'user-session-manager' ), $user->user_login ) );
						?>
					</strong></p>
					<p><?php echo wp_kses_post( SessionsPage::confirm_message( array( $user->ID ), count( $rows ), false ) ); ?></p>
					<p class="usm-confirm-actions">
						<a class="button button-primary" href="<?php echo esc_url( self::url( 'sign_out', (string) $user->ID, $back ) ); ?>"><?php esc_html_e( 'Sign out everywhere', 'user-session-manager' ); ?></a>
						<a class="button-link" href="<?php echo esc_url( $back . '#' . self::ANCHOR ); ?>"><?php esc_html_e( 'Cancel', 'user-session-manager' ); ?></a>
					</p>
				</div>
			<?php endif; ?>
		<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Runs a task from a profile link.
	 */
	public function handle(): void {
		$task   = isset( $_GET['task'] ) ? sanitize_key( wp_unslash( $_GET['task'] ) ) : '';
		$target = '';
		if ( 'revoke' === $task ) {
			$target = isset( $_GET['handle'] ) ? sanitize_text_field( wp_unslash( $_GET['handle'] ) ) : '';
		} elseif ( in_array( $task, array( 'sign_out_others', 'sign_out' ), true ) ) {
			$target = isset( $_GET['user'] ) ? (string) absint( $_GET['user'] ) : '';
		}

		check_admin_referer( self::nonce_action( $task, $target ) );

		$user_id = 'revoke' === $task ? (int) strtok( $target, '-' ) : (int) $target;
		if ( '' === $target || ! Access::can_manage( $user_id ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to sign out these sessions.', 'user-session-manager' ), 403 );
		}

		$args = array();
		switch ( $task ) {
			case 'revoke':
				$args['usm_message'] = $this->sessions->revoke_handle( $target, 'profile' ) ? 'revoked' : 'not_found';
				break;

			case 'sign_out_others':
				$args['usm_message'] = 'signed_out_others';
				$args['usm_count']   = get_current_user_id() === $user_id ? $this->sessions->revoke_others( $user_id, $this->sessions->current_verifier(), 'profile' ) : 0;
				break;

			case 'sign_out':
				$args['usm_message'] = 'signed_out_user';
				$args['usm_count']   = $this->sessions->sign_out_everywhere( $user_id, 'profile' );
				$args['usm_user']    = $user_id;
				break;
		}

		$fallback = self_admin_url( 'profile.php' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Checked by wp_validate_redirect().
		$redirect = isset( $_GET['redirect_to'] ) ? wp_validate_redirect( wp_unslash( $_GET['redirect_to'] ), $fallback ) : $fallback;

		wp_safe_redirect( add_query_arg( $args, $redirect ) . '#' . self::ANCHOR );
		exit;
	}

	/**
	 * Says what the last task did, on the profile screens.
	 */
	public function notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display-only values set by our own redirect.
		$message = isset( $_GET['usm_message'] ) ? sanitize_key( wp_unslash( $_GET['usm_message'] ) ) : '';
		$count   = isset( $_GET['usm_count'] ) ? absint( $_GET['usm_count'] ) : 0;
		$user    = isset( $_GET['usm_user'] ) ? get_userdata( absint( $_GET['usm_user'] ) ) : false;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$text = self::message( $message, $count, $user ? $user->user_login : '' );
		if ( '' === $text ) {
			return;
		}

		printf( '<div class="notice notice-%1$s is-dismissible usm-notice"><p>%2$s</p></div>', 'not_found' === $message ? 'warning' : 'success', esc_html( $text ) );
	}

	/**
	 * Text for a result code.
	 *
	 * @param string $message Result code.
	 * @param int    $count   Sessions affected.
	 * @param string $user    Login of the user signed out, for signed_out_user.
	 * @return string
	 */
	public static function message( string $message, int $count, string $user = '' ): string {
		$number = number_format_i18n( $count );
		switch ( $message ) {
			case 'revoked':
				$text = __( 'Session signed out.', 'user-session-manager' );
				break;
			case 'not_found':
				$text = __( 'That session had already ended.', 'user-session-manager' );
				break;
			case 'signed_out_others':
				/* translators: %s: number of sessions. */
				$text = sprintf( _n( 'Signed out %s other session.', 'Signed out %s other sessions.', $count, 'user-session-manager' ), $number );
				break;
			case 'signed_out_user':
				$text = '' !== $user
					/* translators: 1: user login, 2: number of sessions. */
					? sprintf( _n( '%1$s was signed out of %2$s session.', '%1$s was signed out of %2$s sessions.', $count, 'user-session-manager' ), $user, $number )
					/* translators: %s: number of sessions. */
					: sprintf( _n( 'Signed out of %s session.', 'Signed out of %s sessions.', $count, 'user-session-manager' ), $number );
				break;
			case 'bulk':
				/* translators: %s: number of sessions. */
				$text = sprintf( _n( '%s session signed out.', '%s sessions signed out.', $count, 'user-session-manager' ), $number );
				break;
			default:
				return '';
		}

		/**
		 * Filters the notice after sessions were signed out from a screen.
		 *
		 * @param string $text    Notice text.
		 * @param string $message Result code: revoked, not_found, signed_out_others, signed_out_user or bulk.
		 * @param int    $count   Sessions affected.
		 * @param string $user    Login of the user signed out, for signed_out_user.
		 */
		$filtered = (string) apply_filters( 'user_session_manager_notice', $text, $message, $count, $user );

		return '' !== trim( $filtered ) ? $filtered : $text;
	}
}
