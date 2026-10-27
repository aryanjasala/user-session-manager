<?php
/**
 * `wp user-session-manager` commands.
 *
 * @package AryanX\Axle\UserSessionManager
 */

namespace AryanX\Axle\UserSessionManager\Cli;

use AryanX\Axle\UserSessionManager\Abilities;
use AryanX\Axle\UserSessionManager\Pruner;
use AryanX\Axle\UserSessionManager\Sessions;
use AryanX\Axle\UserSessionManager\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Lists and signs out sessions, cleans up, and reads or changes the policies.
 */
final class Command {

	private const FIELDS = array( 'handle', 'user', 'device', 'ip', 'signed_in', 'last_active', 'expires' );

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
	 * Sets up the command.
	 *
	 * @param Settings $settings Saved settings.
	 * @param Sessions $sessions Session store.
	 * @param Pruner   $pruner   The clean-up.
	 */
	public function __construct( Settings $settings, Sessions $sessions, Pruner $pruner ) {
		$this->settings = $settings;
		$this->sessions = $sessions;
		$this->pruner   = $pruner;
	}

	/**
	 * Lists active sessions, most recently active first.
	 *
	 * ## OPTIONS
	 *
	 * [--user=<user>]
	 * : Only this user: ID, login or email address. This is WP-CLI's global
	 * --user flag, so the command also runs as that user.
	 *
	 * [--fields=<fields>]
	 * : Comma-separated fields. Default handle,user,device,ip,signed_in,last_active,expires. Also: user_id, device_type, ua.
	 *
	 * [--format=<format>]
	 * : table, csv, json, yaml, ids or count.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp user-session-manager list --user=admin
	 *     wp user-session-manager list --format=count
	 *
	 * @subcommand list
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function list_sessions( array $args, array $assoc_args ): void {
		$query = array();
		// WP-CLI keeps --user for itself (it sets the current user), so read it from its config.
		$user = $assoc_args['user'] ?? \WP_CLI::get_config( 'user' );
		if ( is_scalar( $user ) && '' !== (string) $user ) {
			$query['user_id'] = $this->user_id( (string) $user );
		}

		$rows = array_map(
			static function ( array $row ): array {
				return array(
					'handle'      => $row['handle'],
					'user'        => $row['user_login'],
					'user_id'     => $row['user_id'],
					'device'      => $row['device'],
					'device_type' => $row['device_type'],
					'ip'          => $row['ip'],
					'ua'          => $row['ua'],
					'signed_in'   => $row['login'] ? gmdate( 'Y-m-d H:i:s', $row['login'] ) : '',
					'last_active' => $row['last_seen'] ? gmdate( 'Y-m-d H:i:s', $row['last_seen'] ) : '',
					'expires'     => gmdate( 'Y-m-d H:i:s', $row['expiration'] ),
				);
			},
			$this->sessions->query( $query )['rows']
		);

		$format = (string) ( $assoc_args['format'] ?? 'table' );
		if ( 'ids' === $format ) {
			\WP_CLI::line( implode( ' ', array_column( $rows, 'handle' ) ) );
			return;
		}

		$fields = isset( $assoc_args['fields'] ) ? array_map( 'trim', explode( ',', (string) $assoc_args['fields'] ) ) : self::FIELDS;
		\WP_CLI\Utils\format_items( $format, $rows, $fields );
	}

	/**
	 * Signs out one or more sessions by handle.
	 *
	 * ## OPTIONS
	 *
	 * <handle>...
	 * : Handles from `wp user-session-manager list`.
	 *
	 * ## EXAMPLES
	 *
	 *     wp user-session-manager revoke 3-9f86d081884c7d659a2feaa0
	 *
	 * @param array $args Positional arguments.
	 */
	public function revoke( array $args ): void {
		$ended = 0;
		foreach ( $args as $handle ) {
			if ( $this->sessions->revoke_handle( (string) $handle, 'cli' ) ) {
				++$ended;
			} else {
				\WP_CLI::warning( "No active session with handle {$handle}." );
			}
		}

		if ( 0 === $ended ) {
			\WP_CLI::error( 'No session was signed out.' );
		}

		\WP_CLI::success( sprintf( 'Signed out %d session%s.', $ended, 1 === $ended ? '' : 's' ) );
	}

	/**
	 * Signs a user out everywhere.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, login or email address.
	 *
	 * ## EXAMPLES
	 *
	 *     wp user-session-manager sign-out editor
	 *
	 * @subcommand sign-out
	 *
	 * @param array $args Positional arguments.
	 */
	public function sign_out( array $args ): void {
		$user_id = $this->user_id( (string) $args[0] );
		$count   = $this->sessions->sign_out_everywhere( $user_id, 'cli' );

		\WP_CLI::success( sprintf( 'Signed user %d out of %d session%s.', $user_id, $count, 1 === $count ? '' : 's' ) );
	}

	/**
	 * Removes expired sessions and signs out idle ones, for every user.
	 *
	 * The hourly cron event does the same, 500 users per run.
	 *
	 * ## EXAMPLES
	 *
	 *     wp user-session-manager prune
	 */
	public function prune(): void {
		$result = $this->pruner->prune_all();

		\WP_CLI::success( sprintf( 'Looked at %d users. Removed %d expired and %d idle sessions.', $result['users'], $result['expired'], $result['idle'] ) );
	}

	/**
	 * Lists the session policy of every role.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, csv, json or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function policies( array $args, array $assoc_args ): void {
		$rows = array();
		foreach ( array_keys( wp_roles()->get_names() ) as $role ) {
			$rows[] = array( 'role' => $role ) + $this->settings->policy( (string) $role );
		}

		\WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, array_merge( array( 'role' ), array_keys( Settings::POLICY_DEFAULTS ) ) );
	}

	/**
	 * Changes the session policy of a role. 0 means no limit, or the WordPress default length.
	 *
	 * ## OPTIONS
	 *
	 * <role>
	 * : Role name, such as editor.
	 *
	 * [--max-sessions=<number>]
	 * : Sessions at once.
	 *
	 * [--idle-minutes=<minutes>]
	 * : Sign out after this many idle minutes.
	 *
	 * [--lifetime-hours=<hours>]
	 * : Session length.
	 *
	 * [--remember-hours=<hours>]
	 * : Session length with "Remember Me".
	 *
	 * ## EXAMPLES
	 *
	 *     wp user-session-manager set-policy administrator --max-sessions=2 --idle-minutes=30
	 *
	 * @subcommand set-policy
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function set_policy( array $args, array $assoc_args ): void {
		$role = sanitize_key( (string) $args[0] );
		if ( ! wp_roles()->is_role( $role ) ) {
			\WP_CLI::error( "There is no role called {$role}." );
		}

		$policy = $this->settings->policy( $role );
		foreach ( array_keys( Settings::POLICY_DEFAULTS ) as $key ) {
			$flag = str_replace( '_', '-', $key );
			if ( isset( $assoc_args[ $flag ] ) ) {
				$policy[ $key ] = (int) $assoc_args[ $flag ];
			}
		}

		$policies          = $this->settings->get( 'policies' );
		$policies[ $role ] = Settings::sanitize_policy( $policy );
		$this->settings->save( array( 'policies' => $policies ) );

		\WP_CLI::success( "Saved the policy for {$role}." );
	}

	/**
	 * Shows or changes the other settings.
	 *
	 * ## OPTIONS
	 *
	 * [--limit-mode=<mode>]
	 * : evict_oldest or block.
	 *
	 * [--new-device-email=<on|off>]
	 * : Email users about logins from a new browser or network.
	 *
	 * [--device-days=<days>]
	 * : Days a browser and network stay known.
	 *
	 * [--mask-ip=<on|off>]
	 * : Store shortened IP addresses.
	 *
	 * [--format=<format>]
	 * : table or json, when showing.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp user-session-manager settings --limit-mode=block --mask-ip=on
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function settings( array $args, array $assoc_args ): void {
		$changes = array();
		if ( isset( $assoc_args['limit-mode'] ) ) {
			if ( ! in_array( $assoc_args['limit-mode'], Settings::LIMIT_MODES, true ) ) {
				\WP_CLI::error( '--limit-mode must be evict_oldest or block.' );
			}
			$changes['limit_mode'] = (string) $assoc_args['limit-mode'];
		}
		foreach ( array( 'new-device-email', 'mask-ip' ) as $flag ) {
			if ( isset( $assoc_args[ $flag ] ) ) {
				$changes[ str_replace( '-', '_', $flag ) ] = in_array( strtolower( (string) $assoc_args[ $flag ] ), array( 'on', '1', 'yes', 'true' ), true );
			}
		}
		if ( isset( $assoc_args['device-days'] ) ) {
			$changes['device_days'] = max( 1, min( 365, (int) $assoc_args['device-days'] ) );
		}

		if ( $changes ) {
			$this->settings->save( $changes );
			\WP_CLI::success( 'Settings saved.' );
			return;
		}

		$all = $this->settings->all();
		unset( $all['policies'] );
		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			\WP_CLI::line( (string) wp_json_encode( $all ) );
			return;
		}

		foreach ( $all as $key => $value ) {
			\WP_CLI::line( str_pad( $key . ':', 18 ) . ( is_bool( $value ) ? ( $value ? 'on' : 'off' ) : $value ) );
		}
	}

	/**
	 * User ID from an ID, login or email address, or stops.
	 *
	 * @param string $user Input.
	 * @return int
	 */
	private function user_id( string $user ): int {
		$user_id = Abilities::user_id( $user );
		if ( $user_id < 1 ) {
			\WP_CLI::error( "There is no user {$user}." );
		}

		return $user_id;
	}
}
