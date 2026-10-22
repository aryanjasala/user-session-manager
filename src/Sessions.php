<?php
/**
 * Reading and ending login sessions.
 *
 * @package AryanX\UserSessionManager
 */

namespace AryanX\UserSessionManager;

defined( 'ABSPATH' ) || exit;

/**
 * Works on the sessions WordPress keeps in the `session_tokens` user meta.
 *
 * Core stores each session under a verifier, the SHA-256 hash of the token in
 * the login cookie, and only ends a session when it has the raw token. This
 * class reads and writes the same meta with the same structure, so a session
 * can be ended from its verifier alone. Verifiers never leave PHP: screens,
 * WP-CLI and abilities use a handle, an HMAC of the user ID and the verifier
 * keyed with the site's auth salt, which cannot be turned back into either.
 */
final class Sessions implements Service {

	public const META        = 'session_tokens';
	public const CACHE_GROUP = 'user_session_manager';

	private const BATCH     = 500;
	private const MAX_USERS = 10000;

	/**
	 * Saved settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Sets up the store.
	 *
	 * @param Settings $settings Saved settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Clears the cached totals whenever a user's sessions change.
	 */
	public function register(): void {
		foreach ( array( 'added_user_meta', 'updated_user_meta', 'deleted_user_meta' ) as $hook ) {
			add_action( $hook, array( $this, 'flush_totals' ), 10, 3 );
		}
	}

	/**
	 * Whether WordPress keeps sessions in user meta, where this plugin can see them.
	 *
	 * A plugin can move sessions elsewhere (Redis, a custom table) through the
	 * session_token_manager filter. Then there is nothing here to read.
	 *
	 * @return bool
	 */
	public function is_supported(): bool {
		// Core's own filter, read the same way core reads it.
		$manager = apply_filters( 'session_token_manager', 'WP_User_Meta_Session_Tokens' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		return is_string( $manager ) && ( 'WP_User_Meta_Session_Tokens' === $manager || is_a( $manager, 'WP_User_Meta_Session_Tokens', true ) );
	}

	/**
	 * The verifier core stores for a token.
	 *
	 * @param string $token Raw session token.
	 * @return string
	 */
	public static function verifier( string $token ): string {
		return hash( 'sha256', $token );
	}

	/**
	 * Verifier of the session this request was made with, or empty.
	 *
	 * @return string
	 */
	public function current_verifier(): string {
		$token = wp_get_session_token();

		return '' === $token ? '' : self::verifier( $token );
	}

	/**
	 * A user's sessions that have not expired, keyed by verifier.
	 *
	 * @param int $user_id User ID.
	 * @return array<string, array>
	 */
	public function for_user( int $user_id ): array {
		if ( $user_id < 1 || ! $this->is_supported() ) {
			return array();
		}

		$stored = get_user_meta( $user_id, self::META, true );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$now      = time();
		$sessions = array();
		foreach ( $stored as $verifier => $session ) {
			// Sessions from before WordPress 4.0 were only an expiration time.
			if ( ! is_array( $session ) ) {
				$session = array( 'expiration' => (int) $session );
			}
			if ( isset( $session['expiration'] ) && (int) $session['expiration'] >= $now ) {
				$sessions[ (string) $verifier ] = $session;
			}
		}

		return $sessions;
	}

	/**
	 * One session.
	 *
	 * @param int    $user_id  User ID.
	 * @param string $verifier Verifier.
	 * @return array|null
	 */
	public function get( int $user_id, string $verifier ): ?array {
		return $this->for_user( $user_id )[ $verifier ] ?? null;
	}

	/**
	 * Number of a user's sessions that have not expired.
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	public function count( int $user_id ): int {
		return count( $this->for_user( $user_id ) );
	}

	/**
	 * Saves one session.
	 *
	 * @param int    $user_id  User ID.
	 * @param string $verifier Verifier.
	 * @param array  $session  Session data.
	 */
	public function update( int $user_id, string $verifier, array $session ): void {
		$sessions              = $this->for_user( $user_id );
		$sessions[ $verifier ] = $session;
		$this->save( $user_id, $sessions );
	}

	/**
	 * Replaces all of a user's sessions, the way core does: no sessions, no meta.
	 *
	 * @param int   $user_id  User ID.
	 * @param array $sessions Verifier => session.
	 */
	public function save( int $user_id, array $sessions ): void {
		if ( $sessions ) {
			update_user_meta( $user_id, self::META, $sessions );
		} else {
			delete_user_meta( $user_id, self::META );
		}
	}

	/**
	 * The public handle of a session.
	 *
	 * @param int    $user_id  User ID.
	 * @param string $verifier Verifier.
	 * @return string "<user ID>-<24 hex characters>".
	 */
	public function handle( int $user_id, string $verifier ): string {
		return $user_id . '-' . substr( hash_hmac( 'sha256', $user_id . '|' . $verifier, wp_salt( 'auth' ) ), 0, 24 );
	}

	/**
	 * Finds the session behind a handle.
	 *
	 * @param string $handle Handle from handle().
	 * @return array{user_id: int, verifier: string, session: array}|null
	 */
	public function find( string $handle ): ?array {
		if ( ! preg_match( '/^(\d+)-[0-9a-f]{24}$/', $handle, $match ) ) {
			return null;
		}

		$user_id = (int) $match[1];
		foreach ( $this->for_user( $user_id ) as $verifier => $session ) {
			if ( hash_equals( $this->handle( $user_id, $verifier ), $handle ) ) {
				return array(
					'user_id'  => $user_id,
					'verifier' => $verifier,
					'session'  => $session,
				);
			}
		}

		return null;
	}

	/**
	 * Removes one session without announcing it. Callers fire their own action.
	 *
	 * @param int    $user_id  User ID.
	 * @param string $verifier Verifier.
	 * @return array|null The removed session, or null when there was none.
	 */
	public function forget( int $user_id, string $verifier ): ?array {
		$sessions = $this->for_user( $user_id );
		if ( ! isset( $sessions[ $verifier ] ) ) {
			return null;
		}

		$session = $sessions[ $verifier ];
		unset( $sessions[ $verifier ] );
		$this->save( $user_id, $sessions );

		return $session;
	}

	/**
	 * Ends one session.
	 *
	 * @param int    $user_id  User ID.
	 * @param string $verifier Verifier.
	 * @param string $context  Where it was ended: profile, admin, bulk, cli, ability, or a caller's own.
	 * @return bool Whether a session was ended.
	 */
	public function revoke( int $user_id, string $verifier, string $context ): bool {
		$session = $this->forget( $user_id, $verifier );
		if ( null === $session ) {
			return false;
		}

		/**
		 * Fires after one session was ended.
		 *
		 * @param int    $user_id User the session belonged to.
		 * @param array  $session Session data: expiration, ip, ua, login, last_seen and handle.
		 * @param string $context profile, admin, bulk, cli or ability.
		 */
		do_action( 'user_session_manager_session_revoked', $user_id, $session + array( 'handle' => $this->handle( $user_id, $verifier ) ), $context );

		return true;
	}

	/**
	 * Ends the session behind a handle.
	 *
	 * @param string $handle  Handle.
	 * @param string $context Where it was ended.
	 * @return bool Whether a session was ended.
	 */
	public function revoke_handle( string $handle, string $context ): bool {
		$found = $this->find( $handle );

		return null !== $found && $this->revoke( $found['user_id'], $found['verifier'], $context );
	}

	/**
	 * Ends every session of a user except one.
	 *
	 * @param int    $user_id  User ID.
	 * @param string $keep     Verifier of the session to keep.
	 * @param string $context  Where it was ended.
	 * @return int Sessions ended.
	 */
	public function revoke_others( int $user_id, string $keep, string $context ): int {
		$ended = 0;
		foreach ( array_keys( $this->for_user( $user_id ) ) as $verifier ) {
			if ( $verifier !== $keep && $this->revoke( $user_id, (string) $verifier, $context ) ) {
				++$ended;
			}
		}

		return $ended;
	}

	/**
	 * Ends every session of a user.
	 *
	 * @param int    $user_id User ID.
	 * @param string $context Where it was done: profile, admin, bulk, cli, ability or privacy.
	 * @return int Sessions ended.
	 */
	public function sign_out_everywhere( int $user_id, string $context ): int {
		$count = $this->count( $user_id );
		$this->save( $user_id, array() );

		/**
		 * Fires after every session of a user was ended.
		 *
		 * @param int    $user_id The user.
		 * @param int    $count   Sessions that were active.
		 * @param string $context profile, admin, bulk, cli, ability or privacy.
		 */
		do_action( 'user_session_manager_user_signed_out_everywhere', $user_id, $count, $context );

		return $count;
	}

	/**
	 * Whether a session has sat unused for longer than the idle timeout.
	 *
	 * Sessions from before the plugin was active have no "last active" time and
	 * count as active until their next request records one.
	 *
	 * @param array $session Session data.
	 * @param int   $minutes Idle timeout. 0 means never.
	 * @param int   $now     Current time.
	 * @return bool
	 */
	public static function is_idle( array $session, int $minutes, int $now ): bool {
		return $minutes > 0 && ! empty( $session['last_seen'] ) && $now - (int) $session['last_seen'] > $minutes * MINUTE_IN_SECONDS;
	}

	/**
	 * The IP address to store for a session.
	 *
	 * @param string $ip      Full address.
	 * @param int    $user_id User.
	 * @return string
	 */
	public function storable_ip( string $ip, int $user_id ): string {
		if ( '' === $ip || ! $this->settings->mask_ip( $ip, $user_id ) ) {
			return $ip;
		}

		return wp_privacy_anonymize_ip( $ip );
	}

	/**
	 * A session as the screens, WP-CLI and abilities show it. Never the verifier.
	 *
	 * @param int    $user_id  User ID.
	 * @param string $verifier Verifier.
	 * @param array  $session  Session data.
	 * @return array{handle: string, user_id: int, user_login: string, ip: string, ua: string, device: string, device_type: string, login: int, last_seen: int, expiration: int, current: bool}
	 */
	public function describe( int $user_id, string $verifier, array $session ): array {
		$ua     = isset( $session['ua'] ) && is_string( $session['ua'] ) ? $session['ua'] : '';
		$parsed = UserAgent::parse( $ua );
		$user   = get_userdata( $user_id );

		return array(
			'handle'      => $this->handle( $user_id, $verifier ),
			'user_id'     => $user_id,
			'user_login'  => $user ? (string) $user->user_login : '',
			'ip'          => isset( $session['ip'] ) && is_string( $session['ip'] ) ? $session['ip'] : '',
			'ua'          => $ua,
			'device'      => $parsed['label'],
			'device_type' => $parsed['device'],
			'login'       => (int) ( $session['login'] ?? 0 ),
			'last_seen'   => (int) ( $session['last_seen'] ?? 0 ),
			'expiration'  => (int) ( $session['expiration'] ?? 0 ),
			'current'     => get_current_user_id() === $user_id && $this->current_verifier() === $verifier,
		);
	}

	/**
	 * A user's sessions, described, most recently active first.
	 *
	 * @param int $user_id User ID.
	 * @return array[]
	 */
	public function list_for_user( int $user_id ): array {
		$rows = array();
		foreach ( $this->for_user( $user_id ) as $verifier => $session ) {
			$rows[] = $this->describe( $user_id, (string) $verifier, $session );
		}

		return self::sort( $rows, 'last_seen', 'desc' );
	}

	/**
	 * Sessions across users, for the Sessions screen, WP-CLI and abilities.
	 *
	 * Sessions live inside user meta, so this reads every signed-in user's
	 * sessions, BATCH users at a time, then sorts and pages them in PHP. A site
	 * with more signed-in users than the scan limit only sees the first ones;
	 * narrow it down with a role or a search.
	 *
	 * @param array $args {
	 *     Query arguments.
	 *
	 *     @type string $search   User login, email or name, or the start of an IP address.
	 *     @type string $role     Only users with this role on the current site.
	 *     @type int    $user_id  Only this user.
	 *     @type string $orderby  user, login, last_seen or expiration. Default last_seen.
	 *     @type string $order    asc or desc. Default desc.
	 *     @type int    $paged    Page, from 1.
	 *     @type int    $per_page Rows per page. 0 returns every row.
	 * }
	 * @return array{total: int, users: int, rows: array[], truncated: bool}
	 */
	public function query( array $args = array() ): array {
		$args = array_merge(
			array(
				'search'   => '',
				'role'     => '',
				'user_id'  => 0,
				'orderby'  => 'last_seen',
				'order'    => 'desc',
				'paged'    => 1,
				'per_page' => 0,
			),
			$args
		);

		$search    = trim( (string) $args['search'] );
		$ip_search = '' !== $search && (bool) preg_match( '/^[0-9a-f.:]+$/i', $search ) && (bool) preg_match( '/[.:]/', $search );
		$user_args = array();
		if ( '' !== $search && ! $ip_search ) {
			$user_args['search']         = '*' . $search . '*';
			$user_args['search_columns'] = array( 'user_login', 'user_email', 'user_nicename', 'display_name' );
		}
		if ( '' !== (string) $args['role'] ) {
			$user_args['role'] = (string) $args['role'];
		}
		if ( (int) $args['user_id'] > 0 ) {
			$user_args['include'] = array( (int) $args['user_id'] );
		}

		$rows    = array();
		$users   = 0;
		$scanned = $this->scan_users(
			$user_args,
			function ( int $user_id ) use ( &$rows, &$users, $ip_search, $search ): void {
				$found = false;
				foreach ( $this->for_user( $user_id ) as $verifier => $session ) {
					$row = $this->describe( $user_id, (string) $verifier, $session );
					if ( $ip_search && 0 !== stripos( $row['ip'], $search ) ) {
						continue;
					}
					$rows[] = $row;
					$found  = true;
				}
				$users += $found ? 1 : 0;
			}
		);

		$rows     = self::sort( $rows, (string) $args['orderby'], (string) $args['order'] );
		$total    = count( $rows );
		$per_page = max( 0, (int) $args['per_page'] );
		if ( $per_page > 0 ) {
			$rows = array_slice( $rows, ( max( 1, (int) $args['paged'] ) - 1 ) * $per_page, $per_page );
		}

		return array(
			'total'     => $total,
			'users'     => $users,
			'rows'      => $rows,
			'truncated' => $scanned >= $this->max_users(),
		);
	}

	/**
	 * Active sessions and signed-in users across the site, cached until sessions change.
	 *
	 * @return array{sessions: int, users: int}
	 */
	public function totals(): array {
		$cached = wp_cache_get( 'totals', self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$found  = $this->query();
		$totals = array(
			'sessions' => $found['total'],
			'users'    => $found['users'],
		);
		wp_cache_set( 'totals', $totals, self::CACHE_GROUP, HOUR_IN_SECONDS );

		return $totals;
	}

	/**
	 * Drops the cached totals when a user's sessions change.
	 *
	 * @param int|int[] $meta_id   Meta ID(s).
	 * @param int       $object_id User ID.
	 * @param string    $meta_key  Meta key.
	 */
	public function flush_totals( $meta_id, $object_id, $meta_key ): void {
		if ( self::META === $meta_key ) {
			wp_cache_delete( 'totals', self::CACHE_GROUP );
		}
	}

	/**
	 * Calls back once per user who has sessions, BATCH users at a time.
	 *
	 * @param array    $user_args Extra WP_User_Query arguments.
	 * @param callable $callback  Receives the user ID.
	 * @param int      $after     Only users with a higher ID.
	 * @param int      $limit     Most users to visit. 0 for the scan limit.
	 * @return int Users visited.
	 */
	public function scan_users( array $user_args, callable $callback, int $after = 0, int $limit = 0 ): int {
		if ( ! $this->is_supported() ) {
			return 0;
		}

		$limit   = $limit > 0 ? $limit : $this->max_users();
		$visited = 0;

		while ( $visited < $limit ) {
			$ids = $this->user_ids_after( $user_args, $after, min( self::BATCH, $limit - $visited ) );
			if ( ! $ids ) {
				break;
			}

			cache_users( $ids );
			foreach ( $ids as $user_id ) {
				$callback( $user_id );
			}

			$visited += count( $ids );
			$after    = (int) end( $ids );
			if ( count( $ids ) < self::BATCH ) {
				break;
			}
		}

		return $visited;
	}

	/**
	 * Most signed-in users one listing reads.
	 *
	 * @return int
	 */
	public function max_users(): int {
		/**
		 * Filters how many signed-in users the Sessions screen, WP-CLI listing and Site Health read at most.
		 *
		 * @param int $max Default 10000.
		 */
		return max( 1, (int) apply_filters( 'user_session_manager_max_users_scanned', self::MAX_USERS ) );
	}

	/**
	 * IDs of users with stored sessions, in ID order.
	 *
	 * @param array $user_args Extra WP_User_Query arguments.
	 * @param int   $after     Only IDs above this one.
	 * @param int   $number    How many.
	 * @return int[]
	 */
	private function user_ids_after( array $user_args, int $after, int $number ): array {
		$args = array_merge(
			array(
				'fields'       => 'ID',
				'number'       => $number,
				'orderby'      => 'ID',
				'order'        => 'ASC',
				'count_total'  => false,
				// Sessions are stored for users who signed in, a small share of most user tables.
				'meta_key'     => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_compare' => 'EXISTS',
			),
			$user_args
		);
		if ( is_multisite() && ! isset( $args['role'] ) ) {
			// Sessions are network-wide, like users.
			$args['blog_id'] = 0;
		}

		$id_filter = static function ( \WP_User_Query $query ) use ( $after ): void {
			global $wpdb;
			// Only the users table has an ID column, so the bare name is not ambiguous.
			$query->query_where .= $wpdb->prepare( ' AND ID > %d', $after );
		};
		add_action( 'pre_user_query', $id_filter );
		$query = new \WP_User_Query( $args );
		remove_action( 'pre_user_query', $id_filter );

		return array_map( 'intval', (array) $query->get_results() );
	}

	/**
	 * Sorts described sessions.
	 *
	 * @param array[] $rows    Rows from describe().
	 * @param string  $orderby user, login, last_seen or expiration.
	 * @param string  $order   asc or desc.
	 * @return array[]
	 */
	public static function sort( array $rows, string $orderby, string $order ): array {
		$orderby = in_array( $orderby, array( 'user', 'login', 'last_seen', 'expiration' ), true ) ? $orderby : 'last_seen';
		$sign    = 'asc' === strtolower( $order ) ? 1 : -1;

		usort(
			$rows,
			static function ( array $a, array $b ) use ( $orderby, $sign ): int {
				if ( 'user' === $orderby ) {
					$result = strcasecmp( $a['user_login'], $b['user_login'] );
				} else {
					// Sessions with no "last active" yet sort by their login time.
					$key_a  = 'last_seen' === $orderby && ! $a['last_seen'] ? $a['login'] : $a[ $orderby ];
					$key_b  = 'last_seen' === $orderby && ! $b['last_seen'] ? $b['login'] : $b[ $orderby ];
					$result = $key_a <=> $key_b;
				}

				return 0 !== $result ? $sign * $result : strcmp( $a['handle'], $b['handle'] );
			}
		);

		return $rows;
	}
}
