<?php
/**
 * Tests for reading and ending sessions.
 *
 * @package AryanX\UserSessionManager
 */

namespace AryanX\UserSessionManager\Tests\Unit;

use AryanX\UserSessionManager\Admin\SessionsTable;
use AryanX\UserSessionManager\Sessions;
use AryanX\UserSessionManager\Settings;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;

final class SessionsTest extends SessionTestCase {

	private function store(): Sessions {
		return new Sessions( new Settings() );
	}

	public function test_expired_sessions_are_left_out_and_old_formats_read(): void {
		$this->session( 7, 'live' );
		$this->session( 7, 'expired', array( 'expiration' => time() - 1 ) );
		$this->meta[7][ Sessions::META ]['legacy'] = time() + 60;

		$sessions = $this->store()->for_user( 7 );

		$this->assertSame( array( Sessions::verifier( 'live' ), 'legacy' ), array_keys( $sessions ) );
		$this->assertSame( array( 'expiration' => time() + 60 ), $sessions['legacy'] );
		$this->assertSame( 2, $this->store()->count( 7 ) );
	}

	public function test_handles_resolve_and_never_show_the_verifier(): void {
		$verifier = $this->session( 7, 'token-a' );
		$store    = $this->store();
		$handle   = $store->handle( 7, $verifier );

		$this->assertMatchesRegularExpression( '/^7-[0-9a-f]{24}$/', $handle );
		$this->assertStringNotContainsString( substr( $verifier, 0, 24 ), $handle );
		$this->assertSame( $handle, $store->handle( 7, $verifier ) );
		$this->assertSame( $verifier, $store->find( $handle )['verifier'] );

		$this->assertNull( $store->find( '8-' . substr( $handle, 2 ) ), 'A handle only works for its own user.' );
		$this->assertNull( $store->find( '7-zzz' ) );
		$this->assertNull( $store->find( $verifier ) );
	}

	public function test_revoking_ends_one_session_and_says_so(): void {
		$keep   = $this->session( 7, 'keep' );
		$gone   = $this->session( 7, 'gone' );
		$store  = $this->store();
		$handle = $store->handle( 7, $gone );

		Actions\expectDone( 'user_session_manager_session_revoked' )
			->once()
			->with(
				7,
				\Mockery::on(
					static function ( $session ) use ( $handle ) {
						return $handle === $session['handle'] && '203.0.113.7' === $session['ip'];
					}
				),
				'admin'
			);

		$this->assertTrue( $store->revoke_handle( $handle, 'admin' ) );
		$this->assertSame( array( $keep ), array_keys( $this->stored( 7 ) ) );
		$this->assertFalse( $store->revoke_handle( $handle, 'admin' ), 'Ending it again does nothing.' );
	}

	public function test_the_last_session_removes_the_meta_like_core(): void {
		$only = $this->session( 7, 'only' );

		$this->store()->revoke( 7, $only, 'profile' );

		$this->assertArrayNotHasKey( Sessions::META, $this->meta[7] );
	}

	public function test_signing_out_others_keeps_the_current_session(): void {
		$current = $this->session( 7, 'current' );
		$this->session( 7, 'phone' );
		$this->session( 7, 'tablet' );

		Actions\expectDone( 'user_session_manager_session_revoked' )->twice();

		$this->assertSame( 2, $this->store()->revoke_others( 7, $current, 'profile' ) );
		$this->assertSame( array( $current ), array_keys( $this->stored( 7 ) ) );
	}

	public function test_signing_out_everywhere_ends_every_session(): void {
		$this->session( 7, 'a' );
		$this->session( 7, 'b' );

		Actions\expectDone( 'user_session_manager_user_signed_out_everywhere' )->once()->with( 7, 2, 'cli' );

		$this->assertSame( 2, $this->store()->sign_out_everywhere( 7, 'cli' ) );
		$this->assertSame( array(), $this->stored( 7 ) );
	}

	public function test_idle_needs_a_last_active_time_older_than_the_timeout(): void {
		$now = time();

		$this->assertTrue( Sessions::is_idle( array( 'last_seen' => $now - 120 ), 1, $now ) );
		$this->assertFalse( Sessions::is_idle( array( 'last_seen' => $now - 30 ), 1, $now ) );
		$this->assertFalse( Sessions::is_idle( array( 'last_seen' => $now - 9999 ), 0, $now ), '0 turns it off.' );
		$this->assertFalse( Sessions::is_idle( array( 'login' => $now - 9999 ), 1, $now ), 'Sessions from before the plugin count as active.' );
	}

	public function test_ips_are_shortened_when_the_setting_or_filter_says_so(): void {
		$this->policies( array(), array( 'mask_ip' => true ) );

		$this->assertSame( '203.0.113.0', $this->store()->storable_ip( '203.0.113.7', 7 ) );
		$this->assertSame( '', $this->store()->storable_ip( '', 7 ) );

		Filters\expectApplied( 'user_session_manager_mask_ip' )->once()->andReturn( false );
		$this->assertSame( '203.0.113.7', $this->store()->storable_ip( '203.0.113.7', 7 ) );
	}

	public function test_sessions_kept_elsewhere_are_not_supported(): void {
		$this->session( 7, 'a' );
		Filters\expectApplied( 'session_token_manager' )->andReturn( 'Redis_Session_Tokens' );

		$this->assertFalse( $this->store()->is_supported() );
		$this->assertSame( array(), $this->store()->for_user( 7 ) );
	}

	public function test_describe_marks_the_current_session_and_parses_the_browser(): void {
		$this->user( 7, 'alice' );
		$this->current_user = 7;
		$this->token        = 'current';
		$current            = $this->session( 7, 'current' );
		$other              = $this->session( 7, 'other', array( 'ua' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1' ) );
		$store              = $this->store();

		$row = $store->describe( 7, $current, $this->stored( 7 )[ $current ] );
		$this->assertTrue( $row['current'] );
		$this->assertSame( 'Firefox on Windows', $row['device'] );
		$this->assertSame( 'alice', $row['user_login'] );
		$this->assertArrayNotHasKey( 'verifier', $row );

		$row = $store->describe( 7, $other, $this->stored( 7 )[ $other ] );
		$this->assertFalse( $row['current'] );
		$this->assertSame( 'mobile', $row['device_type'] );
	}

	public function test_query_reads_users_in_batches_searches_ips_and_pages(): void {
		$this->user( 2, 'bob' );
		$this->user( 7, 'alice' );
		$this->session( 2, 'b1', array( 'ip' => '198.51.100.4' ) );
		$this->session( 7, 'a1', array( 'last_seen' => time() - 10 ) );
		$this->session( 7, 'a2', array( 'last_seen' => time() - 500 ) );
		\WP_User_Query::$queue = array( array( 2, 7 ) );

		$found = $this->store()->query(
			array(
				'per_page' => 2,
				'paged'    => 1,
			)
		);
		$this->assertSame( 3, $found['total'] );
		$this->assertSame( 2, $found['users'] );
		$this->assertCount( 2, $found['rows'] );
		$this->assertSame( 'alice', $found['rows'][0]['user_login'], 'Most recently active first.' );
		$this->assertSame( 'session_tokens', \WP_User_Query::$made[0]['meta_key'] );

		\WP_User_Query::$queue = array( array( 2, 7 ) );
		$found                 = $this->store()->query( array( 'search' => '198.51.' ) );
		$this->assertSame( 1, $found['total'] );
		$this->assertSame( 'bob', $found['rows'][0]['user_login'] );
		$this->assertArrayNotHasKey( 'search', \WP_User_Query::$made[1], 'An IP search does not search users.' );

		\WP_User_Query::$queue = array( array( 7 ) );
		$this->store()->query( array( 'search' => 'ali' ) );
		$this->assertSame( '*ali*', \WP_User_Query::$made[2]['search'] );
	}

	public function test_the_scan_stops_at_the_filtered_user_limit(): void {
		$this->user( 2, 'bob' );
		$this->session( 2, 'b1' );
		\WP_User_Query::$queue = array( array( 2 ), array( 3 ) );

		Filters\expectApplied( 'user_session_manager_max_users_scanned' )->andReturn( 1 );

		$found = $this->store()->query();
		$this->assertTrue( $found['truncated'] );
		$this->assertCount( 1, \WP_User_Query::$made );
		$this->assertSame( 1, \WP_User_Query::$made[0]['number'] );
	}

	public function test_sorting_by_user_and_by_last_active(): void {
		$rows = array(
			array(
				'handle'     => 'a',
				'user_login' => 'zed',
				'login'      => 100,
				'last_seen'  => 0,
				'expiration' => 5,
			),
			array(
				'handle'     => 'b',
				'user_login' => 'amy',
				'login'      => 10,
				'last_seen'  => 50,
				'expiration' => 9,
			),
		);

		$this->assertSame( array( 'b', 'a' ), array_column( Sessions::sort( $rows, 'user', 'asc' ), 'handle' ) );
		$this->assertSame( array( 'a', 'b' ), array_column( Sessions::sort( $rows, 'last_seen', 'desc' ), 'handle' ), 'No last active time sorts by login time.' );
		$this->assertSame( array( 'b', 'a' ), array_column( Sessions::sort( $rows, 'nonsense', 'asc' ), 'handle' ) );
	}

	public function test_cached_totals_drop_only_when_sessions_change(): void {
		$deleted = array();
		\Brain\Monkey\Functions\when( 'wp_cache_delete' )->alias(
			static function ( $key ) use ( &$deleted ) {
				$deleted[] = $key;
				return true;
			}
		);

		$store = $this->store();
		$store->flush_totals( 1, 7, 'nickname' );
		$store->flush_totals( 1, 7, Sessions::META );

		$this->assertSame( array( 'totals' ), $deleted );
	}

	public function test_the_list_per_page_filter_is_clamped(): void {
		$this->assertSame( 20, SessionsTable::per_page() );

		Filters\expectApplied( 'user_session_manager_list_per_page' )->once()->with( 20 )->andReturn( 0 );
		$this->assertSame( 1, SessionsTable::per_page() );
	}
}
