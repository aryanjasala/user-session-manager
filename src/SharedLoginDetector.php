<?php
/**
 * The checks behind shared login detection.
 *
 * @package AryanX\Axle\UserSessionManager
 */

namespace AryanX\Axle\UserSessionManager;

defined( 'ABSPATH' ) || exit;

/**
 * Looks at one user's sessions and says what, if anything, suggests that
 * several people sign in with the account.
 *
 * It only reads what the sessions already hold: the IP address (shortened when
 * the site asks for that), the parsed browser and the login and last active
 * times. A country is only known when a filter supplies one.
 *
 * Sessions count as used "at the same time" when their last activity is within
 * the overlap of the most recently used session. One person with a phone and a
 * laptop on two networks is normal, so the defaults only flag:
 *
 * - more than 2 networks at the same time (`networks`),
 * - more than 1 country at the same time (`countries`),
 * - the same kind of device (two computers, two phones) on different networks
 *   at the same time (`same_type`),
 * - more than 4 different devices within a day (`devices`).
 */
final class SharedLoginDetector {

	/**
	 * Default rules. 0 turns a check off.
	 */
	public const RULES = array(
		'overlap'               => 900,
		'max_networks'          => 2,
		'max_countries'         => 1,
		'max_networks_per_type' => 1,
		'window'                => 86400,
		'max_devices'           => 4,
		'keep_days'             => 7,
	);

	/**
	 * Highest value each rule accepts.
	 */
	private const RULES_MAX = array(
		'overlap'               => 86400,
		'max_networks'          => 100,
		'max_countries'         => 100,
		'max_networks_per_type' => 100,
		'window'                => 2592000,
		'max_devices'           => 100,
		'keep_days'             => 365,
	);

	/**
	 * Device types that can tell two people apart. Scripts and unknown browsers cannot.
	 */
	private const PERSONAL_TYPES = array( 'desktop', 'mobile', 'tablet' );

	/**
	 * Cleans a set of rules: integers within range, defaults for anything missing.
	 *
	 * @param mixed $rules Rules from the filter.
	 * @return array<string, int>
	 */
	public static function sanitize_rules( $rules ): array {
		$rules = is_array( $rules ) ? $rules : array();
		$clean = array();
		foreach ( self::RULES as $key => $default ) {
			$value         = isset( $rules[ $key ] ) && is_numeric( $rules[ $key ] ) ? (int) $rules[ $key ] : $default;
			$clean[ $key ] = max( 0, min( self::RULES_MAX[ $key ], $value ) );
		}
		$clean['keep_days'] = max( 1, $clean['keep_days'] );

		return $clean;
	}

	/**
	 * The network an IP address belongs to: its /24 for IPv4, its /48 for IPv6.
	 *
	 * @param string $ip Address, full or shortened.
	 * @return string Empty when the address is missing or not valid.
	 */
	public static function network( string $ip ): string {
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return implode( '.', array_slice( explode( '.', $ip ), 0, 3 ) ) . '.0/24';
		}

		$packed = filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ? inet_pton( $ip ) : false;
		if ( false === $packed ) {
			return '';
		}

		return implode( ':', str_split( bin2hex( substr( $packed, 0, 6 ) ), 4 ) ) . '::/48';
	}

	/**
	 * What suggests the account is shared.
	 *
	 * @param array[] $sessions Sessions from Sessions::describe(), each with `network` and `country` added.
	 * @param int[]   $rules    Rules from sanitize_rules().
	 * @param int     $now      Current time.
	 * @return array[] Evidence, each with type, count, limit, handles and time, plus device_type or countries where they apply.
	 */
	public static function detect( array $sessions, array $rules, int $now ): array {
		if ( count( $sessions ) < 2 ) {
			return array();
		}

		$latest = 0;
		foreach ( $sessions as $session ) {
			$latest = max( $latest, self::activity( $session ) );
		}
		$together = array_values(
			array_filter(
				$sessions,
				static function ( array $session ) use ( $latest, $rules ): bool {
					return self::activity( $session ) >= $latest - $rules['overlap'];
				}
			)
		);

		$evidence = array();

		$networks = self::distinct( $together, 'network' );
		if ( $rules['max_networks'] > 0 && count( $networks ) > $rules['max_networks'] ) {
			$evidence[] = self::evidence( 'networks', count( $networks ), $rules['max_networks'], self::with( $together, 'network' ), $latest );
		}

		$countries = self::distinct( $together, 'country' );
		if ( $rules['max_countries'] > 0 && count( $countries ) > $rules['max_countries'] ) {
			$evidence[] = self::evidence( 'countries', count( $countries ), $rules['max_countries'], self::with( $together, 'country' ), $latest ) + array( 'countries' => $countries );
		}

		if ( $rules['max_networks_per_type'] > 0 ) {
			foreach ( self::PERSONAL_TYPES as $type ) {
				$same  = array_values(
					array_filter(
						self::with( $together, 'network' ),
						static function ( array $session ) use ( $type ): bool {
							return $type === $session['device_type'];
						}
					)
				);
				$count = count( self::distinct( $same, 'network' ) );
				if ( $count > $rules['max_networks_per_type'] ) {
					$evidence[] = self::evidence( 'same_type', $count, $rules['max_networks_per_type'], $same, $latest ) + array( 'device_type' => $type );
				}
			}
		}

		if ( $rules['max_devices'] > 0 && $rules['window'] > 0 ) {
			$recent  = array_values(
				array_filter(
					$sessions,
					static function ( array $session ) use ( $now, $rules ): bool {
						return self::activity( $session ) >= $now - $rules['window'];
					}
				)
			);
			$devices = array();
			foreach ( $recent as $session ) {
				$devices[ $session['device'] . '|' . $session['device_type'] ] = true;
			}
			if ( count( $devices ) > $rules['max_devices'] ) {
				$evidence[] = self::evidence( 'devices', count( $devices ), $rules['max_devices'], $recent, $latest ) + array( 'window' => $rules['window'] );
			}
		}

		return $evidence;
	}

	/**
	 * When a session was last used: its last active time, or its login before one is recorded.
	 *
	 * @param array $session Described session.
	 * @return int
	 */
	public static function activity( array $session ): int {
		return (int) ( ! empty( $session['last_seen'] ) ? $session['last_seen'] : ( $session['login'] ?? 0 ) );
	}

	/**
	 * One piece of evidence.
	 *
	 * @param string  $type     networks, countries, same_type or devices.
	 * @param int     $count    What was found.
	 * @param int     $limit    What the rule allows.
	 * @param array[] $sessions Sessions behind it.
	 * @param int     $time     When the sessions were used together.
	 * @return array
	 */
	private static function evidence( string $type, int $count, int $limit, array $sessions, int $time ): array {
		return array(
			'type'    => $type,
			'count'   => $count,
			'limit'   => $limit,
			'handles' => array_values( array_unique( array_column( $sessions, 'handle' ) ) ),
			'time'    => $time,
		);
	}

	/**
	 * Distinct non-empty values of one field.
	 *
	 * @param array[] $sessions Sessions.
	 * @param string  $field    network or country.
	 * @return string[]
	 */
	private static function distinct( array $sessions, string $field ): array {
		return array_values( array_unique( array_filter( array_map( 'strval', array_column( $sessions, $field ) ) ) ) );
	}

	/**
	 * Sessions where a field is known.
	 *
	 * @param array[] $sessions Sessions.
	 * @param string  $field    network or country.
	 * @return array[]
	 */
	private static function with( array $sessions, string $field ): array {
		return array_values(
			array_filter(
				$sessions,
				static function ( array $session ) use ( $field ): bool {
					return '' !== (string) ( $session[ $field ] ?? '' );
				}
			)
		);
	}
}
