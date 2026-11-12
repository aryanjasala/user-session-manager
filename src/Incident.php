<?php
/**
 * "Sign everyone out": the one action for an incident.
 *
 * @package AryanX\Axle\UserSessionManager
 */

namespace AryanX\Axle\UserSessionManager;

defined( 'ABSPATH' ) || exit;

/**
 * Signs every user out, with exclusions, and can also retire every password.
 *
 * The work runs in slices. Each slice visits users for a few seconds, then
 * hands back the job with a cursor, so a site with thousands of users never
 * runs one long request. The Sessions screen carries on from a progress page,
 * WP-CLI and the ability loop until the job is done.
 *
 * The person who starts it is always left out of the password step, so they
 * cannot lock themselves out of the screen they are using. They stay signed in
 * too when they ask to keep their own session.
 */
final class Incident {

	public const LOG = 'user_session_manager_incidents';

	private const MAX_LOG = 20;
	private const SLICE   = 100;

	/**
	 * Session store.
	 *
	 * @var Sessions
	 */
	private Sessions $sessions;

	/**
	 * Sets up the action.
	 *
	 * @param Sessions $sessions Session store.
	 */
	public function __construct( Sessions $sessions ) {
		$this->sessions = $sessions;
	}

	/**
	 * The words a person types to confirm.
	 *
	 * @return string
	 */
	public static function confirm_word(): string {
		/**
		 * Filters the words typed to confirm "Sign everyone out".
		 *
		 * @param string $word Default SIGN OUT.
		 */
		$word = trim( (string) apply_filters( 'user_session_manager_incident_confirm_word', 'SIGN OUT' ) );

		return '' !== $word ? $word : 'SIGN OUT';
	}

	/**
	 * Starts a job.
	 *
	 * @param array $args {
	 *     Options.
	 *
	 *     @type bool     $keep_current  Keep the signed-in user's own sessions. Default true.
	 *     @type string[] $exclude_roles Roles whose users are left alone.
	 *     @type bool     $passwords     Also make every password stop working. Default false.
	 *     @type string   $via           screen, cli or ability.
	 *     @type bool     $check_caps    Check edit_user for each user. Default true; WP-CLI turns it off.
	 * }
	 * @return array The job to hand to run().
	 */
	public function start( array $args = array() ): array {
		$args = array_merge(
			array(
				'keep_current'  => true,
				'exclude_roles' => array(),
				'passwords'     => false,
				'via'           => 'screen',
				'check_caps'    => true,
			),
			$args
		);
		$by   = get_current_user_id();

		$job = array(
			'by'            => $by,
			'via'           => (string) $args['via'],
			'check_caps'    => (bool) $args['check_caps'],
			'keep'          => $args['keep_current'] && $by > 0 ? $by : 0,
			'exclude_roles' => array_values( array_filter( array_map( 'sanitize_key', (array) $args['exclude_roles'] ) ) ),
			'passwords'     => (bool) $args['passwords'],
			'after'         => 0,
			'visited'       => 0,
			'users'         => 0,
			'sessions'      => 0,
			'passwords_set' => 0,
			'skipped'       => 0,
			'excluded'      => 0,
			'started'       => time(),
			'done'          => false,
		);

		/**
		 * Fires when "Sign everyone out" starts.
		 *
		 * @param array $job The job: by, via, keep, exclude_roles and passwords.
		 */
		do_action( 'user_session_manager_incident_started', $job );

		return $job;
	}

	/**
	 * Works on a job for a few seconds.
	 *
	 * @param array $job    Job from start() or an earlier run().
	 * @param float $budget Seconds to work. 0 runs to the end.
	 * @return array The job, with `done` set when every user was visited.
	 */
	public function run( array $job, float $budget = 10.0 ): array {
		if ( ! empty( $job['done'] ) ) {
			return $job;
		}

		$began = microtime( true );
		while ( true ) {
			$ids = $this->sessions->user_ids_after(
				array( 'meta_key' => $job['passwords'] ? false : Sessions::META ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				(int) $job['after'],
				self::SLICE
			);
			if ( ! $ids ) {
				$job['done'] = true;
				break;
			}

			cache_users( $ids );
			foreach ( $ids as $user_id ) {
				$job = $this->visit( $job, (int) $user_id );
				if ( $budget > 0 && microtime( true ) - $began > $budget ) {
					$job['after'] = (int) $user_id;

					return $job;
				}
			}

			$job['after'] = (int) end( $ids );
			if ( count( $ids ) < self::SLICE ) {
				$job['done'] = true;
				break;
			}
			if ( $budget > 0 && microtime( true ) - $began > $budget ) {
				break;
			}
		}

		if ( ! empty( $job['done'] ) ) {
			$this->finish( $job );
		}

		return $job;
	}

	/**
	 * Runs a job to the end.
	 *
	 * @param array $args Options for start().
	 * @return array The finished job.
	 */
	public function run_all( array $args = array() ): array {
		return $this->run( $this->start( $args ), 0.0 );
	}

	/**
	 * Recent incidents, newest first.
	 *
	 * @return array[]
	 */
	public function log(): array {
		$log = is_multisite() ? get_site_option( self::LOG, array() ) : get_option( self::LOG, array() );

		return is_array( $log ) ? array_values( array_filter( $log, 'is_array' ) ) : array();
	}

	/**
	 * Does one user's part of a job.
	 *
	 * @param array $job     Job.
	 * @param int   $user_id User ID.
	 * @return array The job.
	 */
	private function visit( array $job, int $user_id ): array {
		++$job['visited'];
		$job['after'] = $user_id;
		$user         = get_userdata( $user_id );
		if ( ! $user ) {
			return $job;
		}

		if ( array_intersect( (array) $job['exclude_roles'], (array) $user->roles ) ) {
			++$job['excluded'];

			return $job;
		}
		if ( $job['check_caps'] && ! Access::can_manage( $user_id ) ) {
			++$job['skipped'];

			return $job;
		}

		$ended = 0;
		if ( $user_id !== (int) $job['keep'] ) {
			$ended = $this->sessions->count( $user_id ) > 0 ? $this->sessions->sign_out_everywhere( $user_id, 'incident' ) : 0;
		}
		if ( $ended > 0 ) {
			++$job['users'];
			$job['sessions'] += $ended;
		}

		if ( $job['passwords'] && $user_id !== (int) $job['by'] ) {
			NotMe::retire_password( $user_id );
			++$job['passwords_set'];
		}

		return $job;
	}

	/**
	 * Writes the log entry and announces the end.
	 *
	 * @param array $job The finished job.
	 */
	private function finish( array $job ): void {
		$entry = array(
			'time'          => time(),
			'by'            => (int) $job['by'],
			'via'           => (string) $job['via'],
			'users'         => (int) $job['users'],
			'sessions'      => (int) $job['sessions'],
			'passwords'     => (int) $job['passwords_set'],
			'excluded'      => (int) $job['excluded'],
			'skipped'       => (int) $job['skipped'],
			'exclude_roles' => $job['exclude_roles'],
			'kept_own'      => (int) $job['keep'] > 0,
		);

		$log = $this->log();
		array_unshift( $log, $entry );
		$log = array_slice( $log, 0, self::MAX_LOG );
		if ( is_multisite() ) {
			update_site_option( self::LOG, $log );
		} else {
			update_option( self::LOG, $log, false );
		}

		/**
		 * Fires when "Sign everyone out" has visited every user.
		 *
		 * @param array $entry time, by, via, users and sessions (signed out), passwords (made unusable), excluded, skipped, exclude_roles and kept_own.
		 * @param array $job   The finished job.
		 */
		do_action( 'user_session_manager_incident_finished', $entry, $job );
	}
}
