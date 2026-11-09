/*
 * Drives the "contact the owner" card on a bike's page.
 *
 * The card has a name, an email, a rental period, a live price estimate and a
 * link to a WhatsApp chat with the owner. The message itself is never sent from
 * here — the visitor sends it from WhatsApp. What this script does:
 *
 * - fills the estimate and rewrites the wa.me link so the message it pre-fills
 *   mentions the chosen dates;
 * - when the link is tapped, checks name and email, records them (with bike and
 *   dates) through the plugin REST API so Citykleta knows who asked, and lets
 *   the link open WhatsApp. The record is fire-and-forget: it is never waited on,
 *   because opening WhatsApp must not depend on our server answering, and a tab
 *   opened after an async call gets blocked as a popup.
 *
 * See includes/requests/class-citykleta-bike-request-form.php.
 */
( function () {
	'use strict';

	var config = window.CitykletaRequest || {};

	/**
	 * " del 20/09/2026 al 23/09/2026" — the same wording as
	 * Citykleta_Bike_Request::dates_phrase() in PHP, with its leading space,
	 * so the template's `{dates}` reads the same whichever side filled it in.
	 */
	function datesPhrase( from, to ) {
		function fmt( ymd ) {
			var p = ymd.split( '-' );

			return p[2] + '/' + p[1] + '/' + p[0];
		}

		if ( from && to ) {
			return from === to ? ' el ' + fmt( from ) : ' del ' + fmt( from ) + ' al ' + fmt( to );
		}

		if ( from ) {
			return ' desde el ' + fmt( from );
		}

		return to ? ' hasta el ' + fmt( to ) : '';
	}

	function initWidget( root ) {
		if ( root.dataset.citykletaRequestInit ) {
			return;
		}

		var form = root.querySelector( '.citykleta-bike-request__form' );

		if ( ! form ) {
			return;
		}

		root.dataset.citykletaRequestInit = '1';

		// Keep the two date fields consistent: picking a start date can't leave
		// an earlier end date selectable. Only applies to the native-date
		// fallback; with Flatpickr the range is one control and can't be
		// inconsistent in the first place.
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
		 * after a cutoff hour), and this card collects dates but no hours. So it
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

		/*
		 * Rewrites the WhatsApp link with the chosen dates. The message template
		 * arrives from the server with `{dates}` still in it (everything else
		 * already filled), so this is the only substitution left. Only a
		 * https://wa.me/<digits> URL is ever built, whatever the attributes say.
		 */
		var link = root.querySelector( '.citykleta-bike-request__whatsapp' );

		function updateLink() {
			if ( ! link ) {
				return;
			}

			var phone    = ( link.getAttribute( 'data-phone' ) || '' ).replace( /\D+/g, '' );
			var template = link.getAttribute( 'data-message' ) || '';

			if ( ! phone ) {
				return;
			}

			var text = template.split( '{dates}' ).join( datesPhrase( from ? from.value : '', to ? to.value : '' ) );

			link.href = 'https://wa.me/' + phone + '?text=' + encodeURIComponent( text );
		}

		form.addEventListener( 'change', function () {
			updateTotal();
			updateLink();
		} );

		var nameField  = form.elements.name;
		var emailField = form.elements.email;
		var error      = root.querySelector( '.citykleta-bike-request__error' );

		function showError( message, field ) {
			if ( error ) {
				error.textContent = message;
				error.hidden      = false;
			}

			if ( field ) {
				field.focus();
			}
		}

		// Failures the server never sees (they stop here, before the POST) are
		// reported so the Stats widget can count them. Once per reason per page
		// view, so a visitor clicking a few times doesn't read as many failures.
		var reported = {};

		function reportFailure( outcome ) {
			if ( reported[ outcome ] || ! config.restBase || ! window.fetch ) {
				return;
			}

			reported[ outcome ] = true;

			try {
				window.fetch( config.restBase + '/bike-requests/attempt', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					keepalive: true,
					body: JSON.stringify( { outcome: outcome } )
				} ).catch( function () {} );
			} catch ( e ) {}
		}

		if ( link ) {
			link.addEventListener( 'click', function ( event ) {
				var name  = nameField ? nameField.value.trim() : '';
				var email = emailField ? emailField.value.trim() : '';

				if ( error ) {
					error.hidden = true;
				}

				// The link would open WhatsApp on its own; stop it until the
				// visitor has said who they are.
				if ( '' === name ) {
					event.preventDefault();
					showError( 'Escribe tu nombre para continuar.', nameField );
					reportFailure( 'missing_name' );
					return;
				}

				if ( ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( email ) ) {
					event.preventDefault();
					showError( 'Escribe un correo válido para continuar.', emailField );
					reportFailure( 'invalid_email' );
					return;
				}

				// Make sure the href carries the dates as they stand right now,
				// whether or not a change event fired for the last one.
				updateLink();

				if ( ! config.restBase || ! window.fetch ) {
					return;
				}

				// Fire and forget; `keepalive` lets it finish even if the page
				// goes away. A failure here must never get in the way of WhatsApp.
				try {
					window.fetch( config.restBase + '/bike-requests', {
						method: 'POST',
						headers: { 'Content-Type': 'application/json' },
						keepalive: true,
						body: JSON.stringify( {
							bike_id: root.getAttribute( 'data-bike' ),
							name: name,
							email: email,
							from: from ? from.value : '',
							to: to ? to.value : '',
							website: form.elements.website ? form.elements.website.value : '',
							source: window.location.href
						} )
					} ).catch( function () {} );
				} catch ( e ) {}
			} );
		}
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
