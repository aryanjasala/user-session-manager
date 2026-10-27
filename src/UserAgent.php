<?php
/**
 * A small user agent parser.
 *
 * @package AryanX\Axle\UserSessionManager
 */

namespace AryanX\Axle\UserSessionManager;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a user agent string into a browser, an operating system and a device type.
 *
 * It only needs to tell one person's devices apart, so it knows the browsers
 * and systems people sign in with and nothing more. Versions are left out of
 * the label on purpose: a browser update is not a new device.
 */
final class UserAgent {

	/**
	 * Browsers, checked in order: the first pattern that matches wins.
	 * Order matters because Edge, Opera and Samsung Internet also say "Chrome",
	 * and Chrome also says "Safari".
	 */
	private const BROWSERS = array(
		'/\bEdg(?:e|A|iOS)?\/([\d.]+)/'            => 'Edge',
		'/\b(?:OPR|Opera)\/([\d.]+)/'              => 'Opera',
		'/\bSamsungBrowser\/([\d.]+)/'             => 'Samsung Internet',
		'/\bVivaldi\/([\d.]+)/'                    => 'Vivaldi',
		'/\bYaBrowser\/([\d.]+)/'                  => 'Yandex Browser',
		'/\bUCBrowser\/([\d.]+)/'                  => 'UC Browser',
		'/\b(?:Firefox|FxiOS)\/([\d.]+)/'          => 'Firefox',
		'/\bHeadlessChrome\/([\d.]+)/'             => 'Headless Chrome',
		'/\b(?:CriOS)\/([\d.]+)/'                  => 'Chrome',
		'/\bChrom(?:e|ium)\/([\d.]+)/'             => 'Chrome',
		'/\bVersion\/([\d.]+).*\bSafari\//'        => 'Safari',
		'/\bMSIE ([\d.]+)|Trident\/.*rv:([\d.]+)/' => 'Internet Explorer',
		'/\bwp-(?:iphone|android)\/([\d.]+)/i'     => 'WordPress app',
		'/\bWP-CLI\/([\d.]+)/i'                    => 'WP-CLI',
		'/\bcurl\/([\d.]+)/i'                      => 'curl',
		'/\bPostmanRuntime\/([\d.]+)/'             => 'Postman',
		'/\bpython-requests\/([\d.]+)/'            => 'Python',
		'/\bWordPress\/([\d.]+)/'                  => 'WordPress',
	);

	/**
	 * Operating systems, checked in order. iPads that ask for desktop sites say
	 * "Macintosh", which is the best anyone can do from the string alone.
	 */
	private const SYSTEMS = array(
		'/\b(?:iPhone|iPod)\b/'        => 'iOS',
		'/\biPad\b/'                   => 'iPadOS',
		'/\bAndroid\b/'                => 'Android',
		'/\bCrOS\b/'                   => 'ChromeOS',
		'/\bWindows Phone\b/'          => 'Windows Phone',
		'/\bWindows\b/'                => 'Windows',
		'/\b(?:Macintosh|Mac OS X)\b/' => 'macOS',
		'/\bUbuntu\b/'                 => 'Ubuntu',
		'/\b(?:Linux|X11)\b/'          => 'Linux',
	);

	/**
	 * Parses a user agent.
	 *
	 * @param string $ua User agent string.
	 * @return array{browser: string, version: string, os: string, device: string, label: string}
	 */
	public static function parse( string $ua ): array {
		$ua      = substr( trim( $ua ), 0, 500 );
		$browser = '';
		$version = '';
		$os      = '';

		foreach ( self::BROWSERS as $pattern => $name ) {
			if ( preg_match( $pattern, $ua, $match ) ) {
				$browser = $name;
				$version = (string) strtok( $match[1] . ( $match[2] ?? '' ), '.' );
				break;
			}
		}

		foreach ( self::SYSTEMS as $pattern => $name ) {
			if ( preg_match( $pattern, $ua ) ) {
				$os = $name;
				break;
			}
		}

		$parsed = array(
			'browser' => $browser,
			'version' => $version,
			'os'      => $os,
			'device'  => self::device( $ua, $os ),
			'label'   => '',
		);

		/**
		 * Filters a parsed user agent. Use it to plug in a full parser library.
		 *
		 * @param array  $parsed {
		 *     @type string $browser Browser name, or empty when unknown.
		 *     @type string $version Major version.
		 *     @type string $os      Operating system, or empty when unknown.
		 *     @type string $device  desktop, mobile, tablet, bot or unknown.
		 *     @type string $label   What the screens show. Built from browser and os when empty.
		 * }
		 * @param string $ua     The user agent string.
		 */
		$filtered = (array) apply_filters( 'user_session_manager_parse_user_agent', $parsed, $ua );

		$result = array();
		foreach ( $parsed as $key => $default ) {
			$result[ $key ] = isset( $filtered[ $key ] ) && is_scalar( $filtered[ $key ] ) ? (string) $filtered[ $key ] : $default;
		}
		if ( ! in_array( $result['device'], array( 'desktop', 'mobile', 'tablet', 'bot', 'unknown' ), true ) ) {
			$result['device'] = 'unknown';
		}
		if ( '' === $result['label'] ) {
			$result['label'] = self::label( $result['browser'], $result['os'] );
		}

		return $result;
	}

	/**
	 * "Chrome on macOS", "Firefox", "Unknown browser".
	 *
	 * @param string $browser Browser name.
	 * @param string $os      Operating system.
	 * @return string
	 */
	public static function label( string $browser, string $os ): string {
		if ( '' !== $browser && '' !== $os ) {
			/* translators: 1: browser name, e.g. Chrome, 2: operating system, e.g. macOS. */
			return sprintf( __( '%1$s on %2$s', 'user-session-manager' ), $browser, $os );
		}

		if ( '' !== $browser ) {
			return $browser;
		}

		if ( '' !== $os ) {
			/* translators: %s: operating system, e.g. Windows. */
			return sprintf( __( 'Unknown browser on %s', 'user-session-manager' ), $os );
		}

		return __( 'Unknown browser', 'user-session-manager' );
	}

	/**
	 * Device type from the user agent.
	 *
	 * @param string $ua User agent.
	 * @param string $os Operating system already found.
	 * @return string desktop, mobile, tablet, bot or unknown.
	 */
	private static function device( string $ua, string $os ): string {
		if ( '' === $ua ) {
			return 'unknown';
		}

		if ( preg_match( '/bot|crawl|spider|slurp|facebookexternalhit/i', $ua ) ) {
			return 'bot';
		}

		if ( 'iPadOS' === $os || preg_match( '/\bTablet\b|\bKindle\b|\bSilk\//', $ua ) || ( 'Android' === $os && false === strpos( $ua, 'Mobile' ) ) ) {
			return 'tablet';
		}

		if ( in_array( $os, array( 'iOS', 'Android', 'Windows Phone' ), true ) || preg_match( '/\bMobi/', $ua ) ) {
			return 'mobile';
		}

		if ( in_array( $os, array( 'Windows', 'macOS', 'Linux', 'Ubuntu', 'ChromeOS' ), true ) ) {
			return 'desktop';
		}

		return 'unknown';
	}
}
