/**
 * Supplier self-disclosure portal (shortcode [wm_lksg_supplier_portal]).
 *
 * One handler per form, found by the data-wm-lksg-portal attribute and bound once, so two portals on a page each send
 * their own form a single time. Until 2026-09-15 an inline script per portal looked up fixed element IDs: the second
 * portal bound a second listener to the first form (two POSTs per click) and left its own form unwired. The endpoint
 * and the messages come from data attributes; the declaration is sent as the checkbox state, not as a fixed true.
 */
( function () {
	'use strict';

	function bind( form ) {
		if ( form.dataset.wmLksgBound === '1' ) {
			return;
		}
		form.dataset.wmLksgBound = '1';

		var root = form.closest( '.wm-lksg-portal' );
		var status = root ? root.querySelector( '.wm-lksg-portal-status' ) : null;
		var result = root ? root.querySelector( '.wm-lksg-portal-result' ) : null;
		var reference = root ? root.querySelector( '.wm-lksg-portal-reference' ) : null;

		function say( text, ok ) {
			if ( ! status ) {
				return;
			}
			status.textContent = text;
			status.hidden = text === '';
			status.classList.toggle( 'is-error', ! ok );
		}

		function field( name ) {
			return form.elements.namedItem( name );
		}

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			var button = form.querySelector( 'button[type="submit"]' );
			var label = button ? button.textContent : '';
			if ( button ) {
				button.disabled = true;
				button.textContent = form.dataset.msgSending || '';
			}
			say( '', true );

			function fail( message ) {
				say( message, false );
				if ( button ) {
					button.disabled = false;
					button.textContent = label;
				}
			}

			var payload = {
				supplier_name: ( field( 'supplier_name' ).value || '' ).trim(),
				country_code: ( field( 'country_code' ).value || '' ).trim().toUpperCase(),
				industry_sector: field( 'industry_sector' ).value || '',
				tier_level: parseInt( field( 'tier_level' ).value || '1', 10 ),
				has_iso_14001: !! field( 'has_iso_14001' ).checked,
				has_sa_8000: !! field( 'has_sa_8000' ).checked,
				has_iso_45001: !! field( 'has_iso_45001' ).checked,
				declaration_accepted: !! field( 'declaration_accepted' ).checked
			};

			window.fetch( form.dataset.endpoint, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( payload )
			} )
				.then( function ( res ) {
					return res.json().catch( function () {
						return {};
					} ).then( function ( data ) {
						return { status: res.status, data: data };
					} );
				} )
				.then( function ( answer ) {
					// Only a real 201 counts. Anything else keeps the form and its values on screen, because the
					// declaration has NOT been filed.
					if ( answer.status !== 201 || ! answer.data.success ) {
						fail( answer.data.message || form.dataset.msgFailed || '' );
						return;
					}
					if ( reference ) {
						reference.textContent = answer.data.reference || '';
					}
					form.hidden = true;
					if ( result ) {
						result.hidden = false;
						result.focus();
					}
				} )
				.catch( function () {
					fail( form.dataset.msgNetwork || '' );
				} );
		} );
	}

	function init() {
		document.querySelectorAll( 'form[data-wm-lksg-portal]' ).forEach( bind );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
