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

	// "Nearby" rows: the server prints every bike that has coordinates and the
	// row stays hidden until the visitor shares a position — the position is
	// only used here, in the browser, and never sent anywhere. Denied or
	// unavailable: the row simply doesn't appear, rather than claiming
	// "near you" over an arbitrary order.
	function initNearby( section ) {
		if ( section.dataset.citykletaNearbyReady || ! navigator.geolocation ) {
			return;
		}

		var track = section.querySelector( '.citykleta-bike-row__track' );

		if ( ! track ) {
			return;
		}

		section.dataset.citykletaNearbyReady = '1';

		var limit = parseInt( section.dataset.citykletaNearby, 10 ) || 12;

		navigator.geolocation.getCurrentPosition( function ( pos ) {
			var items = Array.prototype.slice.call( track.querySelectorAll( '.citykleta-bike-row__item' ) );

			items.forEach( function ( item ) {
				var lat = parseFloat( item.dataset.lat );
				var lng = parseFloat( item.dataset.lng );

				item._citykletaDistance = isNaN( lat ) || isNaN( lng ) ?
					Infinity :
					distanceKm( pos.coords.latitude, pos.coords.longitude, lat, lng );
			} );

			items.sort( function ( a, b ) {
				return a._citykletaDistance - b._citykletaDistance;
			} );

			items.forEach( function ( item, i ) {
				track.appendChild( item );
				item.hidden = i >= limit || Infinity === item._citykletaDistance;
			} );

			section.hidden = false;
			track.scrollLeft = 0;
			track.dispatchEvent( new Event( 'scroll' ) );
		}, function () {}, { timeout: 8000, maximumAge: 300000 } );
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
