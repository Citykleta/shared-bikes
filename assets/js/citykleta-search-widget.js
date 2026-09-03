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
	// Tipo de uso. La relación "uso -> tipos de bici" que de verdad filtra
	// resultados vive en el servidor (Citykleta_Templates::usage_to_bike_types()).
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

	function initRange( input ) {
		if ( input.dataset.citykletaInit ) {
			return;
		}

		var field    = input.closest( '.citykleta-search-bar__field-inner' );
		var fromInput = field ? field.querySelector( '.citykleta-search-bar__date-from' ) : null;
		var toInput   = field ? field.querySelector( '.citykleta-search-bar__date-to' ) : null;

		if ( 'undefined' === typeof window.flatpickr ) {
			return;
		}

		if ( window.flatpickr.l10ns && window.flatpickr.l10ns.es ) {
			window.flatpickr.localize( window.flatpickr.l10ns.es );
		}

		window.flatpickr( input, {
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
		var inputs = document.querySelectorAll( '.citykleta-search-bar__daterange' );
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
	}
} )();
