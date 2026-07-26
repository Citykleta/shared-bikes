/**
 * Carga infinita del grid de tarjetas (reemplaza la paginación clásica). Al
 * acercarse el final del grid, trae la página siguiente por fetch, extrae sus
 * tarjetas y las anexa. Usa IntersectionObserver con el propio grid (que
 * scrollea por dentro en el app-shell) como root, así solo dispara cuando esa
 * vista está visible — en la vista de mapa (display:none) no carga nada.
 */
( function () {
	'use strict';

	// Construye la URL de la página n conservando el query string (filtros).
	function pageUrl( n ) {
		var url = new URL( window.location.href );
		var path = url.pathname.replace( /\/page\/\d+\/?$/, '/' );

		if ( '/' !== path.charAt( path.length - 1 ) ) {
			path += '/';
		}

		url.pathname = path + 'page/' + n + '/';
		return url.toString();
	}

	function setupGrid( root ) {
		var grid = root.querySelector( '.citykleta-bike-grid' );
		var sentinel = root.querySelector( '.citykleta-load-more' );

		if ( ! grid || ! sentinel || ! ( 'IntersectionObserver' in window ) ) {
			return;
		}

		var maxPages = parseInt( sentinel.getAttribute( 'data-max-pages' ), 10 ) || 1;
		var paged = parseInt( sentinel.getAttribute( 'data-paged' ), 10 ) || 1;
		var status = sentinel.querySelector( '.citykleta-load-more__status' );
		var loading = false;

		if ( paged >= maxPages ) {
			return; // Una sola página: nada que cargar.
		}

		var observer = new IntersectionObserver(
			function ( entries ) {
				if ( entries[ 0 ].isIntersecting ) {
					loadNext();
				}
			},
			{ root: root, rootMargin: '400px' }
		);

		function loadNext() {
			if ( loading || paged >= maxPages ) {
				return;
			}

			loading = true;
			var next = paged + 1;

			if ( status ) {
				status.textContent = 'Cargando…';
			}

			fetch( pageUrl( next ), { credentials: 'same-origin' } )
				.then( function ( response ) {
					return response.ok ? response.text() : Promise.reject();
				} )
				.then( function ( html ) {
					var doc = new DOMParser().parseFromString( html, 'text/html' );
					var cards = doc.querySelectorAll( '.citykleta-bike-grid .citykleta-bike-card' );

					for ( var i = 0; i < cards.length; i++ ) {
						grid.appendChild( document.importNode( cards[ i ], true ) );
					}

					paged = next;
					sentinel.setAttribute( 'data-paged', String( paged ) );

					if ( status ) {
						status.textContent = '';
					}

					if ( paged >= maxPages ) {
						observer.disconnect();
					}

					loading = false;
				} )
				.catch( function () {
					if ( status ) {
						status.textContent = '';
					}
					loading = false; // Permite reintentar en el siguiente cruce.
				} );
		}

		observer.observe( sentinel );
	}

	function setupAll() {
		var grids = document.querySelectorAll( '.citykleta-view--grid' );

		for ( var i = 0; i < grids.length; i++ ) {
			setupGrid( grids[ i ] );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', setupAll );
	} else {
		setupAll();
	}
} )();
