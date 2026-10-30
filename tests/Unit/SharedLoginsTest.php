<?php
/**
 * Tests for shared login detection: the checks, the flag, the hooks, the
 * actions per role, and the ability, WP-CLI and privacy pieces around them.
 *
 * @package AryanX\Axle\UserSessionManager
 */

namespace AryanX\Axle\UserSessionManager\Tests\Unit;

use AryanX\Axle\UserSessionManager\Abilities;
use AryanX\Axle\UserSessionManager\Activity;
use AryanX\Axle\UserSessionManager\Admin\Display;
use AryanX\Axle\UserSessionManager\Cli\Command;
use AryanX\Axle\UserSessionManager\NewDevice;
use AryanX\Axle\UserSessionManager\Privacy;
use AryanX\Axle\UserSessionManager\Pruner;
use AryanX\Axle\UserSessionManager\Sessions;
use AryanX\Axle\UserSessionManager\Settings;
use AryanX\Axle\UserSessionManager\SharedLoginDetector;
use AryanX\Axle\UserSessionManager\SharedLogins;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

require_once dirname( __DIR__ ) . '/Stubs/wp-cli.php';

final class SharedLoginsTest extends SessionTestCase {

	private const WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:128.0) Gecko/20100101 Firefox/128.0';
	private const MAC     = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';
	private const IPHONE  = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
	private const ANDROID = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36';
	private const IPAD    = 'Mozilla/5.0 (iPad; CPU OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
	private const EDGE    = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36 Edg/129.0.2792.52';

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
		Functions\when( 'get_dashboard_url' )->justReturn( 'https://example.com/wp-admin/profile.php' );
		Functions\when( 'admin_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.com/wp-admin/' . $path;
			}
		);
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $url ) {
				return $url . '?' . http_build_query( $args );
			}
		);
		Functions\when( 'sanitize_text_field' )->alias( 'trim' );
		Functions\when( 'human_time_diff' )->justReturn( '1 day' );
		$this->options['admin_email'] = 'owner@example.com';
	}

	private function shared(): SharedLogins {
		$settings = new Settings();

		return new SharedLogins( $settings, new Sessions( $settings ) );
	}

	/**
	 * A described session for the detector.
	 *
	 * @param string $handle Handle.
	 * @param string $ua     User agent.
	 * @param string $ip     IP address.
	 * @param int    $ago    Seconds since it was last used.
	 * @param string $country Country.
	 * @return array
	 */
	private function row( string $handle, string $ua, string $ip, int $ago, string $country = '' ): array {
		$parsed = \AryanX\Axle\UserSessionManager\UserAgent::parse( $ua );

		return array(
			'handle'      => $handle,
			'device'      => $parsed['label'],
			'device_type' => $parsed['device'],
			'ip'          => $ip,
			'network'     => SharedLoginDetector::network( $ip ),
			'country'     => $country,
			'login'       => time() - $ago - 600,
			'last_seen'   => time() - $ago,
		);
	}

	/**
	 * Two computers on two networks, used a minute apart: the clearest sign.
	 *
	 * @param int $user_id User ID.
	 */
	private function two_computers( int $user_id ): void {
		$this->session(
			$user_id,
			'windows',
			array(
				'ua'        => self::WINDOWS,
				'ip'        => '192.0.2.10',
				'login'     => time() - 3600,
				'last_seen' => time() - 60,
			)
		);
		$this->session(
			$user_id,
			'mac',
			array(
				'ua'        => self::MAC,
				'ip'        => '198.51.100.20',
				'login'     => time() - 120,
				'last_seen' => time(),
			)
		);
	}

	public function test_networks_are_the_slash_24_or_slash_48(): void {
		$this->assertSame( '203.0.113.0/24', SharedLoginDetector::network( '203.0.113.77' ) );
		$this->assertSame( '203.0.113.0/24', SharedLoginDetector::network( '203.0.113.0' ), 'A shortened address is in the same network.' );
		$this->assertSame( '2001:0db8:0001::/48', SharedLoginDetector::network( '2001:db8:1:2::5' ) );
		$this->assertSame( '', SharedLoginDetector::network( '' ) );
		$this->assertSame( '', SharedLoginDetector::network( 'not an ip' ) );
	}

	public function test_one_person_with_a_phone_and_a_laptop_is_not_flagged(): void {
		$rows = array(
			$this->row( 'a', self::MAC, '192.0.2.10', 30 ),
			$this->row( 'b', self::IPHONE, '198.51.100.20', 0 ),
		);

		$this->assertSame( array(), SharedLoginDetector::detect( $rows, SharedLoginDetector::RULES, time() ) );
	}

	public function test_two_computers_on_different_networks_at_once_are_flagged(): void {
		$rows = array(
			$this->row( 'a', self::WINDOWS, '192.0.2.10', 60 ),
			$this->row( 'b', self::MAC, '198.51.100.20', 0 ),
		);

		$evidence = SharedLoginDetector::detect( $rows, SharedLoginDetector::RULES, time() );

		$this->assertCount( 1, $evidence );
		$this->assertSame( 'same_type', $evidence[0]['type'] );
		$this->assertSame( 'desktop', $evidence[0]['device_type'] );
		$this->assertSame( 2, $evidence[0]['count'] );
		$this->assertSame( 1, $evidence[0]['limit'] );
		$this->assertSame( array( 'a', 'b' ), $evidence[0]['handles'] );
	}

	public function test_sessions_used_hours_apart_are_not_at_the_same_time(): void {
		$rows = array(
			$this->row( 'home', self::WINDOWS, '192.0.2.10', 5 * HOUR_IN_SECONDS ),
			$this->row( 'work', self::MAC, '198.51.100.20', 0 ),
		);

		$this->assertSame( array(), SharedLoginDetector::detect( $rows, SharedLoginDetector::RULES, time() ) );
	}

	public function test_three_networks_and_two_countries_at_once_are_flagged(): void {
		$rows = array(
			$this->row( 'a', self::MAC, '192.0.2.10', 0, 'IN' ),
			$this->row( 'b', self::IPHONE, '198.51.100.20', 60, 'IN' ),
			$this->row( 'c', self::IPAD, '203.0.113.30', 120, 'DE' ),
		);

		$evidence = SharedLoginDetector::detect( $rows, SharedLoginDetector::RULES, time() );

		$this->assertSame( array( 'networks', 'countries' ), array_column( $evidence, 'type' ) );
		$this->assertSame( 3, $evidence[0]['count'] );
		$this->assertSame( array( 'IN', 'DE' ), $evidence[1]['countries'] );
	}

	public function test_too_many_devices_in_the_window_are_flagged(): void {
		$rows = array(
			$this->row( 'a', self::MAC, '192.0.2.10', 10 * HOUR_IN_SECONDS ),
			$this->row( 'b', self::IPHONE, '192.0.2.10', 8 * HOUR_IN_SECONDS ),
			$this->row( 'c', self::IPAD, '192.0.2.10', 6 * HOUR_IN_SECONDS ),
			$this->row( 'd', self::ANDROID, '192.0.2.10', 4 * HOUR_IN_SECONDS ),
			$this->row( 'e', self::EDGE, '192.0.2.10', 0 ),
			$this->row( 'old', self::WINDOWS, '192.0.2.10', 3 * DAY_IN_SECONDS ),
		);

		$evidence = SharedLoginDetector::detect( $rows, SharedLoginDetector::RULES, time() );

		$this->assertSame( array( 'devices' ), array_column( $evidence, 'type' ) );
		$this->assertSame( 5, $evidence[0]['count'] );
		$this->assertNotContains( 'old', $evidence[0]['handles'] );
	}

	public function test_rules_are_clamped_and_zero_turns_a_check_off(): void {
		$rules = SharedLoginDetector::sanitize_rules(
			array(
				'max_networks_per_type' => 0,
				'max_networks'          => -4,
				'overlap'               => 'junk',
				'keep_days'             => 0,
			)
		);

		$this->assertSame( 0, $rules['max_networks_per_type'] );
		$this->assertSame( 0, $rules['max_networks'] );
		$this->assertSame( 900, $rules['overlap'] );
		$this->assertSame( 1, $rules['keep_days'] );
		$this->assertSame( SharedLoginDetector::RULES, SharedLoginDetector::sanitize_rules( 'not an array' ) );

		$rows = array(
			$this->row( 'a', self::WINDOWS, '192.0.2.10', 60 ),
			$this->row( 'b', self::MAC, '198.51.100.20', 0 ),
		);
		$this->assertSame( array(), SharedLoginDetector::detect( $rows, $rules, time() ) );
	}

	public function test_a_shared_account_is_flagged_once_and_the_action_fires_with_the_evidence(): void {
		$user = $this->user( 7, 'alice' );
		$this->two_computers( 7 );

		Actions\expectDone( 'user_session_manager_shared_login_detected' )
			->once()
			->with(
				$user,
				\Mockery::on(
					static function ( $sessions ) {
						return 2 === count( $sessions ) && isset( $sessions[0]['handle'], $sessions[0]['ip'], $sessions[0]['device'] ) && ! isset( $sessions[0]['ua'] );
					}
				),
				\Mockery::on(
					static function ( $evidence ) {
						return 'same_type' === $evidence[0]['type'];
					}
				)
			);

		$flag = $this->shared()->check( $user );

		$this->assertSame( 'flag', $flag['action'] );
		$this->assertSame( $flag['evidence'], $this->meta[7][ SharedLogins::META ]['evidence'] );
		$this->assertSame( array(), $this->sent, 'Flag only: no email.' );

		// The same sessions again: no new action, no second email or sign out.
		$this->assertNull( $this->shared()->check( $user ) );
	}

	public function test_a_session_not_seen_before_triggers_the_action_again(): void {
		$user = $this->user( 7, 'alice' );
		$this->two_computers( 7 );
		$this->shared()->check( $user );

		$this->session(
			7,
			'edge',
			array(
				'ua'        => self::EDGE,
				'ip'        => '203.0.113.5',
				'login'     => time(),
				'last_seen' => time(),
			)
		);

		Actions\expectDone( 'user_session_manager_shared_login_detected' )->once();
		$this->assertNotNull( $this->shared()->check( $user ) );
	}

	public function test_the_rules_filter_gets_the_defaults_and_the_user(): void {
		$user = $this->user( 7, 'alice' );
		$this->two_computers( 7 );

		Filters\expectApplied( 'user_session_manager_shared_login_rules' )
			->once()
			->with( SharedLoginDetector::RULES, $user )
			->andReturn( array( 'max_networks_per_type' => 0 ) + SharedLoginDetector::RULES );
		Actions\expectDone( 'user_session_manager_shared_login_detected' )->never();

		$this->assertNull( $this->shared()->check( $user ) );
		$this->assertArrayNotHasKey( SharedLogins::META, $this->meta[7] );
	}

	public function test_a_role_set_to_off_is_exempt_and_the_filter_decides(): void {
		$user = $this->user( 7, 'alice', array( 'author' ) );
		$this->two_computers( 7 );
		$this->options[ Settings::OPTION ] = array( 'shared' => array( 'author' => 'off' ) );

		Filters\expectApplied( 'user_session_manager_shared_login_exempt' )->once()->with( true, $user )->andReturn( true );
		$this->assertNull( $this->shared()->check( $user ) );

		Filters\expectApplied( 'user_session_manager_shared_login_exempt' )->once()->with( true, $user )->andReturn( false );
		$this->assertSame( 'flag', $this->shared()->check( $user )['action'], 'Not exempt after all, and "off" is not an action.' );
	}

	public function test_the_most_generous_role_decides_and_users_with_no_role_are_flagged(): void {
		$this->options[ Settings::OPTION ] = array(
			'shared' => array(
				'author' => 'sign_out_others',
				'editor' => 'notify',
				'junk'   => 'explode',
			),
		);
		$settings = new Settings();

		$this->assertSame( 'notify', $settings->shared_mode_for( new \WP_User( 1, 'a', array( 'author', 'editor' ) ) ) );
		$this->assertSame( 'sign_out_others', $settings->shared_mode_for( new \WP_User( 2, 'b', array( 'author' ) ) ) );
		$this->assertSame( 'flag', $settings->shared_mode_for( new \WP_User( 3, 'c', array() ) ) );
		$this->assertSame( 'flag', $settings->shared_mode( 'junk' ) );
		$this->assertArrayNotHasKey( 'junk', $settings->get( 'shared' ) );
	}

	public function test_notify_emails_the_user_and_the_administrator_through_the_email_filter(): void {
		$user = $this->user( 7, 'alice' );
		$this->two_computers( 7 );

		Filters\expectApplied( 'user_session_manager_shared_login_action' )
			->once()
			->with( 'flag', $user, \Mockery::type( 'array' ) )
			->andReturn( 'notify' );
		Filters\expectApplied( 'user_session_manager_shared_login_email' )
			->twice()
			->andReturnUsing(
				static function ( $email, $who, $flag, $audience ) {
					$email['subject'] = $audience . ': ' . $email['subject'];
					return $email;
				}
			);

		$this->shared()->check( $user );

		$this->assertCount( 2, $this->sent );
		$this->assertSame( 'alice@example.com', $this->sent[0]['to'] );
		$this->assertSame( 'user: [Example Shop] Your account was used from several places at once', $this->sent[0]['subject'] );
		$this->assertStringContainsString( '- 2 computers on different networks were used at the same time.', $this->sent[0]['message'] );
		$this->assertSame( 'owner@example.com', $this->sent[1]['to'] );
		$this->assertSame( 'admin: [Example Shop] The account alice may be shared', $this->sent[1]['subject'] );
		$this->assertStringContainsString( 'users.php?page=user-sessions&user_id=7', $this->sent[1]['message'] );
		$this->assertSame( 'notify', $this->meta[7][ SharedLogins::META ]['action'] );
	}

	public function test_the_email_filter_can_skip_an_email(): void {
		$user = $this->user( 7, 'alice' );
		$this->two_computers( 7 );
		$this->options[ Settings::OPTION ] = array( 'shared' => array( 'author' => 'notify' ) );

		Filters\expectApplied( 'user_session_manager_shared_login_email' )
			->twice()
			->andReturnUsing(
				static function ( $email, $who, $flag, $audience ) {
					return 'admin' === $audience ? false : $email;
				}
			);

		$this->shared()->check( $user );

		$this->assertSame( array( 'alice@example.com' ), array_column( $this->sent, 'to' ) );
	}

	public function test_sign_out_others_keeps_only_the_newest_login(): void {
		$user = $this->user( 7, 'alice' );
		$this->options[ Settings::OPTION ] = array( 'shared' => array( 'author' => 'sign_out_others' ) );
		$this->two_computers( 7 );
		$newest = Sessions::verifier( 'mac' );

		Actions\expectDone( 'user_session_manager_session_revoked' )->once()->with( 7, \Mockery::type( 'array' ), 'shared' );

		$flag = $this->shared()->check( $user );

		$this->assertSame( array( $newest ), array_keys( $this->stored( 7 ) ) );
		$this->assertSame( 1, $flag['signed_out'] );
		$this->assertSame( 1, $this->meta[7][ SharedLogins::META ]['signed_out'] );
		$this->assertSame( array(), $this->sent );
	}

	public function test_sign_out_others_keeps_the_session_that_just_started_on_a_tie(): void {
		$user = $this->user( 7, 'alice' );
		$this->options[ Settings::OPTION ] = array( 'shared' => array( 'author' => 'sign_out_others' ) );
		$now = time();
		$this->session( 7, 'first', array( 'ua' => self::WINDOWS, 'ip' => '192.0.2.10', 'login' => $now, 'last_seen' => $now ) );
		$second   = $this->session( 7, 'second', array( 'ua' => self::MAC, 'ip' => '198.51.100.20', 'login' => $now, 'last_seen' => $now ) );
		$sessions = new Sessions( new Settings() );

		$this->shared()->on_login( $user, array( 'handle' => $sessions->handle( 7, $second ) ) );

		$this->assertSame( array( $second ), array_keys( $this->stored( 7 ) ) );
	}

	public function test_an_unknown_action_from_the_filter_falls_back_to_flag(): void {
		$user = $this->user( 7, 'alice' );
		$this->two_computers( 7 );

		Filters\expectApplied( 'user_session_manager_shared_login_action' )->once()->andReturn( 'block' );

		$this->assertSame( 'flag', $this->shared()->check( $user )['action'] );
		$this->assertCount( 2, $this->stored( 7 ), 'Never blocks or signs out on a guess unless asked.' );
	}

	public function test_the_country_filter_adds_countries(): void {
		$user = $this->user( 7, 'alice' );
		$this->session(
			7,
			'phone',
			array(
				'ua'        => self::IPHONE,
				'ip'        => '192.0.2.10',
				'last_seen' => time(),
			)
		);
		$this->session(
			7,
			'laptop',
			array(
				'ua'        => self::MAC,
				'ip'        => '198.51.100.20',
				'last_seen' => time(),
			)
		);

		Filters\expectApplied( 'user_session_manager_shared_login_country' )
			->twice()
			->andReturnUsing(
				static function ( $country, $ip, $user_id ) {
					return 7 === $user_id && '192.0.2.10' === $ip ? 'IN' : 'BR';
				}
			);

		$flag = $this->shared()->check( $user );

		$this->assertSame( array( 'countries' ), array_column( $flag['evidence'], 'type' ) );
		$this->assertSame( 'Used from 2 countries at the same time: BR, IN.', SharedLogins::evidence_text( $flag['evidence'][0] ) );
	}

	public function test_evidence_reads_as_a_sentence_and_the_filter_can_change_it(): void {
		$this->assertSame( 'Used from 3 different networks at the same time, more than the 2 allowed.', SharedLogins::evidence_text( array( 'type' => 'networks', 'count' => 3, 'limit' => 2 ) ) );
		$this->assertSame( '2 phones on different networks were used at the same time.', SharedLogins::evidence_text( array( 'type' => 'same_type', 'device_type' => 'mobile', 'count' => 2 ) ) );
		$this->assertSame( '5 different devices were used within 1 day, more than the 4 allowed.', SharedLogins::evidence_text( array( 'type' => 'devices', 'count' => 5, 'limit' => 4, 'window' => DAY_IN_SECONDS ) ) );

		Filters\expectApplied( 'user_session_manager_shared_login_evidence_text' )->once()->with( \Mockery::type( 'string' ), array( 'type' => 'networks', 'count' => 3, 'limit' => 2 ) )->andReturn( 'Three places.' );
		$this->assertSame( 'Three places.', SharedLogins::evidence_text( array( 'type' => 'networks', 'count' => 3, 'limit' => 2 ) ) );

		Filters\expectApplied( 'user_session_manager_shared_login_evidence_text' )->once()->andReturn( ' ' );
		$this->assertStringStartsWith( 'Used from', SharedLogins::evidence_text( array( 'type' => 'networks', 'count' => 3, 'limit' => 2 ) ) );
	}

	public function test_an_old_flag_is_dropped_after_keep_days(): void {
		$this->user( 7, 'alice' );
		$this->meta[7][ SharedLogins::META ] = array(
			'detected' => time() - 9 * DAY_IN_SECONDS,
			'updated'  => time() - 8 * DAY_IN_SECONDS,
			'evidence' => array( array( 'type' => 'networks' ) ),
		);

		$this->assertNull( $this->shared()->get( 7 ) );
		$this->assertArrayNotHasKey( SharedLogins::META, $this->meta[7] );

		$this->meta[7][ SharedLogins::META ] = array(
			'updated'  => time() - 8 * DAY_IN_SECONDS,
			'evidence' => array( array( 'type' => 'networks' ) ),
		);
		Filters\expectApplied( 'user_session_manager_shared_login_rules' )->once()->andReturn( array( 'keep_days' => 30 ) );
		$this->assertSame( 'flag', $this->shared()->get( 7 )['action'] );
	}

	public function test_login_and_activity_both_run_the_check(): void {
		$user = $this->user( 7, 'alice' );
		$this->two_computers( 7 );

		Actions\expectDone( 'user_session_manager_shared_login_detected' )->once();
		$this->shared()->on_activity( 7 );
		$this->shared()->on_activity( 99 );

		unset( $this->meta[7][ SharedLogins::META ] );
		Actions\expectDone( 'user_session_manager_shared_login_detected' )->once();
		$this->shared()->on_login( $user );
	}

	public function test_saving_last_active_announces_the_session(): void {
		$settings = new Settings();
		$this->user( 7, 'alice' );
		$this->current_user = 7;
		$this->token        = 'mine';
		$verifier           = $this->session( 7, 'mine', array( 'last_seen' => time() - 400 ) );

		Actions\expectDone( 'user_session_manager_session_active' )
			->once()
			->with(
				7,
				\Mockery::on(
					static function ( $session ) {
						return isset( $session['handle'] ) && abs( time() - $session['last_seen'] ) < 3;
					}
				)
			);

		( new Activity( $settings, new Sessions( $settings ) ) )->track();
		// Within the interval nothing is saved, so nothing is announced.
		( new Activity( $settings, new Sessions( $settings ) ) )->track();

		$this->assertArrayHasKey( $verifier, $this->stored( 7 ) );
	}

	public function test_a_query_for_no_users_finds_nothing(): void {
		$this->session( 7, 'a' );
		$sessions = new Sessions( new Settings() );

		$this->assertSame( 0, $sessions->query( array( 'user_ids' => array() ) )['total'] );
		$this->assertSame( 0, $sessions->query( array( 'user_ids' => array( 8 ), 'user_id' => 7 ) )['total'] );
		$this->assertSame( array(), \WP_User_Query::$made, 'No database query when the answer is known.' );

		\WP_User_Query::$queue = array( array( 7 ) );
		$this->assertSame( 1, $sessions->query( array( 'user_ids' => array( 7 ) ) )['total'] );
		$this->assertSame( array( 7 ), \WP_User_Query::$made[0]['include'] );
	}

	public function test_flagged_lists_current_flags_newest_first(): void {
		$this->user( 7, 'alice' );
		$this->user( 8, 'bob' );
		$this->user( 9, 'carol' );
		foreach ( array( 7 => 600, 8 => 60, 9 => 30 * DAY_IN_SECONDS ) as $id => $ago ) {
			$this->meta[ $id ][ SharedLogins::META ] = array(
				'detected' => time() - $ago,
				'updated'  => time() - $ago,
				'evidence' => array( array( 'type' => 'networks' ) ),
			);
		}
		\WP_User_Query::$queue = array( array( 7, 8, 9 ) );

		$this->assertSame( array( 8, 7 ), array_keys( $this->shared()->flagged() ) );
		$this->assertSame( SharedLogins::META, \WP_User_Query::$made[0]['meta_key'] );
	}

	public function test_the_ability_lists_flagged_accounts_with_their_reasons(): void {
		$user = $this->user( 7, 'alice' );
		$this->two_computers( 7 );
		$settings = new Settings();
		$sessions = new Sessions( $settings );
		$shared   = new SharedLogins( $settings, $sessions );
		$shared->check( $user );
		Functions\when( 'is_email' )->justReturn( false );

		\WP_User_Query::$queue = array( array( 7 ) );
		$all = ( new Abilities( $sessions, $shared ) )->list_shared_logins();

		$this->assertSame( 1, $all['total'] );
		$account = $all['accounts'][0];
		$this->assertSame( 'alice', $account['user_login'] );
		$this->assertSame( 'flag', $account['action'] );
		$this->assertSame( 'same_type', $account['evidence'][0]['type'] );
		$this->assertSame( '2 computers on different networks were used at the same time.', $account['evidence'][0]['summary'] );
		$this->assertSame( array( '198.51.100.20', '192.0.2.10' ), array_column( $account['sessions'], 'ip' ), 'Most recently active first.' );
		$this->assertMatchesRegularExpression( '/^7-[0-9a-f]{24}$/', $account['sessions'][0]['handle'] );

		$this->assertSame( 1, ( new Abilities( $sessions, $shared ) )->list_shared_logins( array( 'user' => 'alice' ) )['total'] );
		$this->assertSame( 0, ( new Abilities( $sessions, $shared ) )->list_shared_logins( array( 'user' => 'nobody' ) )['total'] );
	}

	public function test_privacy_exports_and_erases_the_flag(): void {
		$user = $this->user( 7, 'alice' );
		$this->two_computers( 7 );
		Functions\when( 'wp_date' )->justReturn( '30 October 2026 10:00' );
		$this->options['date_format'] = 'j F Y';
		$this->options['time_format'] = 'H:i';
		$settings = new Settings();
		$sessions = new Sessions( $settings );
		$shared   = new SharedLogins( $settings, $sessions );
		$shared->check( $user );
		$privacy = new Privacy( $settings, $sessions, new NewDevice( $settings, $sessions ), $shared );

		$groups = array_count_values( array_column( $privacy->export( 'alice@example.com' )['data'], 'group_id' ) );
		$this->assertSame( 2, $groups['user-session-manager-shared'] );

		$this->assertTrue( $privacy->erase( 'alice@example.com' )['items_removed'] );
		$this->assertArrayNotHasKey( SharedLogins::META, $this->meta[7] );
		$this->assertNull( $shared->get( 7 ) );
	}

	public function test_the_evidence_box_names_each_session_and_what_was_done(): void {
		Functions\when( 'wp_date' )->justReturn( '30 October 2026 10:00' );
		$flag = array(
			'detected'   => time() - 600,
			'updated'    => time() - 60,
			'action'     => 'sign_out_others',
			'signed_out' => 1,
			'evidence'   => array(
				array(
					'type'        => 'same_type',
					'device_type' => 'desktop',
					'count'       => 2,
					'limit'       => 1,
					'handles'     => array( '7-a', '7-b', '7-gone' ),
				),
			),
			'sessions'   => array(
				'7-a' => array(
					'device'    => 'Firefox on Windows',
					'ip'        => '192.0.2.10',
					'country'   => 'IN',
					'last_seen' => time() - 120,
				),
				'7-b' => array(
					'device' => 'Chrome on macOS',
					'ip'     => '',
					'login'  => time() - 60,
				),
			),
		);

		$html = Display::shared_evidence( $flag );

		$this->assertStringContainsString( 'id="usm-shared"', $html );
		$this->assertStringContainsString( 'Possibly shared', $html );
		$this->assertStringContainsString( '2 computers on different networks were used at the same time.', $html );
		$this->assertStringContainsString( '<strong>Firefox on Windows</strong>, 192.0.2.10, IN, last active', $html );
		$this->assertStringContainsString( '<strong>Chrome on macOS</strong>, IP address unknown', $html );
		$this->assertSame( 2, substr_count( $html, '<li><strong>' ), 'A session missing from the copy is left out.' );
		$this->assertStringContainsString( '1 session was signed out. The newest one was kept.', $html );
		$this->assertStringContainsString( 'usm-badge-shared', Display::device( array( 'device_type' => 'desktop', 'ua' => '', 'device' => 'x', 'current' => false ), true ) );
	}

	public function test_cli_lists_flagged_accounts_and_sets_the_mode_per_role(): void {
		\WP_CLI::reset();
		$user = $this->user( 7, 'alice' );
		$this->two_computers( 7 );
		$settings = new Settings();
		$sessions = new Sessions( $settings );
		$shared   = new SharedLogins( $settings, $sessions );
		$shared->check( $user );
		$roles = new class() {
			public function get_names() {
				return array(
					'author' => 'Author',
					'editor' => 'Editor',
				);
			}

			public function is_role( $role ) {
				return isset( $this->get_names()[ $role ] );
			}
		};
		Functions\when( 'wp_roles' )->justReturn( $roles );
		$command = new Command( $settings, $sessions, new Pruner( $settings, $sessions ), $shared );

		\WP_User_Query::$queue = array( array( 7 ) );
		$command->shared( array(), array( 'format' => 'json' ) );
		$listed = \WP_CLI::$formatted[0];
		$this->assertSame( 'json', $listed['format'] );
		$this->assertSame( array( 'user', 'first_noticed', 'last_seen', 'action', 'sessions', 'evidence' ), $listed['fields'] );
		$this->assertSame( 'alice', $listed['items'][0]['user'] );
		$this->assertSame( 2, $listed['items'][0]['sessions'] );
		$this->assertStringContainsString( 'computers on different networks', $listed['items'][0]['evidence'] );

		\WP_User_Query::$queue = array( array( 7 ) );
		$command->shared( array(), array( 'format' => 'ids' ) );
		$this->assertSame( '7', end( \WP_CLI::$lines ) );

		$command->set_policy( array( 'editor' ), array( 'shared' => 'notify' ) );
		$this->assertSame( 'notify', $settings->shared_mode( 'editor' ) );
		$command->policies( array(), array( 'format' => 'json' ) );
		$this->assertSame( 'notify', \WP_CLI::$formatted[1]['items'][1]['shared'] );
		$this->assertContains( 'shared', \WP_CLI::$formatted[1]['fields'] );

		$this->expectException( \RuntimeException::class );
		$command->set_policy( array( 'editor' ), array( 'shared' => 'block' ) );
	}
}
