<?php
/**
 * Tests for "last active" tracking and the idle timeout.
 *
 * @package AryanX\UserSessionManager
 */

namespace AryanX\UserSessionManager\Tests\Unit;

use AryanX\UserSessionManager\Activity;
use AryanX\UserSessionManager\Sessions;
use AryanX\UserSessionManager\Settings;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class ActivityTest extends SessionTestCase {

	private function activity(): Activity {
		$settings = new Settings();

		return new Activity( $settings, new Sessions( $settings ) );
	}

	/**
	 * The login cookie of the request.
	 *
	 * @param string $login User login.
	 * @param string $token Raw token.
	 */
	private function cookie( string $login, string $token ): void {
		Functions\when( 'wp_parse_auth_cookie' )->justReturn(
			array(
				'username' => $login,
				'token'    => $token,
			)
		);
	}

	public function test_new_sessions_start_out_active(): void {
		$session = $this->activity()->stamp_new_session( array( 'extra' => 1 ), 7 );

		$this->assertSame( 1, $session['extra'] );
		$this->assertEqualsWithDelta( time(), $session['last_seen'], 2 );
	}

	public function test_an_idle_session_is_refused_and_ended(): void {
		$this->user( 7, 'alice' );
		$this->policies( array( 'author' => array( 'idle_minutes' => 30 ) ) );
		$idle = $this->session( 7, 'idle', array( 'last_seen' => time() - 31 * MINUTE_IN_SECONDS ) );
		$this->session( 7, 'other' );
		$this->cookie( 'alice', 'idle' );

		Actions\expectDone( 'user_session_manager_idle_session_ended' )
			->once()
			->with(
				7,
				\Mockery::on(
					static function ( $session ) {
						return isset( $session['handle'] ) && 0 === strpos( $session['handle'], '7-' );
					}
				),
				'request'
			);

		$this->assertFalse( $this->activity()->end_idle_session( 7 ) );
		$this->assertArrayNotHasKey( $idle, $this->stored( 7 ) );
		$this->assertCount( 1, $this->stored( 7 ) );
	}

	public function test_an_active_session_passes_through(): void {
		$this->user( 7, 'alice' );
		$this->policies( array( 'author' => array( 'idle_minutes' => 30 ) ) );
		$this->session( 7, 'fresh', array( 'last_seen' => time() - 60 ) );
		$this->cookie( 'alice', 'fresh' );

		Actions\expectDone( 'user_session_manager_idle_session_ended' )->never();

		$this->assertSame( 7, $this->activity()->end_idle_session( 7 ) );
		$this->assertCount( 1, $this->stored( 7 ) );
	}

	public function test_a_cookie_of_another_user_or_no_user_is_left_alone(): void {
		$this->user( 7, 'alice' );
		$this->session( 7, 'idle', array( 'last_seen' => 1 ) );
		Filters\expectApplied( 'user_session_manager_idle_timeout' )->never();

		$this->cookie( 'mallory', 'idle' );
		$this->assertSame( 7, $this->activity()->end_idle_session( 7 ) );
		$this->assertFalse( $this->activity()->end_idle_session( false ) );
	}

	public function test_last_active_is_saved_once_per_interval(): void {
		$this->user( 7, 'alice' );
		$this->current_user = 7;
		$this->token        = 'mine';
		$verifier           = $this->session( 7, 'mine', array( 'last_seen' => time() - 400 ) );

		$this->activity()->track();
		$this->assertEqualsWithDelta( time(), $this->stored( 7 )[ $verifier ]['last_seen'], 2 );

		$this->meta[7][ Sessions::META ][ $verifier ]['last_seen'] = time() - 100;
		$this->activity()->track();
		$this->assertEqualsWithDelta( time() - 100, $this->stored( 7 )[ $verifier ]['last_seen'], 2, 'Within 5 minutes nothing is written.' );

		Filters\expectApplied( 'user_session_manager_last_seen_interval' )->once()->andReturn( 60 );
		$this->activity()->track();
		$this->assertEqualsWithDelta( time(), $this->stored( 7 )[ $verifier ]['last_seen'], 2 );
	}

	public function test_requests_a_filter_rules_out_are_not_activity(): void {
		$this->user( 7, 'alice' );
		$this->current_user = 7;
		$this->token        = 'mine';
		$verifier           = $this->session( 7, 'mine', array( 'last_seen' => time() - 400 ) );

		Filters\expectApplied( 'user_session_manager_track_request' )->once()->with( true, 7 )->andReturn( false );

		$this->activity()->track();
		$this->assertEqualsWithDelta( time() - 400, $this->stored( 7 )[ $verifier ]['last_seen'], 2 );
	}

	public function test_tracking_shortens_an_ip_saved_before_the_setting(): void {
		$this->policies( array(), array( 'mask_ip' => true ) );
		$this->current_user = 7;
		$this->token        = 'mine';
		$verifier           = $this->session( 7, 'mine' );

		$this->activity()->track();

		$this->assertSame( '203.0.113.0', $this->stored( 7 )[ $verifier ]['ip'] );
	}
}
