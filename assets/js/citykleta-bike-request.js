/*
 * Drives the "request this bike" form on a bike's page. The form is always
 * visible (no open/close toggle) — submitting posts to the plugin REST API
 * (Citykleta_Bike_Request_Rest), which stores the request and emails Citykleta
 * (and the owner, when there's an address on file).
 *
 * Nothing is remembered between visits, unlike the app-download wall: asking
 * for a bike twice — different dates, or a follow-up — is two real requests,
 * so there is no localStorage short-circuit here.
 *
 * See includes/requests/class-citykleta-bike-request-form.php.
 */
( function () {
	'use strict';

	var config = window.CitykletaRequest;

	if ( ! config || ! config.restBase ) {
		return;
	}

	function initWidget( root ) {
		var form    = root.querySelector( '.citykleta-bike-request__form' );
		var done    = root.querySelector( '.citykleta-bike-request__done' );
		var error   = root.querySelector( '.citykleta-bike-request__error' );
		var submit  = root.querySelector( '.citykleta-bike-request__submit' );
		var bikeId  = root.getAttribute( 'data-bike' );

		if ( ! form || ! done || ! bikeId ) {
			return;
		}

		// Keep the two date fields consistent: picking a start date can't leave
		// an earlier end date selectable. The server checks this too — this is
		// only so the visitor finds out before submitting.
		var from = form.elements.from;
		var to   = form.elements.to;

		if ( from && to ) {
			from.addEventListener( 'change', function () {
				to.min = from.value || to.getAttribute( 'min' ) || '';

				if ( to.value && from.value && to.value < from.value ) {
					to.value = from.value;
				}
			} );
		}

		function showError( message ) {
			if ( submit ) {
				submit.disabled = false;
			}

			if ( error ) {
				error.textContent = message || 'No se pudo enviar. Inténtalo de nuevo.';
				error.hidden      = false;
			}
		}

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
				bike_id: bikeId,
				name: form.elements.name.value,
				contact: form.elements.contact.value,
				from: from ? from.value : '',
				to: to ? to.value : '',
				message: form.elements.message.value,
				consent: form.elements.consent.checked,
				website: form.elements.website.value,
				source: window.location.href
			};

			fetch( config.restBase + '/bike-requests', {
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
					if ( result.ok && result.data && result.data.ok ) {
						form.hidden = true;
						done.hidden = false;
						return;
					}

					showError( result.data && result.data.message );
				} )
				.catch( function () {
					showError();
				} );
		} );
	}

	function init() {
		var widgets = document.querySelectorAll( '.citykleta-bike-request' );
		Array.prototype.forEach.call( widgets, initWidget );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
