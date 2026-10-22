<?php
/**
 * Abilities: sessions for AI agents and other Abilities API clients.
 *
 * @package AryanX\UserSessionManager
 */

namespace AryanX\UserSessionManager;

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
	 * Sets up the abilities.
	 *
	 * @param Sessions $sessions Session store.
	 */
	public function __construct( Sessions $sessions ) {
		$this->sessions = $sessions;
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
				'description' => __( 'Where users are signed in, and signing out one device or all of them.', 'user-session-manager' ),
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
			'per_page' => $limit,
			'paged'    => 1,
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
