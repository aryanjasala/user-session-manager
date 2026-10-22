<?php
/**
 * Stand-ins for the WordPress user classes the session code touches.
 *
 * @package AryanX\UserSessionManager
 */

// phpcs:ignoreFile

if ( ! class_exists( 'WP_User' ) ) {
	/**
	 * A user with an ID, a login and roles.
	 */
	class WP_User {
		/** @var int */
		public $ID = 0;
		/** @var string */
		public $user_login = '';
		/** @var string */
		public $user_email = '';
		/** @var string */
		public $display_name = '';
		/** @var string[] */
		public $roles = array();

		public function __construct( $id = 0, $login = '', array $roles = array() ) {
			$this->ID           = (int) $id;
			$this->user_login   = (string) $login;
			$this->user_email   = $login . '@example.com';
			$this->display_name = ucfirst( (string) $login );
			$this->roles        = $roles;
		}
	}
}

if ( ! class_exists( 'WP_User_Meta_Session_Tokens' ) ) {
	/**
	 * Core's default session manager, for is_a() checks.
	 */
	class WP_User_Meta_Session_Tokens {}
}

if ( ! class_exists( 'WP_User_Query' ) ) {
	/**
	 * Returns queued user IDs, one batch per query.
	 */
	class WP_User_Query {
		/** @var array[] */
		public static $queue = array();
		/** @var array[] */
		public static $made = array();
		/** @var string */
		public $query_where = '';
		/** @var int[] */
		private $results;

		public function __construct( array $args = array() ) {
			self::$made[]  = $args;
			$this->results = array_shift( self::$queue ) ?? array();
		}

		public function get_results() {
			return $this->results;
		}
	}
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	/**
	 * Enough of WP_List_Table to load the Sessions table class.
	 */
	class WP_List_Table {
		public function __construct( $args = array() ) {}
	}
}
