<?php
/**
 * Abilities: sessions for AI agents and other Abilities API clients.
 *
 * @package AryanX\Axle\UserSessionManager
 */

namespace AryanX\Axle\UserSessionManager;

defined( 'ABSPATH' ) || exit;

/**
 * Registers User Session Manager's abilities on WordPress 6.9 and later.
 *
 * Useful for an agent handling "I lost my phone" or a suspected break-in:
 * list where the account is signed in, then sign out one device or all of them.
 */
final class Abilities implements Service {

	public const CATEGORY = 'user-session-manager';

	private const LIMIT = 50;

	/**
	 * Session store.
	 *
	 * @var Sessions
	 */
	private Sessions $sessions;

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
	 * Sets up the abilities.
	 *
	 * @param Sessions     $sessions Session store.
	 * @param SharedLogins $shared   Shared login detection.
	 * @param History      $history  Sign-in history.
	 * @param NotMe        $not_me   "This wasn't me" reports.
	 * @param Incident     $incident "Sign everyone out".
	 */
	public function __construct( Sessions $sessions, SharedLogins $shared, History $history, NotMe $not_me, Incident $incident ) {
		$this->sessions = $sessions;
		$this->shared   = $shared;
		$this->history  = $history;
		$this->not_me   = $not_me;
		$this->incident = $incident;
	}

	/**
	 * Hooks into the Abilities API when WordPress has it.
	 */
	public function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Registers the category.
	 */
	public function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'User Session Manager', 'user-session-manager' ),
				'description' => __( 'Where users are signed in, which accounts look shared, and signing out one device or all of them.', 'user-session-manager' ),
			)
		);
	}

	/**
	 * Registers the abilities.
	 */
	public function register_abilities(): void {
		$user_field = array(
			'type'        => array( 'integer', 'string' ),
			'description' => 'User ID, login or email address.',
		);
		$session    = array(
			'type'       => 'object',
			'properties' => array(
				'handle'      => array(
					'type'        => 'string',
					'description' => 'Pass this to revoke-session.',
				),
				'user_id'     => array( 'type' => 'integer' ),
				'user_login'  => array( 'type' => 'string' ),
				'device'      => array(
					'type'        => 'string',
					'description' => 'Browser and system, such as "Safari on iOS".',
				),
				'device_type' => array(
					'type' => 'string',
					'enum' => array( 'desktop', 'mobile', 'tablet', 'bot', 'unknown' ),
				),
				'ip'          => array( 'type' => 'string' ),
				'signed_in'   => array( 'type' => 'string' ),
				'last_active' => array(
					'type'        => 'string',
					'description' => 'Empty when not recorded yet.',
				),
				'expires'     => array( 'type' => 'string' ),
				'current'     => array(
					'type'        => 'boolean',
					'description' => 'The session this request was made with.',
				),
			),
		);

		$this->ability(
			'list-sessions',
			__( 'List sessions', 'user-session-manager' ),
			__( 'Where a user is signed in: device, IP address, when each session started, was last active and expires. Without input, the current user\'s own sessions. With user, that user\'s; with all, everyone\'s. Both need permission to manage users.', 'user-session-manager' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'user'  => $user_field,
					'all'   => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'limit' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
						'default' => self::LIMIT,
					),
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'total'    => array( 'type' => 'integer' ),
					'sessions' => array(
						'type'  => 'array',
						'items' => $session,
					),
				),
			),
			array( $this, 'list_sessions' ),
			array( $this, 'can_list' ),
			array(
				'readonly'   => true,
				'idempotent' => true,
			)
		);

		$this->ability(
			'revoke-session',
			__( 'Sign out a session', 'user-session-manager' ),
			__( 'Signs out one session by its handle from list-sessions. That browser is logged out the next time it loads a page. Signing out a session that already ended does nothing.', 'user-session-manager' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'handle' => array(
						'type'    => 'string',
						'pattern' => '^[0-9]+-[0-9a-f]{24}$',
					),
				),
				'required'             => array( 'handle' ),
				'additionalProperties' => false,
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'revoked' => array(
						'type'        => 'boolean',
						'description' => 'False when the session had already ended.',
					),
				),
			),
			array( $this, 'revoke_session' ),
			array( $this, 'can_revoke' ),
			array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => true,
			)
		);

		$this->ability(
			'sign-out-user',
			__( 'Sign a user out everywhere', 'user-session-manager' ),
			__( 'Ends every session of a user, on every device. Use it after a lost device or a suspected break-in, together with a password change.', 'user-session-manager' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'user' => $user_field,
				),
				'required'             => array( 'user' ),
				'additionalProperties' => false,
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'sessions_ended' => array( 'type' => 'integer' ),
				),
			),
			array( $this, 'sign_out_user' ),
			array( $this, 'can_sign_out' ),
			array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => true,
			)
		);

		$this->ability(
			'list-shared-logins',
			__( 'List accounts that look shared', 'user-session-manager' ),
			__( 'Accounts whose sessions look like more than one person, such as two computers on different networks at the same time, with the reasons and the sessions behind them. Without input, every flagged account, which needs permission to manage users. With user, only that user, which also works for your own account. A guess, not proof.', 'user-session-manager' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'user'  => $user_field,
					'limit' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
						'default' => self::LIMIT,
					),
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'total'    => array( 'type' => 'integer' ),
					'accounts' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'user_id'       => array( 'type' => 'integer' ),
								'user_login'    => array( 'type' => 'string' ),
								'first_noticed' => array( 'type' => 'string' ),
								'last_seen'     => array( 'type' => 'string' ),
								'action'        => array(
									'type'        => 'string',
									'enum'        => SharedLogins::ACTIONS,
									'description' => 'What was done: flag only, notify (emails sent) or sign_out_others.',
								),
								'signed_out'    => array( 'type' => 'integer' ),
								'evidence'      => array(
									'type'  => 'array',
									'items' => array(
										'type'       => 'object',
										'properties' => array(
											'type'    => array(
												'type' => 'string',
												'enum' => array( 'networks', 'countries', 'same_type', 'devices' ),
											),
											'summary' => array( 'type' => 'string' ),
											'handles' => array(
												'type'  => 'array',
												'items' => array( 'type' => 'string' ),
											),
										),
									),
								),
								'sessions'      => array(
									'type'  => 'array',
									'items' => array(
										'type'       => 'object',
										'properties' => array(
											'handle'      => array(
												'type' => 'string',
												'description' => 'Pass this to revoke-session while the session is active.',
											),
											'device'      => array( 'type' => 'string' ),
											'device_type' => array( 'type' => 'string' ),
											'ip'          => array( 'type' => 'string' ),
											'country'     => array( 'type' => 'string' ),
											'signed_in'   => array( 'type' => 'string' ),
											'last_active' => array( 'type' => 'string' ),
										),
									),
								),
							),
						),
					),
				),
			),
			array( $this, 'list_shared_logins' ),
			array( $this, 'can_list_shared' ),
			array(
				'readonly'   => true,
				'idempotent' => true,
			)
		);

		$this->ability(
			'list-sign-in-history',
			__( 'List sign-in history', 'user-session-manager' ),
			__( 'When and where an account signed in over the retention time (90 days by default), and how each session ended: signed out, expired, idle, signed out from another device, signed out by an administrator, device limit reached, reported as "This wasn\'t me", or password changed. Without input, the current user. With user, that user, which needs permission to edit them.', 'user-session-manager' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'user'  => $user_field,
					'limit' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 200,
						'default' => 50,
					),
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'total'   => array( 'type' => 'integer' ),
					'entries' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'device'      => array( 'type' => 'string' ),
								'ip'          => array( 'type' => 'string' ),
								'signed_in'   => array( 'type' => 'string' ),
								'last_active' => array( 'type' => 'string' ),
								'ended'       => array(
									'type'        => 'string',
									'description' => 'Empty while the session is active.',
								),
								'state'       => array(
									'type' => 'string',
									'enum' => array( 'active', 'ended' ),
								),
								'reason'      => array(
									'type'        => 'string',
									'enum'        => array_merge( array( '', 'unknown' ), History::REASONS ),
									'description' => 'How the session ended. Empty while it is active.',
								),
							),
						),
					),
				),
			),
			array( $this, 'list_history' ),
			array( $this, 'can_list_history' ),
			array(
				'readonly'   => true,
				'idempotent' => true,
			)
		);

		$this->ability(
			'list-not-me-reports',
			__( 'List "This wasn\'t me" reports', 'user-session-manager' ),
			__( 'Sign-ins that the owner of an account reported as not them, newest first: who, which device and IP address, when, and how many sessions were signed out. Needs permission to manage users. Only people you may edit are listed.', 'user-session-manager' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'days' => array(
						'type'    => 'integer',
						'minimum' => 0,
						'maximum' => 365,
						'default' => 30,
					),
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'total'   => array( 'type' => 'integer' ),
					'reports' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'user_id'    => array( 'type' => 'integer' ),
								'user_login' => array( 'type' => 'string' ),
								'device'     => array( 'type' => 'string' ),
								'ip'         => array( 'type' => 'string' ),
								'reported'   => array( 'type' => 'string' ),
								'ended'      => array( 'type' => 'integer' ),
							),
						),
					),
				),
			),
			array( $this, 'list_reports' ),
			array( Access::class, 'can_view_all' ),
			array(
				'readonly'   => true,
				'idempotent' => true,
			)
		);

		$this->ability(
			'sign-out-everyone',
			__( 'Sign everyone out', 'user-session-manager' ),
			__( 'For an incident. Ends every session of every user you may edit, except your own when keep_current is true and the roles in exclude_roles. With require_new_passwords, every affected password also stops working, so each person has to use "Lost your password?" to choose a new one. Nobody is emailed. Your own password is never changed. It cannot be undone, and confirm must be true.', 'user-session-manager' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'confirm'               => array(
						'type'        => 'boolean',
						'description' => 'Must be true.',
					),
					'keep_current'          => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'exclude_roles'         => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'require_new_passwords' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
				'required'             => array( 'confirm' ),
				'additionalProperties' => false,
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'users_signed_out'  => array( 'type' => 'integer' ),
					'sessions_ended'    => array( 'type' => 'integer' ),
					'passwords_retired' => array( 'type' => 'integer' ),
					'excluded'          => array( 'type' => 'integer' ),
					'skipped'           => array( 'type' => 'integer' ),
				),
			),
			array( $this, 'sign_out_everyone' ),
			array( Access::class, 'can_sign_out_everyone' ),
			array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => false,
			)
		);
	}

	/**
	 * Permission: every flagged account with the capability, or one user the caller may manage.
	 *
	 * @param array|null $input Ability input.
	 * @return bool
	 */
	public function can_list_shared( $input = null ): bool {
		$input = (array) $input;

		return isset( $input['user'] ) ? Access::can_manage( self::user_id( $input['user'] ) ) : Access::can_view_all();
	}

	/**
	 * Ability: accounts that look shared.
	 *
	 * @param array|null $input Ability input.
	 * @return array
	 */
	public function list_shared_logins( $input = null ): array {
		$input = (array) $input;
		$limit = max( 1, min( 100, (int) ( $input['limit'] ?? self::LIMIT ) ) );
		if ( isset( $input['user'] ) ) {
			$user_id = self::user_id( $input['user'] );
			$flag    = $this->shared->get( $user_id );
			$flags   = $flag ? array( $user_id => $flag ) : array();
		} else {
			$flags = array_filter(
				$this->shared->flagged(),
				static function ( $flag, $user_id ): bool {
					return Access::can_manage( (int) $user_id );
				},
				ARRAY_FILTER_USE_BOTH
			);
		}

		$accounts = array();
		foreach ( array_slice( $flags, 0, $limit, true ) as $user_id => $flag ) {
			$user     = get_userdata( (int) $user_id );
			$sessions = array();
			foreach ( $flag['sessions'] as $handle => $session ) {
				$sessions[] = array(
					'handle'      => (string) $handle,
					'device'      => (string) ( $session['device'] ?? '' ),
					'device_type' => (string) ( $session['device_type'] ?? '' ),
					'ip'          => (string) ( $session['ip'] ?? '' ),
					'country'     => (string) ( $session['country'] ?? '' ),
					'signed_in'   => ! empty( $session['login'] ) ? gmdate( 'c', (int) $session['login'] ) : '',
					'last_active' => ! empty( $session['last_seen'] ) ? gmdate( 'c', (int) $session['last_seen'] ) : '',
				);
			}

			$accounts[] = array(
				'user_id'       => (int) $user_id,
				'user_login'    => $user ? (string) $user->user_login : '',
				'first_noticed' => gmdate( 'c', $flag['detected'] ),
				'last_seen'     => gmdate( 'c', $flag['updated'] ),
				'action'        => $flag['action'],
				'signed_out'    => $flag['signed_out'],
				'evidence'      => array_map(
					static function ( array $evidence ): array {
						return array(
							'type'    => (string) ( $evidence['type'] ?? '' ),
							'summary' => SharedLogins::evidence_text( $evidence ),
							'handles' => array_values( array_map( 'strval', (array) ( $evidence['handles'] ?? array() ) ) ),
						);
					},
					$flag['evidence']
				),
				'sessions'      => $sessions,
			);
		}

		return array(
			'total'    => count( $flags ),
			'accounts' => $accounts,
		);
	}

	/**
	 * Permission: the user is the current user, or one they may manage.
	 *
	 * @param array|null $input Ability input.
	 * @return bool
	 */
	public function can_list_history( $input = null ): bool {
		$input = (array) $input;

		return Access::can_manage( isset( $input['user'] ) ? self::user_id( $input['user'] ) : get_current_user_id() );
	}

	/**
	 * Ability: sign-in history.
	 *
	 * @param array|null $input Ability input.
	 * @return array
	 */
	public function list_history( $input = null ): array {
		$input   = (array) $input;
		$limit   = max( 1, min( 200, (int) ( $input['limit'] ?? 50 ) ) );
		$user_id = isset( $input['user'] ) ? self::user_id( $input['user'] ) : get_current_user_id();
		$rows    = $this->history->rows( $user_id );

		return array(
			'total'   => count( $rows ),
			'entries' => array_map(
				static function ( array $row ): array {
					return array(
						'device'      => $row['device'],
						'ip'          => $row['ip'],
						'signed_in'   => $row['start'] ? gmdate( 'c', $row['start'] ) : '',
						'last_active' => $row['seen'] ? gmdate( 'c', $row['seen'] ) : '',
						'ended'       => $row['end'] ? gmdate( 'c', $row['end'] ) : '',
						'state'       => $row['state'],
						'reason'      => 'active' === $row['state'] ? '' : ( '' !== $row['reason'] ? $row['reason'] : 'unknown' ),
					);
				},
				array_slice( $rows, 0, $limit )
			),
		);
	}

	/**
	 * Ability: "This wasn't me" reports for people the caller may manage.
	 *
	 * @param array|null $input Ability input.
	 * @return array
	 */
	public function list_reports( $input = null ): array {
		$days    = max( 0, min( 365, (int) ( ( (array) $input )['days'] ?? 30 ) ) );
		$reports = array();
		foreach ( $this->not_me->events( 0, $days ) as $event ) {
			if ( ! Access::can_manage( (int) $event['user'] ) ) {
				continue;
			}
			$user      = get_userdata( (int) $event['user'] );
			$reports[] = array(
				'user_id'    => (int) $event['user'],
				'user_login' => $user ? (string) $user->user_login : '',
				'device'     => (string) $event['device'],
				'ip'         => (string) $event['ip'],
				'reported'   => gmdate( 'c', (int) $event['time'] ),
				'ended'      => (int) $event['ended'],
			);
		}

		return array(
			'total'   => count( $reports ),
			'reports' => $reports,
		);
	}

	/**
	 * Ability: signs everyone out.
	 *
	 * @param array|null $input Ability input.
	 * @return array|\WP_Error
	 */
	public function sign_out_everyone( $input = null ) {
		$input = (array) $input;
		if ( true !== ( $input['confirm'] ?? false ) ) {
			return new \WP_Error( 'user_session_manager_not_confirmed', __( 'Set confirm to true to sign everyone out.', 'user-session-manager' ) );
		}

		$job = $this->incident->run_all(
			array(
				'keep_current'  => ! array_key_exists( 'keep_current', $input ) || (bool) $input['keep_current'],
				'exclude_roles' => (array) ( $input['exclude_roles'] ?? array() ),
				'passwords'     => ! empty( $input['require_new_passwords'] ),
				'via'           => 'ability',
			)
		);

		return array(
			'users_signed_out'  => (int) $job['users'],
			'sessions_ended'    => (int) $job['sessions'],
			'passwords_retired' => (int) $job['passwords_set'],
			'excluded'          => (int) $job['excluded'],
			'skipped'           => (int) $job['skipped'],
		);
	}

	/**
	 * Permission: own sessions, or someone else's or everyone's with the capability.
	 *
	 * @param array|null $input Ability input.
	 * @return bool
	 */
	public function can_list( $input = null ): bool {
		$input = (array) $input;
		if ( ! empty( $input['all'] ) ) {
			return Access::can_view_all();
		}

		return Access::can_manage( isset( $input['user'] ) ? self::user_id( $input['user'] ) : get_current_user_id() );
	}

	/**
	 * Permission: the session's user is the current user, or one they may manage.
	 *
	 * @param array|null $input Ability input.
	 * @return bool
	 */
	public function can_revoke( $input = null ): bool {
		$handle = (string) ( ( (array) $input )['handle'] ?? '' );

		return Access::can_manage( (int) strtok( $handle, '-' ) );
	}

	/**
	 * Permission: the user is the current user, or one they may manage.
	 *
	 * @param array|null $input Ability input.
	 * @return bool
	 */
	public function can_sign_out( $input = null ): bool {
		return Access::can_manage( self::user_id( ( (array) $input )['user'] ?? 0 ) );
	}

	/**
	 * Ability: sessions.
	 *
	 * @param array|null $input Ability input.
	 * @return array
	 */
	public function list_sessions( $input = null ): array {
		$input = (array) $input;
		$limit = max( 1, min( 100, (int) ( $input['limit'] ?? self::LIMIT ) ) );
		$args  = array(
			'per_page'      => $limit,
			'paged'         => 1,
			// Everyone's sessions means everyone the caller may edit.
			'editable_only' => true,
		);
		if ( empty( $input['all'] ) ) {
			$args['user_id'] = isset( $input['user'] ) ? self::user_id( $input['user'] ) : get_current_user_id();
		}

		$found = $this->sessions->query( $args );

		return array(
			'total'    => $found['total'],
			'sessions' => array_map( array( self::class, 'output' ), $found['rows'] ),
		);
	}

	/**
	 * Ability: signs out one session.
	 *
	 * @param array|null $input Ability input.
	 * @return array
	 */
	public function revoke_session( $input = null ): array {
		return array( 'revoked' => $this->sessions->revoke_handle( (string) ( ( (array) $input )['handle'] ?? '' ), 'ability' ) );
	}

	/**
	 * Ability: signs out a user everywhere.
	 *
	 * @param array|null $input Ability input.
	 * @return array|\WP_Error
	 */
	public function sign_out_user( $input = null ) {
		$user_id = self::user_id( ( (array) $input )['user'] ?? 0 );
		if ( $user_id < 1 ) {
			return new \WP_Error( 'user_session_manager_no_user', __( 'There is no such user.', 'user-session-manager' ) );
		}

		return array( 'sessions_ended' => $this->sessions->sign_out_everywhere( $user_id, 'ability' ) );
	}

	/**
	 * A described session as ability output.
	 *
	 * @param array $row Row from Sessions::describe().
	 * @return array
	 */
	public static function output( array $row ): array {
		return array(
			'handle'      => $row['handle'],
			'user_id'     => $row['user_id'],
			'user_login'  => $row['user_login'],
			'device'      => $row['device'],
			'device_type' => $row['device_type'],
			'ip'          => $row['ip'],
			'signed_in'   => $row['login'] ? gmdate( 'c', $row['login'] ) : '',
			'last_active' => $row['last_seen'] ? gmdate( 'c', $row['last_seen'] ) : '',
			'expires'     => gmdate( 'c', $row['expiration'] ),
			'current'     => $row['current'],
		);
	}

	/**
	 * User ID from an ID, login or email address.
	 *
	 * @param mixed $user Input.
	 * @return int 0 when there is no such user.
	 */
	public static function user_id( $user ): int {
		if ( is_int( $user ) || ( is_string( $user ) && ctype_digit( $user ) ) ) {
			$found = get_userdata( (int) $user );
		} elseif ( is_string( $user ) && '' !== $user ) {
			$found = get_user_by( is_email( $user ) ? 'email' : 'login', $user );
		} else {
			$found = false;
		}

		return $found ? (int) $found->ID : 0;
	}

	/**
	 * Registers one ability in the category.
	 *
	 * @param string   $name        Name without the namespace.
	 * @param string   $label       Label.
	 * @param string   $description What it does, for people and agents.
	 * @param array    $input       Input schema.
	 * @param array    $output      Output schema.
	 * @param callable $execute     Execute callback.
	 * @param callable $permission  Permission callback.
	 * @param array    $annotations readonly, destructive, idempotent.
	 */
	private function ability( string $name, string $label, string $description, array $input, array $output, callable $execute, callable $permission, array $annotations ): void {
		wp_register_ability(
			self::CATEGORY . '/' . $name,
			array(
				'label'               => $label,
				'description'         => $description,
				'category'            => self::CATEGORY,
				'input_schema'        => $input,
				'output_schema'       => $output,
				'execute_callback'    => $execute,
				'permission_callback' => $permission,
				'meta'                => array(
					'annotations'  => $annotations,
					'show_in_rest' => true,
					// Clients such as the MCP adapter only list abilities marked public:
					// `public` from WordPress 7.1, `mcp.public` for the adapter on 6.9 and 7.0.
					'public'       => true,
					'mcp'          => array( 'public' => true ),
				),
			)
		);
	}
}
