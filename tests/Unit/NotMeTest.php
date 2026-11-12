<?php
/**
 * Tests for "This wasn't me" and "Sign everyone out".
 *
 * @package AryanX\Axle\UserSessionManager
 */

namespace AryanX\Axle\UserSessionManager\Tests\Unit;

use AryanX\Axle\UserSessionManager\Incident;
use AryanX\Axle\UserSessionManager\NewDevice;
use AryanX\Axle\UserSessionManager\NotMe;
use AryanX\Axle\UserSessionManager\Sessions;
use AryanX\Axle\UserSessionManager\Settings;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

function wp_parse_url_for_test( string $url, string $part ) {
	return parse_url( $url, $part === 'query' ? PHP_URL_QUERY : PHP_URL_HOST );
}

final class NotMeTest extends SessionTestCase {

	/**
	 * Emails handed to wp_mail().
	 *
	 * @var array[]
	 */
	private array $sent = array();

	/**
	 * Passwords set by wp_set_password(): user ID => password.
	 *
	 * @var array<int, string>
	 */
	private array $passwords = array();

	private Sessions $store;

	protected function setUp(): void {
		parent::setUp();

		$this->sent      = array();
		$this->passwords = array();
		$this->store     = new Sessions( new Settings() );
		Functions\when( 'wp_mail' )->alias(
			function ( $to, $subject, $message ) {
				$this->sent[] = compact( 'to', 'subject', 'message' );
				return true;
			}
		);
		Functions\when( 'wp_set_password' )->alias(
			function ( $password, $user_id ) {
				$this->passwords[ (int) $user_id ] = $password;
			}
		);
		Functions\when( 'wp_generate_password' )->justReturn( 'random-password-64' );
		Functions\when( 'get_password_reset_key' )->justReturn( 'reset-key' );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'get_bloginfo' )->justReturn( 'Example Shop' );
		Functions\when( 'wp_specialchars_decode' )->returnArg();
		Functions\when( 'wp_date' )->justReturn( '13 November 2026 03:00' );
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'network_site_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.com/' . ltrim( (string) $path, '/' );
			}
		);
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $url ) {
				return $url . '?' . http_build_query( $args );
			}
		);
		Functions\when( 'admin_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.com/wp-admin/' . $path;
			}
		);
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->options['admin_email'] = 'owner@example.com';
	}

	private function not_me(): NotMe {
		return new NotMe( new Settings(), $this->store );
	}

	/**
	 * A user with a started session, and the link made for it.
	 *
	 * @return array{0: \WP_User, 1: string, 2: string, 3: array} User, link, key and session.
	 */
	private function link(): array {
		$user     = $this->user( 7, 'alice' );
		$verifier = $this->session( 7, 'laptop' );
		$session  = $this->store->get( 7, $verifier ) + array( 'handle' => $this->store->handle( 7, $verifier ) );
		$link     = $this->not_me()->create_link( $user, $session );
		parse_str( (string) wp_parse_url_for_test( $link, 'query' ), $query );

		return array( $user, $link, (string) $query['usm_key'], $session );
	}

	public function test_the_link_is_random_stored_hashed_and_bound_to_the_session(): void {
		list( , $link, $key, $session ) = $this->link();

		$this->assertStringContainsString( 'wp-login.php?action=user_session_manager_not_me&usm_key=7.', $link );
		$token = substr( $key, 2 );
		$this->assertSame( 48, strlen( $token ) );

		$stored = $this->meta[7][ NotMe::META ];
		$this->assertCount( 1, $stored );
		$this->assertStringNotContainsString( $token, (string) json_encode( $stored ), 'Only a hash of the token is stored.' );
		$link_data = array_values( $stored )[0];
		$this->assertSame( $session['handle'], $link_data['handle'] );
		$this->assertGreaterThan( time() + 6 * DAY_IN_SECONDS, $link_data['expires'], 'Seven days by default.' );
	}

	public function test_two_links_are_different(): void {
		list( , , $first ) = $this->link();
		$user              = $this->users[7];
		$verifier          = $this->session( 7, 'phone' );
		$this->not_me()->create_link( $user, $this->store->get( 7, $verifier ) + array( 'handle' => $this->store->handle( 7, $verifier ) ) );

		$this->assertCount( 2, $this->meta[7][ NotMe::META ] );
		$this->assertNotNull( $this->not_me()->lookup( $first ), 'The first link still works.' );
	}

	public function test_only_a_few_links_are_kept_per_user(): void {
		$user = $this->user( 7, 'alice' );
		for ( $i = 0; $i < 8; $i++ ) {
			$verifier = $this->session( 7, "t{$i}" );
			$this->not_me()->create_link( $user, $this->store->get( 7, $verifier ) + array( 'handle' => $this->store->handle( 7, $verifier ) ) );
		}

		$this->assertCount( 5, $this->meta[7][ NotMe::META ] );
	}

	public function test_a_wrong_expired_or_malformed_key_finds_nothing(): void {
		list( $user, , $key ) = $this->link();
		$not_me               = $this->not_me();

		$this->assertNotNull( $not_me->lookup( $key ) );
		$this->assertNull( $not_me->lookup( '7.' . str_repeat( 'a', 48 ) ) );
		$this->assertNull( $not_me->lookup( '8.' . substr( $key, 2 ) ), 'A token only works for its own user.' );
		$this->assertNull( $not_me->lookup( 'junk' ) );
		$this->assertNull( $not_me->lookup( '' ) );
		$this->assertNull( $not_me->lookup( '7.' . substr( $key, 2 ) . 'ff' ) );

		foreach ( $this->meta[7][ NotMe::META ] as $hash => $stored ) {
			$this->meta[7][ NotMe::META ][ $hash ]['expires'] = time() - 1;
		}
		$this->assertNull( $not_me->lookup( $key ), 'An expired link is gone.' );
		$this->assertSame( 7, $user->ID );
	}

	public function test_the_lifetime_is_filterable_and_clamped(): void {
		Filters\expectApplied( 'user_session_manager_not_me_lifetime' )->once()->andReturn( 5 );
		$this->assertSame( HOUR_IN_SECONDS, ( new Settings() )->not_me_lifetime() );

		Filters\expectApplied( 'user_session_manager_not_me_lifetime' )->once()->andReturn( 99 * DAY_IN_SECONDS );
		$this->assertSame( 30 * DAY_IN_SECONDS, ( new Settings() )->not_me_lifetime() );
	}

	public function test_no_link_is_made_when_the_feature_is_off(): void {
		$this->policies( array(), array( 'not_me' => false ) );
		$user     = $this->user( 7, 'alice' );
		$verifier = $this->session( 7, 'laptop' );

		$link = $this->not_me()->create_link( $user, $this->store->get( 7, $verifier ) + array( 'handle' => $this->store->handle( 7, $verifier ) ) );

		$this->assertSame( '', $link );
		$this->assertArrayNotHasKey( NotMe::META, $this->meta[7] ?? array() );
	}

	public function test_pressing_the_button_signs_out_retires_the_password_emails_and_tells_the_admin(): void {
		list( $user, , $key ) = $this->link();
		$this->session( 7, 'tablet' );
		$not_me = $this->not_me();

		Actions\expectDone( 'user_session_manager_not_me_reported' )->once();
		$report = $not_me->redeem( $not_me->lookup( $key ) );

		$this->assertSame( 2, $report['ended'], 'Every session of the account, by default.' );
		$this->assertSame( array(), $this->stored( 7 ) );
		$this->assertSame( array( 7 => 'random-password-64' ), $this->passwords, 'The password was replaced with one nobody knows.' );
		$this->assertTrue( $report['reset'] );

		$this->assertCount( 2, $this->sent );
		$this->assertSame( $user->user_email, $this->sent[0]['to'] );
		$this->assertStringContainsString( 'action=rp&key=reset-key&login=alice', $this->sent[0]['message'] );
		$this->assertSame( array( 'owner@example.com' ), $this->sent[1]['to'] );
		$this->assertStringContainsString( 'alice', $this->sent[1]['subject'] );
		$this->assertCount( 1, $not_me->events( 7 ) );
		$this->assertSame( 'Firefox on Windows', $not_me->events()[0]['device'] );
	}

	public function test_the_link_works_once(): void {
		list( , , $key ) = $this->link();
		$not_me          = $this->not_me();

		$not_me->redeem( $not_me->lookup( $key ) );

		$this->assertNull( $not_me->lookup( $key ) );
		$this->assertArrayNotHasKey( NotMe::META, $this->meta[7] );
	}

	public function test_the_scope_setting_can_limit_it_to_the_reported_session(): void {
		$this->policies( array(), array( 'not_me_scope' => 'session' ) );
		list( , , $key ) = $this->link();
		$other           = $this->session( 7, 'tablet' );
		$not_me          = $this->not_me();

		$report = $not_me->redeem( $not_me->lookup( $key ) );

		$this->assertSame( 1, $report['ended'] );
		$this->assertSame( array( $other ), array_keys( $this->stored( 7 ) ) );
		$this->assertArrayHasKey( 7, $this->passwords, 'The password is retired either way.' );
	}

	public function test_it_still_secures_the_account_when_the_session_is_already_gone(): void {
		list( , , $key ) = $this->link();
		unset( $this->meta[7][ Sessions::META ] );
		$not_me = $this->not_me();

		$report = $not_me->redeem( $not_me->lookup( $key ) );

		$this->assertSame( 0, $report['ended'] );
		$this->assertArrayHasKey( 7, $this->passwords );
	}

	public function test_recipients_and_the_reset_email_are_filterable(): void {
		list( , , $key ) = $this->link();
		Filters\expectApplied( 'user_session_manager_not_me_recipients' )->once()->andReturn( array( 'security@example.com', 'it@example.com' ) );
		Filters\expectApplied( 'user_session_manager_not_me_reset_email' )->once()->andReturn( false );
		$not_me = $this->not_me();

		$report = $not_me->redeem( $not_me->lookup( $key ) );

		$this->assertFalse( $report['reset'] );
		$this->assertCount( 1, $this->sent );
		$this->assertSame( array( 'security@example.com', 'it@example.com' ), $this->sent[0]['to'] );
	}

	public function test_the_new_device_email_carries_the_link_only_while_the_feature_is_on(): void {
		$user    = $this->user( 7, 'alice' );
		$session = array(
			'ua'     => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
			'ip'     => '203.0.113.9',
			'login'  => time(),
			'handle' => '7-' . str_repeat( 'a', 24 ),
		);
		Functions\when( 'get_dashboard_url' )->justReturn( 'https://example.com/wp-admin/profile.php' );
		$this->options['date_format'] = 'j F Y';
		$this->options['time_format'] = 'H:i';
		$this->policies( array(), array( 'new_device_email' => true ) );
		$this->session( 7, 'old' );
		$settings = new Settings();

		$devices = new NewDevice( $settings, $this->store, new NotMe( $settings, $this->store ) );
		$devices->check( $user, $session );
		$devices->check( $user, array( 'ip' => '198.51.100.4' ) + $session );

		$this->assertCount( 1, $this->sent );
		$this->assertStringContainsString( 'user_session_manager_not_me', $this->sent[0]['message'] );
		$this->assertStringContainsString( 'Nothing happens until you confirm', $this->sent[0]['message'] );

		$this->sent = array();
		$this->policies( array(), array( 'new_device_email' => true, 'not_me' => false ) );
		$settings = new Settings();
		$devices  = new NewDevice( $settings, $this->store, new NotMe( $settings, $this->store ) );
		$devices->check( $user, array( 'ip' => '198.51.100.55' ) + $session );

		$this->assertCount( 1, $this->sent );
		$this->assertStringNotContainsString( 'user_session_manager_not_me', $this->sent[0]['message'] );
	}

	public function test_reports_can_be_listed_by_age_and_user_and_forgotten(): void {
		list( , , $key ) = $this->link();
		$not_me          = $this->not_me();
		$not_me->redeem( $not_me->lookup( $key ) );
		$events                      = $not_me->events();
		$events[0]['time']           = time() - 40 * DAY_IN_SECONDS;
		$this->options[ NotMe::EVENTS ] = $events;

		$this->assertCount( 1, $not_me->events() );
		$this->assertCount( 0, $not_me->events( 0, 30 ) );
		$this->assertCount( 0, $not_me->events( 9 ) );

		$this->assertTrue( $not_me->forget_events( 7 ) );
		$this->assertFalse( $not_me->forget_events( 7 ) );
		$this->assertSame( array(), $not_me->events() );
	}

	public function test_the_report_log_is_capped(): void {
		list( , , $key ) = $this->link();
		Filters\expectApplied( 'user_session_manager_not_me_log_limit' )->andReturn( 5 );
		$this->options[ NotMe::EVENTS ] = array_fill( 0, 9, array( 'user' => 3, 'time' => time(), 'device' => '', 'ip' => '', 'scope' => 'all', 'ended' => 0, 'reset' => true ) );
		$not_me = $this->not_me();

		$not_me->redeem( $not_me->lookup( $key ) );

		$this->assertCount( 5, $not_me->events() );
		$this->assertSame( 7, $not_me->events()[0]['user'] );
	}

	public function test_sign_everyone_out_keeps_you_and_the_chosen_roles_and_can_retire_passwords(): void {
		$this->user( 1, 'admin', array( 'administrator' ) );
		$this->user( 2, 'bob', array( 'editor' ) );
		$this->user( 3, 'carol', array( 'subscriber' ) );
		$this->user( 4, 'dave', array( 'author' ) );
		$this->session( 1, 'a1' );
		$this->session( 2, 'b1' );
		$this->session( 2, 'b2' );
		$this->session( 3, 'c1' );
		// Dave has no session but still needs a new password.
		$this->current_user   = 1;
		\WP_User_Query::$queue = array( array( 1, 2, 3, 4 ) );

		Actions\expectDone( 'user_session_manager_incident_started' )->once();
		Actions\expectDone( 'user_session_manager_incident_finished' )->once();
		$job = ( new Incident( $this->store ) )->run_all(
			array(
				'keep_current'  => true,
				'exclude_roles' => array( 'subscriber' ),
				'passwords'     => true,
			)
		);

		$this->assertTrue( $job['done'] );
		$this->assertNotEmpty( $this->stored( 1 ), 'You stay signed in.' );
		$this->assertSame( array(), $this->stored( 2 ) );
		$this->assertNotEmpty( $this->stored( 3 ), 'The chosen role is left alone.' );
		$this->assertSame( 2, $job['sessions'] );
		$this->assertSame( 1, $job['users'] );
		$this->assertSame( array( 2, 4 ), array_keys( $this->passwords ), 'Not you, not the excluded role, and people with no session too.' );
		$this->assertSame( 1, $job['excluded'] );

		$log = ( new Incident( $this->store ) )->log();
		$this->assertCount( 1, $log );
		$this->assertSame( 1, $log[0]['by'] );
		$this->assertSame( 2, $log[0]['passwords'] );
	}

	public function test_sign_everyone_out_skips_people_the_caller_may_not_edit(): void {
		$this->user( 1, 'admin', array( 'administrator' ) );
		$this->user( 2, 'bob', array( 'editor' ) );
		$this->session( 2, 'b1' );
		$this->current_user = 1;
		Functions\when( 'current_user_can' )->alias(
			static function ( $capability, $user_id = 0 ) {
				return 'edit_user' !== $capability || 2 !== $user_id;
			}
		);
		\WP_User_Query::$queue = array( array( 2 ) );

		$job = ( new Incident( $this->store ) )->run_all();

		$this->assertSame( 1, $job['skipped'] );
		$this->assertNotEmpty( $this->stored( 2 ) );
	}

	public function test_a_job_hands_back_a_cursor_when_its_time_is_up(): void {
		$this->user( 2, 'bob', array( 'editor' ) );
		$this->user( 3, 'carol', array( 'editor' ) );
		$this->session( 2, 'b1' );
		$this->session( 3, 'c1' );
		$incident              = new Incident( $this->store );
		\WP_User_Query::$queue = array( array( 2, 3 ) );

		$job = $incident->start( array( 'check_caps' => false ) );
		$job = $incident->run( $job, 0.0000001 );

		$this->assertFalse( $job['done'] );
		$this->assertSame( 2, $job['after'] );
		$this->assertSame( array(), $this->stored( 2 ) );
		$this->assertNotEmpty( $this->stored( 3 ) );
	}

	public function test_the_typed_words_are_filterable_and_never_empty(): void {
		$this->assertSame( 'SIGN OUT', Incident::confirm_word() );

		Filters\expectApplied( 'user_session_manager_incident_confirm_word' )->once()->andReturn( ' RESET ALL ' );
		$this->assertSame( 'RESET ALL', Incident::confirm_word() );

		Filters\expectApplied( 'user_session_manager_incident_confirm_word' )->once()->andReturn( '  ' );
		$this->assertSame( 'SIGN OUT', Incident::confirm_word() );
	}
}
