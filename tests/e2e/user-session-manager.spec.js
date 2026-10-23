const { test, expect, wp, wpEval, logInAs } = require( './utils' );

const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
const FIREFOX = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:128.0) Gecko/20100101 Firefox/128.0';
const CHROME = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';
const ANDROID = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36';

const SESSIONS = '/wp-admin/users.php?page=user-sessions';
const SETTINGS = '/wp-admin/options-general.php?page=user-session-manager';
const PASSWORD = 'e2e-password-123';

// A must-use plugin for the suite: captures email and lets a test force the idle timeout.
const MU_PLUGIN = `<?php
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

/**
 * Signs a user in through wp-login.php in a fresh browser context.
 *
 * @param {import('@playwright/test').Browser} browser   Browser.
 * @param {string}                             login     User login.
 * @param {string}                             userAgent User agent for the context.
 * @return {Promise<{context: import('@playwright/test').BrowserContext, page: import('@playwright/test').Page}>}
 */
async function signIn( browser, login, userAgent ) {
	return logInAs( browser, login, PASSWORD, { userAgent } );
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
			}` );
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
		for ( const url of [ SETTINGS, '/wp-admin/profile.php', `${ SESSIONS }&user_id=${ id( 'carol' ) }` ] ) {
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

		expect( wp( 'user-session-manager', 'prune' ) ).toContain( 'Removed 0 expired and 1 idle sessions.' );
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
		expect( exported.data ).toHaveLength( 2 );
		const devices = exported.data.map( ( item ) => item.data.find( ( field ) => field.name === 'Device' ).value ).sort();
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
		expect( editor ).toEqual( { role: 'editor', max_sessions: 3, idle_minutes: 45, lifetime_hours: 8, remember_hours: 72 } );

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
		expect( listed.body.map( ( ability ) => ability.name ).sort() ).toEqual( [ 'user-session-manager/list-sessions', 'user-session-manager/revoke-session', 'user-session-manager/sign-out-user' ] );
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
} );
