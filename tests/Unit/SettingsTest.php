<?php
/**
 * Tests for settings and the per-user policy.
 *
 * @package AryanX\UserSessionManager
 */

namespace AryanX\UserSessionManager\Tests\Unit;

use AryanX\UserSessionManager\Access;
use AryanX\UserSessionManager\Settings;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class SettingsTest extends SessionTestCase {

	public function test_defaults_when_nothing_is_saved(): void {
		$all = ( new Settings() )->all();

		$this->assertSame( array(), $all['policies'] );
		$this->assertSame( 'evict_oldest', $all['limit_mode'] );
		$this->assertFalse( $all['new_device_email'] );
		$this->assertSame( 30, $all['device_days'] );
		$this->assertFalse( $all['mask_ip'] );
		$this->assertSame( Settings::POLICY_DEFAULTS, ( new Settings() )->policy( 'editor' ) );
	}

	public function test_policies_are_cast_and_clamped(): void {
		$this->assertSame(
			array(
				'max_sessions'   => 100,
				'idle_minutes'   => 0,
				'lifetime_hours' => 8,
				'remember_hours' => 0,
			),
			Settings::sanitize_policy(
				array(
					'max_sessions'   => '5000',
					'idle_minutes'   => -3,
					'lifetime_hours' => '8',
					'remember_hours' => 'soon',
					'extra'          => 1,
				)
			)
		);
	}

	public function test_a_user_with_several_roles_gets_the_most_generous_value(): void {
		$this->policies(
			array(
				'author' => array(
					'max_sessions' => 2,
					'idle_minutes' => 30,
				),
				'editor' => array(
					'max_sessions' => 5,
					'idle_minutes' => 0,
				),
			)
		);
		$settings = new Settings();
		$both     = new \WP_User( 3, 'pat', array( 'author', 'editor' ) );

		$this->assertSame( 5, $settings->max_sessions( $both ) );
		$this->assertSame( 0, $settings->idle_timeout( $both ), 'No timeout on one role beats a timeout on another.' );
		$this->assertSame( 2, $settings->max_sessions( new \WP_User( 4, 'al', array( 'author' ) ) ) );
		$this->assertSame( 0, $settings->max_sessions( new \WP_User( 5, 'nobody', array() ) ), 'Users without a role have no limit.' );
	}

	public function test_the_max_sessions_filter_gets_the_user_and_cannot_go_negative(): void {
		$this->policies( array( 'author' => array( 'max_sessions' => 2 ) ) );
		$user = new \WP_User( 3, 'pat', array( 'author' ) );

		Filters\expectApplied( 'user_session_manager_max_sessions' )->once()->with( 2, $user )->andReturn( -4 );

		$this->assertSame( 0, ( new Settings() )->max_sessions( $user ) );
	}

	public function test_the_limit_mode_filter_falls_back_on_unknown_values(): void {
		$this->policies( array(), array( 'limit_mode' => 'block' ) );
		$user = new \WP_User( 3, 'pat' );

		Filters\expectApplied( 'user_session_manager_limit_mode' )->once()->with( 'block', $user )->andReturn( 'explode' );

		$this->assertSame( 'evict_oldest', ( new Settings() )->limit_mode( $user ) );
	}

	public function test_the_idle_timeout_filter_can_set_minutes_per_user(): void {
		$user = new \WP_User( 3, 'pat' );

		Filters\expectApplied( 'user_session_manager_idle_timeout' )->once()->with( 0, $user )->andReturn( '15' );

		$this->assertSame( 15, ( new Settings() )->idle_timeout( $user ) );
	}

	public function test_lifetime_comes_from_the_role_with_a_safe_fallback(): void {
		$this->policies(
			array(
				'author' => array(
					'lifetime_hours' => 8,
					'remember_hours' => 0,
				),
			)
		);
		$settings = new Settings();
		$user     = new \WP_User( 3, 'pat', array( 'author' ) );

		$this->assertSame( 8 * HOUR_IN_SECONDS, $settings->lifetime( $user, false, 2 * DAY_IN_SECONDS ) );
		$this->assertSame( 14 * DAY_IN_SECONDS, $settings->lifetime( $user, true, 14 * DAY_IN_SECONDS ), '0 keeps the WordPress default.' );

		Filters\expectApplied( 'user_session_manager_session_lifetime' )->once()->with( 8 * HOUR_IN_SECONDS, $user, false )->andReturn( -1 );
		$this->assertSame( 2 * DAY_IN_SECONDS, $settings->lifetime( $user, false, 2 * DAY_IN_SECONDS ) );
	}

	public function test_last_seen_interval_is_five_minutes_and_clamped(): void {
		$settings = new Settings();
		$this->assertSame( 300, $settings->last_seen_interval() );

		Filters\expectApplied( 'user_session_manager_last_seen_interval' )->twice()->andReturn( 10 * DAY_IN_SECONDS, -5 );
		$this->assertSame( DAY_IN_SECONDS, $settings->last_seen_interval() );
		$this->assertSame( 0, $settings->last_seen_interval() );
	}

	public function test_mask_ip_and_device_days_filters(): void {
		$this->policies(
			array(),
			array(
				'mask_ip'     => true,
				'device_days' => 45,
			)
		);
		$settings = new Settings();

		Filters\expectApplied( 'user_session_manager_mask_ip' )->once()->with( true, '203.0.113.7', 9 )->andReturn( false );
		$this->assertFalse( $settings->mask_ip( '203.0.113.7', 9 ) );

		Filters\expectApplied( 'user_session_manager_device_memory_days' )->once()->with( 45 )->andReturn( 9999 );
		$this->assertSame( 365, $settings->device_days() );
	}

	public function test_save_announces_the_new_and_old_settings(): void {
		$this->policies( array(), array( 'limit_mode' => 'evict_oldest' ) );

		Actions\expectDone( 'user_session_manager_settings_saved' )
			->once()
			->with(
				\Mockery::on(
					static function ( $now ) {
						return 'block' === $now['limit_mode'];
					}
				),
				\Mockery::on(
					static function ( $old ) {
						return 'evict_oldest' === $old['limit_mode'];
					}
				)
			);

		( new Settings() )->save( array( 'limit_mode' => 'block' ) );
	}

	public function test_capability_filters_fall_back_when_empty(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Filters\expectApplied( 'user_session_manager_capability' )->once()->with( 'edit_users' )->andReturn( '' );
		Filters\expectApplied( 'user_session_manager_settings_capability' )->once()->with( 'manage_network_options' )->andReturn( 'manage_sessions' );

		$this->assertSame( 'edit_users', Access::capability() );
		$this->assertSame( 'manage_sessions', Access::settings_capability() );
	}

	public function test_people_manage_their_own_sessions_and_others_need_the_capabilities(): void {
		$this->current_user = 2;
		$caps               = array();
		Functions\when( 'current_user_can' )->alias(
			static function ( $cap, ...$args ) use ( &$caps ) {
				return in_array( $cap, $caps, true );
			}
		);

		$this->assertTrue( Access::can_manage( 2 ) );
		$this->assertFalse( Access::can_manage( 3 ) );
		$this->assertFalse( Access::can_manage( 0 ) );

		$caps = array( 'list_users', 'edit_users' );
		$this->assertFalse( Access::can_manage( 3 ), 'edit_user for that person is needed too.' );

		$caps[] = 'edit_user';
		$this->assertTrue( Access::can_manage( 3 ) );
	}
}
