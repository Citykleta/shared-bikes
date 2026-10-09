( function () {
	'use strict';

	function initRow( viewport ) {
		if ( viewport.dataset.citykletaRowReady ) {
			return;
		}

		var track = viewport.querySelector( '.citykleta-bike-row__track' );

		if ( ! track ) {
			return;
		}

		viewport.dataset.citykletaRowReady = '1';

		var prev = viewport.querySelector( '.citykleta-bike-row__arrow--prev' );
		var next = viewport.querySelector( '.citykleta-bike-row__arrow--next' );

		function update() {
			var max = track.scrollWidth - track.clientWidth;

			viewport.classList.toggle( 'has-prev', track.scrollLeft > 4 );
			viewport.classList.toggle( 'has-next', track.scrollLeft < max - 4 );
		}

		function scrollByPage( direction ) {
			var reduce = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

			track.scrollBy( {
				left: direction * track.clientWidth * 0.9,
				behavior: reduce ? 'auto' : 'smooth',
			} );
		}

		if ( prev ) {
			prev.addEventListener( 'click', function () {
				scrollByPage( -1 );
			} );
		}

		if ( next ) {
			next.addEventListener( 'click', function () {
				scrollByPage( 1 );
			} );
		}

		track.addEventListener( 'scroll', update, { passive: true } );
		window.addEventListener( 'resize', update );

		if ( window.ResizeObserver ) {
			new ResizeObserver( update ).observe( track );
		}

		update();
	}

	function distanceKm( lat1, lng1, lat2, lng2 ) {
		var rad = Math.PI / 180;
		var dLat = ( lat2 - lat1 ) * rad;
		var dLng = ( lng2 - lng1 ) * rad;
		var a = Math.sin( dLat / 2 ) * Math.sin( dLat / 2 ) +
			Math.cos( lat1 * rad ) * Math.cos( lat2 * rad ) * Math.sin( dLng / 2 ) * Math.sin( dLng / 2 );

		return 6371 * 2 * Math.atan2( Math.sqrt( a ), Math.sqrt( 1 - a ) );
	}

	// Bikes this browser opened before, most recent first (written by
	// citykleta-view-stats.js on each bike page). Stays on the device.
	function visitedIds() {
		try {
			var list = JSON.parse( localStorage.getItem( 'citykleta_recent_bikes' ) || '[]' );

			return Array.isArray( list ) ? list : [];
		} catch ( e ) {
			return [];
		}
	}

	// "For you" rows: the server prints every bike that has coordinates and the
	// row stays hidden until the browser has picked from them — first the bikes
	// the visitor already opened (a third of the row at most, so it never turns
	// into just a history), then the nearest ones within "Maximum Distance".
	// Position and history are only read here and never sent anywhere. Either
	// may be missing (location denied, nothing visited yet); with neither, or
	// with nothing close enough, the whole widget stays hidden.
	function initNearby( section ) {
		if ( section.dataset.citykletaNearbyReady ) {
			return;
		}

		var track = section.querySelector( '.citykleta-bike-row__track' );

		if ( ! track ) {
			return;
		}

		section.dataset.citykletaNearbyReady = '1';

		var limit = parseInt( section.dataset.citykletaNearby, 10 ) || 12;
		var radius = parseFloat( section.dataset.citykletaRadius ) || 50;

		function arrange( position ) {
			var items = Array.prototype.slice.call( track.querySelectorAll( '.citykleta-bike-row__item' ) );
			var visited = visitedIds();
			var picked = [];

			// 1. Visited bikes, in the order the visitor opened them.
			visited.forEach( function ( id ) {
				if ( picked.length >= Math.ceil( limit / 3 ) ) {
					return;
				}

				items.some( function ( item ) {
					if ( parseInt( item.dataset.id, 10 ) === id ) {
						picked.push( item );
						return true;
					}

					return false;
				} );
			} );

			// 2. The nearest of the rest.
			if ( position ) {
				items.forEach( function ( item ) {
					var lat = parseFloat( item.dataset.lat );
					var lng = parseFloat( item.dataset.lng );

					item._citykletaDistance = isNaN( lat ) || isNaN( lng ) ?
						Infinity :
						distanceKm( position.coords.latitude, position.coords.longitude, lat, lng );
				} );

				items
					.filter( function ( item ) {
						return picked.indexOf( item ) === -1 && item._citykletaDistance <= radius;
					} )
					.sort( function ( a, b ) {
						return a._citykletaDistance - b._citykletaDistance;
					} )
					.forEach( function ( item ) {
						picked.push( item );
					} );
			}

			picked = picked.slice( 0, limit );

			// Nothing to suggest: the widget stays hidden rather than showing an
			// empty row.
			if ( ! picked.length ) {
				return;
			}

			items.forEach( function ( item ) {
				item.hidden = true;
			} );

			picked.forEach( function ( item ) {
				item.hidden = false;
				track.appendChild( item );
			} );

			section.hidden = false;
			track.scrollLeft = 0;
			track.dispatchEvent( new Event( 'scroll' ) );
		}

		if ( ! navigator.geolocation ) {
			arrange( null );
			return;
		}

		navigator.geolocation.getCurrentPosition( arrange, function () {
			arrange( null );
		}, { timeout: 8000, maximumAge: 300000 } );
	}

	function initAll( root ) {
		var scope = root || document;

		scope.querySelectorAll( '.citykleta-bike-row__viewport' ).forEach( initRow );
		scope.querySelectorAll( '.citykleta-bike-row[data-citykleta-nearby]' ).forEach( initNearby );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			initAll();
		} );
	} else {
		initAll();
	}

	window.addEventListener( 'elementor/frontend/init', function () {
		window.elementorFrontend.hooks.addAction( 'frontend/element_ready/citykleta_bike_rows.default', function ( $scope ) {
			initAll( $scope[ 0 ] );
		} );
	} );
}() );
