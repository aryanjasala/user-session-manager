<?php
/**
 * Plugin bootstrap.
 *
 * @package AryanX\Axle\UserSessionManager
 */

namespace AryanX\Axle\UserSessionManager;

use AryanX\Axle\UserSessionManager\Admin\ProfileSessions;
use AryanX\Axle\UserSessionManager\Admin\SessionsPage;
use AryanX\Axle\UserSessionManager\Admin\SettingsPage;
use AryanX\Axle\UserSessionManager\Admin\UsersColumn;
use AryanX\Axle\UserSessionManager\Cli\Command;
use AryanX\Axle\UserSessionManager\Health\SiteHealth;

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
	 * The "This wasn't me" link.
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
		$this->history  = new History( $this->settings, $this->sessions );
		$this->pruner   = new Pruner( $this->settings, $this->sessions, $this->history );
		$this->not_me   = new NotMe( $this->settings, $this->sessions );
		$this->devices  = new NewDevice( $this->settings, $this->sessions, $this->not_me );
		$this->shared   = new SharedLogins( $this->settings, $this->sessions );
		$this->incident = new Incident( $this->sessions );
	}

	/**
	 * Sign-in history, for other plugins.
	 *
	 * @return History
	 */
	public function history(): History {
		return $this->history;
	}

	/**
	 * "This wasn't me" reports, for other plugins.
	 *
	 * @return NotMe
	 */
	public function not_me(): NotMe {
		return $this->not_me;
	}

	/**
	 * "Sign everyone out", for other plugins.
	 *
	 * @return Incident
	 */
	public function incident(): Incident {
		return $this->incident;
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
	 * Shared login detection, for other plugins.
	 *
	 * @return SharedLogins
	 */
	public function shared(): SharedLogins {
		return $this->shared;
	}

	/**
	 * Registers the services.
	 */
	private function register(): void {
		$services = array(
			$this->sessions,
			$this->history,
			new LoginNotice( $this->history, $this->sessions ),
			$this->not_me,
			new Activity( $this->settings, $this->sessions ),
			new Limits( $this->settings, $this->sessions ),
			$this->devices,
			$this->shared,
			$this->pruner,
			new Privacy( $this->settings, $this->sessions, $this->devices, $this->shared, $this->history, $this->not_me ),
			new ProfileSessions( $this->sessions, $this->shared, $this->history, $this->not_me ),
			new SessionsPage( $this->settings, $this->sessions, $this->shared, $this->not_me, $this->incident ),
			new UsersColumn( $this->sessions ),
			new SettingsPage( $this->settings ),
			new SiteHealth( $this->settings, $this->sessions, $this->not_me ),
			new Abilities( $this->sessions, $this->shared, $this->history, $this->not_me, $this->incident ),
		);

		foreach ( $services as $service ) {
			$service->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'user-session-manager', new Command( $this->settings, $this->sessions, $this->pruner, $this->shared, $this->history, $this->not_me, $this->incident ) );
		}
	}
}
