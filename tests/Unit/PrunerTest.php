<?php
/**
 * Tests for the hourly clean-up, the abilities and the Users column.
 *
 * @package AryanX\Axle\UserSessionManager
 */

namespace AryanX\Axle\UserSessionManager\Tests\Unit;

use AryanX\Axle\UserSessionManager\Abilities;
use AryanX\Axle\UserSessionManager\Admin\SessionsPage;
use AryanX\Axle\UserSessionManager\Admin\UsersColumn;
use AryanX\Axle\UserSessionManager\Pruner;
use AryanX\Axle\UserSessionManager\Sessions;
use AryanX\Axle\UserSessionManager\Settings;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class PrunerTest extends SessionTestCase {

	private function pruner(): Pruner {
		$settings = new Settings();

		return new Pruner( $settings, new Sessions( $settings ) );
	}

	public function test_prune_drops_expired_and_idle_sessions_in_batches(): void {
		$this->user( 2, 'bob', array( 'editor' ) );
		$this->user( 7, 'alice' );
		$this->policies( array( 'author' => array( 'idle_minutes' => 10 ) ) );
		$this->session( 2, 'bob-old', array( 'expiration' => time() - 5 ) );
		$this->session( 2, 'bob-idle-but-no-timeout', array( 'last_seen' => time() - DAY_IN_SECONDS ) );
		$this->session( 7, 'alice-idle', array( 'last_seen' => time() - HOUR_IN_SECONDS ) );
		$keep = $this->session( 7, 'alice-active' );
		\WP_User_Query::$queue = array( array( 2, 7 ) );

		Actions\expectDone( 'user_session_manager_idle_session_ended' )->once()->with( 7, \Mockery::type( 'array' ), 'prune' );
		Actions\expectDone( 'user_session_manager_pruned' )->once();

		$result = $this->pruner()->prune( 0, 500 );

		$this->assertSame(
			array(
				'users'   => 2,
				'expired' => 1,
				'idle'    => 1,
				'last'    => 7,
				'done'    => true,
			),
			$result
		);
		$this->assertCount( 1, $this->stored( 2 ) );
		$this->assertSame( array( $keep ), array_keys( $this->stored( 7 ) ) );
	}

	public function test_the_cron_run_remembers_where_it_stopped(): void {
		$this->user( 2, 'bob' );
		$this->user( 3, 'cy' );
		\WP_User_Query::$queue = array( array( 2 ) );

		Filters\expectApplied( 'user_session_manager_prune_batch' )->andReturn( 1 );
		$this->pruner()->run();
		$this->assertSame( 2, $this->options[ Pruner::CURSOR ] );

		\WP_User_Query::$queue = array( array() );
		$this->pruner()->run();
		$this->assertSame( 0, $this->options[ Pruner::CURSOR ], 'A short batch starts over next time.' );
	}

	public function test_abilities_are_public_with_the_right_annotations(): void {
		$registered = array();
		Functions\when( 'wp_register_ability' )->alias(
			static function ( $name, $args ) use ( &$registered ) {
				$registered[ $name ] = $args;
			}
		);

		( new Abilities( new Sessions( new Settings() ) ) )->register_abilities();

		$this->assertSame( array( 'user-session-manager/list-sessions', 'user-session-manager/revoke-session', 'user-session-manager/sign-out-user' ), array_keys( $registered ) );
		foreach ( $registered as $args ) {
			$this->assertTrue( $args['meta']['public'] );
			$this->assertTrue( $args['meta']['mcp']['public'] );
			$this->assertTrue( $args['meta']['show_in_rest'] );
		}
		$this->assertTrue( $registered['user-session-manager/list-sessions']['meta']['annotations']['readonly'] );
		$this->assertSame(
			array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => true,
			),
			$registered['user-session-manager/revoke-session']['meta']['annotations']
		);
	}

	public function test_ability_permissions_follow_the_screens(): void {
		$this->user( 2, 'bob' );
		$this->user( 7, 'alice' );
		$this->current_user = 7;
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'is_email' )->justReturn( false );
		$abilities = new Abilities( new Sessions( new Settings() ) );

		$this->assertTrue( $abilities->can_list() );
		$this->assertTrue( $abilities->can_list( array( 'user' => 'alice' ) ) );
		$this->assertFalse( $abilities->can_list( array( 'user' => 'bob' ) ) );
		$this->assertFalse( $abilities->can_list( array( 'all' => true ) ) );
		$this->assertTrue( $abilities->can_revoke( array( 'handle' => '7-0123456789abcdef01234567' ) ) );
		$this->assertFalse( $abilities->can_revoke( array( 'handle' => '2-0123456789abcdef01234567' ) ) );
		$this->assertFalse( $abilities->can_sign_out( array( 'user' => 2 ) ) );
		$this->assertTrue( $abilities->can_sign_out( array( 'user' => '7' ) ) );

		$this->assertInstanceOf( \WP_Error::class, $abilities->sign_out_user( array( 'user' => 'ghost' ) ) );
	}

	public function test_the_users_column_counts_and_links_sessions(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'admin_url' )->alias(
			static function ( $path ) {
				return 'https://example.com/wp-admin/' . $path;
			}
		);
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $url ) {
				return $url . '?' . http_build_query( $args );
			}
		);
		$this->session( 7, 'a' );
		$this->session( 7, 'b' );
		$column = new UsersColumn( new Sessions( new Settings() ) );

		$this->assertArrayHasKey( UsersColumn::COLUMN, $column->add_column( array( 'username' => 'Username' ) ) );
		$this->assertSame( 'kept', $column->render( 'kept', 'email', 7 ) );
		$this->assertStringContainsString( 'users.php?page=user-sessions&amp;user_id=7', str_replace( '&', '&amp;', $column->render( '', UsersColumn::COLUMN, 7 ) ) );
		$this->assertStringContainsString( '>2</a>', $column->render( '', UsersColumn::COLUMN, 7 ) );
		$this->assertStringContainsString( 'No sessions', $column->render( '', UsersColumn::COLUMN, 8 ) );
	}

	public function test_bulk_sign_outs_are_announced_and_skip_users_out_of_reach(): void {
		$this->current_user = 1;
		Functions\when( 'current_user_can' )->alias(
			static function ( $cap, ...$args ) {
				return 'edit_user' !== $cap || 9 !== ( $args[0] ?? 0 );
			}
		);
		$sessions = new Sessions( new Settings() );
		$page     = new SessionsPage( $sessions );
		$a        = $sessions->handle( 7, $this->session( 7, 'a' ) );
		$b        = $sessions->handle( 7, $this->session( 7, 'b' ) );
		$boss     = $sessions->handle( 9, $this->session( 9, 'boss' ) );

		Actions\expectDone( 'user_session_manager_before_bulk' )->once()->with( 'revoke', array( $a, $boss ) );
		Actions\expectDone( 'user_session_manager_after_bulk' )->once()->with( 'revoke', array( $a, $boss ), 1 );
		$this->assertSame( 1, $page->bulk( 'revoke', array( $a, $boss, $a, '' ) ) );
		$this->assertCount( 1, $this->stored( 9 ), 'Users the admin may not edit keep their sessions.' );

		$this->session( 7, 'c' );
		Actions\expectDone( 'user_session_manager_user_signed_out_everywhere' )->once()->with( 7, 2, 'bulk' );
		$this->assertSame( 2, $page->bulk( 'sign_out', array( $b, $b ) ) );
	}
}
