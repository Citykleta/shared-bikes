/**
 * Toggle de vistas del archivo de bicis ("showroom"): un solo botón alterna
 * entre la vista de tarjetas y la vista de mapa — solo una visible a la vez.
 * Dos experiencias (ver tarjetas / navegar la ciudad) para enganchar al
 * usuario hacia la app. El botón vive en la barra de filtros (fuera de
 * .citykleta-results), así que se localiza a nivel de documento por
 * [data-view-toggle]; los paneles sí están dentro de .citykleta-results.
 *
 * Detalle Leaflet: el mapa se auto-inicializa al cargar (citykleta-map.js),
 * pero si su panel está oculto (display:none) mide 0px y sale en blanco / con
 * el zoom mal calculado. Por eso, la PRIMERA vez que se muestra el mapa lo
 * re-inicializamos con el contenedor ya visible (tamaño real); en aperturas
 * siguientes basta invalidateSize() para preservar el zoom/paneo del usuario.
 */
( function () {
	'use strict';

	function bind() {
		var button = document.querySelector( '[data-view-toggle]' );
		var results = document.querySelector( '.citykleta-results' );

		if ( ! button || ! results ) {
			return;
		}

		var gridPanel = results.querySelector( '[data-view="grid"]' );
		var mapPanel = results.querySelector( '[data-view="map"]' );
		var label = button.querySelector( '.citykleta-view-toggle__label' );

		if ( ! gridPanel || ! mapPanel ) {
			return;
		}

		function revealMap() {
			var mapEl = mapPanel.querySelector( '.citykleta-map' );

			if ( ! mapEl || ! window.CityKletaMap ) {
				return;
			}

			// El panel ya está visible en este punto (setView lo mostró antes
			// de llamar aquí), así que Leaflet puede medir su tamaño real.
			if ( ! mapEl.dataset.citykletaShown ) {
				try {
					var bikes = JSON.parse( mapEl.getAttribute( 'data-bikes' ) || '[]' );
					var options = JSON.parse( mapEl.getAttribute( 'data-options' ) || '{}' );
					window.CityKletaMap.init( mapEl, bikes, options );
				} catch ( e ) {
					return;
				}
				mapEl.dataset.citykletaShown = '1';
			} else if ( mapEl._citykletaMap ) {
				mapEl._citykletaMap.invalidateSize();
			}
		}

		function setView( view ) {
			var showMap = 'map' === view;

			gridPanel.hidden = showMap;
			mapPanel.hidden = ! showMap;
			results.setAttribute( 'data-active-view', view );
			button.setAttribute( 'aria-pressed', showMap ? 'true' : 'false' );

			if ( label ) {
				label.textContent = showMap ? 'Ver tarjetas' : 'Ver mapa';
			}

			if ( showMap ) {
				revealMap();
			}
		}

		button.addEventListener( 'click', function () {
			var current = results.getAttribute( 'data-active-view' ) || 'grid';
			setView( 'map' === current ? 'grid' : 'map' );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', bind );
	} else {
		bind();
	}
} )();
