<?php
/**
 * Tests that listing and ending sessions checks the person they belong to, not only a global capability.
 *
 * @package AryanX\Axle\UserSessionManager
 */

namespace AryanX\Axle\UserSessionManager\Tests\Unit;

use AryanX\Axle\UserSessionManager\Access;
use AryanX\Axle\UserSessionManager\Sessions;
use AryanX\Axle\UserSessionManager\Settings;
use AryanX\Axle\UserSessionManager\SharedLogins;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class PermissionsTest extends SessionTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->current_user = 1;
		$this->user( 1, 'admin', array( 'administrator' ) );
		$this->user( 2, 'bob', array( 'editor' ) );
		$this->user( 3, 'root', array( 'administrator' ) );
		$this->session( 2, 'b1' );
		$this->session( 3, 'r1' );
		// A site administrator may edit bob but not root, who is a super admin.
		Functions\when( 'current_user_can' )->alias(
			static function ( $capability, $user_id = 0 ) {
				return 'edit_user' !== $capability || 3 !== $user_id;
			}
		);
		Functions\when( 'is_email' )->justReturn( false );
	}

	public function test_the_session_query_can_leave_out_people_the_caller_may_not_edit(): void {
		$sessions = new Sessions( new Settings() );

		\WP_User_Query::$queue = array( array( 2, 3 ) );
		$everyone = $sessions->query();
		\WP_User_Query::$queue = array( array( 2, 3 ) );
		$editable = $sessions->query( array( 'editable_only' => true ) );

		$this->assertSame( 2, $everyone['total'], 'WP-CLI has no current user and sees everyone.' );
		$this->assertSame( 1, $editable['total'] );
		$this->assertSame( 'bob', $editable['rows'][0]['user_login'] );
	}

	public function test_the_list_ability_for_everyone_leaves_out_people_the_caller_may_not_edit(): void {
		$settings  = new Settings();
		$sessions  = new Sessions( $settings );
		$abilities = $this->abilities( $settings, $sessions, new SharedLogins( $settings, $sessions ) );

		\WP_User_Query::$queue = array( array( 2, 3 ) );
		$listed = $abilities->list_sessions( array( 'all' => true ) );

		$this->assertSame( 1, $listed['total'] );
		$this->assertSame( 'bob', $listed['sessions'][0]['user_login'] );
		$this->assertFalse( $abilities->can_list( array( 'user' => 'root' ) ) );
	}

	public function test_history_and_ending_sessions_check_the_person(): void {
		$settings  = new Settings();
		$sessions  = new Sessions( $settings );
		$abilities = $this->abilities( $settings, $sessions, new SharedLogins( $settings, $sessions ) );

		$this->assertTrue( $abilities->can_list_history( array( 'user' => 2 ) ) );
		$this->assertFalse( $abilities->can_list_history( array( 'user' => 3 ) ) );
		$this->assertTrue( $abilities->can_list_history(), 'Your own history.' );
		$this->assertFalse( $abilities->can_revoke( array( 'handle' => '3-' . str_repeat( 'a', 24 ) ) ) );
		$this->assertFalse( $abilities->can_sign_out( array( 'user' => 3 ) ) );
		$this->assertFalse( Access::can_manage( 3 ) );
		$this->assertTrue( Access::can_manage( 2 ) );
	}

	public function test_sign_everyone_out_needs_the_capability_and_an_explicit_yes(): void {
		$settings  = new Settings();
		$sessions  = new Sessions( $settings );
		$abilities = $this->abilities( $settings, $sessions, new SharedLogins( $settings, $sessions ) );

		$this->assertTrue( Access::can_sign_out_everyone() );
		Filters\expectApplied( 'user_session_manager_incident_capability' )->once()->andReturn( 'manage_network' );
		Functions\when( 'current_user_can' )->alias(
			static function ( $capability ) {
				return 'manage_network' !== $capability;
			}
		);
		$this->assertFalse( Access::can_sign_out_everyone() );

		$this->assertInstanceOf( \WP_Error::class, $abilities->sign_out_everyone( array() ) );
		$this->assertInstanceOf( \WP_Error::class, $abilities->sign_out_everyone( array( 'confirm' => 'yes' ) ) );
		$this->assertNotEmpty( $this->stored( 2 ), 'Nothing ended without a clear confirm.' );
	}

	public function test_the_new_settings_have_safe_defaults_and_limits(): void {
		$settings = new Settings();
		$this->assertTrue( $settings->get( 'not_me' ) );
		$this->assertSame( 'all', $settings->get( 'not_me_scope' ) );
		$this->assertSame( 90, $settings->history_days() );

		$this->policies( array(), array( 'not_me_scope' => 'nonsense', 'history_days' => 9999 ) );
		$settings = new Settings();
		$this->assertSame( 'all', $settings->get( 'not_me_scope' ) );
		$this->assertSame( 365, $settings->history_days() );

		Filters\expectApplied( 'user_session_manager_history_days' )->once()->andReturn( -4 );
		$this->assertSame( 0, ( new Settings() )->history_days() );
	}
}
