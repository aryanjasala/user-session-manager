<?php
/**
 * Users > Sessions (Network Admin > Users > Sessions on multisite).
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
 * Every active session across users. Sessions belong to users, who are shared
 * by the whole network, so on multisite the screen lives in Network Admin.
 */
final class SessionsPage implements Service {

	public const SLUG = 'user-sessions';

	/**
	 * Session store.
	 *
	 * @var Sessions
	 */
	private Sessions $sessions;

	/**
	 * Sets up the screen.
	 *
	 * @param Sessions $sessions Session store.
	 */
	public function __construct( Sessions $sessions ) {
		$this->sessions = $sessions;
	}

	/**
	 * Adds the menu entry.
	 */
	public function register(): void {
		add_action( is_multisite() ? 'network_admin_menu' : 'admin_menu', array( $this, 'add_page' ) );
	}

	/**
	 * URL of the screen.
	 *
	 * @param array $args Query arguments.
	 * @return string
	 */
	public static function url( array $args = array() ): string {
		$base = is_multisite() ? network_admin_url( 'users.php' ) : admin_url( 'users.php' );

		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), $base );
	}

	/**
	 * URL of a row action.
	 *
	 * @param string $action revoke or sign_out.
	 * @param string $target Handle or user ID.
	 * @return string
	 */
	public static function action_url( string $action, string $target ): string {
		$key = 'revoke' === $action ? 'handle' : 'user';

		return wp_nonce_url(
			self::url(
				array(
					'action' => $action,
					$key     => $target,
				)
			),
			'user_session_manager_' . $action . '_' . $target
		);
	}

	/**
	 * Registers the screen under Users.
	 */
	public function add_page(): void {
		if ( ! current_user_can( 'list_users' ) ) {
			return;
		}

		$hook = add_submenu_page(
			'users.php',
			__( 'Sessions', 'user-session-manager' ),
			__( 'Sessions', 'user-session-manager' ),
			Access::capability(),
			self::SLUG,
			array( $this, 'render' )
		);

		if ( $hook ) {
			add_action( "load-{$hook}", array( $this, 'handle' ) );
			add_action( "admin_print_styles-{$hook}", array( $this, 'enqueue_styles' ) );
		}
	}

	/**
	 * Screen styles.
	 */
	public function enqueue_styles(): void {
		wp_enqueue_style( 'user-session-manager', plugins_url( 'assets/admin.css', PLUGIN_FILE ), array(), VERSION );
	}

	/**
	 * Runs row and bulk actions, then redirects back to the list.
	 */
	public function handle(): void {
		if ( ! Access::can_view_all() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to see sessions.', 'user-session-manager' ), 403 );
		}

		$action = '';
		foreach ( array( 'action', 'action2' ) as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The nonce is checked below, per action.
			$value = isset( $_REQUEST[ $key ] ) ? sanitize_key( wp_unslash( $_REQUEST[ $key ] ) ) : '';
			if ( in_array( $value, array( 'revoke', 'sign_out' ), true ) ) {
				$action = $value;
				break;
			}
		}
		if ( '' === $action ) {
			return;
		}

		$count = 0;
		if ( isset( $_REQUEST['handles'] ) ) {
			check_admin_referer( 'bulk-sessions' );
			$handles = array_map( 'sanitize_text_field', (array) wp_unslash( $_REQUEST['handles'] ) );
			$count   = $this->bulk( $action, $handles );
		} elseif ( 'revoke' === $action && isset( $_REQUEST['handle'] ) ) {
			$handle = sanitize_text_field( wp_unslash( $_REQUEST['handle'] ) );
			check_admin_referer( 'user_session_manager_revoke_' . $handle );
			$this->require_manage( (int) strtok( $handle, '-' ) );
			$count = $this->sessions->revoke_handle( $handle, 'admin' ) ? 1 : 0;
		} elseif ( 'sign_out' === $action && isset( $_REQUEST['user'] ) ) {
			$user_id = absint( $_REQUEST['user'] );
			check_admin_referer( 'user_session_manager_sign_out_' . $user_id );
			$this->require_manage( $user_id );
			$count = $this->sessions->sign_out_everywhere( $user_id, 'admin' );
		} else {
			return;
		}

		// Keep the search, filter, sorting and page, drop the action.
		$keep = array();
		foreach ( array( 's', 'role', 'user_id', 'orderby', 'order', 'paged' ) as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked above.
			if ( isset( $_REQUEST[ $key ] ) && '' !== $_REQUEST[ $key ] ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked above.
				$keep[ $key ] = sanitize_text_field( wp_unslash( $_REQUEST[ $key ] ) );
			}
		}
		$keep['usm_message'] = 'bulk';
		$keep['usm_count']   = $count;

		wp_safe_redirect( self::url( $keep ) );
		exit;
	}

	/**
	 * Signs out the chosen sessions, or their users everywhere.
	 *
	 * @param string   $action  revoke or sign_out.
	 * @param string[] $handles Handles.
	 * @return int Sessions ended.
	 */
	public function bulk( string $action, array $handles ): int {
		$handles = array_values( array_unique( array_filter( $handles ) ) );

		/**
		 * Fires before a bulk sign out on Users > Sessions.
		 *
		 * @param string   $action  revoke (the chosen sessions) or sign_out (their users, everywhere).
		 * @param string[] $handles Chosen session handles.
		 */
		do_action( 'user_session_manager_before_bulk', $action, $handles );

		$count = 0;
		$users = array();
		foreach ( $handles as $handle ) {
			$user_id = (int) strtok( $handle, '-' );
			if ( ! Access::can_manage( $user_id ) ) {
				continue;
			}
			if ( 'revoke' === $action ) {
				$count += $this->sessions->revoke_handle( $handle, 'bulk' ) ? 1 : 0;
			} elseif ( ! isset( $users[ $user_id ] ) ) {
				$users[ $user_id ] = true;
				$count            += $this->sessions->sign_out_everywhere( $user_id, 'bulk' );
			}
		}

		/**
		 * Fires after a bulk sign out on Users > Sessions.
		 *
		 * @param string   $action  revoke or sign_out.
		 * @param string[] $handles Chosen session handles.
		 * @param int      $count   Sessions ended.
		 */
		do_action( 'user_session_manager_after_bulk', $action, $handles, $count );

		return $count;
	}

	/**
	 * Stops unless the current user may end this user's sessions.
	 *
	 * @param int $user_id User.
	 */
	private function require_manage( int $user_id ): void {
		if ( ! Access::can_manage( $user_id ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to sign out this user.', 'user-session-manager' ), 403 );
		}
	}

	/**
	 * Prints the screen.
	 */
	public function render(): void {
		$table = new SessionsTable( $this->sessions );
		$table->prepare_items();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter value.
		$filtered_user = isset( $_REQUEST['user_id'] ) ? get_userdata( absint( $_REQUEST['user_id'] ) ) : false;
		$total         = (int) $table->get_pagination_arg( 'total_items' );
		?>
		<div class="wrap usm-page">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Sessions', 'user-session-manager' ); ?></h1>
			<?php if ( current_user_can( Access::settings_capability() ) ) : ?>
				<a href="<?php echo esc_url( SettingsPage::url() ); ?>" class="page-title-action"><?php esc_html_e( 'Session policies', 'user-session-manager' ); ?></a>
			<?php endif; ?>
			<hr class="wp-header-end">

			<?php if ( ! $this->sessions->is_supported() ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'Another plugin stores sessions outside WordPress user data, so User Session Manager cannot list or end them.', 'user-session-manager' ); ?></p></div>
			<?php endif; ?>

			<p class="usm-summary">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: number of sessions, such as 3 active sessions. 2: number of users, such as 2 users. */
						__( '%1$s for %2$s.', 'user-session-manager' ),
						/* translators: %d: number of sessions. */
						sprintf( _n( '%d active session', '%d active sessions', $total, 'user-session-manager' ), $total ),
						/* translators: %d: number of users. */
						sprintf( _n( '%d user', '%d users', $table->users, 'user-session-manager' ), $table->users )
					)
				);
				if ( $filtered_user ) {
					echo ' ';
					/* translators: %s: user login. */
					echo esc_html( sprintf( __( 'Showing %s only.', 'user-session-manager' ), $filtered_user->user_login ) );
					printf( ' <a href="%s">%s</a>', esc_url( self::url() ), esc_html__( 'Show everyone', 'user-session-manager' ) );
				}
				?>
			</p>
			<?php if ( $table->truncated ) : ?>
				<div class="notice notice-info inline"><p><?php esc_html_e( 'This site has more signed-in users than one listing reads. Filter by role or search to see the rest.', 'user-session-manager' ); ?></p></div>
			<?php endif; ?>

			<form method="get" action="<?php echo esc_url( is_multisite() ? network_admin_url( 'users.php' ) : admin_url( 'users.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<?php if ( $filtered_user ) : ?>
					<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $filtered_user->ID ); ?>">
				<?php endif; ?>
				<?php
				$table->search_box( __( 'Search sessions', 'user-session-manager' ), 'usm-search' );
				$table->display();
				?>
			</form>
		</div>
		<?php
	}
}
