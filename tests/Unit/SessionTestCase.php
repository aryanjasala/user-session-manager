<?php
/**
 * Base class for tests that work on users and their sessions.
 *
 * @package AryanX\Axle\UserSessionManager
 */

namespace AryanX\Axle\UserSessionManager\Tests\Unit;

use AryanX\Axle\UserSessionManager\Sessions;
use AryanX\Axle\UserSessionManager\Settings;
use Brain\Monkey\Functions;

require_once dirname( __DIR__ ) . '/Stubs/sessions.php';

/**
 * Keeps user meta, users and network options in arrays, so the session code
 * runs against something that behaves like WordPress.
 */
abstract class SessionTestCase extends TestCase {

	/**
	 * User meta: user ID => key => value.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	protected $meta = array();

	/**
	 * Users by ID.
	 *
	 * @var array<int, \WP_User>
	 */
	protected $users = array();

	/**
	 * Current user ID.
	 *
	 * @var int
	 */
	protected $current_user = 0;

	/**
	 * Raw token of the current request's session.
	 *
	 * @var string
	 */
	protected $token = '';

	protected function setUp(): void {
		parent::setUp();

		$this->meta         = array();
		$this->users        = array();
		$this->current_user = 0;
		$this->token        = '';
		\WP_User_Query::$queue = array();
		\WP_User_Query::$made  = array();

		Functions\when( 'get_user_meta' )->alias(
			function ( $user_id, $key = '', $single = false ) {
				return $this->meta[ $user_id ][ $key ] ?? '';
			}
		);
		Functions\when( 'update_user_meta' )->alias(
			function ( $user_id, $key, $value ) {
				$this->meta[ $user_id ][ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_user_meta' )->alias(
			function ( $user_id, $key ) {
				unset( $this->meta[ $user_id ][ $key ] );
				return true;
			}
		);
		Functions\when( 'get_userdata' )->alias(
			function ( $user_id ) {
				return $this->users[ (int) $user_id ] ?? false;
			}
		);
		Functions\when( 'get_user_by' )->alias(
			function ( $field, $value ) {
				foreach ( $this->users as $user ) {
					if ( ( 'email' === $field && $user->user_email === $value ) || ( 'login' === $field && $user->user_login === $value ) ) {
						return $user;
					}
				}
				return false;
			}
		);
		Functions\when( 'get_site_option' )->alias(
			function ( $name, $default = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default;
			}
		);
		Functions\when( 'update_site_option' )->alias(
			function ( $name, $value ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'get_current_user_id' )->alias(
			function () {
				return $this->current_user;
			}
		);
		Functions\when( 'wp_get_session_token' )->alias(
			function () {
				return $this->token;
			}
		);
		Functions\when( 'wp_salt' )->justReturn( 'unit-test-salt' );
		Functions\when( 'sanitize_key' )->alias(
			static function ( $key ) {
				return strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) );
			}
		);
		Functions\when( 'wp_privacy_anonymize_ip' )->alias(
			static function ( $ip ) {
				return (string) preg_replace( '/\.\d+$/', '.0', $ip );
			}
		);
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'cache_users' )->justReturn( null );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'number_format_i18n' )->alias(
			static function ( $number ) {
				return number_format( (float) $number );
			}
		);
	}

	/**
	 * Adds a user.
	 *
	 * @param int      $id    User ID.
	 * @param string   $login Login.
	 * @param string[] $roles Roles.
	 * @return \WP_User
	 */
	protected function user( int $id, string $login, array $roles = array( 'author' ) ): \WP_User {
		$this->users[ $id ] = new \WP_User( $id, $login, $roles );
		return $this->users[ $id ];
	}

	/**
	 * Stores a session for a user and returns its verifier.
	 *
	 * @param int    $user_id User ID.
	 * @param string $token   Raw token.
	 * @param array  $session Session data on top of the defaults.
	 * @return string Verifier.
	 */
	protected function session( int $user_id, string $token, array $session = array() ): string {
		$verifier = Sessions::verifier( $token );
		$this->meta[ $user_id ][ Sessions::META ][ $verifier ] = $session + array(
			'expiration' => time() + 3600,
			'ip'         => '203.0.113.7',
			'ua'         => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:128.0) Gecko/20100101 Firefox/128.0',
			'login'      => time() - 600,
			'last_seen'  => time() - 60,
		);
		return $verifier;
	}

	/**
	 * Stored sessions of a user.
	 *
	 * @param int $user_id User ID.
	 * @return array
	 */
	protected function stored( int $user_id ): array {
		return $this->meta[ $user_id ][ Sessions::META ] ?? array();
	}

	/**
	 * Saves per-role policies.
	 *
	 * @param array $policies Role => policy.
	 * @param array $other    Other settings.
	 */
	protected function policies( array $policies, array $other = array() ): void {
		$this->options[ Settings::OPTION ] = array( 'policies' => $policies ) + $other;
	}
}
