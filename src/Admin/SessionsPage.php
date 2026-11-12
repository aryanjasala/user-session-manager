<?php
/**
 * Users > Sessions (Network Admin > Users > Sessions on multisite).
 *
 * @package AryanX\Axle\UserSessionManager
 */

namespace AryanX\Axle\UserSessionManager\Admin;

use AryanX\Axle\UserSessionManager\Access;
use AryanX\Axle\UserSessionManager\Incident;
use AryanX\Axle\UserSessionManager\NotMe;
use AryanX\Axle\UserSessionManager\Service;
use AryanX\Axle\UserSessionManager\Sessions;
use AryanX\Axle\UserSessionManager\Settings;
use AryanX\Axle\UserSessionManager\SharedLogins;
use const AryanX\Axle\UserSessionManager\PLUGIN_FILE;
use const AryanX\Axle\UserSessionManager\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Every active session across users. Sessions belong to users, who are shared
 * by the whole network, so on multisite the screen lives in Network Admin.
 */
final class SessionsPage implements Service {

	public const SLUG       = 'user-sessions';
	public const HIDE_STEPS = 'user_session_manager_hide_steps';

	/**
	 * Session store.
	 *
	 * @var Sessions
	 */
	private Sessions $sessions;

	/**
	 * Shared login detection.
	 *
	 * @var SharedLogins
	 */
	private SharedLogins $shared;

	/**
	 * Saved settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * "This wasn't me" reports.
	 *
	 * @var NotMe
	 */
	private NotMe $not_me;

	/**
	 * "Sign everyone out".
	 *
	 * @var Incident
	 */
	private Incident $incident;

	/**
	 * A "Sign everyone out" job that is part way through, for the progress page.
	 *
	 * @var array|null
	 */
	private ?array $progress = null;

	/**
	 * Sets up the screen.
	 *
	 * @param Settings     $settings Saved settings.
	 * @param Sessions     $sessions Session store.
	 * @param SharedLogins $shared   Shared login detection.
	 * @param NotMe        $not_me   "This wasn't me" reports.
	 * @param Incident     $incident "Sign everyone out".
	 */
	public function __construct( Settings $settings, Sessions $sessions, SharedLogins $shared, NotMe $not_me, Incident $incident ) {
		$this->settings = $settings;
		$this->sessions = $sessions;
		$this->shared   = $shared;
		$this->not_me   = $not_me;
		$this->incident = $incident;
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
			add_action( "load-{$hook}", array( $this, 'help' ) );
			add_action( "admin_print_styles-{$hook}", array( $this, 'enqueue_styles' ) );
		}
	}

	/**
	 * Screen styles.
	 */
	public function enqueue_styles(): void {
		AxleUi::enqueue();
		wp_enqueue_style( 'user-session-manager', plugins_url( 'assets/admin.css', PLUGIN_FILE ), array( AxleUi::HANDLE ), VERSION );
		Display::enqueue_script();
	}

	/**
	 * The Help tab.
	 */
	public function help(): void {
		AxleUi::help_tabs(
			array(
				array(
					'id'      => 'overview',
					'title'   => __( 'Overview', 'user-session-manager' ),
					'content' => '<p>' . esc_html__( 'This screen lists every browser signed in to the site, with the person, the device, the network and when it was last used. Search by name, email or the start of an IP address, filter by role, and sort any column.', 'user-session-manager' ) . '</p><p>' . esc_html__( 'Times show as "5 minutes ago". Hover one to see the exact time.', 'user-session-manager' ) . '</p>',
				),
				array(
					'id'      => 'sign-out',
					'title'   => __( 'Signing people out', 'user-session-manager' ),
					'content' => '<p>' . esc_html__( 'Use "Sign out" on a row to end one session. Use "Sign out everywhere" to end all of a person\'s sessions. The browser is signed out the next time it loads a page. This cannot be undone, but the person can sign in again with their password.', 'user-session-manager' ) . '</p><p>' . esc_html__( 'You can only sign out people you are allowed to edit.', 'user-session-manager' ) . '</p>',
				),
				array(
					'id'      => 'reports',
					'title'   => __( 'This wasn\'t me', 'user-session-manager' ),
					'content' => '<p>' . esc_html__( 'The new sign-in email has a link for the owner of the account. When they use it, that device is signed out, the password stops working and a reset link is emailed to them. The report shows here for 30 days.', 'user-session-manager' ) . '</p>',
				),
				array(
					'id'      => 'incident',
					'title'   => __( 'Sign everyone out', 'user-session-manager' ),
					'content' => '<p>' . esc_html__( 'For an incident. It signs every person out, except you when you choose that and any roles you leave out. You can also make every password stop working, so each person has to choose a new one with "Lost your password?". Type the confirmation words to go ahead. This cannot be undone.', 'user-session-manager' ) . '</p>',
				),
			)
		);
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

		// phpcs:disable WordPress.Security.NonceVerification -- Each branch below checks its own nonce before it changes anything.
		$special = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		$post    = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'];
		// phpcs:enable WordPress.Security.NonceVerification
		if ( 'incident' === $special && $post ) {
			$this->start_incident();
		} elseif ( 'incident_continue' === $special ) {
			$this->continue_incident();
			return;
		} elseif ( 'dismiss_steps' === $special ) {
			$this->dismiss_steps();
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
		foreach ( array( 's', 'role', 'user_id', 'shared', 'orderby', 'order', 'paged' ) as $key ) {
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
	 * Starts "Sign everyone out" from the form, after the typed confirmation.
	 */
	private function start_incident(): void {
		if ( ! Access::can_sign_out_everyone() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to sign everyone out.', 'user-session-manager' ), 403 );
		}

		check_admin_referer( 'user_session_manager_incident' );

		$typed = isset( $_POST['usm_typed'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['usm_typed'] ) ) ) : '';
		if ( ! hash_equals( Incident::confirm_word(), $typed ) ) {
			wp_safe_redirect(
				self::url(
					array(
						'usm_incident' => 1,
						'usm_error'    => 'typed',
					)
				)
			);
			exit;
		}

		$roles = isset( $_POST['usm_roles'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['usm_roles'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each role is run through sanitize_key().
		$job   = $this->incident->start(
			array(
				'keep_current'  => ! empty( $_POST['usm_keep'] ),
				'exclude_roles' => $roles,
				'passwords'     => ! empty( $_POST['usm_passwords'] ),
				'via'           => 'screen',
			)
		);

		$this->hand_on( $this->incident->run( $job, 8.0 ), '', true );
	}

	/**
	 * Carries on a job that did not finish in one slice.
	 */
	private function continue_incident(): void {
		if ( ! Access::can_sign_out_everyone() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to sign everyone out.', 'user-session-manager' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked just below, against this job.
		$id = isset( $_GET['job'] ) ? preg_replace( '/[^0-9a-f]/', '', sanitize_text_field( wp_unslash( $_GET['job'] ) ) ) : '';
		check_admin_referer( 'user_session_manager_incident_' . $id );

		$job = '' === $id ? false : get_site_transient( 'usm_incident_' . $id );
		if ( ! is_array( $job ) || get_current_user_id() !== (int) $job['by'] ) {
			$this->progress = array(
				'id'  => '',
				'job' => null,
			);

			return;
		}

		$this->hand_on( $this->incident->run( $job, 8.0 ), $id, false );
	}

	/**
	 * After a slice: redirects to the result when the job is done, otherwise saves it.
	 *
	 * @param array  $job      The job.
	 * @param string $id       Job ID, or empty for a new one.
	 * @param bool   $redirect Whether to redirect to the progress page instead of showing it.
	 */
	private function hand_on( array $job, string $id, bool $redirect ): void {
		if ( ! empty( $job['done'] ) ) {
			if ( '' !== $id ) {
				delete_site_transient( 'usm_incident_' . $id );
			}
			wp_safe_redirect(
				self::url(
					array(
						'usm_message' => 'incident',
						'usm_count'   => (int) $job['sessions'],
						'usm_extra'   => (int) $job['passwords_set'],
					)
				)
			);
			exit;
		}

		$id = '' !== $id ? $id : bin2hex( random_bytes( 8 ) );
		set_site_transient( 'usm_incident_' . $id, $job, HOUR_IN_SECONDS );

		if ( $redirect ) {
			wp_safe_redirect(
				wp_nonce_url(
					self::url(
						array(
							'action' => 'incident_continue',
							'job'    => $id,
						) 
					),
					'user_session_manager_incident_' . $id 
				) 
			); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
			exit;
		}

		$this->progress = array(
			'id'  => $id,
			'job' => $job,
		);
	}

	/**
	 * Hides the "Get started" card for the current user.
	 */
	private function dismiss_steps(): void {
		check_admin_referer( 'user_session_manager_dismiss_steps' );
		update_user_meta( get_current_user_id(), self::HIDE_STEPS, 1 );

		wp_safe_redirect( self::url() );
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
		if ( null !== $this->progress ) {
			$this->render_progress( $this->progress );
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only shows the form; the action checks a nonce and the typed words.
		if ( isset( $_GET['usm_incident'] ) && Access::can_sign_out_everyone() ) {
			$this->render_incident_form();
			return;
		}
		if ( null !== $this->confirm ) {
			$this->render_confirm( $this->confirm['users'], $this->confirm['handles'] );
			return;
		}

		$table = new SessionsTable( $this->sessions, $this->shared );
		$table->prepare_items();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter value.
		$filtered_user = isset( $_REQUEST['user_id'] ) ? get_userdata( absint( $_REQUEST['user_id'] ) ) : false;
		$flag          = $filtered_user && Access::can_manage( $filtered_user->ID ) ? $this->shared->get( $filtered_user->ID ) : null;
		$total         = (int) $table->get_pagination_arg( 'total_items' );
		?>
		<div class="wrap axle-ui usm-page">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Sessions', 'user-session-manager' ); ?></h1>
			<?php if ( current_user_can( Access::settings_capability() ) ) : ?>
				<a href="<?php echo esc_url( SettingsPage::url() ); ?>" class="page-title-action"><?php esc_html_e( 'Session policies', 'user-session-manager' ); ?></a>
			<?php endif; ?>
			<hr class="wp-header-end">

			<p class="axle-intro"><?php esc_html_e( 'Every browser signed in to this site, with the person, the device and the network. Sign sessions out one at a time, or sign a person out everywhere.', 'user-session-manager' ); ?></p>

			<?php if ( ! $this->sessions->is_supported() ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'Another plugin stores sessions outside WordPress user data, so User Session Manager cannot list or end them.', 'user-session-manager' ); ?></p></div>
			<?php endif; ?>
			<?php $this->render_steps(); ?>
			<?php $this->render_reports(); ?>

			<p class="usm-summary axle-muted">
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
			<?php if ( $flag ) : ?>
				<?php echo wp_kses( Display::shared_evidence( $flag ), Display::ALLOWED ); ?>
			<?php elseif ( $table->shared_view ) : ?>
				<p class="usm-shared-intro"><?php esc_html_e( 'Accounts whose sessions look like more than one person. Choose "Only this user" on a row to see why.', 'user-session-manager' ); ?></p>
			<?php endif; ?>
			<?php if ( $table->truncated ) : ?>
				<div class="notice notice-info inline"><p><?php esc_html_e( 'This site has more signed-in users than one listing reads. Filter by role or search to see the rest.', 'user-session-manager' ); ?></p></div>
			<?php endif; ?>

			<form method="get" action="<?php echo esc_url( is_multisite() ? network_admin_url( 'users.php' ) : admin_url( 'users.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<?php if ( $filtered_user ) : ?>
					<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $filtered_user->ID ); ?>">
				<?php endif; ?>
				<?php if ( $table->shared_view ) : ?>
					<input type="hidden" name="shared" value="1">
				<?php endif; ?>
				<?php
				$table->views();
				?>
				<div class="axle-toolbar">
					<?php $table->search_box( __( 'Search sessions', 'user-session-manager' ), 'usm-search' ); ?>
				</div>
				<div class="axle-scroll" tabindex="0" role="region" aria-label="<?php esc_attr_e( 'Sessions', 'user-session-manager' ); ?>">
					<?php $table->display(); ?>
				</div>
			</form>
			<?php $this->render_danger_zone(); ?>
			<?php AxleUi::footer(); ?>
		</div>
		<?php
	}

	/**
	 * The "Get started" card, until both steps are done or it is dismissed.
	 */
	private function render_steps(): void {
		if ( get_user_meta( get_current_user_id(), self::HIDE_STEPS, true ) ) {
			return;
		}

		$admin = $this->settings->policy( 'administrator' );
		$steps = array(
			array(
				'title' => __( 'Limit administrator sessions', 'user-session-manager' ),
				'text'  => __( 'Set an idle timeout or a limit on sessions at once for administrators, so a forgotten login does not stay open.', 'user-session-manager' ),
				'done'  => $admin['max_sessions'] > 0 || $admin['idle_minutes'] > 0,
				'link'  => __( 'Open session policies', 'user-session-manager' ),
			),
			array(
				'title' => __( 'Turn on the new sign-in email', 'user-session-manager' ),
				'text'  => __( 'People get an email when they sign in from a new device, with a "This wasn\'t me" link.', 'user-session-manager' ),
				'done'  => (bool) $this->settings->get( 'new_device_email' ),
				'link'  => __( 'Open settings', 'user-session-manager' ),
			),
		);
		$open  = array_filter(
			$steps,
			static function ( array $step ): bool {
				return ! $step['done'];
			}
		);
		if ( ! $open ) {
			return;
		}

		$can_settings = current_user_can( Access::settings_capability() );
		?>
		<section class="axle-card usm-steps" aria-labelledby="usm-steps-title">
			<div class="axle-card__head">
				<h2 class="axle-card__title" id="usm-steps-title"><?php esc_html_e( 'Get started', 'user-session-manager' ); ?></h2>
				<a class="button-link" href="<?php echo esc_url( wp_nonce_url( self::url( array( 'action' => 'dismiss_steps' ) ), 'user_session_manager_dismiss_steps' ) ); ?>"><?php esc_html_e( 'Dismiss', 'user-session-manager' ); ?></a>
			</div>
			<ol class="axle-steps">
				<?php foreach ( $steps as $step ) : ?>
					<li class="axle-step">
						<div class="axle-step__body">
							<strong class="axle-step__title"><?php echo esc_html( $step['title'] ); ?></strong>
							<p><?php echo esc_html( $step['text'] ); ?></p>
						</div>
						<?php if ( $step['done'] ) : ?>
							<span class="axle-badge axle-badge--good"><?php esc_html_e( 'Done', 'user-session-manager' ); ?></span>
						<?php elseif ( $can_settings ) : ?>
							<a class="button" href="<?php echo esc_url( SettingsPage::url() ); ?>"><?php echo esc_html( $step['link'] ); ?></a>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ol>
		</section>
		<?php
	}

	/**
	 * Recent "This wasn't me" reports for people the current user may manage.
	 */
	private function render_reports(): void {
		$events = array_values(
			array_filter(
				$this->not_me->events( 0, 30 ),
				static function ( array $event ): bool {
					return Access::can_manage( (int) $event['user'] );
				}
			)
		);
		if ( ! $events ) {
			return;
		}
		?>
		<section class="axle-card usm-reports" aria-labelledby="usm-reports-title">
			<h2 class="axle-card__title" id="usm-reports-title"><?php esc_html_e( 'Reported as "This wasn\'t me"', 'user-session-manager' ); ?></h2>
			<p class="description"><?php esc_html_e( 'People who answered a new sign-in email by saying it was not them, in the last 30 days. Their sessions were signed out and their password was reset.', 'user-session-manager' ); ?></p>
			<div class="axle-scroll" tabindex="0" role="region" aria-label="<?php esc_attr_e( 'Reports', 'user-session-manager' ); ?>">
				<table class="widefat striped usm-report-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'User', 'user-session-manager' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Device', 'user-session-manager' ); ?></th>
							<th scope="col"><?php esc_html_e( 'IP address', 'user-session-manager' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Reported', 'user-session-manager' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Sessions signed out', 'user-session-manager' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $events as $event ) : ?>
							<?php $user = get_userdata( (int) $event['user'] ); ?>
							<tr>
								<td>
									<span class="axle-badge axle-badge--error"><?php esc_html_e( 'Reported', 'user-session-manager' ); ?></span>
									<?php if ( $user ) : ?>
										<a href="<?php echo esc_url( add_query_arg( 'user_id', $user->ID, self_admin_url( 'user-edit.php' ) ) . '#' . ProfileSessions::ANCHOR ); ?>"><?php echo esc_html( $user->user_login ); ?></a>
									<?php else : ?>
										<?php esc_html_e( 'Deleted user', 'user-session-manager' ); ?>
									<?php endif; ?>
								</td>
								<td data-colname="<?php esc_attr_e( 'Device', 'user-session-manager' ); ?>"><?php echo esc_html( (string) $event['device'] ); ?></td>
								<td class="usm-ip" data-colname="<?php esc_attr_e( 'IP address', 'user-session-manager' ); ?>"><?php echo esc_html( '' !== $event['ip'] ? (string) $event['ip'] : __( 'Unknown', 'user-session-manager' ) ); ?></td>
								<td data-colname="<?php esc_attr_e( 'Reported', 'user-session-manager' ); ?>"><?php echo wp_kses( AxleUi::relative_time( (int) $event['time'] ), Display::ALLOWED ); ?></td>
								<td data-colname="<?php esc_attr_e( 'Sessions signed out', 'user-session-manager' ); ?>"><?php echo esc_html( number_format_i18n( (int) $event['ended'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</section>
		<?php
	}

	/**
	 * The card that leads to "Sign everyone out", with the last few times it was used.
	 */
	private function render_danger_zone(): void {
		if ( ! Access::can_sign_out_everyone() || ! $this->sessions->is_supported() ) {
			return;
		}
		?>
		<section class="axle-card axle-card--danger usm-danger" aria-labelledby="usm-danger-title">
			<h2 class="axle-card__title" id="usm-danger-title"><?php esc_html_e( 'Sign everyone out', 'user-session-manager' ); ?></h2>
			<p><?php esc_html_e( 'For an incident, such as a leaked password list or a stolen laptop. Every person is signed out. You can keep yourself signed in, leave out some roles, and make every password stop working.', 'user-session-manager' ); ?></p>
			<p class="axle-actions"><a class="button axle-button--danger" href="<?php echo esc_url( self::url( array( 'usm_incident' => 1 ) ) ); ?>"><?php esc_html_e( 'Sign everyone out', 'user-session-manager' ); ?></a></p>
			<?php $log = array_slice( $this->incident->log(), 0, 3 ); ?>
			<?php if ( $log ) : ?>
				<h3><?php esc_html_e( 'Recent use', 'user-session-manager' ); ?></h3>
				<ul class="usm-incident-log">
					<?php foreach ( $log as $entry ) : ?>
						<?php $by = get_userdata( (int) $entry['by'] ); ?>
						<li>
							<?php
							echo wp_kses(
								sprintf(
									/* translators: 1: relative time, 2: who did it, 3: number of sessions, 4: number of accounts with a new password. */
									esc_html__( '%1$s by %2$s: %3$s sessions signed out, %4$s passwords reset.', 'user-session-manager' ),
									AxleUi::relative_time( (int) $entry['time'] ),
									esc_html( $by ? $by->user_login : __( 'WP-CLI or a script', 'user-session-manager' ) ),
									esc_html( number_format_i18n( (int) $entry['sessions'] ) ),
									esc_html( number_format_i18n( (int) $entry['passwords'] ) )
								),
								Display::ALLOWED
							);
							?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * The step before "Sign everyone out": what will change, the choices, and the typed words.
	 */
	private function render_incident_form(): void {
		$totals = $this->sessions->totals();
		$word   = Incident::confirm_word();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only error code from our own redirect.
		$error = isset( $_GET['usm_error'] ) && 'typed' === sanitize_key( wp_unslash( $_GET['usm_error'] ) );
		?>
		<div class="wrap axle-ui usm-page">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Sessions', 'user-session-manager' ); ?></h1>
			<hr class="wp-header-end">
			<p class="axle-intro"><?php esc_html_e( 'Check what will happen before you confirm. Nothing ends until you do.', 'user-session-manager' ); ?></p>
			<?php if ( $error ) : ?>
				<div class="notice notice-error inline"><p><?php esc_html_e( 'The confirmation words did not match, so nobody was signed out. Type them exactly as shown and try again.', 'user-session-manager' ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( self::url() ); ?>" class="axle-confirm axle-confirm--danger usm-incident-form" role="group" aria-labelledby="usm-incident-title">
				<h2 class="axle-card__title" id="usm-incident-title"><?php esc_html_e( 'Sign everyone out?', 'user-session-manager' ); ?></h2>
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: number of sessions, 2: number of users. */
							__( '%1$s active sessions of %2$s users will end. Each one is signed out the next time it loads a page. This cannot be undone. People can sign in again with their password, unless you also make passwords stop working.', 'user-session-manager' ),
							number_format_i18n( $totals['sessions'] ),
							number_format_i18n( $totals['users'] )
						)
					);
					?>
				</p>
				<?php wp_nonce_field( 'user_session_manager_incident' ); ?>
				<input type="hidden" name="action" value="incident">
				<fieldset>
					<legend class="screen-reader-text"><?php esc_html_e( 'Choices', 'user-session-manager' ); ?></legend>
					<p><label><input type="checkbox" name="usm_keep" value="1" checked> <?php esc_html_e( 'Keep me signed in, on every device', 'user-session-manager' ); ?></label></p>
					<?php if ( ! is_multisite() ) : ?>
						<p><strong><?php esc_html_e( 'Leave out these roles', 'user-session-manager' ); ?></strong></p>
						<ul class="usm-incident-roles">
							<?php foreach ( wp_roles()->get_names() as $role => $name ) : ?>
								<li><label><input type="checkbox" name="usm_roles[]" value="<?php echo esc_attr( (string) $role ); ?>"> <?php echo esc_html( translate_user_role( $name ) ); ?></label></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
					<p>
						<label><input type="checkbox" name="usm_passwords" value="1"> <?php esc_html_e( 'Also require new passwords', 'user-session-manager' ); ?></label><br>
						<span class="description"><?php esc_html_e( 'Every current password stops working, including for people who are not signed in now. Each person has to use "Lost your password?" to choose a new one. No emails are sent. Your own password is left alone so you can still sign in.', 'user-session-manager' ); ?></span>
					</p>
				</fieldset>
				<p>
					<label for="usm-typed">
						<?php
						printf(
							/* translators: %s: the words to type, such as SIGN OUT. */
							esc_html__( 'Type %s to confirm', 'user-session-manager' ),
							'<strong>' . esc_html( $word ) . '</strong>'
						);
						?>
					</label><br>
					<input type="text" id="usm-typed" name="usm_typed" class="regular-text" autocomplete="off" spellcheck="false" data-usm-typed="<?php echo esc_attr( $word ); ?>">
				</p>
				<p class="axle-actions">
					<button type="submit" class="button axle-button--danger" data-usm-typed-button><?php esc_html_e( 'Sign everyone out', 'user-session-manager' ); ?></button>
					<a class="button-link" href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Cancel', 'user-session-manager' ); ?></a>
				</p>
			</form>
			<?php AxleUi::footer(); ?>
		</div>
		<?php
	}

	/**
	 * The page shown while a big "Sign everyone out" job carries on in slices.
	 *
	 * @param array $progress id and job, or a null job when it expired.
	 */
	private function render_progress( array $progress ): void {
		$job = $progress['job'];
		?>
		<div class="wrap axle-ui usm-page">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Sessions', 'user-session-manager' ); ?></h1>
			<hr class="wp-header-end">
			<?php if ( ! is_array( $job ) ) : ?>
				<div class="notice notice-error inline"><p><?php esc_html_e( 'That job has expired or belongs to someone else. Anyone already signed out stays signed out. Check the list below, and start again if people are still signed in.', 'user-session-manager' ); ?></p></div>
				<p><a class="button" href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Back to sessions', 'user-session-manager' ); ?></a></p>
			<?php else : ?>
				<?php
				$next = wp_nonce_url(
					self::url(
						array(
							'action' => 'incident_continue',
							'job'    => $progress['id'],
						) 
					),
					'user_session_manager_incident_' . $progress['id'] 
				); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
				$text = sprintf(
					/* translators: 1: number of users checked, 2: number of sessions signed out so far. */
					__( 'Signing everyone out. %1$s users checked, %2$s sessions ended so far.', 'user-session-manager' ),
					number_format_i18n( (int) $job['visited'] ),
					number_format_i18n( (int) $job['sessions'] )
				);
				?>
				<section class="axle-card" aria-busy="true" data-usm-progress="<?php echo esc_attr( $text ); ?>">
					<h2 class="axle-card__title"><?php esc_html_e( 'Signing everyone out', 'user-session-manager' ); ?></h2>
					<p><span class="spinner is-active usm-spinner"></span> <?php echo esc_html( $text ); ?></p>
					<p class="description"><?php esc_html_e( 'This page carries on by itself. Keep it open until it says it is done. If it stops, press Continue.', 'user-session-manager' ); ?></p>
					<p class="axle-actions"><a class="button" href="<?php echo esc_url( $next ); ?>" data-usm-continue><?php esc_html_e( 'Continue', 'user-session-manager' ); ?></a></p>
				</section>
			<?php endif; ?>
			<?php AxleUi::footer(); ?>
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
		foreach ( array( 's', 'role', 'user_id', 'shared', 'orderby', 'order', 'paged' ) as $key ) {
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
		<div class="wrap axle-ui usm-page">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Sessions', 'user-session-manager' ); ?></h1>
			<hr class="wp-header-end">
			<p class="axle-intro"><?php esc_html_e( 'Check who will be signed out before you confirm. Nothing ends until you do.', 'user-session-manager' ); ?></p>
			<?php if ( ! $users ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'None of the chosen users can be signed out from here.', 'user-session-manager' ); ?></p></div>
				<p><a class="button" href="<?php echo esc_url( $cancel ); ?>"><?php esc_html_e( 'Back to sessions', 'user-session-manager' ); ?></a></p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( self::url() ); ?>" class="axle-confirm axle-confirm--danger usm-confirm">
					<h2 class="axle-card__title" id="usm-confirm-title"><?php echo esc_html( $title ); ?></h2>
					<p><?php echo wp_kses_post( self::confirm_message( $users, $sessions, $self ) ); ?></p>
					<p><?php esc_html_e( 'Signing out cannot be undone. Each person can sign in again with their password.', 'user-session-manager' ); ?></p>
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
							<button type="submit" name="usm_scope" value="all" class="button axle-button--danger"><?php esc_html_e( 'Sign out everywhere, this browser too', 'user-session-manager' ); ?></button>
						<?php else : ?>
							<button type="submit" name="usm_scope" value="all" class="button axle-button--danger"><?php esc_html_e( 'Sign out everywhere', 'user-session-manager' ); ?></button>
						<?php endif; ?>
						<a class="button-link" href="<?php echo esc_url( $cancel ); ?>"><?php esc_html_e( 'Cancel', 'user-session-manager' ); ?></a>
					</p>
				</form>
			<?php endif; ?>
			<?php AxleUi::footer(); ?>
		</div>
		<?php
	}
}
