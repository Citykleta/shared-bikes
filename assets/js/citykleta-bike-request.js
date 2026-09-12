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
		if ( root.dataset.citykletaRequestInit ) {
			return;
		}

		var form    = root.querySelector( '.citykleta-bike-request__form' );
		var done    = root.querySelector( '.citykleta-bike-request__done' );
		var error   = root.querySelector( '.citykleta-bike-request__error' );
		var submit  = root.querySelector( '.citykleta-bike-request__submit' );
		var bikeId  = root.getAttribute( 'data-bike' );

		if ( ! form || ! done || ! bikeId ) {
			return;
		}

		root.dataset.citykletaRequestInit = '1';

		// Keep the two date fields consistent: picking a start date can't leave
		// an earlier end date selectable. The server checks this too — this is
		// only so the visitor finds out before submitting. Only applies to the
		// native-date fallback; with Flatpickr the range is one control and
		// can't be inconsistent in the first place.
		var from = form.elements.from;
		var to   = form.elements.to;

		if ( from && to && 'date' === from.type ) {
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

		/*
		 * Two panels, one form. "Continuar" only moves between them — nothing
		 * is sent until the visitor submits from step 2, so a half-filled form
		 * never reaches the server and there is still exactly one POST per
		 * request.
		 */
		var stepOne = root.querySelector( '.citykleta-bike-request__step[data-step="1"]' );
		var stepTwo = root.querySelector( '.citykleta-bike-request__step[data-step="2"]' );
		var next    = root.querySelector( '.citykleta-bike-request__next' );
		var back    = root.querySelector( '.citykleta-bike-request__back' );
		var code    = form.elements.verification_code;

		function goToStep( n ) {
			if ( ! stepOne || ! stepTwo ) {
				return;
			}

			stepOne.hidden = 1 !== n;
			stepTwo.hidden = 2 !== n;

			if ( error ) {
				error.hidden = true;
			}
		}

		if ( next ) {
			next.addEventListener( 'click', function () {
				if ( code && code.dataset.required && '' === code.value.trim() ) {
					showError( 'Escribe tu código de verificación, o verifícate primero.' );
					return;
				}

				goToStep( 2 );
			} );
		}

		if ( back ) {
			back.addEventListener( 'click', function () {
				goToStep( 1 );
			} );
		}

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			// Required-ness is checked here rather than with the `required`
			// attribute: a hidden step-2 field with `required` makes the
			// browser refuse to submit while refusing to say why (it can't
			// focus a field nobody can see).
			if ( '' === form.elements.name.value.trim() ) {
				showError( 'Hace falta tu nombre.' );
				return;
			}

			if ( '' === form.elements.contact.value.trim() ) {
				showError( 'Deja un correo o un teléfono para poder responderte.' );
				return;
			}

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
				verification_code: code ? code.value : '',
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

	// Elementor redraws a widget over AJAX while its settings are edited —
	// same reason citykleta-search-widget.js and citykleta-map.js hook this.
	if ( window.elementorFrontend && window.elementorFrontend.hooks ) {
		window.elementorFrontend.hooks.addAction( 'frontend/element_ready/citykleta_bike_request.default', init );
	}
}() );
