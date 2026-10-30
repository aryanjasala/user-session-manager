// Demo users and sessions for the screenshots and the demo GIF.
const crypto = require( 'crypto' );
const fs = require( 'fs' );
const { wp, wpEval } = require( './utils/wp-cli' );
const { STORAGE_STATE } = require( './utils/config' );

const UA = {
	mac: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
	iphone: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
	windows: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:128.0) Gecko/20100101 Firefox/128.0',
	ipad: 'Mozilla/5.0 (iPad; CPU OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
	android: 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36',
	edge: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36 Edg/129.0.2792.52',
};

const PEOPLE = [
	[ 'maria', 'Maria Chen', 'editor' ],
	[ 'james', 'James Okafor', 'author' ],
	[ 'priya', 'Priya Nair', 'subscriber' ],
];

/**
 * Creates the demo users, removing any left from an earlier run.
 */
function createPeople() {
	for ( const [ login, name, role ] of PEOPLE ) {
		try {
			wp( 'user', 'delete', login, '--yes' );
		} catch ( error ) {
			// Not there.
		}
		wp( 'user', 'create', login, `${ login }@example.com`, `--role=${ role }`, `--display_name=${ name }`, '--user_pass=demo-password-123' );
	}
}

/**
 * Removes the demo users.
 */
function removePeople() {
	for ( const [ login ] of PEOPLE ) {
		try {
			wp( 'user', 'delete', login, '--yes' );
		} catch ( error ) {
			// Already gone.
		}
	}
}

/**
 * Adds sessions: [ login, ua key, ip, minutes since login, minutes since last active ].
 *
 * @param {Array[]} rows Sessions to add.
 */
function addSessions( rows ) {
	const php = rows
		.map( ( [ login, ua, ip, loginAgo, seenAgo ] ) => `$add( '${ login }', '${ UA[ ua ] }', '${ ip }', ${ loginAgo * 60 }, ${ seenAgo * 60 } );` )
		.join( '\n' );
	wpEval( `
		$add = function ( $login, $ua, $ip, $login_ago, $seen_ago ) {
			$_SERVER['HTTP_USER_AGENT'] = $ua;
			$_SERVER['REMOTE_ADDR']     = $ip;
			$user    = get_user_by( 'login', $login );
			$manager = WP_Session_Tokens::get_instance( $user->ID );
			$token   = $manager->create( time() + 2 * DAY_IN_SECONDS - $login_ago );
			$session = $manager->get( $token );
			$session['login']     = time() - $login_ago;
			$session['last_seen'] = time() - $seen_ago;
			$manager->update( $token, $session );
		};
		${ php }
	` );
}

/**
 * Removes every session of the demo users and the admin's, except the one the
 * saved admin login (the storage state) uses when keepOwn is true.
 *
 * @param {boolean} keepOwn Keep the session of the saved admin login.
 */
function clearSessions( keepOwn = false ) {
	let keep = '';
	if ( keepOwn ) {
		const cookie = JSON.parse( fs.readFileSync( STORAGE_STATE, 'utf8' ) ).cookies.find( ( c ) => c.name.startsWith( 'wordpress_logged_in_' ) );
		const token = decodeURIComponent( cookie.value ).split( '|' )[ 2 ];
		keep = crypto.createHash( 'sha256' ).update( token ).digest( 'hex' );
	}
	wpEval( `
		foreach ( array( 'maria', 'james', 'priya' ) as $login ) {
			$user = get_user_by( 'login', $login );
			if ( $user ) { delete_user_meta( $user->ID, 'session_tokens' ); }
		}
		$all  = get_user_meta( 1, 'session_tokens', true );
		$all  = is_array( $all ) ? array_intersect_key( $all, array( '${ keep }' => true ) ) : array();
		$all ? update_user_meta( 1, 'session_tokens', $all ) : delete_user_meta( 1, 'session_tokens' );
	` );
}

/**
 * Gives a user two computers on two networks, used a minute apart, and runs
 * shared login detection so the account is flagged.
 *
 * @param {string} login User login.
 */
function markShared( login ) {
	addSessions( [
		[ login, 'windows', '192.0.2.18', 90, 1 ],
		[ login, 'mac', '198.51.100.40', 20, 0 ],
	] );
	wpEval( `\\AryanX\\Axle\\UserSessionManager\\Plugin::instance()->shared()->check( get_user_by( 'login', '${ login }' ) );` );
}

module.exports = { UA, createPeople, removePeople, addSessions, clearSessions, markShared };
