<?php
/**
 * The Sessions column on the Users list.
 *
 * @package AryanX\UserSessionManager
 */

namespace AryanX\UserSessionManager\Admin;

use AryanX\UserSessionManager\Access;
use AryanX\UserSessionManager\Service;
use AryanX\UserSessionManager\Sessions;

defined( 'ABSPATH' ) || exit;

/**
 * Shows how many sessions each user has, linked to their sessions.
 *
 * The Users list already loads each user's meta in one query, so the count
 * costs nothing extra.
 */
final class UsersColumn implements Service {

	public const COLUMN = 'usm_sessions';

	/**
	 * Session store.
	 *
	 * @var Sessions
	 */
	private Sessions $sessions;

	/**
	 * Sets up the column.
	 *
	 * @param Sessions $sessions Session store.
	 */
	public function __construct( Sessions $sessions ) {
		$this->sessions = $sessions;
	}

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_filter( 'manage_users_columns', array( $this, 'add_column' ) );
		add_filter( 'wpmu_users_columns', array( $this, 'add_column' ) );
		add_filter( 'manage_users_custom_column', array( $this, 'render' ), 10, 3 );
	}

	/**
	 * Adds the column.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public function add_column( $columns ): array {
		$columns = (array) $columns;
		if ( Access::can_view_all() && $this->sessions->is_supported() ) {
			$columns[ self::COLUMN ] = __( 'Sessions', 'user-session-manager' );
		}

		return $columns;
	}

	/**
	 * Prints a user's session count.
	 *
	 * @param string $output      Column output so far.
	 * @param string $column_name Column.
	 * @param int    $user_id     User ID.
	 * @return string
	 */
	public function render( $output, $column_name, $user_id ): string {
		if ( self::COLUMN !== $column_name ) {
			return (string) $output;
		}

		$count = $this->sessions->count( (int) $user_id );
		if ( 0 === $count ) {
			return '<span aria-hidden="true">0</span><span class="screen-reader-text">' . esc_html__( 'No sessions', 'user-session-manager' ) . '</span>';
		}

		return sprintf(
			'<a href="%1$s" aria-label="%2$s">%3$d</a>',
			esc_url( SessionsPage::url( array( 'user_id' => (int) $user_id ) ) ),
			/* translators: %d: number of sessions. */
			esc_attr( sprintf( _n( '%d active session', '%d active sessions', $count, 'user-session-manager' ), $count ) ),
			$count
		);
	}
}
