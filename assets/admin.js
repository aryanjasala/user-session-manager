/**
 * User Session Manager: in-place confirm steps, the typed confirm for "Sign everyone out"
 * and spoken results. Everything also works without it: the links go straight to the
 * server-side step.
 *
 * Hooks for other scripts:
 *   wp.hooks.applyFilters( 'user_session_manager.confirm_message', message, link )
 *   wp.hooks.doAction( 'user_session_manager.confirm_opened', link, box )
 */
( function () {
	'use strict';

	var data = window.usmAdmin || {};
	var wp = window.wp || {};
	var open = null;

	/**
	 * Says something to screen readers.
	 *
	 * @param {string} text    Text to announce.
	 * @param {string} politeness polite or assertive.
	 */
	function speak( text, politeness ) {
		if ( wp.a11y && wp.a11y.speak && text ) {
			wp.a11y.speak( text, politeness || 'polite' );
		}
	}

	/**
	 * Removes the open confirm box and gives focus back to its link.
	 *
	 * @param {boolean} restore Whether to move focus back.
	 */
	function close( restore ) {
		if ( ! open ) {
			return;
		}
		var trigger = open.trigger;
		open.box.parentNode.removeChild( open.box );
		open = null;
		if ( restore && trigger ) {
			trigger.focus();
		}
	}

	/**
	 * Opens the confirm box for a link.
	 *
	 * @param {HTMLAnchorElement} link The link that was activated.
	 */
	function ask( link ) {
		close( false );

		var title = link.getAttribute( 'data-usm-confirm-title' ) || data.title || '';
		var message = link.getAttribute( 'data-usm-confirm' ) || '';
		if ( wp.hooks && wp.hooks.applyFilters ) {
			message = wp.hooks.applyFilters( 'user_session_manager.confirm_message', message, link );
		}

		var id = 'usm-confirm-' + Date.now();
		var box = document.createElement( 'div' );
		box.className = 'axle-confirm axle-confirm--danger usm-confirm-box';
		box.setAttribute( 'role', 'group' );
		box.setAttribute( 'aria-labelledby', id );

		var heading = document.createElement( 'p' );
		heading.id = id;
		var strong = document.createElement( 'strong' );
		strong.textContent = title;
		heading.appendChild( strong );

		var body = document.createElement( 'p' );
		body.textContent = message;

		var actions = document.createElement( 'p' );
		actions.className = 'axle-actions';

		var yes = document.createElement( 'a' );
		yes.className = 'button axle-button--danger';
		yes.href = link.href;
		yes.textContent = link.getAttribute( 'data-usm-confirm-button' ) || data.confirm || '';

		var no = document.createElement( 'button' );
		no.type = 'button';
		no.className = 'button-link';
		no.textContent = data.cancel || '';
		no.addEventListener( 'click', function () {
			close( true );
		} );

		actions.appendChild( yes );
		actions.appendChild( no );
		box.appendChild( heading );
		box.appendChild( body );
		box.appendChild( actions );
		box.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key ) {
				event.preventDefault();
				close( true );
			}
		} );

		var anchor = link.closest( '.axle-scroll' ) || link.closest( 'table' ) || link.parentNode;
		anchor.parentNode.insertBefore( box, anchor.nextSibling );
		open = { box: box, trigger: link };
		yes.focus();
		speak( title + '. ' + message );
		if ( wp.hooks && wp.hooks.doAction ) {
			wp.hooks.doAction( 'user_session_manager.confirm_opened', link, box );
		}
	}

	document.addEventListener( 'click', function ( event ) {
		var link = event.target.closest ? event.target.closest( 'a[data-usm-confirm]' ) : null;
		if ( ! link || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || 0 !== event.button ) {
			return;
		}
		event.preventDefault();
		ask( link );
	} );

	/**
	 * Keeps the "Sign everyone out" button off until the words match. The server checks them too.
	 */
	function typedConfirm() {
		var input = document.querySelector( '[data-usm-typed]' );
		var button = document.querySelector( '[data-usm-typed-button]' );
		if ( ! input || ! button ) {
			return;
		}
		var word = input.getAttribute( 'data-usm-typed' );
		var update = function () {
			var match = input.value.trim() === word;
			button.disabled = ! match;
			button.setAttribute( 'aria-disabled', match ? 'false' : 'true' );
		};
		input.addEventListener( 'input', update );
		update();
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		typedConfirm();
		var notice = document.querySelector( '.usm-notice' );
		if ( notice ) {
			speak( notice.textContent );
		}
		var progress = document.querySelector( '[data-usm-progress]' );
		if ( progress ) {
			speak( progress.getAttribute( 'data-usm-progress' ) );
			var next = document.querySelector( '[data-usm-continue]' );
			if ( next ) {
				window.setTimeout( function () {
					window.location.assign( next.href );
				}, 600 );
			}
		}
	} );
}() );
