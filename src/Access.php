<?php
/**
 * Who may see and end whose sessions.
 *
 * @package AryanX\UserSessionManager
 */

namespace AryanX\UserSessionManager;

defined( 'ABSPATH' ) || exit;

/**
 * One place for every permission check, shared by the screens, WP-CLI and abilities.
 *
 * Everyone may see and end their own sessions. Seeing everyone's needs
 * list_users plus the plugin capability (edit_users by default), and ending
 * someone else's also needs edit_user for that person, so a site admin on a
 * network cannot sign out a super admin.
 */
final class Access {

	/**
	 * Capability needed to see and end other people's sessions.
	 *
	 * @return string
	 */
	public static function capability(): string {
		/**
		 * Filters the capability needed to see every session and end other people's.
		 *
		 * @param string $capability Default edit_users. list_users is needed as well.
		 */
		$capability = (string) apply_filters( 'user_session_manager_capability', 'edit_users' );

		return '' !== $capability ? $capability : 'edit_users';
	}

	/**
	 * Capability needed to change the policies.
	 *
	 * @return string
	 */
	public static function settings_capability(): string {
		$default = is_multisite() ? 'manage_network_options' : 'manage_options';

		/**
		 * Filters the capability needed to change session policies and settings.
		 *
		 * @param string $capability Default manage_options, or manage_network_options on multisite.
		 */
		$capability = (string) apply_filters( 'user_session_manager_settings_capability', $default );

		return '' !== $capability ? $capability : $default;
	}

	/**
	 * Whether the current user may see everyone's sessions.
	 *
	 * @return bool
	 */
	public static function can_view_all(): bool {
		return current_user_can( 'list_users' ) && current_user_can( self::capability() );
	}

	/**
	 * Whether the current user may see and end a user's sessions.
	 *
	 * @param int $user_id User whose sessions they are.
	 * @return bool
	 */
	public static function can_manage( int $user_id ): bool {
		$current = get_current_user_id();
		if ( $current < 1 || $user_id < 1 ) {
			return false;
		}

		return $current === $user_id || ( self::can_view_all() && current_user_can( 'edit_user', $user_id ) );
	}
}
