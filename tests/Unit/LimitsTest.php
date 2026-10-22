<?php
/**
 * Tests for session limits and lifetimes at login.
 *
 * @package AryanX\UserSessionManager
 */

namespace AryanX\UserSessionManager\Tests\Unit;

use AryanX\UserSessionManager\Limits;
use AryanX\UserSessionManager\Sessions;
use AryanX\UserSessionManager\Settings;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class LimitsTest extends SessionTestCase {

	private function limits(): Limits {
		$settings = new Settings();

		return new Limits( $settings, new Sessions( $settings ) );
	}

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_kses_post' )->returnArg();
	}

	public function test_block_mode_refuses_a_login_at_the_limit(): void {
		$user = $this->user( 7, 'alice' );
		$this->policies( array( 'author' => array( 'max_sessions' => 2 ) ), array( 'limit_mode' => 'block' ) );
		$this->session( 7, 'one' );
		$this->session( 7, 'two' );

		Actions\expectDone( 'user_session_manager_login_blocked' )->once()->with( $user, 2, 2 );
		Filters\expectApplied( 'user_session_manager_blocked_login_message' )->once()->with( \Mockery::type( 'string' ), $user, 2 )->andReturn( 'Too many devices.' );

		$limits = $this->limits();
		$limits->start_sign_on();
		$result = $limits->block_over_limit( $user );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'user_session_manager_limit', $result->get_error_code() );
		$this->assertSame( 'Too many devices.', $result->get_error_message() );
	}

	public function test_idle_sessions_do_not_count_towards_the_limit(): void {
		$user = $this->user( 7, 'alice' );
		$this->policies(
			array(
				'author' => array(
					'max_sessions' => 1,
					'idle_minutes' => 10,
				),
			),
			array( 'limit_mode' => 'block' )
		);
		$this->session( 7, 'stale', array( 'last_seen' => time() - HOUR_IN_SECONDS ) );

		Actions\expectDone( 'user_session_manager_idle_session_ended' )->once()->with( 7, \Mockery::type( 'array' ), 'login' );

		$limits = $this->limits();
		$limits->start_sign_on();
		$this->assertSame( $user, $limits->block_over_limit( $user ) );
		$this->assertSame( array(), $this->stored( 7 ) );
	}

	public function test_only_logins_that_create_a_session_are_blocked(): void {
		$user = $this->user( 7, 'alice' );
		$this->policies( array( 'author' => array( 'max_sessions' => 1 ) ), array( 'limit_mode' => 'block' ) );
		$this->session( 7, 'one' );

		// No wp_signon(), such as an XML-RPC password check.
		$this->assertSame( $user, $this->limits()->block_over_limit( $user ) );

		$error = new \WP_Error( 'incorrect_password', 'Wrong' );
		$this->assertSame( $error, $this->limits()->block_over_limit( $error ) );
	}

	public function test_an_empty_message_falls_back_to_the_default(): void {
		Filters\expectApplied( 'user_session_manager_blocked_login_message' )->once()->andReturn( '  ' );

		$this->assertStringContainsString( 'already signed in on 3 devices', $this->limits()->blocked_message( $this->user( 7, 'alice' ), 3 ) );
	}

	public function test_evict_mode_signs_out_the_oldest_and_announces_the_new_session(): void {
		$user = $this->user( 7, 'alice' );
		$this->policies( array( 'author' => array( 'max_sessions' => 2 ) ) );
		$oldest = $this->session( 7, 'oldest', array( 'login' => time() - 3000 ) );
		$middle = $this->session( 7, 'middle', array( 'login' => time() - 2000 ) );
		$new    = $this->session( 7, 'new', array( 'login' => time() ) );

		Actions\expectDone( 'user_session_manager_session_evicted' )
			->once()
			->with(
				7,
				\Mockery::on(
					static function ( $evicted ) {
						return time() - 3000 === $evicted['login'];
					}
				),
				\Mockery::on(
					static function ( $session ) {
						return isset( $session['handle'] );
					}
				)
			);
		Actions\expectDone( 'user_session_manager_session_started' )->once()->with( $user, \Mockery::type( 'array' ) );

		$this->limits()->after_login( 'cookie', 0, time() + 3600, 7, 'logged_in', 'new' );

		$this->assertSame( array( $middle, $new ), array_keys( $this->stored( 7 ) ) );
		$this->assertArrayNotHasKey( $oldest, $this->stored( 7 ) );
	}

	public function test_block_mode_ends_a_new_session_that_skipped_the_login_form(): void {
		$user = $this->user( 7, 'alice' );
		$this->policies( array( 'author' => array( 'max_sessions' => 1 ) ), array( 'limit_mode' => 'block' ) );
		$old = $this->session( 7, 'old', array( 'login' => time() - 3000 ) );
		$this->session( 7, 'social', array( 'login' => time() ) );

		Actions\expectDone( 'user_session_manager_login_blocked' )->once()->with( $user, 1, 1 );
		Actions\expectDone( 'user_session_manager_session_started' )->never();

		$this->limits()->after_login( 'cookie', 0, time() + 3600, 7, 'logged_in', 'social' );

		$this->assertSame( array( $old ), array_keys( $this->stored( 7 ) ) );
	}

	public function test_a_reissued_cookie_for_an_old_session_is_not_a_login(): void {
		$this->user( 7, 'alice' );
		$this->policies( array( 'author' => array( 'max_sessions' => 1 ) ) );
		$this->session( 7, 'a', array( 'login' => time() - 3000 ) );
		$this->session( 7, 'b', array( 'login' => time() - 2000 ) );

		Actions\expectDone( 'user_session_manager_session_started' )->never();
		Actions\expectDone( 'user_session_manager_session_evicted' )->never();

		$this->limits()->after_login( 'cookie', 0, time() + 3600, 7, 'logged_in', 'b' );
		$this->limits()->after_login( 'cookie', 0, time() + 3600, 7, 'logged_in', '' );

		$this->assertCount( 2, $this->stored( 7 ) );
	}

	public function test_a_new_session_stores_a_shortened_ip_when_asked(): void {
		$this->user( 7, 'alice' );
		$this->policies( array(), array( 'mask_ip' => true ) );
		$new = $this->session( 7, 'new', array( 'login' => time() ) );

		$this->limits()->after_login( 'cookie', 0, time() + 3600, 7, 'logged_in', 'new' );

		$this->assertSame( '203.0.113.0', $this->stored( 7 )[ $new ]['ip'] );
	}

	public function test_lifetime_follows_the_role(): void {
		$this->user( 7, 'alice' );
		$this->policies( array( 'author' => array( 'lifetime_hours' => 1 ) ) );

		$this->assertSame( HOUR_IN_SECONDS, $this->limits()->lifetime( 2 * DAY_IN_SECONDS, 7, false ) );
		$this->assertSame( 14 * DAY_IN_SECONDS, $this->limits()->lifetime( 14 * DAY_IN_SECONDS, 7, true ) );
		$this->assertSame( 2 * DAY_IN_SECONDS, $this->limits()->lifetime( 2 * DAY_IN_SECONDS, 99, false ), 'Unknown users keep the default.' );
	}
}
