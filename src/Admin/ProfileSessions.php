<?php
/**
 * Profile > Your sessions.
 *
 * @package AryanX\UserSessionManager
 */

namespace AryanX\UserSessionManager\Admin;

use AryanX\UserSessionManager\Access;
use AryanX\UserSessionManager\Service;
use AryanX\UserSessionManager\Sessions;
use const AryanX\UserSessionManager\PLUGIN_FILE;
use const AryanX\UserSessionManager\VERSION;

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
		if ( in_array( $hook_suffix, array( 'profile.php', 'user-edit.php' ), true ) ) {
			wp_enqueue_style( 'user-session-manager', plugins_url( 'assets/admin.css', PLUGIN_FILE ), array(), VERSION );
		}
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
							<td><?php echo wp_kses( Display::device( $row ), Display::ALLOWED ); ?></td>
							<td class="usm-ip"><?php echo esc_html( '' !== $row['ip'] ? $row['ip'] : __( 'Unknown', 'user-session-manager' ) ); ?></td>
							<td><?php echo wp_kses( Display::time( $row['login'] ), Display::ALLOWED ); ?></td>
							<td><?php echo wp_kses( Display::time( $row['last_seen'] ), Display::ALLOWED ); ?></td>
							<td><?php echo wp_kses( Display::time( $row['expiration'] ), Display::ALLOWED ); ?></td>
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
				<?php elseif ( ! $own ) : ?>
					<a class="button" href="<?php echo esc_url( self::url( 'sign_out', (string) $user->ID, $back ) ); ?>"><?php esc_html_e( 'Sign out everywhere', 'user-session-manager' ); ?></a>
				<?php endif; ?>
				<?php if ( ! $own && Access::can_view_all() ) : ?>
					<a href="<?php echo esc_url( SessionsPage::url( array( 'user_id' => $user->ID ) ) ); ?>"><?php esc_html_e( 'All sessions', 'user-session-manager' ); ?></a>
				<?php endif; ?>
			</p>
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
				$args['usm_message'] = 'signed_out';
				$args['usm_count']   = $this->sessions->sign_out_everywhere( $user_id, 'profile' );
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
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$text = self::message( $message, $count );
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
	 * @return string
	 */
	public static function message( string $message, int $count ): string {
		switch ( $message ) {
			case 'revoked':
				return __( 'Session signed out.', 'user-session-manager' );
			case 'not_found':
				return __( 'That session had already ended.', 'user-session-manager' );
			case 'signed_out_others':
				/* translators: %d: number of sessions. */
				return sprintf( _n( 'Signed out %d other session.', 'Signed out %d other sessions.', $count, 'user-session-manager' ), $count );
			case 'signed_out':
				/* translators: %d: number of sessions. */
				return sprintf( _n( 'Signed out of %d session.', 'Signed out of %d sessions.', $count, 'user-session-manager' ), $count );
			case 'bulk':
				/* translators: %d: number of sessions. */
				return sprintf( _n( '%d session signed out.', '%d sessions signed out.', $count, 'user-session-manager' ), $count );
		}

		return '';
	}
}
