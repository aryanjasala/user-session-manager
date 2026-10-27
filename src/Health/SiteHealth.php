<?php
/**
 * Site Health check.
 *
 * @package AryanX\Axle\UserSessionManager
 */

namespace AryanX\Axle\UserSessionManager\Health;

use AryanX\Axle\UserSessionManager\Admin\SessionsPage;
use AryanX\Axle\UserSessionManager\Admin\SettingsPage;
use AryanX\Axle\UserSessionManager\Service;
use AryanX\Axle\UserSessionManager\Sessions;
use AryanX\Axle\UserSessionManager\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a User Session Manager test to Tools > Site Health.
 *
 * It flags sessions kept outside user meta (nothing can be listed or ended),
 * and administrator sessions with neither a limit nor an idle timeout.
 */
final class SiteHealth implements Service {

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
	 * Sets up the check.
	 *
	 * @param Settings $settings Saved settings.
	 * @param Sessions $sessions Session store.
	 */
	public function __construct( Settings $settings, Sessions $sessions ) {
		$this->settings = $settings;
		$this->sessions = $sessions;
	}

	/**
	 * Registers the test.
	 */
	public function register(): void {
		add_filter( 'site_status_tests', array( $this, 'add_test' ) );
	}

	/**
	 * Adds the test to the direct tests.
	 *
	 * @param array $tests Site Health tests.
	 * @return array
	 */
	public function add_test( $tests ): array {
		$tests                                   = (array) $tests;
		$tests['direct']['user_session_manager'] = array(
			'label' => __( 'User Session Manager', 'user-session-manager' ),
			'test'  => array( $this, 'run' ),
		);

		return $tests;
	}

	/**
	 * Runs the test.
	 *
	 * @return array Site Health result.
	 */
	public function run(): array {
		$actions = sprintf( '<p><a href="%s">%s</a></p>', esc_url( SettingsPage::url() ), esc_html__( 'Open session policies', 'user-session-manager' ) );

		if ( ! $this->sessions->is_supported() ) {
			return $this->result(
				'recommended',
				__( 'Login sessions are stored where User Session Manager cannot see them', 'user-session-manager' ),
				__( 'Another plugin moved login sessions out of user meta (the session_token_manager filter). Sessions cannot be listed, signed out, limited or timed out until that plugin is turned off.', 'user-session-manager' ),
				$actions
			);
		}

		$admin = $this->settings->policy( 'administrator' );
		if ( 0 === $admin['max_sessions'] && 0 === $admin['idle_minutes'] ) {
			return $this->result(
				'recommended',
				__( 'Administrator sessions have no idle timeout or session limit', 'user-session-manager' ),
				__( 'A forgotten administrator login on a shared or lost computer stays signed in until it expires. Set an idle timeout or a session limit for administrators.', 'user-session-manager' ),
				$actions
			);
		}

		$totals = $this->sessions->totals();

		return $this->result(
			'good',
			__( 'Login sessions are managed', 'user-session-manager' ),
			sprintf(
				/* translators: 1: number of sessions, 2: number of users. */
				_n( '%1$d active session across %2$d users. Administrator sessions are limited or time out when idle.', '%1$d active sessions across %2$d users. Administrator sessions are limited or time out when idle.', $totals['sessions'], 'user-session-manager' ),
				$totals['sessions'],
				$totals['users']
			),
			sprintf( '<p><a href="%s">%s</a></p>', esc_url( SessionsPage::url() ), esc_html__( 'See all sessions', 'user-session-manager' ) )
		);
	}

	/**
	 * Builds a Site Health result.
	 *
	 * @param string $status      good, recommended or critical.
	 * @param string $label       Heading.
	 * @param string $description Explanation.
	 * @param string $actions     Action links HTML.
	 * @return array
	 */
	private function result( string $status, string $label, string $description, string $actions ): array {
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Security', 'user-session-manager' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html( $description ) . '</p>',
			'actions'     => $actions,
			'test'        => 'user_session_manager',
		);
	}
}
