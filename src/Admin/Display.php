<?php
/**
 * Small formatting helpers for the screens.
 *
 * @package AryanX\Axle\UserSessionManager
 */

namespace AryanX\Axle\UserSessionManager\Admin;

use AryanX\Axle\UserSessionManager\SharedLogins;

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
		'div'    => array(
			'class' => true,
			'id'    => true,
		),
		'p'      => array( 'class' => true ),
		'ul'     => array( 'class' => true ),
		'li'     => array( 'class' => true ),
	);

	/**
	 * Anchor of the shared login evidence.
	 */
	public const SHARED_ANCHOR = 'usm-shared';

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
	 * @param array $row    Row from Sessions::describe().
	 * @param bool  $shared Whether the session is part of the evidence that the account is shared.
	 * @return string HTML.
	 */
	public static function device( array $row, bool $shared = false ): string {
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
			'<span class="usm-device"><span class="dashicons %1$s" aria-hidden="true"></span><span><strong title="%2$s">%3$s</strong>%4$s%5$s<br><small>%6$s</small></span></span>',
			esc_attr( $icons[ $type ] ),
			esc_attr( $row['ua'] ),
			esc_html( $row['device'] ),
			$row['current'] ? ' <span class="usm-badge">' . esc_html__( 'This device', 'user-session-manager' ) . '</span>' : '',
			$shared ? ' ' . self::shared_badge() : '',
			esc_html( $types[ $type ] )
		);
	}

	/**
	 * The "Possibly shared" badge.
	 *
	 * @return string HTML.
	 */
	public static function shared_badge(): string {
		return '<span class="usm-badge usm-badge-shared">' . esc_html__( 'Possibly shared', 'user-session-manager' ) . '</span>';
	}

	/**
	 * Why an account looks shared: each piece of evidence with the sessions behind it.
	 *
	 * @param array $flag Flag from SharedLogins::get().
	 * @return string HTML.
	 */
	public static function shared_evidence( array $flag ): string {
		$items = '';
		foreach ( $flag['evidence'] as $evidence ) {
			$sessions = '';
			foreach ( (array) ( $evidence['handles'] ?? array() ) as $handle ) {
				$session = $flag['sessions'][ $handle ] ?? null;
				if ( ! is_array( $session ) ) {
					continue;
				}
				$where = array_filter( array( (string) ( $session['ip'] ?? '' ), (string) ( $session['country'] ?? '' ) ) );
				$seen  = (int) ( $session['last_seen'] ?? 0 ) ? (int) $session['last_seen'] : (int) ( $session['login'] ?? 0 );

				$sessions .= sprintf(
					'<li><strong>%1$s</strong>, %2$s, %3$s</li>',
					esc_html( (string) ( $session['device'] ?? '' ) ),
					esc_html( $where ? implode( ', ', $where ) : __( 'IP address unknown', 'user-session-manager' ) ),
					/* translators: %s: relative time, such as "3 minutes ago". */
					sprintf( esc_html__( 'last active %s', 'user-session-manager' ), self::time( $seen ) )
				);
			}

			$items .= sprintf(
				'<li>%1$s%2$s</li>',
				esc_html( SharedLogins::evidence_text( $evidence ) ),
				'' !== $sessions ? '<ul class="usm-shared-sessions">' . $sessions . '</ul>' : ''
			);
		}

		if ( 'sign_out_others' === $flag['action'] && $flag['signed_out'] > 0 ) {
			/* translators: %s: number of sessions. */
			$outcome = sprintf( _n( '%s session was signed out. The newest one was kept.', '%s sessions were signed out. The newest one was kept.', $flag['signed_out'], 'user-session-manager' ), number_format_i18n( $flag['signed_out'] ) );
		} elseif ( 'notify' === $flag['action'] ) {
			$outcome = __( 'The user and the site administrator were emailed. Nothing was blocked.', 'user-session-manager' );
		} else {
			$outcome = __( 'Nothing was blocked.', 'user-session-manager' );
		}

		return sprintf(
			'<div class="usm-shared notice notice-warning inline" id="%1$s"><p>%2$s <strong>%3$s</strong></p><p>%4$s</p><ul class="usm-shared-evidence">%5$s</ul><p class="description">%6$s %7$s</p></div>',
			esc_attr( self::SHARED_ANCHOR ),
			self::shared_badge(),
			esc_html__( 'This account looks shared by more than one person.', 'user-session-manager' ),
			sprintf(
				/* translators: 1: when it was first noticed, 2: when it was last seen, both relative such as "2 hours ago". */
				esc_html__( 'First noticed %1$s, last seen %2$s.', 'user-session-manager' ),
				self::time( $flag['detected'] ),
				self::time( $flag['updated'] )
			),
			$items,
			esc_html__( 'This is a guess from the browsers, networks and times of the sessions.', 'user-session-manager' ),
			esc_html( $outcome )
		);
	}
}
