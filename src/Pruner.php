<?php
/**
 * The hourly clean-up.
 *
 * @package AryanX\UserSessionManager
 */

namespace AryanX\UserSessionManager;

defined( 'ABSPATH' ) || exit;

/**
 * Drops expired sessions and signs out idle ones, a batch of users at a time.
 *
 * WordPress only removes an expired session the next time its user signs in,
 * so the meta of users who never come back keeps them forever. The hourly
 * event works through every user with sessions, 500 at a time, picking up
 * where the last run stopped.
 */
final class Pruner implements Service {

	public const HOOK   = 'user_session_manager_prune';
	public const CURSOR = 'user_session_manager_prune_cursor';

	private const BATCH = 500;

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
	 * Sets up the clean-up.
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
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'admin_init', array( $this, 'schedule' ) );
	}

	/**
	 * Keeps the hourly event scheduled. Sessions are network-wide, so on multisite only the main site runs it.
	 */
	public function schedule(): void {
		if ( is_main_site() && ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::HOOK );
		}
	}

	/**
	 * Cron callback: one batch, then remembers where to carry on.
	 */
	public function run(): void {
		$result = $this->prune( (int) get_site_option( self::CURSOR, 0 ), $this->batch_size() );
		update_site_option( self::CURSOR, $result['done'] ? 0 : $result['last'] );
	}

	/**
	 * Every batch, for WP-CLI.
	 *
	 * @return array{users: int, expired: int, idle: int}
	 */
	public function prune_all(): array {
		$totals = array(
			'users'   => 0,
			'expired' => 0,
			'idle'    => 0,
		);
		$after  = 0;

		do {
			$result = $this->prune( $after, $this->batch_size() );
			foreach ( $totals as $key => $value ) {
				$totals[ $key ] = $value + $result[ $key ];
			}
			$after = $result['last'];
		} while ( ! $result['done'] );

		return $totals;
	}

	/**
	 * Cleans one batch of users.
	 *
	 * @param int $after Start after this user ID.
	 * @param int $limit Users in the batch.
	 * @return array{users: int, expired: int, idle: int, last: int, done: bool}
	 */
	public function prune( int $after, int $limit ): array {
		$result = array(
			'users'   => 0,
			'expired' => 0,
			'idle'    => 0,
			'last'    => $after,
			'done'    => true,
		);
		$now    = time();

		$visited = $this->sessions->scan_users(
			array(),
			function ( int $user_id ) use ( &$result, $now ): void {
				$result['last'] = $user_id;
				++$result['users'];

				$stored   = get_user_meta( $user_id, Sessions::META, true );
				$sessions = $this->sessions->for_user( $user_id );
				$expired  = is_array( $stored ) ? count( $stored ) - count( $sessions ) : 0;
				$user     = get_userdata( $user_id );
				$minutes  = $user ? $this->settings->idle_timeout( $user ) : 0;
				$idle     = array();

				foreach ( $sessions as $verifier => $session ) {
					if ( Sessions::is_idle( $session, $minutes, $now ) ) {
						$idle[ $verifier ] = $session;
						unset( $sessions[ $verifier ] );
					}
				}

				if ( $expired > 0 || $idle ) {
					$this->sessions->save( $user_id, $sessions );
				}
				foreach ( $idle as $verifier => $session ) {
					Activity::announce_idle( $user_id, $session + array( 'handle' => $this->sessions->handle( $user_id, (string) $verifier ) ), 'prune' );
				}

				$result['expired'] += $expired;
				$result['idle']    += count( $idle );
			},
			$after,
			$limit
		);

		$result['done'] = $visited < $limit;

		/**
		 * Fires after a batch of users was cleaned.
		 *
		 * @param array $result users (looked at), expired and idle (sessions removed), last (user ID) and done.
		 */
		do_action( 'user_session_manager_pruned', $result );

		return $result;
	}

	/**
	 * Users one cron run looks at.
	 *
	 * @return int
	 */
	private function batch_size(): int {
		/**
		 * Filters how many users one run of the hourly clean-up looks at.
		 *
		 * @param int $size Default 500.
		 */
		return max( 1, min( 5000, (int) apply_filters( 'user_session_manager_prune_batch', self::BATCH ) ) );
	}
}
