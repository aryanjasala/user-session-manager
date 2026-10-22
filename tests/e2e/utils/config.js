const path = require( 'path' );

module.exports = {
	SLUG: 'user-session-manager',
	BASE_URL: process.env.WP_BASE_URL || 'http://localhost:8895',
	// Test-only credentials for the throwaway Docker site.
	USERNAME: process.env.WP_USERNAME || 'admin',
	PASSWORD: process.env.WP_PASSWORD || 'admin',
	STORAGE_STATE: path.join( __dirname, '..', '.auth', 'admin.json' ),
	// This plugin's own Docker site by default. A shared site can be used by pointing WP_COMPOSE_DIR at its compose folder.
	COMPOSE_DIR: process.env.WP_COMPOSE_DIR || path.join( __dirname, '..', 'env' ),
	// Options the plugin stores, cleared before each run.
	OPTIONS: ['user_session_manager_settings', 'user_session_manager_prune_cursor'],
};
