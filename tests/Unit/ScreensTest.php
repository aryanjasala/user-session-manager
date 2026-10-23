<?php
/**
 * Tests for the wording and choices on the screens: sign out notices, the
 * question before a sign out everywhere, core's sessions row and Site Health.
 *
 * @package AryanX\UserSessionManager
 */

namespace AryanX\UserSessionManager\Tests\Unit;

use AryanX\UserSessionManager\Admin\ProfileSessions;
use AryanX\UserSessionManager\Admin\SessionsPage;
use AryanX\UserSessionManager\Health\SiteHealth;
use AryanX\UserSessionManager\Sessions;
use AryanX\UserSessionManager\Settings;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class ScreensTest extends SessionTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'current_user_can' )->justReturn( true );
	}

	public function test_signing_one_user_out_names_them_in_the_notice(): void {
		Filters\expectApplied( 'user_session_manager_notice' )->once()->with( 'alice was signed out of 6 sessions.', 'signed_out_user', 6, 'alice' )->andReturnFirstArg();

		$this->assertSame( 'alice was signed out of 6 sessions.', ProfileSessions::message( 'signed_out_user', 6, 'alice' ) );
	}

	public function test_notice_numbers_use_the_site_format_and_the_filter_can_change_them(): void {
		$this->assertSame( '2,002 sessions signed out.', ProfileSessions::message( 'bulk', 2002 ) );
		$this->assertSame( '', ProfileSessions::message( 'unknown', 1 ) );

		Filters\expectApplied( 'user_session_manager_notice' )->once()->andReturn( 'Done.' );
		$this->assertSame( 'Done.', ProfileSessions::message( 'revoked', 1 ) );
	}

	public function test_the_question_says_this_browser_stays_unless_you_choose_otherwise(): void {
		$this->user( 1, 'admin', array( 'administrator' ) );
		Filters\expectApplied( 'user_session_manager_confirm_message' )->once()->with( \Mockery::type( 'string' ), array( 1 ), 3, true )->andReturnFirstArg();

		$message = SessionsPage::confirm_message( array( 1 ), 3, true );

		$this->assertStringContainsString( 'this browser included', $message );
		$this->assertStringContainsString( 'Sign out everywhere else keeps you signed in here', $message );
		$this->assertStringContainsString( 'also signs out this browser', $message );
	}

	public function test_the_question_about_someone_else_names_them_and_counts_their_devices(): void {
		$this->user( 7, 'alice' );

		$this->assertSame( 'alice is signed in on 2 devices. Each one is signed out the next time it loads a page.', SessionsPage::confirm_message( array( 7 ), 2, false ) );
		$this->assertStringContainsString( 'You are one of them', SessionsPage::confirm_message( array( 1, 7 ), 5, true ) );

		Filters\expectApplied( 'user_session_manager_confirm_message' )->once()->andReturn( ' ' );
		$this->assertStringStartsWith( 'alice is signed in', SessionsPage::confirm_message( array( 7 ), 2, false ) );
	}

	public function test_users_of_reads_each_user_once_from_the_handles(): void {
		$this->assertSame( array( 7, 9 ), SessionsPage::users_of( array( '7-aaa', '9-bbb', '7-ccc', 'junk' ) ) );
	}

	public function test_core_sessions_row_is_hidden_only_where_the_table_shows(): void {
		$this->current_user = 1;
		$this->user( 1, 'admin', array( 'administrator' ) );
		$profile = new ProfileSessions( new Sessions( new Settings() ) );

		Filters\expectApplied( 'user_session_manager_hide_core_sessions' )->once()->with( true, 1 )->andReturn( true );
		$this->assertTrue( $profile->hides_core_row( 1 ) );
		$this->assertFalse( $profile->hides_core_row( 0 ) );

		Filters\expectApplied( 'session_token_manager' )->andReturn( 'Some_Other_Store' );
		$this->assertFalse( $profile->hides_core_row( 1 ) );
	}

	public function test_site_health_title_matches_what_it_describes(): void {
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'admin_url' )->alias(
			static function ( $path = '' ) {
				return 'http://example.org/wp-admin/' . $path;
			}
		);
		Functions\when( 'add_query_arg' )->justReturn( 'http://example.org/wp-admin/options-general.php?page=user-session-manager' );
		$settings = new Settings();
		$result   = ( new SiteHealth( $settings, new Sessions( $settings ) ) )->run();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertSame( 'Administrator sessions have no idle timeout or session limit', $result['label'] );
		$this->assertStringContainsString( 'stays signed in until it expires', $result['description'] );
	}
}
