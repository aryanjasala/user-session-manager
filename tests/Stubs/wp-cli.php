<?php
/**
 * Stand-ins for the WP-CLI pieces the commands use, so they run in unit tests.
 *
 * @package AryanX\Axle\UserSessionManager
 */

// phpcs:ignoreFile

namespace {
	if ( ! class_exists( 'WP_CLI' ) ) {
		/**
		 * Records what a command printed. error() throws, as the real one exits.
		 */
		class WP_CLI {
			/** @var string[] */
			public static $lines = array();
			/** @var array[] */
			public static $formatted = array();
			/** @var array */
			public static $config = array();

			public static function reset() {
				self::$lines     = array();
				self::$formatted = array();
				self::$config    = array();
			}

			public static function line( $message = '' ) {
				self::$lines[] = (string) $message;
			}

			public static function success( $message ) {
				self::$lines[] = 'Success: ' . $message;
			}

			public static function warning( $message ) {
				self::$lines[] = 'Warning: ' . $message;
			}

			public static function error( $message ) {
				throw new \RuntimeException( (string) $message );
			}

			public static function get_config( $key = null ) {
				return self::$config[ $key ] ?? null;
			}
		}
	}
}

namespace WP_CLI\Utils {
	if ( ! function_exists( 'WP_CLI\Utils\format_items' ) ) {
		/**
		 * Records the rows a command would print.
		 *
		 * @param string $format Format.
		 * @param array  $items  Rows.
		 * @param array  $fields Fields.
		 */
		function format_items( $format, $items, $fields ) {
			\WP_CLI::$formatted[] = array(
				'format' => $format,
				'items'  => $items,
				'fields' => $fields,
			);
		}
	}
}
