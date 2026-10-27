<?php
/**
 * Tests for the new device email and the privacy tools.
 *
 * @package AryanX\Axle\UserSessionManager
 */

namespace AryanX\Axle\UserSessionManager\Tests\Unit;

use AryanX\Axle\UserSessionManager\NewDevice;
use AryanX\Axle\UserSessionManager\Privacy;
use AryanX\Axle\UserSessionManager\Sessions;
use AryanX\Axle\UserSessionManager\Settings;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class NewDeviceTest extends SessionTestCase {

	private const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';

	/**
	 * Emails handed to wp_mail().
	 *
	 * @var array[]
	 */
	private array $sent = array();

	protected function setUp(): void {
		parent::setUp();

		$this->sent = array();
		Functions\when( 'wp_mail' )->alias(
			function ( $to, $subject, $message ) {
				$this->sent[] = compact( 'to', 'subject', 'message' );
				return true;
			}
		);
		Functions\when( 'get_bloginfo' )->justReturn( 'Example Shop' );
		Functions\when( 'wp_specialchars_decode' )->returnArg();
		Functions\when( 'wp_date' )->justReturn( '23 October 2026 10:00' );
		Functions\when( 'get_dashboard_url' )->justReturn( 'https://example.com/wp-admin/profile.php' );
		$this->options['date_format'] = 'j F Y';
		$this->options['time_format'] = 'H:i';
	}

	private function devices(): NewDevice {
		$settings = new Settings();

		return new NewDevice( $settings, new Sessions( $settings ) );
	}

	/**
	 * A new session from a browser.
	 *
	 * @param string $ua User agent.
	 * @param string $ip IP address.
	 * @return array
	 */
	private function login( string $ua, string $ip = '203.0.113.7' ): array {
		return array(
			'ua'    => $ua,
			'ip'    => $ip,
			'login' => time(),
		);
	}

	public function test_nothing_is_kept_while_the_email_is_off(): void {
		$this->devices()->check( $this->user( 7, 'alice' ), $this->login( self::IPHONE ) );

		$this->assertArrayNotHasKey( 7, $this->meta );
		$this->assertSame( array(), $this->sent );
	}

	public function test_the_first_login_only_fills_the_memory(): void {
		$this->policies( array(), array( 'new_device_email' => true ) );
		$this->session( 7, 'laptop' );

		Actions\expectDone( 'user_session_manager_new_device_login' )->never();
		$this->devices()->check( $this->user( 7, 'alice' ), $this->login( self::IPHONE ) );

		$this->assertSame( array(), $this->sent );
		$this->assertCount( 2, $this->meta[7][ NewDevice::META ]['devices'], 'The other session and this one.' );
		$this->assertStringNotContainsString( '203.0.113.7', (string) wp_json_encode_for_test( $this->meta[7][ NewDevice::META ] ) );
	}

	public function test_a_new_browser_sends_the_email_and_a_known_one_does_not(): void {
		$this->policies( array(), array( 'new_device_email' => true ) );
		$user = $this->user( 7, 'alice' );
		$this->devices()->check( $user, $this->login( self::IPHONE ) );

		$this->devices()->check( $user, $this->login( self::IPHONE ) );
		$this->assertSame( array(), $this->sent );

		Actions\expectDone( 'user_session_manager_new_device_login' )->once()->with( $user, \Mockery::type( 'array' ) );
		$this->devices()->check( $user, $this->login( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:128.0) Gecko/20100101 Firefox/128.0' ) );

		$this->assertCount( 1, $this->sent );
		$this->assertSame( 'alice@example.com', $this->sent[0]['to'] );
		$this->assertSame( '[Example Shop] New sign-in to your account', $this->sent[0]['subject'] );
		$this->assertStringContainsString( 'Device: Firefox on Windows', $this->sent[0]['message'] );
		$this->assertStringContainsString( 'profile.php#usm-sessions', $this->sent[0]['message'] );
	}

	public function test_the_same_browser_on_a_new_network_is_new(): void {
		$this->policies( array(), array( 'new_device_email' => true ) );
		$user = $this->user( 7, 'alice' );
		$this->devices()->check( $user, $this->login( self::IPHONE ) );

		$this->devices()->check( $user, $this->login( self::IPHONE, '198.51.100.20' ) );

		$this->assertCount( 1, $this->sent );
	}

	public function test_the_email_filter_can_change_or_skip_it(): void {
		$this->policies( array(), array( 'new_device_email' => true ) );
		$user = $this->user( 7, 'alice' );
		$this->devices()->check( $user, $this->login( self::IPHONE ) );

		Filters\expectApplied( 'user_session_manager_new_device_email' )->twice()->andReturnUsing(
			static function ( $email ) {
				static $calls = 0;
				++$calls;
				return 1 === $calls ? array( 'to' => '' ) + $email : array( 'to' => 'security@example.com' ) + $email;
			}
		);

		$this->devices()->check( $user, $this->login( self::IPHONE, '198.51.100.1' ) );
		$this->assertSame( array(), $this->sent );

		$this->devices()->check( $user, $this->login( self::IPHONE, '198.51.100.2' ) );
		$this->assertSame( 'security@example.com', $this->sent[0]['to'] );
	}

	public function test_old_devices_are_forgotten_and_the_list_is_capped(): void {
		$this->policies(
			array(),
			array(
				'new_device_email' => true,
				'device_days'      => 30,
			)
		);
		$this->meta[7][ NewDevice::META ] = array(
			'since'   => 1,
			'devices' => array(
				'old'    => array(
					'label' => 'Opera on Linux',
					'seen'  => time() - 31 * DAY_IN_SECONDS,
				),
				'recent' => array(
					'label' => 'Edge on Windows',
					'seen'  => time() - DAY_IN_SECONDS,
				),
			),
		);

		Filters\expectApplied( 'user_session_manager_device_limit' )->once()->andReturn( 1 );
		$this->devices()->check( $this->user( 7, 'alice' ), $this->login( self::IPHONE ) );

		$this->assertCount( 1, $this->sent );
		$this->assertSame( array( 'Safari on iOS' ), array_column( $this->devices()->devices( 7 ), 'label' ) );
	}

	public function test_privacy_export_and_erase(): void {
		$this->user( 7, 'alice' );
		$this->session( 7, 'laptop' );
		$this->session( 7, 'phone', array( 'ua' => self::IPHONE ) );
		$this->meta[7][ NewDevice::META ] = array(
			'since'   => 1,
			'devices' => array(
				'x' => array(
					'label' => 'Safari on iOS',
					'seen'  => time(),
				),
			),
		);
		$settings = new Settings();
		$sessions = new Sessions( $settings );
		$privacy  = new Privacy( $settings, $sessions, new NewDevice( $settings, $sessions ) );

		$export = $privacy->export( 'alice@example.com' );
		$this->assertTrue( $export['done'] );
		$this->assertCount( 3, $export['data'] );
		$this->assertSame( array( 'user-session-manager-sessions', 'user-session-manager-sessions', 'user-session-manager-devices' ), array_column( $export['data'], 'group_id' ) );
		$this->assertSame( array(), $privacy->export( 'nobody@example.com' )['data'] );

		Actions\expectDone( 'user_session_manager_user_signed_out_everywhere' )->once()->with( 7, 2, 'privacy' );
		$erased = $privacy->erase( 'alice@example.com' );

		$this->assertTrue( $erased['items_removed'] );
		$this->assertSame( array( 'Signed the user out of 2 sessions.' ), $erased['messages'] );
		$this->assertSame( array(), $this->stored( 7 ) );
		$this->assertArrayNotHasKey( NewDevice::META, $this->meta[7] );
		$this->assertFalse( $privacy->erase( 'alice@example.com' )['items_removed'] );
	}
}

/**
 * JSON for assertions, without WordPress.
 *
 * @param mixed $data Data.
 * @return string|false
 */
function wp_json_encode_for_test( $data ) {
	return json_encode( $data );
}
