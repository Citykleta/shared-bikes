/*
 * Cuenta las vistas de la página individual de una bici. Al cargar la página,
 * envía un aviso (navigator.sendBeacon) al endpoint REST del plugin, que suma
 * la vista al día de hoy en un meta del post. Se hace en el navegador (no en
 * el render de PHP) para que sobreviva a la caché de página y evite casi todos
 * los bots. Ver includes/stats/class-citykleta-view-stats.php.
 *
 * Para no inflar el conteo con recargas, cada bici se cuenta una sola vez por
 * sesión del navegador (sessionStorage se borra al cerrar la pestaña).
 */
( function () {
	'use strict';

	var config = window.CitykletaViews;

	if ( ! config || ! config.bikeId ) {
		return;
	}

	// Remembers, in this browser only, which bikes the visitor has opened (most
	// recent first, capped) so the "for you" row in Bike Rows can bring them
	// back. It runs before the once-per-session guard below: reopening a bike
	// should refresh its place in the list even when it isn't counted again.
	try {
		var recentKey = 'citykleta_recent_bikes';
		var recent = JSON.parse( localStorage.getItem( recentKey ) || '[]' );
		var id = parseInt( config.bikeId, 10 );

		if ( ! Array.isArray( recent ) ) {
			recent = [];
		}

		recent = recent.filter( function ( value ) {
			return value !== id;
		} );
		recent.unshift( id );

		localStorage.setItem( recentKey, JSON.stringify( recent.slice( 0, 20 ) ) );
	} catch ( e ) {
		// Private mode or blocked storage: the row just won't have history.
	}

	var storageKey = 'citykleta_viewed_' + config.bikeId;

	try {
		if ( window.sessionStorage && sessionStorage.getItem( storageKey ) ) {
			return;
		}
		if ( window.sessionStorage ) {
			sessionStorage.setItem( storageKey, '1' );
		}
	} catch ( e ) {
		// sessionStorage puede fallar (modo privado, cuota); si pasa, seguimos
		// y contamos la vista igual en vez de romper.
	}

	var url = config.restBase + '/bikes/' + config.bikeId + '/view';

	if ( navigator.sendBeacon ) {
		navigator.sendBeacon( url );
	} else {
		fetch( url, { method: 'POST', keepalive: true, credentials: 'same-origin' } );
	}
}() );
