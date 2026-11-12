<?php
/**
 * Sign-in history: when and where an account signed in, and how each session ended.
 *
 * @package AryanX\Axle\UserSessionManager
 */

namespace AryanX\Axle\UserSessionManager;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps a short, capped list per user in one user meta row.
 *
 * Sessions are deleted when they end, so this is the only record of a session
 * after the fact. Each entry holds the session's handle (never its verifier),
 * the device label, the IP address as stored for the session (shortened when
 * that setting is on), when it started and last ran, and how it ended.
 *
 * The list never grows without bound: entries older than the retention
 * setting are dropped on every write and by the hourly clean-up, and no user
 * keeps more than the entry limit (100 by default). Nothing is written on
 * ordinary requests, only when a session starts or ends.
 *
 * A session that expired on its own fires no event. Its entry stays open, and
 * when the history is read an open entry whose session is gone and whose
 * expiry time has passed is shown as expired.
 */
final class History implements Service {

	public const META   = 'user_session_manager_history';
	public const CURSOR = 'user_session_manager_history_cursor';

	/**
	 * Why a session ended. `signed_out` is the person's own sign out.
	 */
	public const REASONS = array( 'signed_out', 'expired', 'idle', 'other_device', 'admin', 'limit', 'not_me', 'password', 'shared' );

	private const MAX_ENTRIES = 100;

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
	 * Verifier of the session being signed out, kept between core's two logout hooks.
	 *
	 * @var string
	 */
	private string $logging_out = '';

	/**
	 * Sets up the history.
	 *
	 * @param Settings $settings Saved settings.
	 * @param Sessions $sessions Session store.
	 */
	public function __construct( Settings $settings, Sessions $sessions ) {
		$this->settings = $settings;
		$this->sessions = $sessions;
	}

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_action( 'user_session_manager_session_started', array( $this, 'on_started' ), 5, 2 );
		add_action( 'user_session_manager_session_revoked', array( $this, 'on_revoked' ), 10, 3 );
		add_action( 'user_session_manager_idle_session_ended', array( $this, 'on_idle' ), 10, 2 );
		add_action( 'user_session_manager_session_evicted', array( $this, 'on_evicted' ), 10, 2 );
		add_action( 'user_session_manager_user_signed_out_everywhere', array( $this, 'on_signed_out_everywhere' ), 10, 3 );
		// Core destroys the session before it fires wp_logout, so the token is read first.
		add_action( 'clear_auth_cookie', array( $this, 'remember_logout' ) );
		add_action( 'wp_logout', array( $this, 'on_logout' ) );
		add_action( 'after_password_reset', array( $this, 'on_password_reset' ) );
		add_action( 'profile_update', array( $this, 'on_profile_update' ), 10, 2 );
	}

	/**
	 * Days history is kept. 0 means none.
	 *
	 * @return int
	 */
	public function days(): int {
		return $this->settings->history_days();
	}

	/**
	 * Whether history is kept.
	 *
	 * @return bool
	 */
	public function enabled(): bool {
		return $this->settings->history_days() > 0;
	}

	/**
	 * Most entries kept per user.
	 *
	 * @return int
	 */
	public function limit(): int {
		/**
		 * Filters how many sign-ins are kept per user.
		 *
		 * @param int $limit Default 100. Kept between 10 and 500.
		 */
		return max( 10, min( 500, (int) apply_filters( 'user_session_manager_history_limit', self::MAX_ENTRIES ) ) );
	}

	/**
	 * The stored entries of a user, newest first.
	 *
	 * @param int $user_id User ID.
	 * @return array[]
	 */
	public function entries( int $user_id ): array {
		$stored = get_user_meta( $user_id, self::META, true );

		return is_array( $stored ) ? array_values( array_filter( $stored, 'is_array' ) ) : array();
	}

	/**
	 * One entry by session handle.
	 *
	 * @param int    $user_id User ID.
	 * @param string $handle  Session handle.
	 * @return array|null
	 */
	public function find( int $user_id, string $handle ): ?array {
		foreach ( $this->entries( $user_id ) as $entry ) {
			if ( isset( $entry['handle'] ) && hash_equals( (string) $entry['handle'], $handle ) ) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * A new session starts an entry.
	 *
	 * @param mixed $user    The user, a WP_User.
	 * @param array $session The new session, with its handle.
	 */
	public function on_started( $user, $session = array() ): void {
		if ( ! $user instanceof \WP_User || ! $this->enabled() || empty( $session['handle'] ) ) {
			return;
		}

		$entries = $this->entries( $user->ID );
		array_unshift( $entries, $this->entry_from( (array) $session ) );
		$this->save( $user->ID, $entries );
	}

	/**
	 * One session was signed out.
	 *
	 * @param int    $user_id User ID.
	 * @param array  $session Session with its handle.
	 * @param string $context Where it was ended.
	 */
	public function on_revoked( $user_id, $session, $context = '' ): void {
		$ending = self::reason_for( (int) $user_id, (string) $context );
		$this->end( (int) $user_id, (array) $session, $ending['reason'], $ending['by'] );
	}

	/**
	 * A session sat idle for too long.
	 *
	 * @param int   $user_id User ID.
	 * @param array $session Session with its handle.
	 */
	public function on_idle( $user_id, $session ): void {
		$this->end( (int) $user_id, (array) $session, 'idle', 0 );
	}

	/**
	 * A session made room for a new login.
	 *
	 * @param int   $user_id User ID.
	 * @param array $evicted Session with its handle.
	 */
	public function on_evicted( $user_id, $evicted ): void {
		$this->end( (int) $user_id, (array) $evicted, 'limit', 0 );
	}

	/**
	 * Every session of a user ended.
	 *
	 * @param int    $user_id User ID.
	 * @param int    $count   Sessions that were active.
	 * @param string $context Where it was done.
	 */
	public function on_signed_out_everywhere( $user_id, $count = 0, $context = '' ): void {
		$ending = self::reason_for( (int) $user_id, (string) $context );
		$this->end_all( (int) $user_id, $ending['reason'], $ending['by'] );
	}

	/**
	 * Notes the session that is about to lose its cookie.
	 */
	public function remember_logout(): void {
		$this->logging_out = $this->sessions->current_verifier();
	}

	/**
	 * The person signed out.
	 *
	 * @param int $user_id User ID, from WordPress 5.5.
	 */
	public function on_logout( $user_id = 0 ): void {
		$user_id           = (int) $user_id > 0 ? (int) $user_id : get_current_user_id();
		$verifier          = $this->logging_out;
		$this->logging_out = '';
		if ( $user_id < 1 || '' === $verifier ) {
			return;
		}

		$this->end( $user_id, array( 'handle' => $this->sessions->handle( $user_id, $verifier ) ), 'signed_out', 0 );
	}

	/**
	 * A password reset signs everyone out.
	 *
	 * @param mixed $user The user, a WP_User.
	 */
	public function on_password_reset( $user ): void {
		if ( $user instanceof \WP_User ) {
			$this->end_all( $user->ID, 'password', 0 );
		}
	}

	/**
	 * A changed password signs every session out.
	 *
	 * @param int   $user_id  User ID.
	 * @param mixed $old_user User before the update, a WP_User.
	 */
	public function on_profile_update( $user_id, $old_user = null ): void {
		$user = get_userdata( (int) $user_id );
		if ( $user && $old_user instanceof \WP_User && $user->user_pass !== $old_user->user_pass ) {
			$this->end_all( (int) $user_id, 'password', 0 );
		}
	}

	/**
	 * Marks a session as ended.
	 *
	 * @param int    $user_id User ID.
	 * @param array  $session Session data with a handle. Used to add an entry when none was kept.
	 * @param string $reason  One of REASONS.
	 * @param int    $by      User who did it, or 0.
	 */
	public function end( int $user_id, array $session, string $reason, int $by ): void {
		if ( ! $this->enabled() || empty( $session['handle'] ) || ! in_array( $reason, self::REASONS, true ) ) {
			return;
		}

		$entries = $this->entries( $user_id );
		$now     = time();
		$index   = null;
		foreach ( $entries as $key => $entry ) {
			if ( isset( $entry['handle'] ) && hash_equals( (string) $entry['handle'], (string) $session['handle'] ) ) {
				$index = $key;
				break;
			}
		}

		if ( null === $index ) {
			// A session from before the history was on: build the entry from the session itself.
			if ( empty( $session['login'] ) ) {
				return;
			}
			array_unshift( $entries, $this->entry_from( $session ) );
			$index = 0;
		}

		if ( ! empty( $entries[ $index ]['end'] ) ) {
			return;
		}

		$entries[ $index ]['end']    = $now;
		$entries[ $index ]['reason'] = $reason;
		$entries[ $index ]['by']     = $by;
		if ( ! empty( $session['last_seen'] ) ) {
			$entries[ $index ]['seen'] = (int) $session['last_seen'];
		}
		$this->save( $user_id, $entries );

		/**
		 * Fires after the history recorded how a session ended.
		 *
		 * @param int    $user_id User the session belonged to.
		 * @param array  $entry   The entry: handle, start, seen, end, expires, reason, by, ip, device and type.
		 * @param string $reason  signed_out, expired, idle, other_device, admin, limit, not_me, password or shared.
		 */
		do_action( 'user_session_manager_history_session_ended', $user_id, $entries[ $index ], $reason );
	}

	/**
	 * Ends every open entry of a user.
	 *
	 * @param int    $user_id User ID.
	 * @param string $reason  One of REASONS.
	 * @param int    $by      User who did it, or 0.
	 */
	public function end_all( int $user_id, string $reason, int $by ): void {
		if ( ! $this->enabled() || ! in_array( $reason, self::REASONS, true ) ) {
			return;
		}

		$entries = $this->entries( $user_id );
		$changed = false;
		$now     = time();
		foreach ( $entries as $key => $entry ) {
			if ( empty( $entry['end'] ) && (int) ( $entry['expires'] ?? 0 ) >= $now ) {
				$entries[ $key ]['end']    = $now;
				$entries[ $key ]['reason'] = $reason;
				$entries[ $key ]['by']     = $by;
				$changed                   = true;
			}
		}

		if ( $changed ) {
			$this->save( $user_id, $entries );
		}
	}

	/**
	 * Why a session was ended, from where it was done.
	 *
	 * Ending your own session from another device is "signed out from another
	 * device". Anyone else doing it, or WP-CLI, is an administrator.
	 *
	 * @param int    $user_id User the session belonged to.
	 * @param string $context profile, admin, bulk, cli, ability, shared, not_me, incident or privacy.
	 * @return array{reason: string, by: int}
	 */
	public static function reason_for( int $user_id, string $context ): array {
		$current = get_current_user_id();
		if ( 'shared' === $context ) {
			return array(
				'reason' => 'shared',
				'by'     => 0,
			);
		}
		if ( 'not_me' === $context ) {
			return array(
				'reason' => 'not_me',
				'by'     => 0,
			);
		}
		if ( in_array( $context, array( 'profile', 'admin', 'bulk', 'ability' ), true ) && $current === $user_id ) {
			return array(
				'reason' => 'other_device',
				'by'     => 0,
			);
		}

		return array(
			'reason' => 'admin',
			'by'     => $current,
		);
	}

	/**
	 * A user's history for the profile and WP-CLI: every entry with its state worked out.
	 *
	 * Active sessions come first from the live session data, so "last active"
	 * is current. An open entry whose session is gone is expired when its time
	 * is up, and "ended" with no reason otherwise.
	 *
	 * @param int $user_id User ID.
	 * @param int $limit   Most rows. 0 for all.
	 * @return array[] Each with handle, state (active or ended), reason, by, start, seen, end, expires, ip, device and type.
	 */
	public function rows( int $user_id, int $limit = 0 ): array {
		$now   = time();
		$live  = array();
		$known = array();
		foreach ( $this->sessions->for_user( $user_id ) as $verifier => $session ) {
			$live[ $this->sessions->handle( $user_id, (string) $verifier ) ] = $session;
		}

		$rows = array();
		foreach ( $this->entries( $user_id ) as $entry ) {
			$handle  = (string) ( $entry['handle'] ?? '' );
			$row     = $this->row_from( $entry );
			$known[] = $handle;
			if ( isset( $live[ $handle ] ) ) {
				$row['state'] = 'active';
				$row['seen']  = (int) ( $live[ $handle ]['last_seen'] ?? $row['seen'] );
				$row['end']   = 0;
			} elseif ( '' === $row['reason'] ) {
				$row['state']  = 'ended';
				$row['reason'] = $now >= $row['expires'] ? 'expired' : 'unknown';
				$row['end']    = $now >= $row['expires'] ? $row['expires'] : 0;
			}
			$rows[] = $row;
		}

		// Sessions from before the history was on still show while they are active.
		foreach ( $live as $handle => $session ) {
			if ( ! in_array( $handle, $known, true ) ) {
				$row          = $this->row_from( $this->entry_from( $session + array( 'handle' => $handle ) ) );
				$row['state'] = 'active';
				$rows[]       = $row;
			}
		}

		usort(
			$rows,
			static function ( array $a, array $b ): int {
				return $b['start'] <=> $a['start'];
			}
		);

		return $limit > 0 ? array_slice( $rows, 0, $limit ) : $rows;
	}

	/**
	 * The words for how a session ended.
	 *
	 * @param string $reason One of REASONS, or unknown.
	 * @return string
	 */
	public static function reason_label( string $reason ): string {
		$labels = array(
			'signed_out'   => __( 'Signed out', 'user-session-manager' ),
			'expired'      => __( 'Expired', 'user-session-manager' ),
			'idle'         => __( 'Idle timeout', 'user-session-manager' ),
			'other_device' => __( 'Signed out from another device', 'user-session-manager' ),
			'admin'        => __( 'Signed out by an administrator', 'user-session-manager' ),
			'limit'        => __( 'Device limit reached', 'user-session-manager' ),
			'not_me'       => __( 'Reported as "This wasn\'t me"', 'user-session-manager' ),
			'password'     => __( 'Password changed', 'user-session-manager' ),
			'shared'       => __( 'Account looked shared', 'user-session-manager' ),
			'unknown'      => __( 'Ended, reason not recorded', 'user-session-manager' ),
		);
		$label  = $labels[ $reason ] ?? $labels['unknown'];

		/**
		 * Filters the words shown for how a session ended.
		 *
		 * @param string $label  The label.
		 * @param string $reason signed_out, expired, idle, other_device, admin, limit, not_me, password, shared or unknown.
		 */
		$filtered = (string) apply_filters( 'user_session_manager_history_reason_label', $label, $reason );

		return '' !== trim( $filtered ) ? $filtered : $label;
	}

	/**
	 * The tone of an ending, for the badge: good, warning, error or neutral.
	 *
	 * @param string $reason One of REASONS, or unknown.
	 * @return string
	 */
	public static function reason_tone( string $reason ): string {
		if ( 'not_me' === $reason ) {
			return 'error';
		}
		if ( in_array( $reason, array( 'admin', 'password', 'shared', 'limit', 'other_device' ), true ) ) {
			return 'warning';
		}

		return 'neutral';
	}

	/**
	 * Drops entries past the retention time, for one user.
	 *
	 * @param int $user_id User ID.
	 * @return int Entries removed.
	 */
	public function prune_user( int $user_id ): int {
		$entries = $this->entries( $user_id );
		if ( ! $entries ) {
			return 0;
		}

		$kept = $this->trim( $entries );
		if ( count( $kept ) === count( $entries ) ) {
			return 0;
		}

		$this->write( $user_id, $kept );

		return count( $entries ) - count( $kept );
	}

	/**
	 * Cleans one batch of users who have a history, for the hourly clean-up.
	 *
	 * @param int $after Start after this user ID.
	 * @param int $limit Users in the batch.
	 * @return array{users: int, removed: int, last: int, done: bool}
	 */
	public function prune( int $after, int $limit ): array {
		$result = array(
			'users'   => 0,
			'removed' => 0,
			'last'    => $after,
			'done'    => true,
		);

		$ids = $this->sessions->user_ids_after(
			array(
				'meta_key'     => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_compare' => 'EXISTS',
			),
			$after,
			$limit
		);

		foreach ( $ids as $user_id ) {
			$result['last']     = $user_id;
			$result['removed'] += $this->prune_user( $user_id );
			++$result['users'];
		}
		$result['done'] = count( $ids ) < $limit;

		return $result;
	}

	/**
	 * Forgets a user's history.
	 *
	 * @param int $user_id User ID.
	 * @return bool Whether there was anything to forget.
	 */
	public function forget( int $user_id ): bool {
		$had = is_array( get_user_meta( $user_id, self::META, true ) );
		delete_user_meta( $user_id, self::META );

		return $had;
	}

	/**
	 * A new entry from a session.
	 *
	 * @param array $session Session data with a handle.
	 * @return array
	 */
	private function entry_from( array $session ): array {
		$ua     = isset( $session['ua'] ) && is_string( $session['ua'] ) ? $session['ua'] : '';
		$parsed = UserAgent::parse( $ua );
		$user   = (int) strtok( (string) ( $session['handle'] ?? '' ), '-' );

		return array(
			'handle'  => (string) ( $session['handle'] ?? '' ),
			'start'   => (int) ( $session['login'] ?? time() ),
			'seen'    => (int) ( $session['last_seen'] ?? $session['login'] ?? time() ),
			'end'     => 0,
			'expires' => (int) ( $session['expiration'] ?? 0 ),
			'reason'  => '',
			'by'      => 0,
			'ip'      => $this->sessions->storable_ip( isset( $session['ip'] ) && is_string( $session['ip'] ) ? $session['ip'] : '', $user ),
			'device'  => $parsed['label'],
			'type'    => $parsed['device'],
		);
	}

	/**
	 * A stored entry with every field present.
	 *
	 * @param array $entry Stored entry.
	 * @return array
	 */
	private function row_from( array $entry ): array {
		return array(
			'handle'  => (string) ( $entry['handle'] ?? '' ),
			'state'   => ! empty( $entry['end'] ) ? 'ended' : 'active',
			'reason'  => in_array( $entry['reason'] ?? '', self::REASONS, true ) ? (string) $entry['reason'] : '',
			'by'      => (int) ( $entry['by'] ?? 0 ),
			'start'   => (int) ( $entry['start'] ?? 0 ),
			'seen'    => (int) ( $entry['seen'] ?? 0 ),
			'end'     => (int) ( $entry['end'] ?? 0 ),
			'expires' => (int) ( $entry['expires'] ?? 0 ),
			'ip'      => (string) ( $entry['ip'] ?? '' ),
			'device'  => (string) ( $entry['device'] ?? '' ),
			'type'    => (string) ( $entry['type'] ?? 'unknown' ),
		);
	}

	/**
	 * Applies the retention time and the entry limit.
	 *
	 * @param array[] $entries Entries, newest first.
	 * @return array[]
	 */
	private function trim( array $entries ): array {
		if ( ! $this->enabled() ) {
			return array();
		}

		$cutoff = time() - $this->settings->history_days() * DAY_IN_SECONDS;
		$kept   = array();
		foreach ( $entries as $entry ) {
			$last = (int) ( $entry['end'] ?? 0 ) > 0 ? (int) $entry['end'] : max( (int) ( $entry['seen'] ?? 0 ), (int) ( $entry['start'] ?? 0 ), (int) ( $entry['expires'] ?? 0 ) );
			if ( $last >= $cutoff ) {
				$kept[] = $entry;
			}
		}

		return array_slice( $kept, 0, $this->limit() );
	}

	/**
	 * Saves a user's entries, trimmed.
	 *
	 * @param int     $user_id User ID.
	 * @param array[] $entries Entries, newest first.
	 */
	private function save( int $user_id, array $entries ): void {
		$this->write( $user_id, $this->trim( $entries ) );
	}

	/**
	 * Writes the list, or removes the meta when it is empty.
	 *
	 * @param int     $user_id User ID.
	 * @param array[] $entries Entries.
	 */
	private function write( int $user_id, array $entries ): void {
		if ( $entries ) {
			update_user_meta( $user_id, self::META, array_values( $entries ) );
		} else {
			delete_user_meta( $user_id, self::META );
		}
	}
}
