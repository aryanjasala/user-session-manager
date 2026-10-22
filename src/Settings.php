<?php
/**
 * Saved settings and the policy that applies to each user.
 *
 * @package AryanX\UserSessionManager
 */

namespace AryanX\UserSessionManager;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the plugin's settings, and works out the session policy for a user.
 *
 * Sessions belong to users, and users are shared by every site of a network, so
 * the settings are a network option on multisite (a normal option otherwise).
 * Policies are set per role. A user with several roles gets the most generous
 * value of each: no limit beats any limit, and a longer limit beats a shorter one.
 */
final class Settings {

	public const OPTION      = 'user_session_manager_settings';
	public const LIMIT_MODES = array( 'evict_oldest', 'block' );

	/**
	 * Per-role values: 0 means no limit, or the WordPress default for lifetimes.
	 */
	public const POLICY_DEFAULTS = array(
		'max_sessions'   => 0,
		'idle_minutes'   => 0,
		'lifetime_hours' => 0,
		'remember_hours' => 0,
	);

	/**
	 * Highest value each per-role setting accepts.
	 */
	public const POLICY_MAX = array(
		'max_sessions'   => 100,
		'idle_minutes'   => 525600,
		'lifetime_hours' => 8760,
		'remember_hours' => 8760,
	);

	private const DEFAULTS = array(
		'policies'         => array(),
		'limit_mode'       => 'evict_oldest',
		'new_device_email' => false,
		'device_days'      => 30,
		'mask_ip'          => false,
	);

	private const LAST_SEEN_INTERVAL = 300;

	/**
	 * All settings, with defaults filled in.
	 *
	 * @return array{policies: array<string, array<string, int>>, limit_mode: string, new_device_email: bool, device_days: int, mask_ip: bool}
	 */
	public function all(): array {
		$saved = get_site_option( self::OPTION, array() );
		$all   = array_merge( self::DEFAULTS, is_array( $saved ) ? array_intersect_key( $saved, self::DEFAULTS ) : array() );

		$policies = array();
		foreach ( (array) $all['policies'] as $role => $policy ) {
			$policies[ sanitize_key( (string) $role ) ] = self::sanitize_policy( (array) $policy );
		}

		return array(
			'policies'         => $policies,
			'limit_mode'       => in_array( $all['limit_mode'], self::LIMIT_MODES, true ) ? (string) $all['limit_mode'] : 'evict_oldest',
			'new_device_email' => (bool) $all['new_device_email'],
			'device_days'      => max( 1, min( 365, (int) $all['device_days'] ) ),
			'mask_ip'          => (bool) $all['mask_ip'],
		);
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting name.
	 * @return mixed
	 */
	public function get( string $key ) {
		return $this->all()[ $key ] ?? null;
	}

	/**
	 * Changes some settings and keeps the rest.
	 *
	 * @param array $changes Setting name => value.
	 */
	public function update( array $changes ): void {
		$saved = get_site_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();

		update_site_option( self::OPTION, array_merge( $saved, array_intersect_key( $changes, self::DEFAULTS ) ) );
	}

	/**
	 * Saves changes from the screen or WP-CLI, and announces them.
	 *
	 * @param array $changes Setting name => value.
	 */
	public function save( array $changes ): void {
		$old = $this->all();
		$this->update( $changes );

		/**
		 * Fires after the session policies or settings changed, from the screen or WP-CLI.
		 *
		 * @param array $settings The settings now.
		 * @param array $old      The settings before.
		 */
		do_action( 'user_session_manager_settings_saved', $this->all(), $old );
	}

	/**
	 * The saved policy of a role, with defaults.
	 *
	 * @param string $role Role name.
	 * @return array<string, int>
	 */
	public function policy( string $role ): array {
		return $this->all()['policies'][ $role ] ?? self::POLICY_DEFAULTS;
	}

	/**
	 * Cleans one role's policy.
	 *
	 * @param array $policy Submitted values.
	 * @return array<string, int>
	 */
	public static function sanitize_policy( array $policy ): array {
		$clean = array();
		foreach ( self::POLICY_DEFAULTS as $key => $default ) {
			$value         = isset( $policy[ $key ] ) && is_numeric( $policy[ $key ] ) ? (int) $policy[ $key ] : $default;
			$clean[ $key ] = max( 0, min( self::POLICY_MAX[ $key ], $value ) );
		}

		return $clean;
	}

	/**
	 * Most sessions a user may have at once. 0 means no limit.
	 *
	 * @param \WP_User $user User.
	 * @return int
	 */
	public function max_sessions( \WP_User $user ): int {
		/**
		 * Filters how many sessions a user may have at once.
		 *
		 * @param int      $max  From the user's roles. 0 means no limit.
		 * @param \WP_User $user The user.
		 */
		return max( 0, (int) apply_filters( 'user_session_manager_max_sessions', $this->most_generous( $user, 'max_sessions' ), $user ) );
	}

	/**
	 * What happens when a login would go over the limit.
	 *
	 * @param \WP_User $user User.
	 * @return string evict_oldest or block.
	 */
	public function limit_mode( \WP_User $user ): string {
		/**
		 * Filters what happens when a login would go over the session limit.
		 *
		 * @param string   $mode evict_oldest signs out the oldest session, block refuses the new login.
		 * @param \WP_User $user The user.
		 */
		$mode = apply_filters( 'user_session_manager_limit_mode', $this->get( 'limit_mode' ), $user );

		return in_array( $mode, self::LIMIT_MODES, true ) ? (string) $mode : 'evict_oldest';
	}

	/**
	 * Minutes a session may sit unused before it ends. 0 means never.
	 *
	 * @param \WP_User $user User.
	 * @return int
	 */
	public function idle_timeout( \WP_User $user ): int {
		/**
		 * Filters how many minutes a session may sit unused before it is signed out.
		 *
		 * @param int      $minutes From the user's roles. 0 turns the idle timeout off.
		 * @param \WP_User $user    The user.
		 */
		return max( 0, (int) apply_filters( 'user_session_manager_idle_timeout', $this->most_generous( $user, 'idle_minutes' ), $user ) );
	}

	/**
	 * Seconds a new session lasts.
	 *
	 * @param \WP_User $user     User.
	 * @param bool     $remember Whether "Remember Me" was ticked.
	 * @param int      $fallback What WordPress would use.
	 * @return int
	 */
	public function lifetime( \WP_User $user, bool $remember, int $fallback ): int {
		$hours   = $this->most_generous( $user, $remember ? 'remember_hours' : 'lifetime_hours' );
		$seconds = $hours > 0 ? $hours * HOUR_IN_SECONDS : $fallback;

		/**
		 * Filters how long a new session lasts.
		 *
		 * @param int      $seconds  From the user's roles, or the WordPress default (2 days, 14 with Remember Me).
		 * @param \WP_User $user     The user.
		 * @param bool     $remember Whether "Remember Me" was ticked.
		 */
		$seconds = (int) apply_filters( 'user_session_manager_session_lifetime', $seconds, $user, $remember );

		return $seconds > 0 ? $seconds : $fallback;
	}

	/**
	 * Seconds between two "last active" writes for the same session.
	 *
	 * @return int
	 */
	public function last_seen_interval(): int {
		/**
		 * Filters how often "last active" is saved, at most, for one session.
		 *
		 * @param int $seconds Default 300 (5 minutes). 0 saves it on every request.
		 */
		return max( 0, min( DAY_IN_SECONDS, (int) apply_filters( 'user_session_manager_last_seen_interval', self::LAST_SEEN_INTERVAL ) ) );
	}

	/**
	 * Whether to store a shortened IP address.
	 *
	 * @param string $ip      Full address.
	 * @param int    $user_id User the session belongs to.
	 * @return bool
	 */
	public function mask_ip( string $ip, int $user_id ): bool {
		/**
		 * Filters whether a session stores a shortened IP address (the last part set to zero).
		 *
		 * @param bool   $mask    From the setting. Default false.
		 * @param string $ip      The full address.
		 * @param int    $user_id The user.
		 */
		return (bool) apply_filters( 'user_session_manager_mask_ip', $this->get( 'mask_ip' ), $ip, $user_id );
	}

	/**
	 * Days a browser and network stay known for the new device email.
	 *
	 * @return int
	 */
	public function device_days(): int {
		/**
		 * Filters how many days a browser and IP address stay known, for the new device email.
		 *
		 * @param int $days From the setting. Default 30.
		 */
		return max( 1, min( 365, (int) apply_filters( 'user_session_manager_device_memory_days', $this->get( 'device_days' ) ) ) );
	}

	/**
	 * The most generous value of one policy setting across a user's roles.
	 *
	 * @param \WP_User $user User.
	 * @param string   $key  Policy setting.
	 * @return int 0 when any role has no limit, or the user has no role.
	 */
	private function most_generous( \WP_User $user, string $key ): int {
		$values = array();
		foreach ( (array) $user->roles as $role ) {
			$values[] = (int) $this->policy( (string) $role )[ $key ];
		}

		if ( ! $values || in_array( 0, $values, true ) ) {
			return 0;
		}

		return max( $values );
	}
}
