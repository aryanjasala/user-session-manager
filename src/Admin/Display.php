<?php
/**
 * Small formatting helpers for the screens.
 *
 * @package AryanX\UserSessionManager
 */

namespace AryanX\UserSessionManager\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Relative times and device icons, already escaped.
 */
final class Display {

	/**
	 * Tags these helpers print, for wp_kses().
	 */
	public const ALLOWED = array(
		'time'   => array(
			'datetime' => true,
			'title'    => true,
		),
		'span'   => array(
			'class'       => true,
			'aria-hidden' => true,
		),
		'strong' => array( 'title' => true ),
		'small'  => array(),
		'br'     => array(),
	);

	/**
	 * "3 hours ago" or "in 2 days", with the exact time on hover.
	 *
	 * @param int    $timestamp Unix time. 0 prints $fallback.
	 * @param string $fallback  Text when there is no time.
	 * @return string HTML.
	 */
	public static function time( int $timestamp, string $fallback = '' ): string {
		if ( $timestamp < 1 ) {
			return esc_html( '' !== $fallback ? $fallback : __( 'Not recorded', 'user-session-manager' ) );
		}

		$now = time();
		if ( abs( $now - $timestamp ) < MINUTE_IN_SECONDS ) {
			$text = __( 'Just now', 'user-session-manager' );
		} elseif ( $timestamp < $now ) {
			/* translators: %s: time span, e.g. "3 hours". */
			$text = sprintf( __( '%s ago', 'user-session-manager' ), human_time_diff( $timestamp, $now ) );
		} else {
			/* translators: %s: time span, e.g. "2 days". */
			$text = sprintf( __( 'in %s', 'user-session-manager' ), human_time_diff( $now, $timestamp ) );
		}

		return sprintf(
			'<time datetime="%1$s" title="%2$s">%3$s</time>',
			esc_attr( gmdate( 'c', $timestamp ) ),
			esc_attr( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) ),
			esc_html( $text )
		);
	}

	/**
	 * The device icon and name.
	 *
	 * @param array $row Row from Sessions::describe().
	 * @return string HTML.
	 */
	public static function device( array $row ): string {
		$icons = array(
			'desktop' => 'dashicons-desktop',
			'mobile'  => 'dashicons-smartphone',
			'tablet'  => 'dashicons-tablet',
			'bot'     => 'dashicons-admin-generic',
			'unknown' => 'dashicons-admin-site-alt3',
		);
		$types = array(
			'desktop' => __( 'Computer', 'user-session-manager' ),
			'mobile'  => __( 'Phone', 'user-session-manager' ),
			'tablet'  => __( 'Tablet', 'user-session-manager' ),
			'bot'     => __( 'Script or bot', 'user-session-manager' ),
			'unknown' => __( 'Unknown device', 'user-session-manager' ),
		);
		$type  = isset( $icons[ $row['device_type'] ] ) ? $row['device_type'] : 'unknown';

		return sprintf(
			'<span class="usm-device"><span class="dashicons %1$s" aria-hidden="true"></span><span><strong title="%2$s">%3$s</strong>%4$s<br><small>%5$s</small></span></span>',
			esc_attr( $icons[ $type ] ),
			esc_attr( $row['ua'] ),
			esc_html( $row['device'] ),
			$row['current'] ? ' <span class="usm-badge">' . esc_html__( 'This device', 'user-session-manager' ) . '</span>' : '',
			esc_html( $types[ $type ] )
		);
	}
}
