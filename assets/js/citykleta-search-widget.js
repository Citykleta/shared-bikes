/**
 * Inicializa Flatpickr en modo rango sobre el campo de fechas del widget de
 * búsqueda. El input visible es de solo lectura (Flatpickr lo controla);
 * las fechas reales que se mandan en el formulario van en dos inputs
 * ocultos (from / to), en formato Y-m-d.
 *
 * Corre tanto en el front-end como dentro del editor de Elementor (donde
 * puede haber varias instancias del widget en la misma página, por eso se
 * inicializa cada una por separado en vez de asumir un único campo).
 */
( function () {
	'use strict';

	// La descripción de cada uso (texto de orientación que se ve al
	// desplegar Tom Select) ya no vive aquí fija — se lee del propio HTML
	// (atributo data-description de cada <option>), que a su vez viene del
	// campo "description" nativo del término de wp-admin → Bicicletas →
	// Bike types. El filtro de verdad se resuelve en el servidor
	// (Citykleta_Templates::request_filters()): un tipo incluye a sus subtipos.
	function initUsage( select ) {
		if ( select.dataset.citykletaInit ) {
			return;
		}

		if ( 'undefined' === typeof window.TomSelect ) {
			return;
		}

		var info = {};
		var opts = select.querySelectorAll( 'option' );
		for ( var i = 0; i < opts.length; i++ ) {
			if ( ! opts[ i ].value ) {
				continue;
			}
			info[ opts[ i ].value ] = {
				title: opts[ i ].textContent.trim(),
				description: opts[ i ].dataset.description || '',
			};
		}

		new window.TomSelect( select, {
			allowEmptyOption: true,
			maxOptions: null,
			/*
			 * Without this, Tom Select's dropdown (.ts-dropdown, position:
			 * absolute) stays nested inside .ts-wrapper, a flex item of
			 * .citykleta-search-bar — a flex container with no explicit
			 * width, sized to its own content. Chromium's intrinsic-width
			 * calculation for that auto-sized container ends up counting the
			 * open dropdown's 320px box even though it's positioned out of
			 * flow, so the whole bar grows and re-centers the moment the
			 * dropdown opens. Reparenting the dropdown to <body> (Tom Select
			 * repositions it itself, including on scroll) removes it from
			 * that ancestor chain entirely.
			 */
			dropdownParent: 'body',
			// The <select> carries .citykleta-search-bar__usage; copying it
			// onto the now-body-level .ts-dropdown is what lets
			// search-bar-usage.css still scope its width/z-index override to
			// just this field instead of every Tom Select dropdown on the page.
			copyClassesToDropdown: true,
			render: {
				option: function ( data, escape ) {
					var i = info[ data.value ];
					if ( ! i || ! i.description ) {
						return '<div class="citykleta-usage-option">' + escape( data.text ) + '</div>';
					}
					return (
						'<div class="citykleta-usage-option">' +
						'<span class="citykleta-usage-option__title">' + escape( i.title ) + '</span>' +
						'<span class="citykleta-usage-option__desc">' + escape( i.description ) + '</span>' +
						'</div>'
					);
				},
				item: function ( data, escape ) {
					var i = info[ data.value ];
					return '<div>' + escape( i ? i.title : data.text ) + '</div>';
				},
			},
		} );

		select.dataset.citykletaInit = '1';
	}

	/*
	 * El mismo selector de rango que usa la barra de búsqueda lo usa también
	 * el formulario de solicitud ("Periodo de renta"), así que esta función no
	 * conoce ninguna clase de esos dos componentes: el contenedor se marca con
	 * data-citykleta-range y los dos inputs ocultos con data-citykleta-date,
	 * y cualquier input con la clase .citykleta-daterange entra por acá. Así
	 * el visitante ve el mismo calendario en los dos sitios sin que haya dos
	 * implementaciones que se puedan ir separando con el tiempo.
	 */
	function initRange( input ) {
		if ( input.dataset.citykletaInit ) {
			return;
		}

		var field     = input.closest( '[data-citykleta-range]' ) || input.parentNode;
		var fromInput = field ? field.querySelector( '[data-citykleta-date="from"]' ) : null;
		var toInput   = field ? field.querySelector( '[data-citykleta-date="to"]' ) : null;

		if ( 'undefined' === typeof window.flatpickr ) {
			return;
		}

		if ( window.flatpickr.l10ns && window.flatpickr.l10ns.es ) {
			window.flatpickr.localize( window.flatpickr.l10ns.es );
		}

		window.flatpickr( input, {
			/*
			 * Sin esto, Flatpickr arma la clase del altInput como
			 * "<clases del input original> form-control input" — o sea que el
			 * input visible que crea se lleva también .citykleta-daterange, y
			 * la siguiente pasada de initAll() (el hook element_ready del
			 * editor, por ejemplo) le montaría un segundo calendario encima.
			 * Se le pasan las clases de estilo sin la que dispara la
			 * inicialización.
			 */
			altInputClass: input.className.replace( /\bcitykleta-daterange\b/g, '' ).trim(),
			mode: 'range',
			showMonths: 2,
			minDate: 'today',
			dateFormat: 'Y-m-d',
			altInput: true,
			altFormat: 'j M Y',
			rangeSeparator: ' - ',
			onChange: function ( selectedDates, dateStr, instance ) {
				if ( fromInput ) {
					fromInput.value = selectedDates[0] ? instance.formatDate( selectedDates[0], 'Y-m-d' ) : '';
				}
				if ( toInput ) {
					toInput.value = selectedDates[1] ? instance.formatDate( selectedDates[1], 'Y-m-d' ) : '';
				}

				// Setting .value from script fires no event, so whoever listens
				// to these fields (the request form's live price estimate) would
				// never find out the range changed. A native-date fallback fires
				// its own 'change', this keeps the Flatpickr path consistent with it.
				[ fromInput, toInput ].forEach( function ( hidden ) {
					if ( hidden ) {
						hidden.dispatchEvent( new Event( 'change', { bubbles: true } ) );
					}
				} );
			},
		} );

		input.dataset.citykletaInit = '1';
	}

	// Antes de mandar el formulario (GET), deja la URL limpia: los campos
	// vacíos se desactivan para que no aparezcan como "key=" en la query
	// string, y el input oculto "electric=0" (el truco para detectar el
	// checkbox sin marcar) se desactiva cuando el checkbox SÍ está marcado,
	// para no mandar "electric=0&electric=1" a la vez.
	function initFormCleanup( form ) {
		if ( form.dataset.citykletaCleanInit ) {
			return;
		}

		form.addEventListener( 'submit', function () {
			var fields = form.querySelectorAll( 'input[name], select[name]' );
			for ( var i = 0; i < fields.length; i++ ) {
				var field = fields[ i ];
				if ( 'checkbox' === field.type || 'radio' === field.type ) {
					continue;
				}
				if ( ! field.value ) {
					field.disabled = true;
				}
			}

			var electricCheckbox = form.querySelector( 'input[type="checkbox"][name="electric"]' );
			if ( electricCheckbox && electricCheckbox.checked ) {
				var electricHidden = form.querySelector( 'input[type="hidden"][name="electric"]' );
				if ( electricHidden ) {
					electricHidden.disabled = true;
				}
			}
		} );

		form.dataset.citykletaCleanInit = '1';
	}

	function initAll() {
		var inputs = document.querySelectorAll( '.citykleta-daterange' );
		for ( var i = 0; i < inputs.length; i++ ) {
			initRange( inputs[ i ] );
		}

		var usageSelects = document.querySelectorAll( '.citykleta-search-bar__usage' );
		for ( var j = 0; j < usageSelects.length; j++ ) {
			initUsage( usageSelects[ j ] );
		}

		var forms = document.querySelectorAll( '.citykleta-search-bar' );
		for ( var k = 0; k < forms.length; k++ ) {
			initFormCleanup( forms[ k ] );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', initAll );
	} else {
		initAll();
	}

	// El editor de Elementor vuelve a dibujar los widgets por AJAX al
	// cambiar ajustes; hay que re-inicializar cuando eso pase.
	if ( window.elementorFrontend && window.elementorFrontend.hooks ) {
		window.elementorFrontend.hooks.addAction( 'frontend/element_ready/citykleta_search.default', initAll );
		// El widget "Bike Price & Request" trae el mismo campo de rango.
		window.elementorFrontend.hooks.addAction( 'frontend/element_ready/citykleta_bike_request.default', initAll );
	}
} )();
