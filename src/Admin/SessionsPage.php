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
	 * URL of the question before a user is signed out everywhere. Showing it changes nothing.
	 *
	 * @param int $user_id User.
	 * @return string
	 */
	public static function confirm_url( int $user_id ): string {
		return self::url(
			array(
				'action' => 'sign_out',
				'user'   => $user_id,
			)
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
	 * What the confirmation screen asks about, when a sign out everywhere waits for a yes.
	 *
	 * @var array{users: int[], handles: string[]}|null
	 */
	private ?array $confirm = null;

	/**
	 * Runs row and bulk actions, then redirects back to the list.
	 *
	 * Signing a user out everywhere asks first: the row action and the bulk
	 * action both show a confirmation, and nothing ends until it is sent.
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

		// phpcs:disable WordPress.Security.NonceVerification -- The nonce is checked below, before anything changes.
		$confirmed = ! empty( $_POST['usm_confirmed'] );
		$keep      = ! isset( $_POST['usm_scope'] ) || 'all' !== sanitize_key( wp_unslash( $_POST['usm_scope'] ) );
		// phpcs:enable WordPress.Security.NonceVerification

		$count   = 0;
		$message = 'bulk';
		$signed  = 0;
		if ( isset( $_REQUEST['handles'] ) ) {
			$handles = array_map( 'sanitize_text_field', (array) wp_unslash( $_REQUEST['handles'] ) );
			if ( 'sign_out' === $action && ! $confirmed ) {
				check_admin_referer( 'bulk-sessions' );
				$this->ask( self::users_of( $handles ), $handles );
				return;
			}
			check_admin_referer( 'sign_out' === $action ? 'user_session_manager_confirm' : 'bulk-sessions' );
			$count = $this->bulk( $action, $handles, $keep );
			if ( 'sign_out' === $action && ! $keep && in_array( get_current_user_id(), self::users_of( $handles ), true ) ) {
				$this->signed_out_here();
			}
		} elseif ( 'revoke' === $action && isset( $_REQUEST['handle'] ) ) {
			$handle = sanitize_text_field( wp_unslash( $_REQUEST['handle'] ) );
			check_admin_referer( 'user_session_manager_revoke_' . $handle );
			$this->require_manage( (int) strtok( $handle, '-' ) );
			$count   = $this->sessions->revoke_handle( $handle, 'admin' ) ? 1 : 0;
			$message = $count ? 'revoked' : 'not_found';
		} elseif ( 'sign_out' === $action && isset( $_REQUEST['user'] ) ) {
			$user_id = absint( $_REQUEST['user'] );
			$this->require_manage( $user_id );
			if ( ! $confirmed ) {
				$this->ask( array( $user_id ), array() );
				return;
			}
			check_admin_referer( 'user_session_manager_confirm' );
			if ( get_current_user_id() === $user_id && $keep ) {
				$count   = $this->sessions->revoke_others( $user_id, $this->sessions->current_verifier(), 'admin' );
				$message = 'signed_out_others';
			} else {
				$count   = $this->sessions->sign_out_everywhere( $user_id, 'admin' );
				$message = 'signed_out_user';
				$signed  = $user_id;
				if ( get_current_user_id() === $user_id ) {
					$this->signed_out_here();
				}
			}
		} else {
			return;
		}

		// Keep the search, filter, sorting and page, drop the action.
		$args = array();
		foreach ( array( 's', 'role', 'user_id', 'orderby', 'order', 'paged' ) as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked above.
			if ( isset( $_REQUEST[ $key ] ) && '' !== $_REQUEST[ $key ] ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked above.
				$args[ $key ] = sanitize_text_field( wp_unslash( $_REQUEST[ $key ] ) );
			}
		}
		$args['usm_message'] = $message;
		$args['usm_count']   = $count;
		if ( $signed ) {
			$args['usm_user'] = $signed;
		}

		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	/**
	 * The users that chosen sessions belong to.
	 *
	 * @param string[] $handles Handles.
	 * @return int[]
	 */
	public static function users_of( array $handles ): array {
		$users = array();
		foreach ( $handles as $handle ) {
			$user_id = (int) strtok( (string) $handle, '-' );
			if ( $user_id > 0 ) {
				$users[ $user_id ] = $user_id;
			}
		}

		return array_values( $users );
	}

	/**
	 * Holds a sign out everywhere until it is confirmed.
	 *
	 * @param int[]    $users   Users to sign out.
	 * @param string[] $handles Chosen sessions, for the bulk action.
	 */
	private function ask( array $users, array $handles ): void {
		$this->confirm = array(
			'users'   => array_values( array_filter( $users, array( Access::class, 'can_manage' ) ) ),
			'handles' => $handles,
		);
	}

	/**
	 * The person signed their own browser out: clear the cookie and say so on the login screen.
	 */
	private function signed_out_here(): void {
		wp_clear_auth_cookie();
		wp_safe_redirect( add_query_arg( 'loggedout', 'true', wp_login_url( self::url() ) ) );
		exit;
	}

	/**
	 * The question on the confirmation screen.
	 *
	 * @param int[] $users    Users to sign out.
	 * @param int   $sessions Their active sessions.
	 * @param bool  $you      Whether the current user is one of them.
	 * @return string
	 */
	public static function confirm_message( array $users, int $sessions, bool $you ): string {
		$user = 1 === count( $users ) ? get_userdata( (int) $users[0] ) : false;
		if ( $you && 1 === count( $users ) ) {
			$message = sprintf(
				/* translators: %d: number of sessions. */
				_n( 'You are signed in on %d device, this browser included.', 'You are signed in on %d devices, this browser included.', $sessions, 'user-session-manager' ),
				$sessions
			) . ' ' . __( 'Sign out everywhere else keeps you signed in here. Sign out everywhere also signs out this browser, and you will need to log in again.', 'user-session-manager' );
		} elseif ( $user ) {
			$message = sprintf(
				/* translators: 1: user login, 2: number of sessions. */
				_n( '%1$s is signed in on %2$s device. Each one is signed out the next time it loads a page.', '%1$s is signed in on %2$s devices. Each one is signed out the next time it loads a page.', $sessions, 'user-session-manager' ),
				$user->user_login,
				number_format_i18n( $sessions )
			);
		} else {
			$message = sprintf(
				/* translators: 1: number of users, 2: number of sessions. */
				__( '%1$s users with %2$s active sessions between them. Each session is signed out the next time it loads a page.', 'user-session-manager' ),
				number_format_i18n( count( $users ) ),
				number_format_i18n( $sessions )
			);
			if ( $you ) {
				$message .= ' ' . __( 'You are one of them: choose whether this browser stays signed in.', 'user-session-manager' );
			}
		}

		/**
		 * Filters the question asked before users are signed out everywhere.
		 *
		 * @param string $message  The question.
		 * @param int[]  $users    Users to sign out.
		 * @param int    $sessions Their active sessions.
		 * @param bool   $you      Whether the person asking is one of them.
		 */
		$filtered = (string) apply_filters( 'user_session_manager_confirm_message', $message, $users, $sessions, $you );

		return '' !== trim( $filtered ) ? $filtered : $message;
	}

	/**
	 * Signs out the chosen sessions, or their users everywhere.
	 *
	 * @param string   $action  revoke or sign_out.
	 * @param string[] $handles Handles.
	 * @param bool     $keep    Keep the current session when the person signs themselves out everywhere.
	 * @return int Sessions ended.
	 */
	public function bulk( string $action, array $handles, bool $keep = true ): int {
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
				$count            += $keep && get_current_user_id() === $user_id
					? $this->sessions->revoke_others( $user_id, $this->sessions->current_verifier(), 'bulk' )
					: $this->sessions->sign_out_everywhere( $user_id, 'bulk' );
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
		if ( null !== $this->confirm ) {
			$this->render_confirm( $this->confirm['users'], $this->confirm['handles'] );
			return;
		}

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
						/* translators: %s: number of sessions. */
						sprintf( _n( '%s active session', '%s active sessions', $total, 'user-session-manager' ), number_format_i18n( $total ) ),
						/* translators: %s: number of users. */
						sprintf( _n( '%s user', '%s users', $table->users, 'user-session-manager' ), number_format_i18n( $table->users ) )
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

	/**
	 * Asks before users are signed out everywhere, and says whether this browser goes too.
	 *
	 * @param int[]    $users   Users to sign out.
	 * @param string[] $handles Chosen sessions, for the bulk action.
	 */
	private function render_confirm( array $users, array $handles ): void {
		$self     = in_array( get_current_user_id(), $users, true );
		$sessions = 0;
		foreach ( $users as $user_id ) {
			$sessions += $this->sessions->count( $user_id );
		}
		$single = 1 === count( $users ) ? get_userdata( $users[0] ) : false;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list arguments carried through the form.
		$keep = array();
		foreach ( array( 's', 'role', 'user_id', 'orderby', 'order', 'paged' ) as $key ) {
			if ( isset( $_REQUEST[ $key ] ) && '' !== $_REQUEST[ $key ] && ! is_array( $_REQUEST[ $key ] ) ) {
				$keep[ $key ] = sanitize_text_field( wp_unslash( $_REQUEST[ $key ] ) );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$cancel = self::url( $keep );

		if ( $self && 1 === count( $users ) ) {
			$title = __( 'Sign out your other devices?', 'user-session-manager' );
		} elseif ( $single ) {
			/* translators: %s: user login. */
			$title = sprintf( __( 'Sign out %s everywhere?', 'user-session-manager' ), $single->user_login );
		} else {
			/* translators: %s: number of users. */
			$title = sprintf( _n( 'Sign out %s user everywhere?', 'Sign out %s users everywhere?', count( $users ), 'user-session-manager' ), number_format_i18n( count( $users ) ) );
		}
		?>
		<div class="wrap usm-page">
			<h1><?php esc_html_e( 'Sessions', 'user-session-manager' ); ?></h1>
			<?php if ( ! $users ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'None of the chosen users can be signed out from here.', 'user-session-manager' ); ?></p></div>
				<p><a class="button" href="<?php echo esc_url( $cancel ); ?>"><?php esc_html_e( 'Back to sessions', 'user-session-manager' ); ?></a></p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( self::url() ); ?>" class="usm-confirm">
					<h2 id="usm-confirm-title"><?php echo esc_html( $title ); ?></h2>
					<p><?php echo wp_kses_post( self::confirm_message( $users, $sessions, $self ) ); ?></p>
					<?php if ( ! $single ) : ?>
						<ul class="usm-confirm-users">
							<?php foreach ( array_slice( $users, 0, 20 ) as $user_id ) : ?>
								<?php $user = get_userdata( $user_id ); ?>
								<li><?php echo esc_html( $user ? $user->user_login : (string) $user_id ); ?><?php echo get_current_user_id() === $user_id ? ' ' . esc_html__( '(you)', 'user-session-manager' ) : ''; ?></li>
							<?php endforeach; ?>
							<?php if ( count( $users ) > 20 ) : ?>
								<li>
									<?php
									/* translators: %s: number of users. */
									echo esc_html( sprintf( __( 'and %s more', 'user-session-manager' ), number_format_i18n( count( $users ) - 20 ) ) );
									?>
								</li>
							<?php endif; ?>
						</ul>
					<?php endif; ?>
					<?php wp_nonce_field( 'user_session_manager_confirm' ); ?>
					<input type="hidden" name="action" value="sign_out">
					<input type="hidden" name="usm_confirmed" value="1">
					<?php if ( $handles ) : ?>
						<?php foreach ( $handles as $handle ) : ?>
							<input type="hidden" name="handles[]" value="<?php echo esc_attr( $handle ); ?>">
						<?php endforeach; ?>
					<?php else : ?>
						<input type="hidden" name="user" value="<?php echo esc_attr( (string) $users[0] ); ?>">
					<?php endif; ?>
					<?php foreach ( $keep as $key => $value ) : ?>
						<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>">
					<?php endforeach; ?>
					<p class="usm-confirm-actions">
						<?php if ( $self ) : ?>
							<button type="submit" name="usm_scope" value="others" class="button button-primary"><?php echo $single ? esc_html__( 'Sign out everywhere else', 'user-session-manager' ) : esc_html__( 'Sign out, but keep this browser', 'user-session-manager' ); ?></button>
							<button type="submit" name="usm_scope" value="all" class="button usm-danger-button"><?php esc_html_e( 'Sign out everywhere, this browser too', 'user-session-manager' ); ?></button>
						<?php else : ?>
							<button type="submit" name="usm_scope" value="all" class="button button-primary"><?php esc_html_e( 'Sign out everywhere', 'user-session-manager' ); ?></button>
						<?php endif; ?>
						<a class="button-link" href="<?php echo esc_url( $cancel ); ?>"><?php esc_html_e( 'Cancel', 'user-session-manager' ); ?></a>
					</p>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}
