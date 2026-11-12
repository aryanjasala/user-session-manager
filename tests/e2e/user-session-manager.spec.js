const { test, expect, wp, wpEval, logIn, logInAs } = require( './utils' );
const { PASSWORD: ADMIN_PASSWORD } = require( './utils/config' );

const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
const FIREFOX = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:128.0) Gecko/20100101 Firefox/128.0';
const CHROME = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';
const ANDROID = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36';

const SESSIONS = '/wp-admin/users.php?page=user-sessions';
const SETTINGS = '/wp-admin/options-general.php?page=user-session-manager';
const PASSWORD = 'e2e-password-123';

// A must-use plugin for the suite: captures email, lets a test force the idle timeout,
// and lets a browser context say which IP address it signs in from (the X-USM-E2E-IP header),
// since every context reaches the Docker site from the same address.
const MU_PLUGIN = `<?php
if ( ! empty( $_SERVER['HTTP_X_USM_E2E_IP'] ) && filter_var( $_SERVER['HTTP_X_USM_E2E_IP'], FILTER_VALIDATE_IP ) ) {
	$_SERVER['REMOTE_ADDR'] = $_SERVER['HTTP_X_USM_E2E_IP'];
}
add_filter( 'pre_wp_mail', function ( $short, $atts ) {
	$log   = get_option( 'usm_e2e_mail', array() );
	$log[] = array( 'to' => $atts['to'], 'subject' => $atts['subject'], 'message' => $atts['message'] );
	update_option( 'usm_e2e_mail', $log, false );
	return true;
}, 10, 2 );
add_filter( 'user_session_manager_idle_timeout', function ( $minutes, $user ) {
	$forced = get_option( 'usm_e2e_idle', '' );
	// Never the admin: every test shares its session.
	return '' === $forced || 'admin' === $user->user_login ? $minutes : (int) $forced;
}, 10, 2 );
`;

const id = ( login ) => Number( wp( 'user', 'get', login, '--field=ID' ) );
const sessions = ( login ) => JSON.parse( wp( 'user-session-manager', 'list', `--user=${ login }`, '--fields=handle,device,ip,ua,signed_in,last_active,expires', '--format=json' ) );
const raw = ( login ) => JSON.parse( wpEval( `echo wp_json_encode( get_user_meta( ${ id( login ) }, 'session_tokens', true ) ?: new stdClass() );` ) );
const mail = () => JSON.parse( wpEval( `echo wp_json_encode( get_option( 'usm_e2e_mail', array() ) );` ) );
const shared = () => JSON.parse( wp( 'user-session-manager', 'shared', '--fields=user,action,sessions,evidence,signed_out', '--format=json' ) );
const flag = ( login ) => JSON.parse( wpEval( `echo wp_json_encode( get_user_meta( ${ id( login ) }, 'user_session_manager_shared', true ) ?: null );` ) );

/**
 * Signs a user in through wp-login.php in a fresh browser context.
 *
 * @param {import('@playwright/test').Browser} browser   Browser.
 * @param {string}                             login     User login.
 * @param {string}                             userAgent User agent for the context.
 * @return {Promise<{context: import('@playwright/test').BrowserContext, page: import('@playwright/test').Page}>}
 */
async function signIn( browser, login, userAgent, ip = '' ) {
	return logInAs( browser, login, PASSWORD, { userAgent, ...( ip ? { extraHTTPHeaders: { 'X-USM-E2E-IP': ip } } : {} ) } );
}

/**
 * Answers the in-place confirm step (.axle-confirm) that a destructive link opens.
 *
 * @param {import('@playwright/test').Page} page  Page.
 * @param {string}                           label Name of the confirming link.
 */
async function confirmInPlace( page, label ) {
	const box = page.locator( '.usm-confirm-box' );
	await expect( box ).toBeVisible();
	await box.getByRole( 'link', { name: label } ).click();
}

/**
 * Whether a context is still signed in.
 *
 * @param {import('@playwright/test').Page} page Page of the context.
 * @return {Promise<boolean>}
 */
async function signedIn( page ) {
	await page.goto( '/wp-admin/profile.php' );
	return ! page.url().includes( 'wp-login.php' );
}

test.describe( 'User Session Manager', () => {
	const contexts = [];
	const open = async ( ...args ) => {
		const opened = await signIn( ...args );
		contexts.push( opened.context );
		return opened;
	};

	test.beforeAll( () => {
		for ( const [ login, role ] of [ [ 'alice', 'author' ], [ 'bob', 'editor' ], [ 'sam', 'subscriber' ], [ 'carol', 'administrator' ] ] ) {
			try {
				wp( 'user', 'delete', login, '--yes' );
			} catch ( error ) {
				// Not there yet.
			}
			wp( 'user', 'create', login, `${ login }@example.com`, `--role=${ role }`, `--user_pass=${ PASSWORD }`, `--display_name=${ login[ 0 ].toUpperCase() + login.slice( 1 ) }` );
		}
		wpEval( `wp_mkdir_p( WPMU_PLUGIN_DIR ); file_put_contents( WPMU_PLUGIN_DIR . '/usm-e2e.php', base64_decode( '${ Buffer.from( MU_PLUGIN ).toString( 'base64' ) }' ) );` );
	} );

	test.beforeEach( () => {
		wp( 'option', 'delete', 'user_session_manager_settings' );
		wpEval( `delete_option( 'usm_e2e_mail' ); delete_option( 'usm_e2e_idle' );
			foreach ( array( 'alice', 'bob', 'sam', 'carol' ) as $login ) {
				$user = get_user_by( 'login', $login );
				delete_user_meta( $user->ID, 'session_tokens' );
				delete_user_meta( $user->ID, 'user_session_manager_devices' );
				delete_user_meta( $user->ID, 'user_session_manager_shared' );
				delete_user_meta( $user->ID, 'user_session_manager_history' );
				delete_user_meta( $user->ID, 'user_session_manager_not_me' );
			}
			delete_option( 'user_session_manager_not_me_events' ); delete_option( 'user_session_manager_incidents' );
			delete_user_meta( 1, 'user_session_manager_hide_steps' );
			foreach ( array( 'alice', 'bob', 'sam', 'carol' ) as $login ) { wp_set_password( 'e2e-password-123', get_user_by( 'login', $login )->ID ); }
			global $wpdb; $wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE '%usm_nm_%'" );` );
	} );

	test.afterEach( async () => {
		for ( const context of contexts.splice( 0 ) ) {
			await context.close();
		}
	} );

	test.afterAll( () => {
		wp( 'option', 'delete', 'user_session_manager_settings' );
		wpEval( `@unlink( WPMU_PLUGIN_DIR . '/usm-e2e.php' ); delete_option( 'usm_e2e_mail' ); delete_option( 'usm_e2e_idle' );` );
		for ( const login of [ 'alice', 'bob', 'sam', 'carol' ] ) {
			wp( 'user', 'delete', login, '--yes' );
		}
	} );

	test( 'the profile lists every device, and signing one out bounces it to the login screen', async ( { browser } ) => {
		const phone = await open( browser, 'alice', IPHONE );
		const laptop = await open( browser, 'alice', FIREFOX );

		await laptop.page.goto( '/wp-admin/profile.php' );
		const section = laptop.page.locator( '.usm-sessions' );
		await expect( laptop.page.getByRole( 'heading', { name: 'Your sessions' } ) ).toBeVisible();
		// Core's own "Log Out Everywhere Else" row would be a second control for the same thing.
		await expect( laptop.page.locator( '.user-sessions-wrap' ) ).toBeHidden();
		await expect( section.locator( '.usm-session' ) ).toHaveCount( 2 );
		const current = section.locator( '.usm-session.is-current' );
		await expect( current ).toContainText( 'Firefox on Windows' );
		await expect( current ).toContainText( 'This device' );
		await expect( current.getByRole( 'link', { name: 'Sign out' } ) ).toHaveCount( 0 );
		const other = section.locator( '.usm-session:not(.is-current)' );
		await expect( other ).toContainText( 'Safari on iOS' );
		await expect( other ).toContainText( 'Phone' );

		// Neither the raw tokens nor the stored verifiers ever reach the page.
		const html = await laptop.page.content();
		for ( const verifier of Object.keys( raw( 'alice' ) ) ) {
			expect( html ).not.toContain( verifier );
		}

		await other.getByRole( 'link', { name: 'Sign out' } ).click();
		// It asks in place first, and Cancel changes nothing.
		await expect( laptop.page.locator( '.usm-confirm-box' ) ).toContainText( 'cannot be undone' );
		await laptop.page.locator( '.usm-confirm-box' ).getByRole( 'button', { name: 'Cancel' } ).click();
		expect( sessions( 'alice' ) ).toHaveLength( 2 );
		await other.getByRole( 'link', { name: 'Sign out' } ).click();
		await confirmInPlace( laptop.page, 'Sign out this session' );
		await expect( laptop.page.locator( '.usm-notice' ) ).toContainText( 'Session signed out.' );
		await expect( section.locator( '.usm-session' ) ).toHaveCount( 1 );

		const left = sessions( 'alice' );
		expect( left ).toHaveLength( 1 );
		expect( left[ 0 ].ua ).toBe( FIREFOX );
		expect( await signedIn( phone.page ) ).toBe( false );
		expect( await signedIn( laptop.page ) ).toBe( true );
	} );

	test( '"Sign out all other sessions" keeps only this one, and "last active" is saved', async ( { browser } ) => {
		const phone = await open( browser, 'alice', IPHONE );
		const tablet = await open( browser, 'alice', ANDROID );
		const laptop = await open( browser, 'alice', CHROME );
		expect( sessions( 'alice' ) ).toHaveLength( 3 );

		// A request after the 5 minute interval saves a new "last active" time.
		wpEval( `$id = get_user_by( 'login', 'alice' )->ID; $all = get_user_meta( $id, 'session_tokens', true );
			foreach ( $all as $k => $s ) { $all[ $k ]['last_seen'] = time() - 600; } update_user_meta( $id, 'session_tokens', $all );` );
		await laptop.page.goto( '/wp-admin/profile.php' );
		const seen = Object.values( raw( 'alice' ) ).map( ( session ) => session.last_seen ).sort();
		expect( Math.floor( Date.now() / 1000 ) - seen[ 2 ] ).toBeLessThan( 60 );
		expect( Math.floor( Date.now() / 1000 ) - seen[ 0 ] ).toBeGreaterThan( 500 );

		await laptop.page.reload();
		await laptop.page.getByRole( 'link', { name: 'Sign out all other sessions' } ).click();
		await confirmInPlace( laptop.page, 'Sign out all other sessions' );
		await expect( laptop.page.locator( '.usm-notice' ) ).toContainText( 'Signed out 2 other sessions.' );
		expect( sessions( 'alice' ).map( ( s ) => s.ua ) ).toEqual( [ CHROME ] );
		expect( await signedIn( phone.page ) ).toBe( false );
		expect( await signedIn( tablet.page ) ).toBe( false );
	} );

	test( 'Users > Sessions lists everyone, filters, and signs out one session or a whole user', async ( { page, browser } ) => {
		await open( browser, 'alice', IPHONE );
		await open( browser, 'alice', FIREFOX );
		await open( browser, 'bob', CHROME );
		const aliceUrl = `${ SESSIONS }&user_id=${ id( 'alice' ) }`;

		// The Users list counts sessions and links to them.
		await page.goto( '/wp-admin/users.php' );
		const aliceRow = page.locator( 'tr', { has: page.getByRole( 'link', { name: 'alice', exact: true } ) } );
		await aliceRow.locator( '.column-usm_sessions a' ).click();
		await expect( page.locator( '.usm-summary' ) ).toContainText( '2 active sessions for 1 user. Showing alice only.' );
		await expect( page.locator( 'tbody .usm-session' ) ).toHaveCount( 2 );

		await page.goto( SESSIONS );
		await page.locator( '#usm-role' ).selectOption( 'editor' );
		await page.getByRole( 'button', { name: 'Filter' } ).click();
		await expect( page.locator( 'tbody .usm-session' ) ).toHaveCount( 1 );
		await expect( page.locator( 'tbody .usm-session' ) ).toContainText( 'Chrome on macOS' );

		await page.goto( SESSIONS );
		await page.locator( '#usm-search-search-input' ).fill( 'alice' );
		await page.locator( '#search-submit' ).click();
		await expect( page.locator( 'tbody .usm-session' ) ).toHaveCount( 2 );

		// Search by IP address prefix.
		const ip = sessions( 'bob' )[ 0 ].ip;
		await page.goto( `${ SESSIONS }&s=${ ip.split( '.' ).slice( 0, 2 ).join( '.' ) }.` );
		await expect( page.locator( 'tbody .usm-session' ).filter( { hasText: 'bob' } ) ).toHaveCount( 1 );

		const html = await page.content();
		for ( const verifier of [ ...Object.keys( raw( 'alice' ) ), ...Object.keys( raw( 'bob' ) ) ] ) {
			expect( html ).not.toContain( verifier );
		}

		// Row action: one session. Filtered to alice, so the admin's own sessions from earlier runs never push her rows to page 2.
		await page.goto( aliceUrl );
		const iphoneRow = page.locator( 'tbody .usm-session', { hasText: 'Safari on iOS' } );
		await iphoneRow.hover();
		await iphoneRow.getByRole( 'link', { name: 'Sign out', exact: true } ).click();
		await confirmInPlace( page, 'Sign out this session' );
		await expect( page.locator( '.usm-notice' ) ).toContainText( 'Session signed out.' );
		expect( sessions( 'alice' ).map( ( s ) => s.ua ) ).toEqual( [ FIREFOX ] );

		// Bulk: sign bob out everywhere, after a confirmation.
		await page.goto( `${ SESSIONS }&user_id=${ id( 'bob' ) }` );
		await page.locator( 'tbody .usm-session', { hasText: 'bob' } ).locator( 'input[type=checkbox]' ).check();
		await page.locator( '#bulk-action-selector-top' ).selectOption( 'sign_out' );
		await page.locator( '#doaction' ).click();
		await expect( page.getByRole( 'heading', { name: 'Sign out bob everywhere?' } ) ).toBeVisible();
		expect( sessions( 'bob' ) ).toHaveLength( 1 );
		await page.getByRole( 'button', { name: 'Sign out everywhere', exact: true } ).click();
		await expect( page.locator( '.usm-notice' ) ).toContainText( '1 session signed out.' );
		expect( sessions( 'bob' ) ).toHaveLength( 0 );

		// Row action: a whole user. It asks first, and Cancel changes nothing.
		await page.goto( aliceUrl );
		const aliceSession = page.locator( 'tbody .usm-session', { hasText: 'alice' } );
		await aliceSession.hover();
		await aliceSession.getByRole( 'link', { name: 'Sign out everywhere' } ).click();
		await expect( page.getByRole( 'heading', { name: 'Sign out alice everywhere?' } ) ).toBeVisible();
		await expect( page.locator( '.usm-confirm' ) ).toContainText( 'alice is signed in on 1 device.' );
		await page.getByRole( 'link', { name: 'Cancel' } ).click();
		expect( sessions( 'alice' ) ).toHaveLength( 1 );

		await aliceSession.hover();
		await aliceSession.getByRole( 'link', { name: 'Sign out everywhere' } ).click();
		await page.getByRole( 'button', { name: 'Sign out everywhere', exact: true } ).click();
		await expect( page.locator( '.usm-notice' ) ).toContainText( 'alice was signed out of 1 session.' );
		expect( sessions( 'alice' ) ).toHaveLength( 0 );
	} );

	test( 'signing yourself out everywhere asks, and keeps this browser unless told otherwise', async ( { browser } ) => {
		const phone = await open( browser, 'carol', IPHONE );
		const laptop = await open( browser, 'carol', CHROME );
		const carolUrl = `${ SESSIONS }&user_id=${ id( 'carol' ) }`;

		// The current session has no checkbox and no "Sign out": a list never signs out the browser in use.
		await laptop.page.goto( carolUrl );
		const current = laptop.page.locator( 'tbody .usm-session.is-current' );
		await expect( current.locator( 'input[type=checkbox]' ) ).toHaveCount( 0 );
		await current.hover();
		await expect( current.getByRole( 'link', { name: 'Sign out', exact: true } ) ).toHaveCount( 0 );

		// On your own row the action is "Sign out everywhere else", and the question says what happens to this browser.
		await current.getByRole( 'link', { name: 'Sign out everywhere else' } ).click();
		await expect( laptop.page.getByRole( 'heading', { name: 'Sign out your other devices?' } ) ).toBeVisible();
		await expect( laptop.page.locator( '.usm-confirm' ) ).toContainText( 'You are signed in on 2 devices, this browser included.' );
		await expect( laptop.page.getByRole( 'button', { name: 'Sign out everywhere else' } ) ).toHaveClass( /button-primary/ );
		expect( sessions( 'carol' ) ).toHaveLength( 2 );

		await laptop.page.getByRole( 'button', { name: 'Sign out everywhere else' } ).click();
		await expect( laptop.page.locator( '.usm-notice' ) ).toContainText( 'Signed out 1 other session.' );
		expect( sessions( 'carol' ).map( ( s ) => s.ua ) ).toEqual( [ CHROME ] );
		expect( await signedIn( phone.page ) ).toBe( false );
		expect( await signedIn( laptop.page ) ).toBe( true );

		// "This browser too" ends every session and lands on the login screen, logged out.
		await open( browser, 'carol', FIREFOX );
		await laptop.page.goto( carolUrl );
		await laptop.page.locator( 'tbody .usm-session.is-current' ).hover();
		await laptop.page.locator( 'tbody .usm-session.is-current' ).getByRole( 'link', { name: 'Sign out everywhere else' } ).click();
		await laptop.page.getByRole( 'button', { name: 'Sign out everywhere, this browser too' } ).click();
		await expect( laptop.page ).toHaveURL( /wp-login\.php.*loggedout=true/ );
		expect( sessions( 'carol' ) ).toHaveLength( 0 );
		expect( await signedIn( laptop.page ) ).toBe( false );
	} );

	test( 'signing someone out from their profile asks first, and the counts use the site number format', async ( { page, browser } ) => {
		await open( browser, 'alice', IPHONE );
		await open( browser, 'alice', FIREFOX );

		await page.goto( `/wp-admin/user-edit.php?user_id=${ id( 'alice' ) }` );
		await expect( page.locator( '.user-sessions-wrap' ) ).toBeHidden();
		await page.locator( '.usm-profile' ).getByRole( 'link', { name: 'Sign out everywhere' } ).click();
		await expect( page.locator( '.usm-confirm' ) ).toContainText( 'alice is signed in on 2 devices.' );
		expect( sessions( 'alice' ) ).toHaveLength( 2 );
		await page.locator( '.usm-confirm' ).getByRole( 'link', { name: 'Sign out everywhere' } ).click();
		await expect( page.locator( '.usm-notice' ) ).toContainText( 'alice was signed out of 2 sessions.' );
		expect( sessions( 'alice' ) ).toHaveLength( 0 );

		// 1,001 sessions read "1,001", like the pagination next to them.
		wpEval( `$id = get_user_by( 'login', 'bob' )->ID; $all = array();
			for ( $i = 0; $i < 1001; $i++ ) { $all[ hash( 'sha256', "usm-e2e-$i" ) ] = array( 'expiration' => time() + 3600, 'ip' => '203.0.113.9', 'ua' => 'curl/8', 'login' => time() - 60 ); }
			update_user_meta( $id, 'session_tokens', $all );` );
		await page.goto( `${ SESSIONS }&user_id=${ id( 'bob' ) }` );
		await expect( page.locator( '.usm-summary' ) ).toContainText( '1,001 active sessions for 1 user.' );
	} );

	test( 'the screens fit a phone without sideways scrolling', async ( { browser } ) => {
		const phone = await open( browser, 'carol', IPHONE );
		await open( browser, 'carol', FIREFOX );
		await phone.page.setViewportSize( { width: 375, height: 800 } );
		for ( const url of [ SETTINGS, '/wp-admin/profile.php', `${ SESSIONS }&usm_incident=1`, `/wp-admin/user-edit.php?user_id=${ id( 'carol' ) }#usm-history`, `${ SESSIONS }&user_id=${ id( 'carol' ) }` ] ) {
			await phone.page.goto( url );
			expect( await phone.page.evaluate( () => document.documentElement.scrollWidth ), url ).toBeLessThanOrEqual( 375 );
		}
		// One "Show more details" toggle per row, as on core's lists.
		const row = phone.page.locator( 'tbody .usm-session:not(.is-current)' );
		await expect( row.locator( '.toggle-row' ) ).toHaveCount( 1 );
		await row.locator( '.toggle-row' ).click();
		await expect( row.getByRole( 'link', { name: 'Sign out everywhere else' } ) ).toBeVisible();
	} );

	test( 'a role limit of one signs out the older session', async ( { page, browser } ) => {
		await page.goto( SETTINGS );
		await page.locator( '#usm-author-max_sessions' ).fill( '1' );
		await page.getByRole( 'button', { name: 'Save settings' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Settings saved.' );
		expect( JSON.parse( wp( 'option', 'get', 'user_session_manager_settings', '--format=json' ) ).policies.author.max_sessions ).toBe( 1 );

		const first = await open( browser, 'alice', IPHONE );
		const second = await open( browser, 'alice', FIREFOX );

		expect( sessions( 'alice' ).map( ( s ) => s.ua ) ).toEqual( [ FIREFOX ] );
		expect( await signedIn( first.page ) ).toBe( false );
		expect( await signedIn( second.page ) ).toBe( true );

		// Other roles are not limited.
		await open( browser, 'bob', IPHONE );
		await open( browser, 'bob', FIREFOX );
		expect( sessions( 'bob' ) ).toHaveLength( 2 );
	} );

	test( 'block mode refuses the new login with a clear message', async ( { browser } ) => {
		wp( 'user-session-manager', 'set-policy', 'author', '--max-sessions=1' );
		wp( 'user-session-manager', 'settings', '--limit-mode=block' );

		const first = await open( browser, 'alice', IPHONE );
		const second = await open( browser, 'alice', FIREFOX );

		await expect( second.page.locator( '#login_error' ) ).toContainText( 'This account can be signed in on 1 device at a time. It already has 1 active session.' );
		expect( sessions( 'alice' ).map( ( s ) => s.ua ) ).toEqual( [ IPHONE ] );
		expect( await signedIn( first.page ) ).toBe( true );
	} );

	test( 'an idle session is signed out on its next request, and by the clean-up', async ( { browser } ) => {
		const phone = await open( browser, 'alice', IPHONE );
		await open( browser, 'alice', FIREFOX );
		wpEval( `update_option( 'usm_e2e_idle', 1 );` );

		// Both sessions were last active two minutes ago.
		wpEval( `$id = get_user_by( 'login', 'alice' )->ID; $all = get_user_meta( $id, 'session_tokens', true );
			foreach ( $all as $k => $s ) { $all[ $k ]['last_seen'] = time() - 120; } update_user_meta( $id, 'session_tokens', $all );` );

		expect( await signedIn( phone.page ) ).toBe( false );
		expect( sessions( 'alice' ).map( ( s ) => s.ua ) ).toEqual( [ FIREFOX ] );

		expect( wp( 'user-session-manager', 'prune' ) ).toContain( 'Removed 0 expired and 1 idle sessions' );
		expect( sessions( 'alice' ) ).toHaveLength( 0 );
	} );

	test( 'session length follows the role', async ( { page, browser } ) => {
		await page.goto( SETTINGS );
		await page.locator( '#usm-author-lifetime_hours' ).fill( '1' );
		await page.getByRole( 'button', { name: 'Save settings' } ).click();
		await expect( page.locator( '.notice-success' ) ).toBeVisible();

		await open( browser, 'alice', CHROME );
		await open( browser, 'bob', CHROME );

		const alice = Object.values( raw( 'alice' ) )[ 0 ];
		expect( alice.expiration - alice.login ).toBe( 3600 );
		const bob = Object.values( raw( 'bob' ) )[ 0 ];
		expect( bob.expiration - bob.login ).toBe( 2 * 86400 );
	} );

	test( 'the new device email goes out for a new browser only, and IPs can be shortened', async ( { page, browser } ) => {
		await page.goto( SETTINGS );
		await page.getByLabel( 'Email users when they sign in from a new browser or network' ).check();
		await page.getByLabel( 'Store shortened IP addresses (the last part set to zero)' ).check();
		await page.getByRole( 'button', { name: 'Save settings' } ).click();
		await expect( page.locator( '.notice-success' ) ).toBeVisible();

		// The first login only fills the memory.
		await open( browser, 'alice', CHROME );
		expect( mail() ).toHaveLength( 0 );

		await open( browser, 'alice', IPHONE );
		const sent = mail();
		expect( sent ).toHaveLength( 1 );
		expect( sent[ 0 ].to ).toBe( 'alice@example.com' );
		expect( sent[ 0 ].subject ).toContain( 'New sign-in to your account' );
		expect( sent[ 0 ].message ).toContain( 'Device: Safari on iOS' );

		// Same browser and network again: no email.
		await open( browser, 'alice', CHROME );
		expect( mail() ).toHaveLength( 1 );

		const remembered = JSON.parse( wpEval( `echo wp_json_encode( get_user_meta( get_user_by( 'login', 'alice' )->ID, 'user_session_manager_devices', true ) );` ) );
		expect( Object.keys( remembered.devices ) ).toHaveLength( 2 );
		expect( JSON.stringify( remembered ) ).not.toContain( sessions( 'alice' )[ 0 ].ip );

		for ( const session of sessions( 'alice' ) ) {
			expect( session.ip ).toMatch( /\.0$|::$/ );
		}
	} );

	test( 'the privacy tools export sessions, and erasing signs the user out everywhere', async ( { browser } ) => {
		const phone = await open( browser, 'alice', IPHONE );
		await open( browser, 'alice', FIREFOX );

		const privacy = ( tool ) =>
			JSON.parse(
				wpEval( `
					$tools = apply_filters( 'wp_privacy_personal_data_${ tool }s', array() );
					echo wp_json_encode( call_user_func( $tools['user-session-manager']['callback'], 'alice@example.com', 1 ) );
				` )
			);

		const exported = privacy( 'exporter' );
		const sessionItems = exported.data.filter( ( item ) => item.group_id === 'user-session-manager-sessions' );
		expect( sessionItems ).toHaveLength( 2 );
		const devices = sessionItems.map( ( item ) => item.data.find( ( field ) => field.name === 'Device' ).value ).sort();
		expect( devices ).toEqual( [ 'Firefox on Windows', 'Safari on iOS' ] );

		expect( privacy( 'eraser' ) ).toMatchObject( { items_removed: true, done: true, messages: [ 'Signed the user out of 2 sessions.' ] } );
		expect( sessions( 'alice' ) ).toHaveLength( 0 );
		expect( await signedIn( phone.page ) ).toBe( false );
	} );

	test( 'Site Health recommends a policy for administrators', async ( { page } ) => {
		await page.goto( '/wp-admin/site-health.php' );
		await expect( page.getByText( 'Administrator sessions have no idle timeout or session limit' ) ).toBeVisible( { timeout: 30_000 } );

		wp( 'user-session-manager', 'set-policy', 'administrator', '--idle-minutes=600' );
		await page.goto( '/wp-admin/site-health.php' );
		await page.getByRole( 'button', { name: 'Passed tests' } ).click();
		await expect( page.getByText( 'Login sessions are managed' ) ).toBeVisible( { timeout: 30_000 } );
	} );

	test( 'WP-CLI lists, signs out and changes policies', async ( { browser } ) => {
		await open( browser, 'alice', IPHONE );
		await open( browser, 'alice', FIREFOX );
		await open( browser, 'bob', CHROME );

		const listed = sessions( 'alice' );
		expect( listed.map( ( s ) => s.device ).sort() ).toEqual( [ 'Firefox on Windows', 'Safari on iOS' ] );
		expect( listed[ 0 ].handle ).toMatch( /^\d+-[0-9a-f]{24}$/ );

		expect( wp( 'user-session-manager', 'revoke', listed[ 0 ].handle ) ).toContain( 'Signed out 1 session.' );
		expect( sessions( 'alice' ) ).toHaveLength( 1 );
		expect( () => wp( 'user-session-manager', 'revoke', listed[ 0 ].handle ) ).toThrow( /No session was signed out/ );

		expect( wp( 'user-session-manager', 'sign-out', 'bob@example.com' ) ).toContain( 'out of 1 session.' );
		expect( sessions( 'bob' ) ).toHaveLength( 0 );

		wp( 'user-session-manager', 'set-policy', 'editor', '--max-sessions=3', '--idle-minutes=45', '--lifetime-hours=8', '--remember-hours=72' );
		const editor = JSON.parse( wp( 'user-session-manager', 'policies', '--format=json' ) ).find( ( row ) => row.role === 'editor' );
		expect( editor ).toEqual( { role: 'editor', max_sessions: 3, idle_minutes: 45, lifetime_hours: 8, remember_hours: 72, shared: 'flag' } );

		wp( 'user-session-manager', 'settings', '--limit-mode=block', '--mask-ip=on' );
		expect( JSON.parse( wp( 'user-session-manager', 'settings', '--format=json' ) ) ).toMatchObject( { limit_mode: 'block', mask_ip: true, new_device_email: false } );
	} );

	test( 'abilities: list, sign out a session and sign a user out, with permissions', async ( { rest, browser } ) => {
		test.skip( 'yes' !== wpEval( "echo function_exists( 'wp_register_ability' ) ? 'yes' : 'no';" ), 'The Abilities API needs WordPress 6.9.' );
		await open( browser, 'alice', IPHONE );
		await open( browser, 'alice', FIREFOX );
		await open( browser, 'bob', CHROME );
		const path = ( name ) => `/wp-abilities/v1/abilities/user-session-manager/${ name }/run`;

		const listed = await rest( 'GET', '/wp-abilities/v1/abilities?category=user-session-manager' );
		expect( listed.body.map( ( ability ) => ability.name ).sort() ).toEqual( [
			'user-session-manager/list-not-me-reports',
			'user-session-manager/list-sessions',
			'user-session-manager/list-shared-logins',
			'user-session-manager/list-sign-in-history',
			'user-session-manager/revoke-session',
			'user-session-manager/sign-out-everyone',
			'user-session-manager/sign-out-user',
		] );
		// MCP clients only list abilities marked public.
		expect( JSON.parse( wpEval( `echo wp_json_encode( array_values( array_filter( array_map( function ( $ability ) { $meta = $ability->get_meta(); return ( ! empty( $meta['public'] ) && ! empty( $meta['mcp']['public'] ) ) ? '' : $ability->get_name(); }, wp_get_abilities() ), function ( $name ) { return 0 === strpos( $name, 'user-session-manager/' ); } ) ) );` ) ) ).toEqual( [] );

		// Without input: the caller's own sessions, the current one marked.
		const own = await rest( 'GET', path( 'list-sessions' ) );
		expect( own.status ).toBe( 200 );
		expect( own.body.sessions.every( ( session ) => session.user_login === 'admin' ) ).toBe( true );
		expect( own.body.sessions.filter( ( session ) => session.current ) ).toHaveLength( 1 );

		const alice = await rest( 'GET', `${ path( 'list-sessions' ) }?input[user]=alice` );
		expect( alice.body.total ).toBe( 2 );
		const phone = alice.body.sessions.find( ( session ) => session.device === 'Safari on iOS' );
		expect( phone ).toMatchObject( { device_type: 'mobile', user_login: 'alice', current: false } );

		const everyone = await rest( 'GET', `${ path( 'list-sessions' ) }?input[all]=1` );
		expect( everyone.body.sessions.map( ( session ) => session.user_login ) ).toEqual( expect.arrayContaining( [ 'admin', 'alice', 'bob' ] ) );

		const revoked = await rest( 'DELETE', `${ path( 'revoke-session' ) }?input[handle]=${ phone.handle }` );
		expect( revoked.body ).toEqual( { revoked: true } );
		expect( sessions( 'alice' ).map( ( s ) => s.ua ) ).toEqual( [ FIREFOX ] );
		// Idempotent: the second call changes nothing and still succeeds.
		expect( ( await rest( 'DELETE', `${ path( 'revoke-session' ) }?input[handle]=${ phone.handle }` ) ).body ).toEqual( { revoked: false } );

		const signedOut = await rest( 'DELETE', `${ path( 'sign-out-user' ) }?input[user]=bob` );
		expect( signedOut.body ).toEqual( { sessions_ended: 1 } );
		expect( sessions( 'bob' ) ).toHaveLength( 0 );

		// A subscriber may list and end their own sessions, nobody else's.
		const asSam = ( php ) => wpEval( `wp_set_current_user( get_user_by( 'login', 'sam' )->ID ); ${ php }` );
		expect( asSam( `$r = wp_get_ability( 'user-session-manager/list-sessions' )->execute( array( 'user' => 'alice' ) ); echo is_wp_error( $r ) ? $r->get_error_code() : 'ran';` ) ).toBe( 'ability_invalid_permissions' );
		expect( asSam( `$r = wp_get_ability( 'user-session-manager/sign-out-user' )->execute( array( 'user' => 'alice' ) ); echo is_wp_error( $r ) ? $r->get_error_code() : 'ran';` ) ).toBe( 'ability_invalid_permissions' );
		expect( asSam( `$r = wp_get_ability( 'user-session-manager/list-sessions' )->execute( array() ); echo is_wp_error( $r ) ? $r->get_error_code() : $r['total'];` ) ).toBe( '0' );
		expect( sessions( 'alice' ) ).toHaveLength( 1 );
	} );

	test( 'two computers on different networks at once flag the account, with the reasons on both screens', async ( { page, browser } ) => {
		const windows = await open( browser, 'alice', FIREFOX, '192.0.2.10' );
		await open( browser, 'alice', CHROME, '198.51.100.20' );

		// Stored once, with the evidence and a copy of the sessions behind it.
		const stored = flag( 'alice' );
		expect( stored.action ).toBe( 'flag' );
		expect( stored.evidence.map( ( e ) => e.type ) ).toEqual( [ 'same_type' ] );
		expect( Object.values( stored.sessions ).map( ( s ) => s.ip ).sort() ).toEqual( [ '192.0.2.10', '198.51.100.20' ] );
		expect( shared() ).toEqual( [ { user: 'alice', action: 'flag', sessions: 2, evidence: '2 computers on different networks were used at the same time.', signed_out: 0 } ] );
		// Just a flag: nobody is emailed or signed out on a guess.
		expect( mail() ).toHaveLength( 0 );
		expect( sessions( 'alice' ) ).toHaveLength( 2 );

		// Her own profile says so, and marks both sessions.
		await windows.page.goto( '/wp-admin/profile.php' );
		const box = windows.page.locator( '.usm-shared' );
		await expect( box ).toContainText( 'This account looks shared by more than one person.' );
		await expect( box ).toContainText( '2 computers on different networks were used at the same time.' );
		await expect( box ).toContainText( 'Firefox on Windows, 192.0.2.10, last active' );
		await expect( box ).toContainText( 'Chrome on macOS, 198.51.100.20, last active' );
		await expect( box ).toContainText( 'Nothing was blocked.' );
		await expect( windows.page.locator( '.usm-sessions .usm-badge-shared' ) ).toHaveCount( 2 );

		// Users > Sessions: a "Possibly shared" view, the badges, and the reasons for one user.
		await page.goto( SESSIONS );
		await page.locator( '.subsubsub' ).getByRole( 'link', { name: /Possibly shared/ } ).click();
		await expect( page.locator( '.subsubsub .current' ) ).toContainText( 'Possibly shared (1)' );
		await expect( page.locator( 'tbody .usm-session' ) ).toHaveCount( 2 );
		await expect( page.locator( 'tbody .usm-session .usm-badge-shared' ) ).toHaveCount( 2 );
		const row = page.locator( 'tbody .usm-session' ).first();
		await row.hover();
		await row.getByRole( 'link', { name: 'Only this user' } ).click();
		await expect( page.locator( '.usm-shared' ) ).toContainText( '2 computers on different networks were used at the same time.' );
	} );

	test( 'a phone and a laptop on two networks are one person, not a shared account', async ( { page, browser } ) => {
		await open( browser, 'alice', CHROME, '192.0.2.10' );
		await open( browser, 'alice', IPHONE, '198.51.100.20' );

		expect( flag( 'alice' ) ).toBeNull();
		expect( shared() ).toEqual( [] );
		await page.goto( SESSIONS );
		await expect( page.locator( '.subsubsub' ) ).toHaveCount( 0 );
	} );

	test( '"Badge and email" emails the user and the administrator, once per new session', async ( { page, browser } ) => {
		await page.goto( SETTINGS );
		await page.getByLabel( 'Author: Account looks shared' ).selectOption( 'notify' );
		await page.getByRole( 'button', { name: 'Save settings' } ).click();
		await expect( page.locator( '.notice-success' ) ).toBeVisible();
		expect( JSON.parse( wp( 'option', 'get', 'user_session_manager_settings', '--format=json' ) ).shared.author ).toBe( 'notify' );
		await expect( page.getByLabel( 'Author: Account looks shared' ) ).toHaveValue( 'notify' );

		const windows = await open( browser, 'alice', FIREFOX, '192.0.2.10' );
		await open( browser, 'alice', CHROME, '198.51.100.20' );

		const sent = mail();
		expect( sent.map( ( m ) => m.to ) ).toEqual( [ 'alice@example.com', 'admin@example.com' ] );
		expect( sent[ 0 ].subject ).toContain( 'Your account was used from several places at once' );
		expect( sent[ 0 ].message ).toContain( '- 2 computers on different networks were used at the same time.' );
		expect( sent[ 1 ].subject ).toContain( 'The account alice may be shared' );
		expect( sent[ 1 ].message ).toContain( `user_id=${ id( 'alice' ) }` );
		expect( flag( 'alice' ).action ).toBe( 'notify' );

		// More activity from the same sessions sends nothing new.
		wpEval( `$id = get_user_by( 'login', 'alice' )->ID; $all = get_user_meta( $id, 'session_tokens', true );
			foreach ( $all as $k => $s ) { $all[ $k ]['last_seen'] = time() - 400; } update_user_meta( $id, 'session_tokens', $all );` );
		await windows.page.goto( '/wp-admin/profile.php' );
		expect( mail() ).toHaveLength( 2 );
		expect( sessions( 'alice' ) ).toHaveLength( 2 );
	} );

	test( '"Keep only the newest session" signs out the rest, and "Do not check" leaves an account alone', async ( { browser } ) => {
		wp( 'user-session-manager', 'set-policy', 'author', '--shared=sign_out_others' );
		wp( 'user-session-manager', 'set-policy', 'editor', '--shared=off' );
		expect( JSON.parse( wp( 'user-session-manager', 'policies', '--format=json' ) ).find( ( r ) => r.role === 'author' ).shared ).toBe( 'sign_out_others' );

		const first = await open( browser, 'alice', FIREFOX, '192.0.2.10' );
		const second = await open( browser, 'alice', CHROME, '198.51.100.20' );

		expect( sessions( 'alice' ).map( ( s ) => s.ua ) ).toEqual( [ CHROME ] );
		expect( await signedIn( first.page ) ).toBe( false );
		expect( await signedIn( second.page ) ).toBe( true );
		expect( shared() ).toEqual( [ expect.objectContaining( { user: 'alice', action: 'sign_out_others', signed_out: 1 } ) ] );
		await second.page.goto( '/wp-admin/profile.php' );
		await expect( second.page.locator( '.usm-shared' ) ).toContainText( '1 session was signed out. The newest one was kept.' );

		// An editor account meant to be shared, such as a kiosk, is never checked.
		await open( browser, 'bob', FIREFOX, '192.0.2.10' );
		await open( browser, 'bob', CHROME, '198.51.100.20' );
		expect( flag( 'bob' ) ).toBeNull();
		expect( sessions( 'bob' ) ).toHaveLength( 2 );
	} );

	test( 'ability: list accounts that look shared, with permissions', async ( { rest, browser } ) => {
		test.skip( 'yes' !== wpEval( "echo function_exists( 'wp_register_ability' ) ? 'yes' : 'no';" ), 'The Abilities API needs WordPress 6.9.' );
		await open( browser, 'alice', FIREFOX, '192.0.2.10' );
		await open( browser, 'alice', CHROME, '198.51.100.20' );
		const path = '/wp-abilities/v1/abilities/user-session-manager/list-shared-logins/run';

		const ability = await rest( 'GET', '/wp-abilities/v1/abilities/user-session-manager/list-shared-logins' );
		expect( ability.body.meta.annotations ).toMatchObject( { readonly: true } );

		const listed = await rest( 'GET', path );
		expect( listed.status ).toBe( 200 );
		expect( listed.body.total ).toBe( 1 );
		const account = listed.body.accounts[ 0 ];
		expect( account ).toMatchObject( { user_login: 'alice', action: 'flag', signed_out: 0 } );
		expect( account.evidence[ 0 ] ).toMatchObject( { type: 'same_type', summary: '2 computers on different networks were used at the same time.' } );
		expect( account.sessions.map( ( s ) => s.ip ).sort() ).toEqual( [ '192.0.2.10', '198.51.100.20' ] );
		// The handles work with revoke-session while the session is active.
		expect( sessions( 'alice' ).map( ( s ) => s.handle ).sort() ).toEqual( account.sessions.map( ( s ) => s.handle ).sort() );

		expect( ( await rest( 'GET', `${ path }?input[user]=bob` ) ).body ).toEqual( { total: 0, accounts: [] } );

		// A subscriber may ask about their own account, not about everyone.
		const asSam = ( php ) => wpEval( `wp_set_current_user( get_user_by( 'login', 'sam' )->ID ); ${ php }` );
		expect( asSam( `$r = wp_get_ability( 'user-session-manager/list-shared-logins' )->execute( array() ); echo is_wp_error( $r ) ? $r->get_error_code() : 'ran';` ) ).toBe( 'ability_invalid_permissions' );
		expect( asSam( `$r = wp_get_ability( 'user-session-manager/list-shared-logins' )->execute( array( 'user' => 'sam' ) ); echo is_wp_error( $r ) ? $r->get_error_code() : $r['total'];` ) ).toBe( '0' );
	} );

	test( 'erasing personal data drops the flag too', async ( { browser } ) => {
		await open( browser, 'alice', FIREFOX, '192.0.2.10' );
		await open( browser, 'alice', CHROME, '198.51.100.20' );
		expect( flag( 'alice' ) ).not.toBeNull();

		const exported = JSON.parse(
			wpEval( `$tools = apply_filters( 'wp_privacy_personal_data_exporters', array() );
				echo wp_json_encode( call_user_func( $tools['user-session-manager']['callback'], 'alice@example.com', 1 ) );` )
		);
		expect( exported.data.filter( ( item ) => item.group_id === 'user-session-manager-shared' ) ).toHaveLength( 2 );

		wpEval( `$tools = apply_filters( 'wp_privacy_personal_data_erasers', array() );
			call_user_func( $tools['user-session-manager']['callback'], 'alice@example.com', 1 );` );
		expect( flag( 'alice' ) ).toBeNull();
		expect( shared() ).toEqual( [] );
	} );
	test( '"This wasn\'t me": the email link signs the account out, retires the password and tells the administrator', async ( { page, browser } ) => {
		wp( 'user-session-manager', 'settings', '--new-device-email=on' );
		await open( browser, 'alice', CHROME );
		await open( browser, 'alice', IPHONE, '198.51.100.20' );
		const sent = mail();
		expect( sent ).toHaveLength( 1 );
		expect( sent[ 0 ].message ).toContain( 'Nothing happens until you confirm' );
		const link = sent[ 0 ].message.match( /http\S*user_session_manager_not_me\S*/ )[ 0 ];

		// Opening the link, as a mail scanner does, shows a page and changes nothing. No login is needed.
		const visitor = await browser.newContext( { storageState: { cookies: [], origins: [] } } );
		contexts.push( visitor );
		const tab = await visitor.newPage();
		const response = await tab.goto( link );
		expect( response.status() ).toBe( 200 );
		await expect( tab.getByRole( 'button', { name: 'This wasn\'t me, secure the account' } ) ).toBeVisible();
		await expect( tab.locator( 'body' ) ).toContainText( 'Safari on iOS' );
		await expect( tab.locator( 'body' ) ).not.toContainText( 'alice' );
		expect( sessions( 'alice' ) ).toHaveLength( 2 );
		expect( mail() ).toHaveLength( 1 );

		await tab.getByRole( 'button', { name: 'This wasn\'t me, secure the account' } ).click();
		await expect( tab.locator( 'body' ) ).toContainText( 'Done. We signed the account out' );
		expect( sessions( 'alice' ) ).toHaveLength( 0 );

		// The old password no longer works.
		const refused = await logInAs( browser, 'alice', PASSWORD );
		contexts.push( refused.context );
		await expect( refused.page.locator( '#login_error' ) ).toBeVisible();

		// A reset link went to the account, and the site administrator was told.
		const all = mail();
		expect( all ).toHaveLength( 3 );
		expect( all[ 1 ].to ).toBe( 'alice@example.com' );
		expect( all[ 1 ].message ).toMatch( /wp-login\.php\?action=rp&key=\S+&login=alice/ );
		const adminEmail = wpEval( "echo get_option( 'admin_email' );" );
		expect( [].concat( all[ 2 ].to ) ).toEqual( [ adminEmail ] );
		expect( all[ 2 ].message ).toContain( 'alice' );

		// Single use, and a wrong key looks exactly the same.
		const again = await tab.goto( link );
		expect( again.status() ).toBe( 404 );
		await expect( tab.locator( 'body' ) ).toContainText( 'This link has expired, or it has already been used' );
		const wrong = await tab.goto( link.replace( /usm_key=(\d+)\.[0-9a-f]+/, 'usm_key=$1.' + 'a'.repeat( 48 ) ) );
		expect( wrong.status() ).toBe( 404 );
		await expect( tab.locator( 'body' ) ).toContainText( 'This link has expired, or it has already been used' );

		// The event shows on the profile, on Users > Sessions, in WP-CLI and in the history.
		await page.goto( `/wp-admin/user-edit.php?user_id=${ id( 'alice' ) }` );
		await expect( page.locator( '.usm-report' ) ).toContainText( 'This wasn\'t me' );
		await expect( page.locator( '.usm-report' ) ).toContainText( 'Safari on iOS' );
		await expect( page.locator( '.usm-history-row' ).first() ).toContainText( 'Reported as "This wasn\'t me"' );
		await page.goto( SESSIONS );
		await expect( page.locator( '.usm-reports' ) ).toContainText( 'alice' );
		const reports = JSON.parse( wp( 'user-session-manager', 'reports', '--format=json' ) );
		expect( reports ).toHaveLength( 1 );
		expect( reports[ 0 ] ).toMatchObject( { user: 'alice', device: 'Safari on iOS', scope: 'all', ended: 2 } );
		const history = JSON.parse( wp( 'user-session-manager', 'history', 'alice', '--format=json' ) );
		expect( history.map( ( row ) => row.reason ) ).toEqual( [ 'not_me', 'not_me' ] );
	} );

	test( '"This wasn\'t me" can be limited to one session, turned off, and rate limited', async ( { request, browser } ) => {
		wp( 'user-session-manager', 'settings', '--new-device-email=on', '--not-me-scope=session' );
		await open( browser, 'alice', CHROME );
		await open( browser, 'alice', IPHONE, '198.51.100.20' );
		const link = mail()[ 0 ].message.match( /http\S*user_session_manager_not_me\S*/ )[ 0 ];
		const visitor = await browser.newContext( { storageState: { cookies: [], origins: [] } } );
		contexts.push( visitor );
		const tab = await visitor.newPage();
		await tab.goto( link );
		await tab.getByRole( 'button', { name: 'This wasn\'t me, secure the account' } ).click();
		expect( sessions( 'alice' ).map( ( s ) => s.device ) ).toEqual( [ 'Chrome on macOS' ] );

		// Off: no link in new emails, and a link already sent stops working.
		wp( 'user-session-manager', 'settings', '--not-me=off', '--not-me-scope=all' );
		await open( browser, 'alice', FIREFOX, '198.51.100.30' );
		expect( mail().pop().message ).not.toContain( 'user_session_manager_not_me' );

		// Too many wrong keys from one address slow down.
		const bad = '/wp-login.php?action=user_session_manager_not_me&usm_key=3.' + 'b'.repeat( 48 );
		for ( let i = 0; i < 10; i++ ) {
			expect( ( await request.get( bad ) ).status() ).toBe( 404 );
		}
		expect( ( await request.get( bad ) ).status() ).toBe( 429 );
	} );

	test( 'sign-in history shows how sessions ended, and the login screen says why you were signed out', async ( { page, browser } ) => {
		wp( 'user-session-manager', 'set-policy', 'author', '--max-sessions=1' );
		const phone = await open( browser, 'alice', IPHONE );
		const laptop = await open( browser, 'alice', FIREFOX );

		// The phone was signed out to make room: its next request says so, once, and only to the browser that held the session.
		await phone.page.goto( '/wp-admin/profile.php' );
		await expect( phone.page ).toHaveURL( /wp-login\.php/ );
		await expect( phone.page.locator( '.message' ) ).toContainText( 'You were signed out because this account signed in on another device, at' );
		await phone.page.goto( '/wp-login.php' );
		await expect( phone.page.locator( '.message' ) ).toHaveCount( 0 );
		const stranger = await browser.newContext( { storageState: { cookies: [], origins: [] } } );
		contexts.push( stranger );
		const strangerPage = await stranger.newPage();
		await strangerPage.goto( '/wp-admin/' );
		await expect( strangerPage.locator( '.message' ) ).toHaveCount( 0 );

		await page.goto( `/wp-admin/user-edit.php?user_id=${ id( 'alice' ) }#usm-history` );
		const rows = page.locator( '.usm-history-row' );
		await expect( rows ).toHaveCount( 2 );
		await expect( rows.filter( { hasText: 'Safari on iOS' } ) ).toContainText( 'Device limit reached' );
		await expect( rows.filter( { hasText: 'Firefox on Windows' } ) ).toContainText( 'Still signed in' );
		await expect( rows.first().locator( 'time.axle-time' ).first() ).toHaveAttribute( 'datetime', /\d{4}-\d\d-\d\dT/ );

		// An administrator signs the laptop out.
		wp( 'user-session-manager', 'revoke', sessions( 'alice' )[ 0 ].handle );
		await laptop.page.goto( '/wp-admin/profile.php' );
		await expect( laptop.page.locator( '.message' ) ).toContainText( 'You were signed out by a site administrator, at' );

		// Idle timeout, and a session that ran out.
		const idle = await open( browser, 'alice', ANDROID );
		wpEval( `update_option( 'usm_e2e_idle', 1 ); $id = get_user_by( 'login', 'alice' )->ID; $all = get_user_meta( $id, 'session_tokens', true );
			foreach ( $all as $k => $s ) { $all[ $k ]['last_seen'] = time() - 120; } update_user_meta( $id, 'session_tokens', $all );` );
		await idle.page.goto( '/wp-admin/profile.php' );
		await expect( idle.page.locator( '.message' ) ).toContainText( 'You were signed out because you were inactive for a while' );

		wpEval( `delete_option( 'usm_e2e_idle' );` );
		const expired = await open( browser, 'alice', CHROME );
		wpEval( `$id = get_user_by( 'login', 'alice' )->ID; $all = get_user_meta( $id, 'session_tokens', true );
			foreach ( $all as $k => $s ) { $all[ $k ]['expiration'] = time() - 5; } update_user_meta( $id, 'session_tokens', $all );
			$h = get_user_meta( $id, 'user_session_manager_history', true ); $h[0]['expires'] = time() - 5; update_user_meta( $id, 'user_session_manager_history', $h );` );
		await expired.page.goto( '/wp-admin/profile.php' );
		await expect( expired.page.locator( '.message' ) ).toContainText( 'Your session ended because it reached its time limit' );

		// Your own sign out needs no explanation and is recorded as such.
		const own = await open( browser, 'alice', FIREFOX );
		await own.page.goto( '/wp-login.php?action=logout' );
		await own.page.getByRole( 'link', { name: 'log out' } ).click();
		await expect( own.page.locator( '.message' ) ).toContainText( 'You are now logged out' );
		await expect( own.page.locator( '.message' ) ).not.toContainText( 'You were signed out because' );
		const history = JSON.parse( wp( 'user-session-manager', 'history', 'alice', '--format=json' ) );
		expect( history.map( ( row ) => row.reason ) ).toEqual( expect.arrayContaining( [ 'limit', 'admin', 'idle', 'expired', 'signed_out' ] ) );
		expect( history[ 0 ].reason ).toBe( 'signed_out' );
	} );

	test( 'history follows the retention setting, the privacy tools and the clean-up', async ( { browser } ) => {
		await open( browser, 'alice', IPHONE );
		await open( browser, 'alice', FIREFOX );
		const keep = () => JSON.parse( wpEval( `echo wp_json_encode( get_user_meta( get_user_by( 'login', 'alice' )->ID, 'user_session_manager_history', true ) ?: array() );` ) );
		expect( keep() ).toHaveLength( 2 );

		const exported = JSON.parse(
			wpEval( `$tools = apply_filters( 'wp_privacy_personal_data_exporters', array() );
				echo wp_json_encode( call_user_func( $tools['user-session-manager']['callback'], 'alice@example.com', 1 ) );` )
		);
		expect( exported.data.filter( ( item ) => item.group_id === 'user-session-manager-history' ) ).toHaveLength( 2 );

		// An entry past the retention time goes with the clean-up, even for someone who never comes back.
		wpEval( `$id = get_user_by( 'login', 'alice' )->ID; $h = get_user_meta( $id, 'user_session_manager_history', true );
			$h[1]['start'] = time() - 200 * DAY_IN_SECONDS; $h[1]['seen'] = $h[1]['start']; $h[1]['end'] = $h[1]['start'] + 60; $h[1]['expires'] = $h[1]['end']; $h[1]['reason'] = 'idle';
			update_user_meta( $id, 'user_session_manager_history', $h );` );
		expect( wp( 'user-session-manager', 'prune' ) ).toContain( '1 old sign-in history entries' );
		expect( keep() ).toHaveLength( 1 );

		// Retention 0 keeps none, and the clean-up empties what was there.
		wp( 'user-session-manager', 'settings', '--history-days=0' );
		wp( 'user-session-manager', 'prune' );
		expect( keep() ).toHaveLength( 0 );
		await open( browser, 'alice', CHROME );
		expect( keep() ).toHaveLength( 0 );

		wp( 'user-session-manager', 'settings', '--history-days=90' );
		await open( browser, 'alice', ANDROID );
		expect( keep() ).toHaveLength( 1 );
		wpEval( `$tools = apply_filters( 'wp_privacy_personal_data_erasers', array() );
			call_user_func( $tools['user-session-manager']['callback'], 'alice@example.com', 1 );` );
		expect( keep() ).toHaveLength( 0 );
	} );

	test( 'Sign everyone out asks for the words, keeps you and the chosen roles, and can retire passwords', async ( { page, browser } ) => {
		await open( browser, 'alice', IPHONE );
		await open( browser, 'bob', CHROME );
		await open( browser, 'sam', FIREFOX );
		await open( browser, 'carol', ANDROID );

		await page.goto( SESSIONS );
		await page.getByRole( 'link', { name: 'Sign everyone out' } ).click();
		await expect( page.getByRole( 'heading', { name: 'Sign everyone out?' } ) ).toBeVisible();
		await expect( page.locator( '.usm-incident-form' ) ).toContainText( 'This cannot be undone' );
		const go = page.getByRole( 'button', { name: 'Sign everyone out' } );
		const typed = page.getByLabel( /Type SIGN OUT to confirm/ );
		await expect( go ).toBeDisabled();
		await typed.fill( 'sign out' );
		await expect( go ).toBeDisabled();

		// The server checks the words too, whatever the page says.
		await page.evaluate( () => {
			const button = document.querySelector( '[data-usm-typed-button]' );
			button.removeAttribute( 'disabled' );
			button.setAttribute( 'aria-disabled', 'false' );
		} );
		await go.click();
		await expect( page.locator( '.notice-error' ) ).toContainText( 'did not match' );
		expect( sessions( 'alice' ) ).toHaveLength( 1 );

		await page.getByLabel( 'Editor' ).check();
		await page.getByLabel( 'Also require new passwords' ).check();
		await page.getByLabel( /Type SIGN OUT to confirm/ ).fill( 'SIGN OUT' );
		await expect( page.getByRole( 'button', { name: 'Sign everyone out' } ) ).toBeEnabled();
		await page.getByRole( 'button', { name: 'Sign everyone out' } ).click();

		await expect( page.locator( '.usm-notice' ) ).toContainText( 'Everyone was signed out: 3 sessions ended.' );
		await expect( page.locator( '.usm-notice' ) ).toContainText( 'must now choose a new password' );
		expect( sessions( 'alice' ) ).toHaveLength( 0 );
		expect( sessions( 'sam' ) ).toHaveLength( 0 );
		expect( sessions( 'carol' ) ).toHaveLength( 0 );
		expect( sessions( 'bob' ) ).toHaveLength( 1 );
		expect( await signedIn( page ) ).toBe( true );
		await expect( page.locator( '.usm-incident-log' ) ).toHaveCount( 0 );

		// Passwords: retired for everyone but you and the left-out role.
		const works = ( login, password ) => {
			try {
				wp( 'user', 'check-password', login, password );
				return true;
			} catch ( error ) {
				return false;
			}
		};
		expect( works( 'alice', PASSWORD ) ).toBe( false );
		expect( works( 'sam', PASSWORD ) ).toBe( false );
		expect( works( 'bob', PASSWORD ) ).toBe( true );
		expect( works( 'admin', ADMIN_PASSWORD ) ).toBe( true );
		await page.goto( SESSIONS );
		await expect( page.locator( '.usm-incident-log' ) ).toContainText( '3 sessions signed out' );

		// It is in each person's history, and the login screen says why.
		const history = JSON.parse( wp( 'user-session-manager', 'history', 'alice', '--format=json' ) );
		expect( history[ 0 ].reason ).toBe( 'admin' );
	} );

	test( 'Sign everyone out from WP-CLI and from the ability, with exclusions', async ( { rest, browser } ) => {
		test.skip( 'yes' !== wpEval( "echo function_exists( 'wp_register_ability' ) ? 'yes' : 'no';" ), 'The Abilities API needs WordPress 6.9.' );
		await open( browser, 'alice', IPHONE );
		await open( browser, 'bob', CHROME );
		await open( browser, 'sam', FIREFOX );
		const path = '/wp-abilities/v1/abilities/user-session-manager/sign-out-everyone/run';

		// An explicit yes is required, and nobody is signed out without it.
		const refused = await rest( 'POST', path, { input: { confirm: false } } );
		expect( refused.status ).toBeGreaterThanOrEqual( 400 );
		expect( sessions( 'alice' ) ).toHaveLength( 1 );

		const done = await rest( 'POST', path, { input: { confirm: true, exclude_roles: [ 'editor' ] } } );
		expect( done.status ).toBe( 200 );
		expect( done.body ).toMatchObject( { users_signed_out: 2, sessions_ended: 2, passwords_retired: 0 } );
		expect( sessions( 'bob' ) ).toHaveLength( 1 );

		// A subscriber has no permission.
		expect( wpEval( `wp_set_current_user( get_user_by( 'login', 'sam' )->ID ); $r = wp_get_ability( 'user-session-manager/sign-out-everyone' )->execute( array( 'confirm' => true ) ); echo is_wp_error( $r ) ? $r->get_error_code() : 'ran';` ) ).toBe( 'ability_invalid_permissions' );

		await open( browser, 'alice', IPHONE );
		expect( wp( 'user-session-manager', 'sign-out-everyone', '--keep-user=admin', '--exclude-roles=editor', '--yes' ) ).toContain( 'Signed' );
		expect( sessions( 'alice' ) ).toHaveLength( 0 );
		expect( sessions( 'bob' ) ).toHaveLength( 1 );
		const log = JSON.parse( wpEval( `echo wp_json_encode( get_option( 'user_session_manager_incidents' ) );` ) );
		expect( log.map( ( entry ) => entry.via ) ).toEqual( [ 'cli', 'ability' ] );
	} );

	test( 'the settings screen saves the new options, has a Help tab, and the Get started card can be dismissed', async ( { page } ) => {
		await page.goto( SETTINGS );
		await page.getByLabel( 'Sign out only the session the email was about' ).check();
		await page.getByLabel( 'Keep sign-in history for' ).fill( '30' );
		await page.getByRole( 'button', { name: 'Save settings' } ).click();
		await expect( page.locator( '.usm-notice' ) ).toContainText( 'Settings saved.' );
		const saved = JSON.parse( wp( 'user-session-manager', 'settings', '--format=json' ) );
		expect( saved ).toMatchObject( { not_me: true, not_me_scope: 'session', history_days: 30 } );

		await page.getByRole( 'button', { name: 'Help' } ).click();
		await page.locator( '#tab-link-user-session-manager-history a' ).click();
		await expect( page.locator( '#tab-panel-user-session-manager-history' ) ).toContainText( 'kept for the number of days you set' );

		await page.goto( SESSIONS );
		await expect( page.locator( '.usm-steps' ) ).toContainText( 'Limit administrator sessions' );
		await page.locator( '.usm-steps' ).getByRole( 'link', { name: 'Dismiss' } ).click();
		await expect( page.locator( '.usm-steps' ) ).toHaveCount( 0 );
		await page.reload();
		await expect( page.locator( '.usm-steps' ) ).toHaveCount( 0 );
	} );

	test( 'other scripts can change the confirm step through wp.hooks', async ( { page, browser } ) => {
		await open( browser, 'alice', IPHONE );
		await page.goto( `/wp-admin/user-edit.php?user_id=${ id( 'alice' ) }` );
		await page.evaluate( () => wp.hooks.addFilter( 'user_session_manager.confirm_message', 'e2e', ( message ) => message + ' Hooked.' ) );
		await page.locator( '.usm-sessions' ).getByRole( 'link', { name: 'Sign out' } ).click();
		await expect( page.locator( '.usm-confirm-box' ) ).toContainText( 'Hooked.' );
		// Escape cancels and gives focus back to the link.
		await page.keyboard.press( 'Escape' );
		await expect( page.locator( '.usm-confirm-box' ) ).toHaveCount( 0 );
		await expect( page.locator( '.usm-sessions' ).getByRole( 'link', { name: 'Sign out' } ) ).toBeFocused();
		expect( sessions( 'alice' ) ).toHaveLength( 1 );
	} );
} );
