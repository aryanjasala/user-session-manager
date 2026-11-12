<?php
/**
 * `wp user-session-manager` commands.
 *
 * @package AryanX\Axle\UserSessionManager
 */

namespace AryanX\Axle\UserSessionManager\Cli;

use AryanX\Axle\UserSessionManager\Abilities;
use AryanX\Axle\UserSessionManager\History;
use AryanX\Axle\UserSessionManager\Incident;
use AryanX\Axle\UserSessionManager\NotMe;
use AryanX\Axle\UserSessionManager\Pruner;
use AryanX\Axle\UserSessionManager\Sessions;
use AryanX\Axle\UserSessionManager\Settings;
use AryanX\Axle\UserSessionManager\SharedLogins;

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
	 * "Sign everyone out".
	 *
	 * @var Incident
	 */
	private Incident $incident;

	/**
	 * Sets up the command.
	 *
	 * @param Settings     $settings Saved settings.
	 * @param Sessions     $sessions Session store.
	 * @param Pruner       $pruner   The clean-up.
	 * @param SharedLogins $shared   Shared login detection.
	 * @param History      $history  Sign-in history.
	 * @param NotMe        $not_me   "This wasn't me" reports.
	 * @param Incident     $incident "Sign everyone out".
	 */
	public function __construct( Settings $settings, Sessions $sessions, Pruner $pruner, SharedLogins $shared, History $history, NotMe $not_me, Incident $incident ) {
		$this->settings = $settings;
		$this->sessions = $sessions;
		$this->pruner   = $pruner;
		$this->shared   = $shared;
		$this->history  = $history;
		$this->not_me   = $not_me;
		$this->incident = $incident;
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
	 * Removes expired sessions and signs out idle ones, for every user. Also drops sign-in history past its retention time.
	 *
	 * The hourly cron event does the same, 500 users per run.
	 *
	 * ## EXAMPLES
	 *
	 *     wp user-session-manager prune
	 */
	public function prune(): void {
		$result = $this->pruner->prune_all();

		$history = $this->pruner->prune_history_all();

		\WP_CLI::success( sprintf( 'Looked at %d users. Removed %d expired and %d idle sessions, and %d old sign-in history entries.', $result['users'], $result['expired'], $result['idle'], $history ) );
	}

	/**
	 * Shows when and where an account signed in, and how each session ended.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, login or email address.
	 *
	 * [--fields=<fields>]
	 * : Comma-separated fields. Default device,ip,signed_in,last_active,ended,reason. Also: state, by.
	 *
	 * [--format=<format>]
	 * : table, csv, json, yaml, ids or count.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp user-session-manager history editor
	 *     wp user-session-manager history 7 --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function history( array $args, array $assoc_args ): void {
		$rows = array_map(
			static function ( array $row ): array {
				return array(
					'device'      => $row['device'],
					'ip'          => $row['ip'],
					'signed_in'   => $row['start'] ? gmdate( 'Y-m-d H:i:s', $row['start'] ) : '',
					'last_active' => $row['seen'] ? gmdate( 'Y-m-d H:i:s', $row['seen'] ) : '',
					'ended'       => $row['end'] ? gmdate( 'Y-m-d H:i:s', $row['end'] ) : '',
					'state'       => $row['state'],
					'reason'      => 'active' === $row['state'] ? '' : ( '' !== $row['reason'] ? $row['reason'] : 'unknown' ),
					'by'          => $row['by'],
				);
			},
			$this->history->rows( $this->user_id( (string) $args[0] ) )
		);

		$format = (string) ( $assoc_args['format'] ?? 'table' );
		$fields = isset( $assoc_args['fields'] ) ? array_map( 'trim', explode( ',', (string) $assoc_args['fields'] ) ) : array( 'device', 'ip', 'signed_in', 'last_active', 'ended', 'reason' );
		\WP_CLI\Utils\format_items( $format, $rows, $fields );
	}

	/**
	 * Lists sign-ins that people reported as "This wasn't me", newest first.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 * : Only reports from this many days back. Default 30. 0 lists every report that is kept.
	 *
	 * [--format=<format>]
	 * : table, csv, json, yaml, ids or count.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp user-session-manager reports --days=7
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function reports( array $args, array $assoc_args ): void {
		$rows = array();
		foreach ( $this->not_me->events( 0, max( 0, (int) ( $assoc_args['days'] ?? 30 ) ) ) as $event ) {
			$user   = get_userdata( (int) $event['user'] );
			$rows[] = array(
				'user'     => $user ? $user->user_login : (string) $event['user'],
				'device'   => (string) $event['device'],
				'ip'       => (string) $event['ip'],
				'reported' => gmdate( 'Y-m-d H:i:s', (int) $event['time'] ),
				'scope'    => (string) $event['scope'],
				'ended'    => (int) $event['ended'],
			);
		}

		\WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, array( 'user', 'device', 'ip', 'reported', 'scope', 'ended' ) );
	}

	/**
	 * Signs every user out, for an incident. Cannot be undone.
	 *
	 * Visits every user in batches. With --require-new-passwords every password also stops
	 * working, so each person has to use "Lost your password?". Nobody is emailed.
	 *
	 * ## OPTIONS
	 *
	 * [--keep-user=<user>]
	 * : Leave this user signed in: ID, login or email address.
	 *
	 * [--exclude-roles=<roles>]
	 * : Comma-separated roles whose users are left alone, such as administrator.
	 *
	 * [--require-new-passwords]
	 * : Also make every password stop working.
	 *
	 * [--yes]
	 * : Do not ask first.
	 *
	 * ## EXAMPLES
	 *
	 *     wp user-session-manager sign-out-everyone --keep-user=admin
	 *     wp user-session-manager sign-out-everyone --exclude-roles=administrator --require-new-passwords --yes
	 *
	 * @subcommand sign-out-everyone
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function sign_out_everyone( array $args, array $assoc_args ): void {
		$keep = 0;
		if ( isset( $assoc_args['keep-user'] ) ) {
			$keep = $this->user_id( (string) $assoc_args['keep-user'] );
			wp_set_current_user( $keep );
		}
		$roles     = isset( $assoc_args['exclude-roles'] ) ? array_map( 'trim', explode( ',', (string) $assoc_args['exclude-roles'] ) ) : array();
		$passwords = isset( $assoc_args['require-new-passwords'] );

		\WP_CLI::confirm( 'Sign every user out' . ( $passwords ? ' and make every password stop working' : '' ) . '? This cannot be undone.', $assoc_args );

		$job = $this->incident->run_all(
			array(
				'keep_current'  => $keep > 0,
				'exclude_roles' => $roles,
				'passwords'     => $passwords,
				'via'           => 'cli',
				'check_caps'    => false,
			)
		);

		\WP_CLI::success( sprintf( 'Signed %d users out of %d sessions. %d passwords made unusable. %d users left out.', $job['users'], $job['sessions'], $job['passwords_set'], $job['excluded'] ) );
	}

	/**
	 * Lists accounts that look shared by several people, most recently flagged first.
	 *
	 * An account is flagged when its sessions look like more than one person,
	 * for example two computers on different networks at the same time. The
	 * flag is kept for 7 days after the last sign of it.
	 *
	 * ## OPTIONS
	 *
	 * [--fields=<fields>]
	 * : Comma-separated fields. Default user,first_noticed,last_seen,action,sessions,evidence. Also: user_id, signed_out.
	 *
	 * [--format=<format>]
	 * : table, csv, json, yaml, ids or count.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp user-session-manager shared
	 *     wp user-session-manager shared --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function shared( array $args, array $assoc_args ): void {
		$rows = array();
		foreach ( $this->shared->flagged() as $user_id => $flag ) {
			$user   = get_userdata( $user_id );
			$rows[] = array(
				'user'          => $user ? $user->user_login : (string) $user_id,
				'user_id'       => $user_id,
				'first_noticed' => gmdate( 'Y-m-d H:i:s', $flag['detected'] ),
				'last_seen'     => gmdate( 'Y-m-d H:i:s', $flag['updated'] ),
				'action'        => $flag['action'],
				'sessions'      => count( $flag['sessions'] ),
				'signed_out'    => $flag['signed_out'],
				'evidence'      => implode( ' ', array_map( array( SharedLogins::class, 'evidence_text' ), $flag['evidence'] ) ),
			);
		}

		$format = (string) ( $assoc_args['format'] ?? 'table' );
		if ( 'ids' === $format ) {
			\WP_CLI::line( implode( ' ', array_column( $rows, 'user_id' ) ) );
			return;
		}

		$fields = isset( $assoc_args['fields'] ) ? array_map( 'trim', explode( ',', (string) $assoc_args['fields'] ) ) : array( 'user', 'first_noticed', 'last_seen', 'action', 'sessions', 'evidence' );
		\WP_CLI\Utils\format_items( $format, $rows, $fields );
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
			$rows[] = array( 'role' => $role ) + $this->settings->policy( (string) $role ) + array( 'shared' => $this->settings->shared_mode( (string) $role ) );
		}

		\WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, array_merge( array( 'role' ), array_keys( Settings::POLICY_DEFAULTS ), array( 'shared' ) ) );
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
	 * [--shared=<mode>]
	 * : When an account looks shared: off (do not check), flag, notify or sign_out_others.
	 *
	 * ## EXAMPLES
	 *
	 *     wp user-session-manager set-policy administrator --max-sessions=2 --idle-minutes=30
	 *     wp user-session-manager set-policy subscriber --shared=notify
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

		$changes = array();
		if ( isset( $assoc_args['shared'] ) ) {
			if ( ! in_array( $assoc_args['shared'], Settings::SHARED_MODES, true ) ) {
				\WP_CLI::error( '--shared must be off, flag, notify or sign_out_others.' );
			}
			$shared            = $this->settings->get( 'shared' );
			$shared[ $role ]   = (string) $assoc_args['shared'];
			$changes['shared'] = $shared;
		}

		$policies            = $this->settings->get( 'policies' );
		$policies[ $role ]   = Settings::sanitize_policy( $policy );
		$changes['policies'] = $policies;
		$this->settings->save( $changes );

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
	 * [--not-me=<on|off>]
	 * : Add a "This wasn't me" link to the new device email.
	 *
	 * [--not-me-scope=<scope>]
	 * : all (sign the account out everywhere) or session (only the reported session).
	 *
	 * [--history-days=<days>]
	 * : Days sign-in history is kept. 0 keeps none.
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
		if ( isset( $assoc_args['not-me-scope'] ) ) {
			if ( ! in_array( $assoc_args['not-me-scope'], Settings::NOT_ME_SCOPES, true ) ) {
				\WP_CLI::error( '--not-me-scope must be all or session.' );
			}
			$changes['not_me_scope'] = (string) $assoc_args['not-me-scope'];
		}
		foreach ( array( 'new-device-email', 'mask-ip', 'not-me' ) as $flag ) {
			if ( isset( $assoc_args[ $flag ] ) ) {
				$changes[ str_replace( '-', '_', $flag ) ] = in_array( strtolower( (string) $assoc_args[ $flag ] ), array( 'on', '1', 'yes', 'true' ), true );
			}
		}
		if ( isset( $assoc_args['history-days'] ) ) {
			$changes['history_days'] = max( 0, min( 365, (int) $assoc_args['history-days'] ) );
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
			\WP_CLI::line( str_pad( $key . ':', 18 ) . ( is_bool( $value ) ? ( $value ? 'on' : 'off' ) : ( is_array( $value ) ? (string) wp_json_encode( $value ) : $value ) ) );
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
