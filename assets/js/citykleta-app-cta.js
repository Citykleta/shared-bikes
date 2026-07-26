/*
 * Drives the app-download wall rendered by [citykleta_app_download].
 *
 * Button -> sign-up form -> store links. Submitting the form captures the lead
 * through the plugin REST API (Citykleta_Subscriber_Rest); clicking a store
 * link reports the download for that lead. A visitor who already signed up
 * (remembered in localStorage) skips the form and goes straight to the stores,
 * and the download is reported at most once per browser.
 *
 * See includes/subscribers/class-citykleta-app-cta.php.
 */
( function () {
	'use strict';

	var config = window.CitykletaApp;

	if ( ! config || ! config.restBase ) {
		return;
	}

	var ID_KEY         = 'citykleta_app_subscriber_id';
	var DOWNLOADED_KEY = 'citykleta_app_downloaded';

	// localStorage may throw (private mode, quota); every access is guarded so a
	// failure just degrades to "not remembered" instead of breaking the wall.
	function readStore( key ) {
		try {
			return window.localStorage ? localStorage.getItem( key ) : null;
		} catch ( e ) {
			return null;
		}
	}

	function writeStore( key, value ) {
		try {
			if ( window.localStorage ) {
				localStorage.setItem( key, value );
			}
		} catch ( e ) {
			// Ignore — remembering the sign-up is a nicety, not required.
		}
	}

	function reportDownload( id ) {
		if ( ! id || readStore( DOWNLOADED_KEY ) ) {
			return;
		}

		writeStore( DOWNLOADED_KEY, '1' );

		var url = config.restBase + '/subscribers/' + id + '/download';

		if ( navigator.sendBeacon ) {
			navigator.sendBeacon( url );
		} else {
			fetch( url, { method: 'POST', keepalive: true } );
		}
	}

	function initWidget( root ) {
		var openBtn = root.querySelector( '.citykleta-app-cta__open' );
		var form    = root.querySelector( '.citykleta-app-cta__form' );
		var stores  = root.querySelector( '.citykleta-app-cta__stores' );
		var error   = root.querySelector( '.citykleta-app-cta__error' );
		var submit  = root.querySelector( '.citykleta-app-cta__submit' );

		if ( ! openBtn || ! form || ! stores ) {
			return;
		}

		function showStores() {
			openBtn.hidden = true;
			form.hidden    = true;
			stores.hidden  = false;
		}

		openBtn.addEventListener( 'click', function () {
			// Returning visitor who already left their email: skip the form.
			if ( readStore( ID_KEY ) ) {
				showStores();
				return;
			}

			openBtn.hidden = true;
			form.hidden    = false;
		} );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			if ( error ) {
				error.hidden      = true;
				error.textContent = '';
			}

			if ( submit ) {
				submit.disabled = true;
			}

			var payload = {
				name: form.elements.name.value,
				email: form.elements.email.value,
				consent: form.elements.consent.checked,
				website: form.elements.website.value,
				source: window.location.href
			};

			fetch( config.restBase + '/subscribers', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( payload )
			} )
				.then( function ( response ) {
					return response.json().then( function ( data ) {
						return { ok: response.ok, data: data };
					} );
				} )
				.then( function ( result ) {
					if ( result.ok && result.data && result.data.id ) {
						writeStore( ID_KEY, String( result.data.id ) );
						showStores();
						return;
					}

					showError( result.data && result.data.message );
				} )
				.catch( function () {
					showError();
				} );

			function showError( message ) {
				if ( submit ) {
					submit.disabled = false;
				}
				if ( error ) {
					error.textContent = message || 'No se pudo completar. Inténtalo de nuevo.';
					error.hidden      = false;
				}
			}
		} );

		// Report the download when a store link is clicked, without blocking the
		// navigation to the store.
		stores.addEventListener( 'click', function ( event ) {
			var link = event.target.closest( '.citykleta-app-cta__store' );

			if ( link ) {
				reportDownload( readStore( ID_KEY ) );
			}
		} );
	}

	function init() {
		var widgets = document.querySelectorAll( '.citykleta-app-cta' );
		Array.prototype.forEach.call( widgets, initWidget );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
