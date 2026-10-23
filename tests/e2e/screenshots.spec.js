// Regenerates .wordpress-org/screenshot-N.png (readme.txt "== Screenshots ==" and README.md).
// Run on demand: npm run screenshots
const { test, expect, wp } = require( './utils' );
const { prepareSite, shot } = require( './utils/media' );
const { UA, createPeople, removePeople, addSessions, clearSessions } = require( './seed' );

test.describe( 'Screenshots', () => {
	let context;
	let page;

	test.beforeAll( async ( { browser } ) => {
		prepareSite( 'Example Shop' );
		wp( 'option', 'delete', 'user_session_manager_settings' );
		createPeople();
		clearSessions();

		// The admin signs in from a Mac, so "This device" reads well.
		context = await browser.newContext( { storageState: { cookies: [], origins: [] }, userAgent: UA.mac, viewport: { width: 1280, height: 900 }, deviceScaleFactor: 2 } );
		page = await context.newPage();
		await page.goto( '/wp-login.php' );
		await page.locator( '#user_login' ).fill( 'admin' );
		await page.locator( '#user_pass' ).fill( 'admin' );
		await page.locator( '#wp-submit' ).click();
		await page.waitForURL( /wp-admin/ );

		addSessions( [
			[ 'admin', 'iphone', '203.0.113.24', 26 * 60, 40 ],
			[ 'admin', 'ipad', '198.51.100.71', 9 * 60, 7 * 60 ],
			[ 'maria', 'windows', '192.0.2.18', 180, 4 ],
			[ 'maria', 'android', '203.0.113.90', 30 * 60, 95 ],
			[ 'james', 'edge', '198.51.100.12', 50, 12 ],
			[ 'priya', 'iphone', '192.0.2.200', 20 * 60, 3 * 60 ],
		] );
	} );

	test.afterAll( async () => {
		await context.close();
		wp( 'option', 'delete', 'user_session_manager_settings' );
		removePeople();
	} );

	test( '1: Profile > Your sessions', async () => {
		await page.goto( '/wp-admin/profile.php' );
		const section = page.locator( '.usm-profile' );
		await expect( section.locator( '.usm-session' ) ).toHaveCount( 3 );
		await section.scrollIntoViewIfNeeded();
		const box = await section.boundingBox();
		await shot( page, 1, { clip: { x: 150, y: box.y - 16, width: 1130, height: box.height + 32 } } );
	} );

	test( '2: Users > Sessions with every signed-in device', async () => {
		await page.goto( '/wp-admin/users.php?page=user-sessions' );
		await expect( page.locator( 'tbody .usm-session' ) ).toHaveCount( 7 );
		await shot( page, 2 );
	} );

	test( '3: Settings > User Sessions with policies per role', async () => {
		wp( 'user-session-manager', 'set-policy', 'administrator', '--max-sessions=3', '--idle-minutes=60', '--lifetime-hours=12' );
		wp( 'user-session-manager', 'set-policy', 'editor', '--max-sessions=2', '--idle-minutes=120' );
		wp( 'user-session-manager', 'set-policy', 'subscriber', '--max-sessions=1', '--remember-hours=720' );
		wp( 'user-session-manager', 'settings', '--new-device-email=on' );
		await page.goto( '/wp-admin/options-general.php?page=user-session-manager' );
		await expect( page.locator( '#usm-administrator-idle_minutes' ) ).toHaveValue( '60' );
		await shot( page, 3, { fullPage: true } );
	} );

	test( '4: a login refused over the limit', async ( { browser } ) => {
		wp( 'user-session-manager', 'set-policy', 'editor', '--max-sessions=2' );
		wp( 'user-session-manager', 'settings', '--limit-mode=block' );
		const other = await browser.newContext( { storageState: { cookies: [], origins: [] }, userAgent: UA.ipad, viewport: { width: 1280, height: 900 }, deviceScaleFactor: 2 } );
		const login = await other.newPage();
		await login.goto( '/wp-login.php' );
		await login.locator( '#user_login' ).fill( 'maria' );
		await login.locator( '#user_pass' ).fill( 'demo-password-123' );
		await login.locator( '#wp-submit' ).click();
		await expect( login.locator( '#login_error' ) ).toContainText( 'This account can be signed in on 2 devices at a time.' );
		await shot( login, 4, { clip: { x: 390, y: 0, width: 500, height: 620 } } );
		await other.close();
	} );
} );
