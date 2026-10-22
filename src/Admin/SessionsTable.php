<?php
/**
 * The list table on Users > Sessions.
 *
 * @package AryanX\UserSessionManager
 */

namespace AryanX\UserSessionManager\Admin;

use AryanX\UserSessionManager\Access;
use AryanX\UserSessionManager\Sessions;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Every active session across users, with search, a role filter, sorting and paging.
 */
final class SessionsTable extends \WP_List_Table {

	private const PER_PAGE = 20;

	/**
	 * Session store.
	 *
	 * @var Sessions
	 */
	private Sessions $sessions;

	/**
	 * Signed-in users in the current result.
	 *
	 * @var int
	 */
	public int $users = 0;

	/**
	 * Whether the scan stopped at the user limit.
	 *
	 * @var bool
	 */
	public bool $truncated = false;

	/**
	 * Sets up the table.
	 *
	 * @param Sessions $sessions Session store.
	 */
	public function __construct( Sessions $sessions ) {
		$this->sessions = $sessions;

		parent::__construct(
			array(
				'singular' => 'session',
				'plural'   => 'sessions',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Rows per page.
	 *
	 * @return int
	 */
	public static function per_page(): int {
		/**
		 * Filters how many sessions Users > Sessions shows per page.
		 *
		 * @param int $per_page Default 20.
		 */
		return max( 1, min( 500, (int) apply_filters( 'user_session_manager_list_per_page', self::PER_PAGE ) ) );
	}

	/**
	 * Loads the sessions for the current request.
	 */
	public function prepare_items(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list arguments.
		$args = array(
			'search'   => isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '',
			'role'     => isset( $_REQUEST['role'] ) && ! is_network_admin() ? sanitize_key( wp_unslash( $_REQUEST['role'] ) ) : '',
			'user_id'  => isset( $_REQUEST['user_id'] ) ? absint( $_REQUEST['user_id'] ) : 0,
			'orderby'  => isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'last_seen',
			'order'    => isset( $_REQUEST['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_REQUEST['order'] ) ) ) ? 'asc' : 'desc',
			'paged'    => $this->get_pagenum(),
			'per_page' => self::per_page(),
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$found           = $this->sessions->query( $args );
		$this->items     = $found['rows'];
		$this->users     = $found['users'];
		$this->truncated = $found['truncated'];

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'user' );
		$this->set_pagination_args(
			array(
				'total_items' => $found['total'],
				'per_page'    => $args['per_page'],
			)
		);
	}

	/**
	 * Columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns() {
		return array(
			'cb'         => '<input type="checkbox" />',
			'user'       => __( 'User', 'user-session-manager' ),
			'device'     => __( 'Device', 'user-session-manager' ),
			'ip'         => __( 'IP address', 'user-session-manager' ),
			'login'      => __( 'Signed in', 'user-session-manager' ),
			'last_seen'  => __( 'Last active', 'user-session-manager' ),
			'expiration' => __( 'Expires', 'user-session-manager' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array<string, array>
	 */
	protected function get_sortable_columns() {
		return array(
			'user'       => array( 'user', false ),
			'login'      => array( 'login', true ),
			'last_seen'  => array( 'last_seen', true ),
			'expiration' => array( 'expiration', true ),
		);
	}

	/**
	 * Bulk actions.
	 *
	 * @return array<string, string>
	 */
	protected function get_bulk_actions() {
		return array(
			'revoke'   => __( 'Sign out', 'user-session-manager' ),
			'sign_out' => __( 'Sign out everywhere', 'user-session-manager' ),
		);
	}

	/**
	 * Text when there is nothing to list.
	 */
	public function no_items(): void {
		esc_html_e( 'No active sessions found.', 'user-session-manager' );
	}

	/**
	 * Role filter above the table.
	 *
	 * @param string $which top or bottom.
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which || is_network_admin() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter value.
		$current = isset( $_REQUEST['role'] ) ? sanitize_key( wp_unslash( $_REQUEST['role'] ) ) : '';
		?>
		<div class="alignleft actions">
			<label for="usm-role" class="screen-reader-text"><?php esc_html_e( 'Filter by role', 'user-session-manager' ); ?></label>
			<select name="role" id="usm-role">
				<option value=""><?php esc_html_e( 'All roles', 'user-session-manager' ); ?></option>
				<?php foreach ( wp_roles()->get_names() as $role => $name ) : ?>
					<option value="<?php echo esc_attr( $role ); ?>" <?php selected( $current, $role ); ?>><?php echo esc_html( translate_user_role( $name ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Filter', 'user-session-manager' ), '', 'filter_action', false ); ?>
		</div>
		<?php
	}

	/**
	 * Checkbox column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_cb( $item ) {
		return sprintf(
			'<label class="screen-reader-text" for="usm-cb-%1$s">%2$s</label><input type="checkbox" id="usm-cb-%1$s" name="handles[]" value="%1$s" />',
			esc_attr( $item['handle'] ),
			esc_html__( 'Select session', 'user-session-manager' )
		);
	}

	/**
	 * User column, with the row actions.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_user( $item ): string {
		$user = get_userdata( $item['user_id'] );
		if ( ! $user ) {
			return esc_html( (string) $item['user_id'] );
		}

		$name = sprintf( '<strong>%s</strong>', esc_html( $user->user_login ) );
		if ( current_user_can( 'edit_user', $user->ID ) ) {
			$name = sprintf( '<strong><a href="%1$s">%2$s</a></strong>', esc_url( add_query_arg( 'user_id', $user->ID, self_admin_url( 'user-edit.php' ) ) . '#' . ProfileSessions::ANCHOR ), esc_html( $user->user_login ) );
		}

		$actions = array();
		if ( Access::can_manage( $user->ID ) && ! $item['current'] ) {
			$actions['revoke'] = sprintf( '<a href="%1$s">%2$s</a>', esc_url( SessionsPage::action_url( 'revoke', $item['handle'] ) ), esc_html__( 'Sign out', 'user-session-manager' ) );
		}
		if ( Access::can_manage( $user->ID ) ) {
			$actions['sign_out'] = sprintf( '<a href="%1$s" class="usm-danger">%2$s</a>', esc_url( SessionsPage::action_url( 'sign_out', (string) $user->ID ) ), esc_html__( 'Sign out everywhere', 'user-session-manager' ) );
		}
		$actions['filter'] = sprintf( '<a href="%1$s">%2$s</a>', esc_url( SessionsPage::url( array( 'user_id' => $user->ID ) ) ), esc_html__( 'Only this user', 'user-session-manager' ) );

		return sprintf(
			'<span class="usm-user">%1$s<span>%2$s<br><small>%3$s</small></span></span>%4$s',
			get_avatar( $user->ID, 32 ),
			$name,
			esc_html( $user->display_name !== $user->user_login ? $user->display_name : $user->user_email ),
			$this->row_actions( $actions )
		);
	}

	/**
	 * Device column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_device( $item ): string {
		return wp_kses( Display::device( $item ), Display::ALLOWED );
	}

	/**
	 * Other columns.
	 *
	 * @param array  $item        Row.
	 * @param string $column_name Column.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'ip':
				return esc_html( '' !== $item['ip'] ? $item['ip'] : __( 'Unknown', 'user-session-manager' ) );
			case 'login':
			case 'last_seen':
			case 'expiration':
				return wp_kses( Display::time( (int) $item[ $column_name ] ), Display::ALLOWED );
		}

		return '';
	}

	/**
	 * Row CSS class, so the current session stands out.
	 *
	 * @param array $item Row.
	 */
	public function single_row( $item ): void {
		printf( '<tr class="usm-session%s">', $item['current'] ? ' is-current' : '' );
		$this->single_row_columns( $item );
		echo '</tr>';
	}
}
