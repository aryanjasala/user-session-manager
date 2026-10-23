// Records .github/media/demo.gif for the README. Run on demand: npm run demo
const { test, expect, wp, wpEval } = require( './utils' );
const { prepareSite, recordDemo } = require( './utils/media' );
const { UA, createPeople, removePeople, addSessions, clearSessions } = require( './seed' );

test( 'demo: sign out a lost phone, then a whole user', async ( { browser } ) => {
	test.setTimeout( 180_000 );
	prepareSite( 'Example Shop' );
	wp( 'option', 'delete', 'user_session_manager_settings' );
	createPeople();
	// Keep only the session the recording is signed in with.
	clearSessions( true );
	// Show that session as the Mac it stands in for.
	wpEval( `$all = get_user_meta( 1, 'session_tokens', true ); foreach ( $all as $k => $s ) { $all[ $k ]['ua'] = '${ UA.mac }'; } update_user_meta( 1, 'session_tokens', $all );` );
	addSessions( [
		[ 'admin', 'iphone', '203.0.113.24', 26 * 60, 40 ],
		[ 'admin', 'ipad', '198.51.100.71', 9 * 60, 7 * 60 ],
		[ 'maria', 'windows', '192.0.2.18', 180, 4 ],
		[ 'maria', 'android', '203.0.113.90', 30 * 60, 95 ],
		[ 'james', 'edge', '198.51.100.12', 50, 12 ],
	] );

	try {
		await recordDemo( browser, async ( page, demo ) => {
			await page.goto( '/wp-admin/profile.php' );
			await page.locator( '.usm-profile' ).scrollIntoViewIfNeeded();
			await demo.start();
			await demo.pause( 1500 );

			// The phone was lost: sign it out from the profile.
			const phone = page.locator( '.usm-session', { hasText: 'Safari on iOS' } );
			await demo.click( phone.getByRole( 'link', { name: 'Sign out' } ), 300 );
			await expect( page.locator( '.usm-notice' ) ).toContainText( 'Session signed out.' );
			await page.locator( '.usm-profile' ).scrollIntoViewIfNeeded();
			await demo.pause( 1800 );

			// Every signed-in device on the site, and one user signed out everywhere.
			await demo.click( page.locator( '#menu-users a[href*="page=user-sessions"]' ), 300 );
			await expect( page.locator( 'tbody .usm-session' ) ).toHaveCount( 5 );
			await demo.pause( 1500 );
			const maria = page.locator( 'tbody .usm-session', { hasText: 'maria' } ).first();
			await demo.moveTo( maria.locator( '.column-user' ) );
			await demo.pause( 600 );
			await demo.click( maria.getByRole( 'link', { name: 'Sign out everywhere' } ), 300 );
			// It asks first.
			await expect( page.getByRole( 'heading', { name: 'Sign out maria everywhere?' } ) ).toBeVisible();
			await demo.pause( 1500 );
			await demo.click( page.getByRole( 'button', { name: 'Sign out everywhere', exact: true } ), 300 );
			await expect( page.locator( '.usm-notice' ) ).toContainText( 'maria was signed out of 2 sessions.' );
			await demo.pause( 2000 );
		} );
	} finally {
		wp( 'option', 'delete', 'user_session_manager_settings' );
		removePeople();
	}
} );
