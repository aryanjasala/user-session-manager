<?php
/**
 * Tests for the sign-in history and the signed-out message.
 *
 * @package AryanX\Axle\UserSessionManager
 */

namespace AryanX\Axle\UserSessionManager\Tests\Unit;

use AryanX\Axle\UserSessionManager\History;
use AryanX\Axle\UserSessionManager\LoginNotice;
use AryanX\Axle\UserSessionManager\Sessions;
use AryanX\Axle\UserSessionManager\Settings;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class HistoryTest extends SessionTestCase {

	private Sessions $store;

	protected function setUp(): void {
		parent::setUp();
		$this->store = new Sessions( new Settings() );
		Functions\when( 'wp_date' )->alias(
			static function ( $format, $timestamp ) {
				return gmdate( 'H:i', (int) $timestamp );
			}
		);
		$this->options['time_format'] = 'H:i';
		$this->options['date_format'] = 'j F Y';
	}

	private function history(): History {
		return new History( new Settings(), $this->store );
	}

	/**
	 * A started session as the new-session action passes it.
	 *
	 * @param int    $user_id User.
	 * @param string $token   Token.
	 * @return array
	 */
	private function started( int $user_id, string $token ): array {
		$verifier = $this->session( $user_id, $token );
		$session  = $this->store->get( $user_id, $verifier );

		return $session + array( 'handle' => $this->store->handle( $user_id, $verifier ) );
	}

	public function test_a_started_session_makes_an_entry_with_the_device_and_the_stored_ip(): void {
		$user    = $this->user( 7, 'alice' );
		$session = $this->started( 7, 'laptop' );

		$this->history()->on_started( $user, $session );

		$entries = $this->history()->entries( 7 );
		$this->assertCount( 1, $entries );
		$this->assertSame( $session['handle'], $entries[0]['handle'] );
		$this->assertSame( 'Firefox on Windows', $entries[0]['device'] );
		$this->assertSame( '203.0.113.7', $entries[0]['ip'] );
		$this->assertSame( 0, $entries[0]['end'] );
	}

	public function test_the_ip_follows_the_shortening_setting(): void {
		$this->policies( array(), array( 'mask_ip' => true ) );
		$user    = $this->user( 7, 'alice' );
		$session = $this->started( 7, 'laptop' );

		$this->history()->on_started( $user, $session );

		$this->assertSame( '203.0.113.0', $this->history()->entries( 7 )[0]['ip'] );
	}

	public function test_nothing_is_kept_when_retention_is_zero(): void {
		$this->policies( array(), array( 'history_days' => 0 ) );
		$user = $this->user( 7, 'alice' );

		$this->history()->on_started( $user, $this->started( 7, 'laptop' ) );
		$this->history()->end_all( 7, 'admin', 1 );

		$this->assertArrayNotHasKey( History::META, $this->meta[7] ?? array() );
	}

	public function test_ending_a_session_records_why_and_fires_an_action(): void {
		$user    = $this->user( 7, 'alice' );
		$session = $this->started( 7, 'laptop' );
		$history = $this->history();
		$history->on_started( $user, $session );

		Actions\expectDone( 'user_session_manager_history_session_ended' )->once()->with( 7, \Mockery::type( 'array' ), 'idle' );
		$history->on_idle( 7, $session );

		$entry = $history->entries( 7 )[0];
		$this->assertSame( 'idle', $entry['reason'] );
		$this->assertGreaterThan( 0, $entry['end'] );

		// A second ending does not overwrite the first.
		$history->on_evicted( 7, $session );
		$this->assertSame( 'idle', $history->entries( 7 )[0]['reason'] );
	}

	public function test_a_session_from_before_the_history_gets_an_entry_when_it_ends(): void {
		$this->user( 7, 'alice' );
		$session = $this->started( 7, 'old' );

		$this->history()->on_evicted( 7, $session );

		$entries = $this->history()->entries( 7 );
		$this->assertCount( 1, $entries );
		$this->assertSame( 'limit', $entries[0]['reason'] );
	}

	public function test_who_ended_it_decides_the_reason(): void {
		$this->current_user = 7;
		$this->assertSame( array( 'reason' => 'other_device', 'by' => 0 ), History::reason_for( 7, 'profile' ) );
		$this->assertSame( array( 'reason' => 'admin', 'by' => 7 ), History::reason_for( 9, 'admin' ) );
		$this->assertSame( array( 'reason' => 'admin', 'by' => 7 ), History::reason_for( 7, 'cli' ) );
		$this->assertSame( array( 'reason' => 'shared', 'by' => 0 ), History::reason_for( 7, 'shared' ) );
		$this->assertSame( array( 'reason' => 'not_me', 'by' => 0 ), History::reason_for( 7, 'not_me' ) );
		$this->assertSame( 'admin', History::reason_for( 7, 'incident' )['reason'] );
	}

	public function test_signing_out_everywhere_ends_every_open_entry(): void {
		$user = $this->user( 7, 'alice' );
		$one  = $this->started( 7, 'one' );
		$two  = $this->started( 7, 'two' );
		$history = $this->history();
		$history->on_started( $user, $one );
		$history->on_started( $user, $two );

		$this->current_user = 1;
		$history->on_signed_out_everywhere( 7, 2, 'admin' );

		foreach ( $history->entries( 7 ) as $entry ) {
			$this->assertSame( 'admin', $entry['reason'] );
			$this->assertSame( 1, $entry['by'] );
		}
	}

	public function test_own_sign_out_is_read_from_the_cookie_before_core_destroys_the_session(): void {
		$user        = $this->user( 7, 'alice' );
		$this->token = 'laptop';
		$session     = $this->started( 7, 'laptop' );
		$history     = $this->history();
		$history->on_started( $user, $session );

		$history->remember_logout();
		$history->on_logout( 7 );

		$this->assertSame( 'signed_out', $history->entries( 7 )[0]['reason'] );
	}

	public function test_rows_merge_live_sessions_and_work_out_expiry(): void {
		$user = $this->user( 7, 'alice' );
		$live = $this->started( 7, 'live' );
		$gone = $this->started( 7, 'gone' );
		$history = $this->history();
		$history->on_started( $user, $live );
		$history->on_started( $user, $gone );

		// The second session expired on its own: it is no longer stored and its time is up.
		$entries                = $history->entries( 7 );
		$entries[0]['expires']  = time() - 10;
		$entries[0]['start']    = time() - 7200;
		$this->meta[7][ History::META ] = $entries;
		unset( $this->meta[7][ Sessions::META ][ Sessions::verifier( 'gone' ) ] );

		$rows = $history->rows( 7 );

		$this->assertCount( 2, $rows );
		$by_handle = array_column( $rows, null, 'handle' );
		$this->assertSame( 'active', $by_handle[ $live['handle'] ]['state'] );
		$this->assertSame( 'expired', $by_handle[ $gone['handle'] ]['reason'] );
	}

	public function test_an_active_session_with_no_entry_still_shows(): void {
		$this->user( 7, 'alice' );
		$this->started( 7, 'before' );

		$rows = $this->history()->rows( 7 );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'active', $rows[0]['state'] );
	}

	public function test_old_entries_and_extra_entries_are_dropped_on_every_write(): void {
		$this->policies( array(), array( 'history_days' => 30 ) );
		$user = $this->user( 7, 'alice' );
		$this->meta[7][ History::META ] = array(
			array( 'handle' => '7-old', 'start' => time() - 90 * DAY_IN_SECONDS, 'seen' => time() - 90 * DAY_IN_SECONDS, 'end' => time() - 89 * DAY_IN_SECONDS, 'expires' => time() - 80 * DAY_IN_SECONDS, 'reason' => 'idle' ),
			array( 'handle' => '7-new', 'start' => time() - DAY_IN_SECONDS, 'seen' => time(), 'end' => time() - 3600, 'expires' => time() + 3600, 'reason' => 'idle' ),
		);

		$this->history()->on_started( $user, $this->started( 7, 'laptop' ) );

		$handles = array_column( $this->history()->entries( 7 ), 'handle' );
		$this->assertNotContains( '7-old', $handles );
		$this->assertContains( '7-new', $handles );

		Filters\expectApplied( 'user_session_manager_history_limit' )->andReturn( 10 );
		$many = array();
		for ( $i = 0; $i < 30; $i++ ) {
			$many[] = array( 'handle' => "7-{$i}", 'start' => time() - $i, 'seen' => time(), 'end' => 0, 'expires' => time() + 3600, 'reason' => '' );
		}
		$this->meta[7][ History::META ] = $many;
		$this->history()->on_started( $user, $this->started( 7, 'more' ) );
		$this->assertCount( 10, $this->history()->entries( 7 ) );
	}

	public function test_the_clean_up_removes_history_past_retention_for_users_who_never_come_back(): void {
		$this->policies( array(), array( 'history_days' => 10 ) );
		$this->meta[7][ History::META ] = array(
			array( 'handle' => '7-old', 'start' => time() - 50 * DAY_IN_SECONDS, 'seen' => time() - 50 * DAY_IN_SECONDS, 'end' => time() - 49 * DAY_IN_SECONDS, 'expires' => time() - 40 * DAY_IN_SECONDS, 'reason' => 'idle' ),
		);
		\WP_User_Query::$queue = array( array( 7 ) );

		$result = $this->history()->prune( 0, 500 );

		$this->assertSame( 1, $result['removed'] );
		$this->assertTrue( $result['done'] );
		$this->assertArrayNotHasKey( History::META, $this->meta[7] );
	}

	public function test_forgetting_removes_the_history(): void {
		$this->meta[7][ History::META ] = array( array( 'handle' => '7-a' ) );

		$this->assertTrue( $this->history()->forget( 7 ) );
		$this->assertFalse( $this->history()->forget( 7 ) );
	}

	public function test_reason_labels_are_filterable_and_unknown_reasons_fall_back(): void {
		$this->assertSame( 'Idle timeout', History::reason_label( 'idle' ) );
		$this->assertSame( 'Ended, reason not recorded', History::reason_label( 'nonsense' ) );
		$this->assertSame( 'error', History::reason_tone( 'not_me' ) );

		Filters\expectApplied( 'user_session_manager_history_reason_label' )->once()->andReturn( 'Timed out' );
		$this->assertSame( 'Timed out', History::reason_label( 'idle' ) );
	}

	public function test_the_login_message_names_the_reason_and_the_time(): void {
		$when = time() - 120;

		$this->assertStringContainsString( 'signed in on another device', LoginNotice::message( 'limit', $when ) );
		$this->assertStringContainsString( gmdate( 'H:i', $when ), LoginNotice::message( 'limit', $when ) );
		$this->assertStringContainsString( 'inactive', LoginNotice::message( 'idle', $when ) );
		$this->assertStringContainsString( 'administrator', LoginNotice::message( 'admin', $when ) );
		$this->assertSame( '', LoginNotice::message( 'signed_out', $when ), 'Your own sign out needs no message.' );
		$this->assertSame( '', LoginNotice::message( 'made_up', $when ) );
		$this->assertSame( '', LoginNotice::message( 'idle', time() - 40 * DAY_IN_SECONDS ), 'Too old to mean anything.' );
		$this->assertSame( '', LoginNotice::message( 'idle', time() + HOUR_IN_SECONDS ), 'A time in the future is not real.' );

		Filters\expectApplied( 'user_session_manager_signed_out_message' )->once()->andReturn( 'Custom.' );
		$this->assertSame( 'Custom.', LoginNotice::message( 'idle', $when ) );
	}

	public function test_the_login_screen_shows_the_message_once_and_ignores_a_forged_cookie(): void {
		$notice = new LoginNotice( $this->history(), $this->store );
		$name   = LoginNotice::cookie_name();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'is_ssl' )->justReturn( false );

		$_COOKIE[ $name ] = 'limit.' . ( time() - 60 );
		$errors           = \Mockery::mock( \WP_Error::class );
		$errors->shouldReceive( 'add' )->once()->with( 'user_session_manager_ended', \Mockery::pattern( '/signed in on another device/' ), 'message' );
		$this->assertSame( $errors, $notice->add_message( $errors ) );
		$this->assertArrayNotHasKey( $name, $_COOKIE, 'The cookie is read once.' );

		$_COOKIE[ $name ] = '<script>.1';
		$untouched        = \Mockery::mock( \WP_Error::class );
		$untouched->shouldNotReceive( 'add' );
		$this->assertSame( $untouched, $notice->add_message( $untouched ) );
	}
}
