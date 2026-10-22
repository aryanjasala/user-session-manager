<?php
/**
 * Removes User Session Manager's settings, device memory and schedule, on every site.
 *
 * The `last_seen` time the plugin adds inside core's `session_tokens` user meta
 * is left alone: core ignores it, and it goes away with each session.
 *
 * @package UserSessionManager
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Removes User Session Manager data from the current site.
 */
function user_session_manager_uninstall_site(): void {
	wp_clear_scheduled_hook( 'user_session_manager_prune' );
	delete_option( 'user_session_manager_settings' );
	delete_option( 'user_session_manager_prune_cursor' );
}

if ( is_multisite() ) {
	$user_session_manager_offset = 0;
	do {
		$user_session_manager_sites = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => $user_session_manager_offset,
			)
		);

		foreach ( $user_session_manager_sites as $user_session_manager_site ) {
			switch_to_blog( $user_session_manager_site );
			user_session_manager_uninstall_site();
			restore_current_blog();
		}

		$user_session_manager_offset += 100;
	} while ( $user_session_manager_sites );

	// Settings are network-wide on multisite.
	delete_site_option( 'user_session_manager_settings' );
	delete_site_option( 'user_session_manager_prune_cursor' );
} else {
	user_session_manager_uninstall_site();
}

// The device memory for the new device email is user meta, shared across a network.
delete_metadata( 'user', 0, 'user_session_manager_devices', '', true );
