/*
 * Drives the "request this bike" form on a bike's page. The form is always
 * visible (no open/close toggle) — submitting posts to the plugin REST API
 * (Citykleta_Bike_Request_Rest), which checks the verification code with the
 * app, stores the request, emails Citykleta (and the owner, when there's an
 * address on file) and answers with a WhatsApp link to the owner when the code
 * checked out.
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

		/*
		 * Live estimate under the dates: "$7 USD × 3 días" and the total.
		 *
		 * An ESTIMATE, and the lowest the app could charge: it bills hotel-style
		 * (calendar days between the dates, plus one when the bike comes back
		 * after a cutoff hour), and this form collects dates but no hours. So it
		 * shows the calendar days, never fewer than one — the same floor the app
		 * applies — and the markup says the owner confirms the final total.
		 */
		var price    = parseFloat( root.getAttribute( 'data-price' ) );
		var totalBox = root.querySelector( '.citykleta-bike-request__total' );
		var money    = new Intl.NumberFormat( 'es', { maximumFractionDigits: 2 } );

		function updateTotal() {
			if ( ! totalBox || isNaN( price ) ) {
				return;
			}

			var start = from && from.value ? Date.parse( from.value + 'T00:00:00Z' ) : NaN;
			var end   = to && to.value ? Date.parse( to.value + 'T00:00:00Z' ) : NaN;

			// Only quote once the range is complete: a lone start date is not a rental.
			if ( isNaN( start ) || isNaN( end ) || end < start ) {
				totalBox.hidden = true;
				return;
			}

			var days = Math.max( 1, Math.round( ( end - start ) / 86400000 ) );

			totalBox.querySelector( '.citykleta-bike-request__total-calc' ).textContent =
				'$' + money.format( price ) + ' USD × ' + days + ( 1 === days ? ' día' : ' días' );
			totalBox.querySelector( '.citykleta-bike-request__total-amount' ).textContent =
				'$' + money.format( price * days ) + ' USD';
			totalBox.hidden = false;
		}

		form.addEventListener( 'change', updateTotal );

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
		 * Swaps the form for the confirmation. When the server verified the
		 * code and the owner has a phone it also returns a ready-made wa.me
		 * link: show it as the next step. Only a https://wa.me/ URL is ever
		 * put in the href, whatever the response says.
		 */
		function showDone( data ) {
			var link = done.querySelector( '.citykleta-bike-request__whatsapp' );
			var text = done.querySelector( '.citykleta-bike-request__done-text' );
			var url  = data && 'string' === typeof data.whatsapp_url ? data.whatsapp_url : '';

			if ( link && 0 === url.indexOf( 'https://wa.me/' ) ) {
				link.href   = url;
				link.hidden = false;

				if ( text ) {
					text.textContent = data.owner_name
						? 'Tu código está verificado. Escríbele ahora a ' + data.owner_name + ' para coordinar.'
						: 'Tu código está verificado. Escríbele ahora al propietario para coordinar.';
				}
			}

			form.hidden = true;
			done.hidden = false;
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

			if ( error ) {
				error.hidden      = true;
				error.textContent = '';
			}

			if ( submit ) {
				submit.disabled = true;
			}

			var payload = {
				bike_id: bikeId,
				from: from ? from.value : '',
				to: to ? to.value : '',
				message: form.elements.message.value,
				verification_code: code ? code.value : '',
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
						return { ok: response.ok, status: response.status, data: data };
					} );
				} )
				.then( function ( result ) {
					if ( result.ok && result.data && result.data.ok ) {
						showDone( result.data );
						return;
					}

					// 403 = the app rejected the code. The code field lives on
					// step 1, so bring the visitor back to where they can fix it
					// instead of leaving the error on a step with nothing to edit.
					if ( 403 === result.status ) {
						goToStep( 1 );
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
