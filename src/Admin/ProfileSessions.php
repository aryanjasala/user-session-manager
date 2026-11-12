<?php
/**
 * Profile > Your sessions.
 *
 * @package AryanX\Axle\UserSessionManager
 */

namespace AryanX\Axle\UserSessionManager\Admin;

use AryanX\Axle\UserSessionManager\Access;
use AryanX\Axle\UserSessionManager\History;
use AryanX\Axle\UserSessionManager\NotMe;
use AryanX\Axle\UserSessionManager\Service;
use AryanX\Axle\UserSessionManager\Sessions;
use AryanX\Axle\UserSessionManager\SharedLogins;
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
	 * Shared login detection.
	 *
	 * @var SharedLogins
	 */
	private SharedLogins $shared;

	/**
	 * Sign-in history.
	 *
	 * @var History
	 */
	private History $history;

	/**
	 * "This wasn't me" reports.
	 *
	 * @var NotMe
	 */
	private NotMe $not_me;

	/**
	 * Sets up the section.
	 *
	 * @param Sessions     $sessions Session store.
	 * @param SharedLogins $shared   Shared login detection.
	 * @param History      $history  Sign-in history.
	 * @param NotMe        $not_me   "This wasn't me" reports.
	 */
	public function __construct( Sessions $sessions, SharedLogins $shared, History $history, NotMe $not_me ) {
		$this->sessions = $sessions;
		$this->shared   = $shared;
		$this->history  = $history;
		$this->not_me   = $not_me;
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

		AxleUi::enqueue();
		wp_enqueue_style( 'user-session-manager', plugins_url( 'assets/admin.css', PLUGIN_FILE ), array( AxleUi::HANDLE ), VERSION );
		Display::enqueue_script();

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

		$own    = get_current_user_id() === $user->ID;
		$rows   = $this->sessions->list_for_user( $user->ID );
		$flag   = $this->shared->get( $user->ID );
		$shared = $this->shared->handles( $user->ID );
		$back   = $own ? self_admin_url( 'profile.php' ) : add_query_arg( 'user_id', $user->ID, self_admin_url( 'user-edit.php' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only shows the question; the sign out itself has a nonce.
		$confirm = isset( $_GET['usm_confirm'] ) && 'sign_out' === sanitize_key( wp_unslash( $_GET['usm_confirm'] ) );
		?>
		<div class="usm-profile axle-ui">
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
		<?php $this->render_reports( $user ); ?>
		<?php if ( $flag ) : ?>
			<?php echo wp_kses( Display::shared_evidence( $flag ), Display::ALLOWED ); ?>
		<?php endif; ?>
		<?php if ( ! $rows ) : ?>
			<div class="axle-empty usm-empty"><p><?php esc_html_e( 'No active sessions. Sessions show up here when this account signs in.', 'user-session-manager' ); ?></p></div>
		<?php else : ?>
			<div class="axle-scroll" tabindex="0" role="region" aria-label="<?php esc_attr_e( 'Active sessions', 'user-session-manager' ); ?>">
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
							<td class="usm-device-cell"><?php echo wp_kses( Display::device( $row, in_array( $row['handle'], $shared, true ) ), Display::ALLOWED ); ?></td>
							<td class="usm-ip" data-colname="<?php esc_attr_e( 'IP address', 'user-session-manager' ); ?>"><?php echo esc_html( '' !== $row['ip'] ? $row['ip'] : __( 'Unknown', 'user-session-manager' ) ); ?></td>
							<td data-colname="<?php esc_attr_e( 'Signed in', 'user-session-manager' ); ?>"><?php echo wp_kses( Display::time( $row['login'] ), Display::ALLOWED ); ?></td>
							<td data-colname="<?php esc_attr_e( 'Last active', 'user-session-manager' ); ?>"><?php echo wp_kses( Display::time( $row['last_seen'] ), Display::ALLOWED ); ?></td>
							<td data-colname="<?php esc_attr_e( 'Expires', 'user-session-manager' ); ?>"><?php echo wp_kses( Display::time( $row['expiration'] ), Display::ALLOWED ); ?></td>
							<td class="usm-actions">
								<?php if ( ! $row['current'] ) : ?>
									<a class="button button-small" href="<?php echo esc_url( self::url( 'revoke', $row['handle'], $back ) ); ?>" data-usm-confirm="<?php echo esc_attr( __( 'That browser is signed out the next time it loads a page. This cannot be undone, but the person can sign in again.', 'user-session-manager' ) ); ?>" data-usm-confirm-title="<?php echo esc_attr( $row['device'] ); ?>" data-usm-confirm-button="<?php esc_attr_e( 'Sign out this session', 'user-session-manager' ); ?>"><?php esc_html_e( 'Sign out', 'user-session-manager' ); ?></a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			</div>
			<p class="usm-bulk">
				<?php if ( $own && count( $rows ) > 1 ) : ?>
					<a class="button" href="<?php echo esc_url( self::url( 'sign_out_others', (string) $user->ID, $back ) ); ?>" data-usm-confirm="<?php echo esc_attr( __( 'Every other browser is signed out the next time it loads a page. This browser stays signed in. This cannot be undone.', 'user-session-manager' ) ); ?>" data-usm-confirm-title="<?php esc_attr_e( 'Sign out all other sessions?', 'user-session-manager' ); ?>" data-usm-confirm-button="<?php esc_attr_e( 'Sign out all other sessions', 'user-session-manager' ); ?>"><?php esc_html_e( 'Sign out all other sessions', 'user-session-manager' ); ?></a>
				<?php elseif ( ! $own && ! $confirm ) : ?>
					<a class="button" href="<?php echo esc_url( add_query_arg( 'usm_confirm', 'sign_out', $back ) . '#' . self::ANCHOR ); ?>"><?php esc_html_e( 'Sign out everywhere', 'user-session-manager' ); ?></a>
				<?php endif; ?>
				<?php if ( ! $own && Access::can_view_all() ) : ?>
					<a href="<?php echo esc_url( SessionsPage::url( array( 'user_id' => $user->ID ) ) ); ?>"><?php esc_html_e( 'All sessions', 'user-session-manager' ); ?></a>
				<?php endif; ?>
			</p>
			<?php if ( ! $own && $confirm ) : ?>
				<div class="axle-confirm axle-confirm--danger usm-confirm usm-confirm-inline" role="group" aria-labelledby="usm-confirm-title">
					<p class="axle-card__title" id="usm-confirm-title">
						<?php
						/* translators: %s: user login. */
						echo esc_html( sprintf( __( 'Sign out %s everywhere?', 'user-session-manager' ), $user->user_login ) );
						?>
					</p>
					<p><?php echo wp_kses_post( SessionsPage::confirm_message( array( $user->ID ), count( $rows ), false ) ); ?></p>
					<p><?php esc_html_e( 'Signing out cannot be undone. The person can sign in again with their password.', 'user-session-manager' ); ?></p>
					<p class="axle-actions">
						<a class="button axle-button--danger" href="<?php echo esc_url( self::url( 'sign_out', (string) $user->ID, $back ) ); ?>"><?php esc_html_e( 'Sign out everywhere', 'user-session-manager' ); ?></a>
						<a class="button-link" href="<?php echo esc_url( $back . '#' . self::ANCHOR ); ?>"><?php esc_html_e( 'Cancel', 'user-session-manager' ); ?></a>
					</p>
				</div>
			<?php endif; ?>
		<?php endif; ?>
		<?php $this->render_history( $user ); ?>
		</div>
		<?php
	}

	/**
	 * "This wasn't me" reports for the account, from the last 30 days.
	 *
	 * @param \WP_User $user User being edited.
	 */
	private function render_reports( \WP_User $user ): void {
		foreach ( $this->not_me->events( $user->ID, 30 ) as $event ) {
			?>
			<div class="notice notice-error inline usm-report">
				<p>
					<span class="axle-badge axle-badge--error"><?php esc_html_e( 'Reported', 'user-session-manager' ); ?></span>
					<strong><?php esc_html_e( 'A sign-in was reported as "This wasn\'t me".', 'user-session-manager' ); ?></strong>
				</p>
				<p>
					<?php
					echo wp_kses(
						sprintf(
							/* translators: 1: browser and system, 2: IP address, 3: relative time, such as "2 hours ago". */
							esc_html__( '%1$s from %2$s, reported %3$s.', 'user-session-manager' ),
							esc_html( (string) $event['device'] ),
							esc_html( '' !== $event['ip'] ? (string) $event['ip'] : __( 'an unknown address', 'user-session-manager' ) ),
							AxleUi::relative_time( (int) $event['time'] )
						),
						Display::ALLOWED
					);
					echo ' ';
					echo esc_html(
						sprintf(
							/* translators: %s: number of sessions. */
							_n( '%s session was signed out and the password was reset.', '%s sessions were signed out and the password was reset.', (int) $event['ended'], 'user-session-manager' ),
							number_format_i18n( (int) $event['ended'] )
						)
					);
					?>
				</p>
			</div>
			<?php
		}
	}

	/**
	 * Sign-in history: when and where the account signed in, and how each session ended.
	 *
	 * @param \WP_User $user User being edited.
	 */
	private function render_history( \WP_User $user ): void {
		$days = $this->history->days();
		?>
		<h3 id="usm-history"><?php esc_html_e( 'Sign-in history', 'user-session-manager' ); ?></h3>
		<?php
		if ( $days < 1 ) {
			?>
			<div class="axle-empty usm-empty"><p><?php esc_html_e( 'Sign-in history is turned off. Turn it on in the User Sessions settings to see when and where this account signs in.', 'user-session-manager' ); ?></p></div>
			<?php
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only choice.
		$all  = isset( $_GET['usm_history'] ) && 'all' === sanitize_key( wp_unslash( $_GET['usm_history'] ) );
		$show = $this->history_rows_shown();
		$rows = $this->history->rows( $user->ID, $all ? 0 : $show );
		$more = ! $all && count( $this->history->entries( $user->ID ) ) > $show;
		?>
		<p class="description">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: number of days. */
					_n( 'Sign-ins from the last %s day, and how each session ended.', 'Sign-ins from the last %s days, and how each session ended.', $days, 'user-session-manager' ),
					number_format_i18n( $days )
				)
			);
			?>
		</p>
		<?php if ( ! $rows ) : ?>
			<div class="axle-empty usm-empty"><p><?php esc_html_e( 'No sign-ins recorded yet. They show up here the next time this account signs in.', 'user-session-manager' ); ?></p></div>
			<?php return; ?>
		<?php endif; ?>
		<div class="axle-scroll" tabindex="0" role="region" aria-label="<?php esc_attr_e( 'Sign-in history', 'user-session-manager' ); ?>">
		<table class="widefat striped usm-sessions usm-history">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Device', 'user-session-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'IP address', 'user-session-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Signed in', 'user-session-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Last active', 'user-session-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'How it ended', 'user-session-manager' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr class="usm-history-row" data-state="<?php echo esc_attr( $row['state'] ); ?>">
						<td><strong><?php echo esc_html( '' !== $row['device'] ? $row['device'] : __( 'Unknown device', 'user-session-manager' ) ); ?></strong></td>
						<td class="usm-ip" data-colname="<?php esc_attr_e( 'IP address', 'user-session-manager' ); ?>"><?php echo esc_html( '' !== $row['ip'] ? $row['ip'] : __( 'Unknown', 'user-session-manager' ) ); ?></td>
						<td data-colname="<?php esc_attr_e( 'Signed in', 'user-session-manager' ); ?>"><?php echo wp_kses( Display::time( $row['start'] ), Display::ALLOWED ); ?></td>
						<td data-colname="<?php esc_attr_e( 'Last active', 'user-session-manager' ); ?>"><?php echo wp_kses( Display::time( $row['seen'] ), Display::ALLOWED ); ?></td>
						<td data-colname="<?php esc_attr_e( 'How it ended', 'user-session-manager' ); ?>"><?php echo wp_kses( Display::ending( $row ), Display::ALLOWED ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php if ( $more ) : ?>
			<p><a href="<?php echo esc_url( add_query_arg( 'usm_history', 'all' ) . '#usm-history' ); ?>"><?php esc_html_e( 'Show all sign-ins', 'user-session-manager' ); ?></a></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Rows of history shown before "Show all".
	 *
	 * @return int
	 */
	private function history_rows_shown(): int {
		/**
		 * Filters how many sign-ins the profile shows before "Show all sign-ins".
		 *
		 * @param int $rows Default 20. Kept between 5 and 200.
		 */
		return max( 5, min( 200, (int) apply_filters( 'user_session_manager_history_rows', 20 ) ) );
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
		$extra   = isset( $_GET['usm_extra'] ) ? absint( $_GET['usm_extra'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$text = self::message( $message, $count, $user ? $user->user_login : '', $extra );
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
	 * @param int    $extra   Accounts that must choose a new password, for incident.
	 * @return string
	 */
	public static function message( string $message, int $count, string $user = '', int $extra = 0 ): string {
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
			case 'incident':
				/* translators: %s: number of sessions. */
				$text = sprintf( _n( 'Everyone was signed out: %s session ended.', 'Everyone was signed out: %s sessions ended.', $count, 'user-session-manager' ), $number );
				if ( $extra > 0 ) {
					/* translators: %s: number of accounts. */
					$text .= ' ' . sprintf( _n( '%s account must now choose a new password with "Lost your password?". This cannot be undone.', '%s accounts must now choose a new password with "Lost your password?". This cannot be undone.', $extra, 'user-session-manager' ), number_format_i18n( $extra ) );
				}
				break;
			default:
				return '';
		}

		/**
		 * Filters the notice after sessions were signed out from a screen.
		 *
		 * @param string $text    Notice text.
		 * @param string $message Result code: revoked, not_found, signed_out_others, signed_out_user, bulk or incident.
		 * @param int    $count   Sessions affected.
		 * @param string $user    Login of the user signed out, for signed_out_user.
		 */
		$filtered = (string) apply_filters( 'user_session_manager_notice', $text, $message, $count, $user );

		return '' !== trim( $filtered ) ? $filtered : $text;
	}
}
