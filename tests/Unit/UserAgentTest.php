<?php
/**
 * Tests for the user agent parser.
 *
 * @package AryanX\UserSessionManager
 */

namespace AryanX\UserSessionManager\Tests\Unit;

use AryanX\UserSessionManager\UserAgent;
use Brain\Monkey\Filters;

final class UserAgentTest extends TestCase {

	/**
	 * User agents people really sign in with.
	 *
	 * @return array[]
	 */
	public function agents(): array {
		return array(
			'chrome mac'       => array( 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36', 'Chrome', '129', 'macOS', 'desktop' ),
			'firefox windows'  => array( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:128.0) Gecko/20100101 Firefox/128.0', 'Firefox', '128', 'Windows', 'desktop' ),
			'edge windows'     => array( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36 Edg/129.0.2792.52', 'Edge', '129', 'Windows', 'desktop' ),
			'opera linux'      => array( 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36 OPR/114.0.0.0', 'Opera', '114', 'Linux', 'desktop' ),
			'safari iphone'    => array( 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1', 'Safari', '17', 'iOS', 'mobile' ),
			'chrome iphone'    => array( 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/129.0.6668.69 Mobile/15E148 Safari/604.1', 'Chrome', '129', 'iOS', 'mobile' ),
			'safari ipad'      => array( 'Mozilla/5.0 (iPad; CPU OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1', 'Safari', '17', 'iPadOS', 'tablet' ),
			'chrome android'   => array( 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36', 'Chrome', '129', 'Android', 'mobile' ),
			'android tablet'   => array( 'Mozilla/5.0 (Linux; Android 13; SM-X710) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36', 'Chrome', '129', 'Android', 'tablet' ),
			'samsung internet' => array( 'Mozilla/5.0 (Linux; Android 14; SM-S921B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/25.0 Chrome/121.0.0.0 Mobile Safari/537.36', 'Samsung Internet', '25', 'Android', 'mobile' ),
			'chromebook'       => array( 'Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36', 'Chrome', '129', 'ChromeOS', 'desktop' ),
			'firefox ubuntu'   => array( 'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0', 'Firefox', '130', 'Ubuntu', 'desktop' ),
			'wordpress app'    => array( 'wp-iphone/25.2 (iOS 17.5, iPhone)', 'WordPress app', '25', 'iOS', 'mobile' ),
			'curl'             => array( 'curl/8.7.1', 'curl', '8', '', 'unknown' ),
			'googlebot'        => array( 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', '', '', '', 'bot' ),
		);
	}

	/**
	 * @dataProvider agents
	 */
	public function test_it_tells_browsers_systems_and_devices_apart( string $ua, string $browser, string $version, string $os, string $device ): void {
		$parsed = UserAgent::parse( $ua );

		$this->assertSame( $browser, $parsed['browser'] );
		$this->assertSame( $version, $parsed['version'] );
		$this->assertSame( $os, $parsed['os'] );
		$this->assertSame( $device, $parsed['device'] );
	}

	public function test_labels_leave_out_the_version(): void {
		$this->assertSame( 'Safari on iOS', UserAgent::parse( 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1' )['label'] );
		$this->assertSame( 'curl', UserAgent::label( 'curl', '' ) );
		$this->assertSame( 'Unknown browser on Windows', UserAgent::label( '', 'Windows' ) );
		$this->assertSame( 'Unknown browser', UserAgent::parse( '' )['label'] );
		$this->assertSame( 'unknown', UserAgent::parse( '' )['device'] );
	}

	public function test_a_filter_can_plug_in_another_parser_and_bad_values_fall_back(): void {
		Filters\expectApplied( 'user_session_manager_parse_user_agent' )
			->once()
			->with( \Mockery::type( 'array' ), 'Custom/1.0' )
			->andReturn(
				array(
					'browser' => 'Custom',
					'os'      => 'PlanOS',
					'device'  => 'fridge',
					'version' => array( 'not', 'a', 'string' ),
				)
			);

		$parsed = UserAgent::parse( 'Custom/1.0' );

		$this->assertSame( 'Custom on PlanOS', $parsed['label'] );
		$this->assertSame( 'unknown', $parsed['device'] );
		$this->assertSame( '', $parsed['version'] );
	}
}
