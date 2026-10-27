<?php
/**
 * Settings > User Sessions (Network Admin > Settings on multisite).
 *
 * @package AryanX\Axle\UserSessionManager
 */

namespace AryanX\Axle\UserSessionManager\Admin;

use AryanX\Axle\UserSessionManager\Access;
use AryanX\Axle\UserSessionManager\Service;
use AryanX\Axle\UserSessionManager\Settings;
use const AryanX\Axle\UserSessionManager\PLUGIN_FILE;
use const AryanX\Axle\UserSessionManager\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Per-role session policies, what happens over the limit, the new device email and IP shortening.
 */
final class SettingsPage implements Service {

	public const SLUG = 'user-session-manager';

	private const ACTION = 'user_session_manager_settings';

	/**
	 * Saved settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Sets up the screen.
	 *
	 * @param Settings $settings Saved settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Adds the menu entry, form handler and plugin link.
	 */
	public function register(): void {
		add_action( is_multisite() ? 'network_admin_menu' : 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_form' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PLUGIN_FILE ), array( $this, 'add_settings_link' ) );
		add_filter( 'network_admin_plugin_action_links_' . plugin_basename( PLUGIN_FILE ), array( $this, 'add_settings_link' ) );
	}

	/**
	 * URL of the screen.
	 *
	 * @param array $args Query arguments.
	 * @return string
	 */
	public static function url( array $args = array() ): string {
		$base = is_multisite() ? network_admin_url( 'settings.php' ) : admin_url( 'options-general.php' );

		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), $base );
	}

	/**
	 * Registers the screen.
	 */
	public function add_page(): void {
		$hook = add_submenu_page(
			is_multisite() ? 'settings.php' : 'options-general.php',
			__( 'User Sessions', 'user-session-manager' ),
			__( 'User Sessions', 'user-session-manager' ),
			Access::settings_capability(),
			self::SLUG,
			array( $this, 'render' )
		);

		if ( $hook ) {
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
	 * Adds a Settings link on the Plugins screen.
	 *
	 * @param string[] $links Action links.
	 * @return string[]
	 */
	public function add_settings_link( $links ): array {
		$links = (array) $links;
		if ( current_user_can( Access::settings_capability() ) ) {
			array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( self::url() ), esc_html__( 'Settings', 'user-session-manager' ) ) );
		}

		return $links;
	}

	/**
	 * Prints the screen.
	 */
	public function render(): void {
		$settings = $this->settings->all();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only value set by our own redirect.
		$saved = isset( $_GET['usm_saved'] );
		?>
		<div class="wrap usm-page usm-settings">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'User Sessions', 'user-session-manager' ); ?></h1>
			<?php if ( Access::can_view_all() ) : ?>
				<a href="<?php echo esc_url( SessionsPage::url() ); ?>" class="page-title-action"><?php esc_html_e( 'See all sessions', 'user-session-manager' ); ?></a>
			<?php endif; ?>
			<hr class="wp-header-end">

			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'user-session-manager' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">

				<h2><?php esc_html_e( 'Policies per role', 'user-session-manager' ); ?></h2>
				<p class="description">
					<?php
					esc_html_e( 'Leave a field at 0 for no limit, or for the WordPress default session length (2 days, 14 with "Remember Me"). A user with several roles gets the most generous value of each. Users with no role on this site have no limits.', 'user-session-manager' );
					?>
				</p>
				<table class="widefat striped usm-policies">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Role', 'user-session-manager' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Sessions at once', 'user-session-manager' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Sign out after idle (minutes)', 'user-session-manager' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Session length (hours)', 'user-session-manager' ); ?></th>
							<th scope="col"><?php esc_html_e( 'With "Remember Me" (hours)', 'user-session-manager' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( wp_roles()->get_names() as $role => $name ) : ?>
							<?php $policy = $this->settings->policy( (string) $role ); ?>
							<tr data-role="<?php echo esc_attr( $role ); ?>">
								<th scope="row"><?php echo esc_html( translate_user_role( $name ) ); ?></th>
								<?php foreach ( Settings::POLICY_DEFAULTS as $key => $default ) : ?>
									<td data-colname="<?php echo esc_attr( $this->field_label( $key ) ); ?>">
										<label class="screen-reader-text" for="<?php echo esc_attr( "usm-{$role}-{$key}" ); ?>"><?php echo esc_html( translate_user_role( $name ) . ': ' . $this->field_label( $key ) ); ?></label>
										<input type="number" class="small-text" min="0" max="<?php echo esc_attr( (string) Settings::POLICY_MAX[ $key ] ); ?>" step="1" id="<?php echo esc_attr( "usm-{$role}-{$key}" ); ?>" name="<?php echo esc_attr( "policies[{$role}][{$key}]" ); ?>" value="<?php echo esc_attr( (string) $policy[ $key ] ); ?>">
									</td>
								<?php endforeach; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Over the limit', 'user-session-manager' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'Over the limit', 'user-session-manager' ); ?></legend>
								<label><input type="radio" name="limit_mode" value="evict_oldest" <?php checked( 'evict_oldest', $settings['limit_mode'] ); ?>> <?php esc_html_e( 'Sign out the oldest session (recommended)', 'user-session-manager' ); ?></label><br>
								<label><input type="radio" name="limit_mode" value="block" <?php checked( 'block', $settings['limit_mode'] ); ?>> <?php esc_html_e( 'Refuse the new login', 'user-session-manager' ); ?></label>
								<p class="description"><?php esc_html_e( 'What happens when someone signs in and already has as many sessions as their role allows. Refusing is stricter, but a user who lost a device has to ask an administrator to end that session.', 'user-session-manager' ); ?></p>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'New device email', 'user-session-manager' ); ?></th>
						<td>
							<label><input type="checkbox" name="new_device_email" value="1" <?php checked( $settings['new_device_email'] ); ?>> <?php esc_html_e( 'Email users when they sign in from a new browser or network', 'user-session-manager' ); ?></label>
							<p>
								<label for="usm-device-days"><?php esc_html_e( 'Remember browsers and networks for', 'user-session-manager' ); ?></label>
								<input type="number" class="small-text" min="1" max="365" id="usm-device-days" name="device_days" value="<?php echo esc_attr( (string) $settings['device_days'] ); ?>">
								<?php esc_html_e( 'days', 'user-session-manager' ); ?>
							</p>
							<p class="description"><?php esc_html_e( 'A browser and IP address pair not seen on the account for that long counts as new. Only a scrambled fingerprint is kept, not the address. The first login after this is turned on is remembered without an email.', 'user-session-manager' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'IP addresses', 'user-session-manager' ); ?></th>
						<td>
							<label><input type="checkbox" name="mask_ip" value="1" <?php checked( $settings['mask_ip'] ); ?>> <?php esc_html_e( 'Store shortened IP addresses (the last part set to zero)', 'user-session-manager' ); ?></label>
							<p class="description"><?php esc_html_e( 'For privacy. Sessions still show roughly where a login came from, but not the exact address. Existing sessions are shortened the next time they are used.', 'user-session-manager' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save settings', 'user-session-manager' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Label of a policy field, for screen readers.
	 *
	 * @param string $key Policy setting.
	 * @return string
	 */
	private function field_label( string $key ): string {
		$labels = array(
			'max_sessions'   => __( 'Sessions at once', 'user-session-manager' ),
			'idle_minutes'   => __( 'Sign out after idle (minutes)', 'user-session-manager' ),
			'lifetime_hours' => __( 'Session length (hours)', 'user-session-manager' ),
			'remember_hours' => __( 'With "Remember Me" (hours)', 'user-session-manager' ),
		);

		return $labels[ $key ] ?? $key;
	}

	/**
	 * Saves the form.
	 */
	public function handle_form(): void {
		if ( ! current_user_can( Access::settings_capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to change these settings.', 'user-session-manager' ), 403 );
		}

		check_admin_referer( self::ACTION );

		// Each value is cast to an integer and clamped in Settings::sanitize_policy().
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$submitted = isset( $_POST['policies'] ) && is_array( $_POST['policies'] ) ? wp_unslash( $_POST['policies'] ) : array();
		$policies  = array();
		foreach ( array_keys( wp_roles()->get_names() ) as $role ) {
			if ( isset( $submitted[ $role ] ) && is_array( $submitted[ $role ] ) ) {
				$policies[ $role ] = Settings::sanitize_policy( array_map( 'absint', $submitted[ $role ] ) );
			}
		}

		$mode = isset( $_POST['limit_mode'] ) ? sanitize_key( wp_unslash( $_POST['limit_mode'] ) ) : 'evict_oldest';

		$this->settings->save(
			array(
				'policies'         => $policies,
				'limit_mode'       => in_array( $mode, Settings::LIMIT_MODES, true ) ? $mode : 'evict_oldest',
				'new_device_email' => ! empty( $_POST['new_device_email'] ),
				'device_days'      => isset( $_POST['device_days'] ) ? max( 1, min( 365, absint( $_POST['device_days'] ) ) ) : 30,
				'mask_ip'          => ! empty( $_POST['mask_ip'] ),
			)
		);

		wp_safe_redirect( self::url( array( 'usm_saved' => 1 ) ) );
		exit;
	}
}
