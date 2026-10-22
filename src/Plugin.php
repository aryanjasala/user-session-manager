<?php
/**
 * Plugin bootstrap.
 *
 * @package AryanX\UserSessionManager
 */

namespace AryanX\UserSessionManager;

use AryanX\UserSessionManager\Admin\ProfileSessions;
use AryanX\UserSessionManager\Admin\SessionsPage;
use AryanX\UserSessionManager\Admin\SettingsPage;
use AryanX\UserSessionManager\Admin\UsersColumn;
use AryanX\UserSessionManager\Cli\Command;
use AryanX\UserSessionManager\Health\SiteHealth;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the services once and registers their hooks.
 */
final class Plugin {

	/**
	 * The running instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

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
	 * The clean-up.
	 *
	 * @var Pruner
	 */
	private Pruner $pruner;

	/**
	 * Device memory for the new device email.
	 *
	 * @var NewDevice
	 */
	private NewDevice $devices;

	/**
	 * Starts the plugin. Runs on `plugins_loaded`.
	 */
	public static function boot(): void {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->register();
		}
	}

	/**
	 * The running instance.
	 *
	 * @return Plugin|null
	 */
	public static function instance(): ?Plugin {
		return self::$instance;
	}

	/**
	 * Builds the shared objects.
	 */
	private function __construct() {
		$this->settings = new Settings();
		$this->sessions = new Sessions( $this->settings );
		$this->pruner   = new Pruner( $this->settings, $this->sessions );
		$this->devices  = new NewDevice( $this->settings, $this->sessions );
	}

	/**
	 * The session store, for other plugins.
	 *
	 * @return Sessions
	 */
	public function sessions(): Sessions {
		return $this->sessions;
	}

	/**
	 * Saved settings and per-user policy, for other plugins.
	 *
	 * @return Settings
	 */
	public function settings(): Settings {
		return $this->settings;
	}

	/**
	 * Registers the services.
	 */
	private function register(): void {
		$services = array(
			$this->sessions,
			new Activity( $this->settings, $this->sessions ),
			new Limits( $this->settings, $this->sessions ),
			$this->devices,
			$this->pruner,
			new Privacy( $this->settings, $this->sessions, $this->devices ),
			new ProfileSessions( $this->sessions ),
			new SessionsPage( $this->sessions ),
			new UsersColumn( $this->sessions ),
			new SettingsPage( $this->settings ),
			new SiteHealth( $this->settings, $this->sessions ),
			new Abilities( $this->sessions ),
		);

		foreach ( $services as $service ) {
			$service->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'user-session-manager', new Command( $this->settings, $this->sessions, $this->pruner ) );
		}
	}
}
