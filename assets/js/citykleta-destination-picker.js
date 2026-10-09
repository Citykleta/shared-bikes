/**
 * Geofence picker on a destination's edit screen.
 *
 * Click the map or drag the pin to set the center; the latitude/longitude
 * inputs of the same box are filled in, and the circle follows the radius
 * input. Typing in any of the three inputs moves the map too, so the inputs
 * stay the single source of truth for what gets saved.
 */
( function () {
	'use strict';

	var HAVANA = [ 23.1136, -82.3666 ];

	function init( el ) {
		if ( 'undefined' === typeof window.L || el.dataset.citykletaInit ) {
			return;
		}
		el.dataset.citykletaInit = '1';

		var options = {};
		try {
			options = JSON.parse( el.dataset.options || '{}' );
		} catch ( e ) {
			options = {};
		}

		var latInput    = document.getElementById( 'citykleta_destination_lat' );
		var lngInput    = document.getElementById( 'citykleta_destination_lng' );
		var radiusInput = document.getElementById( 'citykleta_destination_radius_km' );
		var countEl     = document.querySelector( '.citykleta-destination-picker__count' );

		if ( ! latInput || ! lngInput || ! radiusInput ) {
			return;
		}

		var map = window.L.map( el );
		window.L.tileLayer( options.tileUrl || 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
			attribution: options.attribution || '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
			maxZoom: 19,
		} ).addTo( map );

		var bikes = ( options.bikes || [] ).map( function ( point ) {
			return window.L.circleMarker( point, {
				radius: 4,
				weight: 1,
				color: '#151b1d',
				fillOpacity: 0.9,
			} ).addTo( map );
		} );

		var marker = null;
		var circle = null;

		function readCenter() {
			var lat = parseFloat( latInput.value );
			var lng = parseFloat( lngInput.value );
			// 0,0 is what an empty field saves as; nobody means the Gulf of Guinea.
			if ( isNaN( lat ) || isNaN( lng ) || ( 0 === lat && 0 === lng ) ) {
				return null;
			}
			return window.L.latLng( lat, lng );
		}

		function readRadiusMeters() {
			var km = parseFloat( radiusInput.value );
			return isNaN( km ) || km < 0 ? 0 : km * 1000;
		}

		// Recolors the bike dots and reports how many fall inside — the same
		// distance check Citykleta_Destination_Matcher runs on every sync.
		function refreshBikes( center, meters ) {
			var inside = 0;
			bikes.forEach( function ( dot ) {
				var hit = center && meters > 0 && center.distanceTo( dot.getLatLng() ) <= meters;
				if ( hit ) {
					inside++;
				}
				dot.setStyle( { fillColor: hit ? '#fed94c' : '#b5b0a5' } );
			} );

			if ( countEl ) {
				countEl.textContent = center && meters > 0
					? inside + ' of ' + bikes.length + ' bikes inside.'
					: '';
			}
		}

		function render( fit ) {
			var center = readCenter();
			var meters = readRadiusMeters();

			if ( ! center ) {
				if ( marker ) {
					map.removeLayer( marker );
					marker = null;
				}
				if ( circle ) {
					map.removeLayer( circle );
					circle = null;
				}
				refreshBikes( null, 0 );
				return;
			}

			if ( ! marker ) {
				// A divIcon, not Leaflet's default pin: that one is a PNG under
				// assets/css/images/, which the vendor download doesn't include.
				marker = window.L.marker( center, {
					draggable: true,
					icon: window.L.divIcon( {
						className: '',
						html: '<span style="display:block;width:22px;height:22px;border-radius:50%;background:#e0960a;border:3px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.45);cursor:move;"></span>',
						iconSize: [ 22, 22 ],
						iconAnchor: [ 11, 11 ],
					} ),
				} ).addTo( map );
				marker.on( 'drag', function () {
					setCenter( marker.getLatLng(), false );
				} );
			} else {
				marker.setLatLng( center );
			}

			if ( meters > 0 ) {
				if ( ! circle ) {
					circle = window.L.circle( center, {
						radius: meters,
						color: '#e0960a',
						weight: 2,
						fillColor: '#fed94c',
						fillOpacity: 0.15,
						interactive: false,
					} ).addTo( map );
				} else {
					circle.setLatLng( center ).setRadius( meters );
				}
			} else if ( circle ) {
				map.removeLayer( circle );
				circle = null;
			}

			refreshBikes( center, meters );

			if ( fit ) {
				if ( meters > 0 ) {
					// Not circle.getBounds(): that one projects through the map,
					// which has no view yet on the first render and throws.
					map.fitBounds( center.toBounds( meters * 2 ), { padding: [ 20, 20 ] } );
				} else {
					map.setView( center, 15 );
				}
			}
		}

		function setCenter( latlng, moveMarker ) {
			// Six decimals ≈ 10 cm: more than a geofence needs, short enough to read.
			latInput.value = latlng.lat.toFixed( 6 );
			lngInput.value = latlng.lng.toFixed( 6 );

			if ( moveMarker || ! marker ) {
				render( false );
				return;
			}

			// Mid-drag: the marker is already there, only the rest follows.
			if ( circle ) {
				circle.setLatLng( latlng );
			}
			refreshBikes( latlng, readRadiusMeters() );
		}

		map.on( 'click', function ( event ) {
			setCenter( event.latlng, true );
		} );

		[ latInput, lngInput, radiusInput ].forEach( function ( input ) {
			input.addEventListener( 'input', function () {
				render( false );
			} );
			input.addEventListener( 'change', function () {
				render( input !== radiusInput );
			} );
		} );

		// Initial view: the saved geofence, else every bike, else Havana.
		function initialView() {
			if ( readCenter() ) {
				render( true );
			} else if ( bikes.length ) {
				map.fitBounds( window.L.featureGroup( bikes ).getBounds(), { padding: [ 20, 20 ] } );
				render( false );
			} else {
				map.setView( HAVANA, 13 );
				render( false );
			}
		}
		initialView();

		// Leaflet measures its container once. If that happened before the box
		// had its final size (it loaded collapsed, the layout was still
		// settling), the fit was computed for the wrong size — maxed-out zoom
		// and half the tiles missing. So remeasure on every size change, and
		// redo the fit too until the user has moved the map themselves.
		var touched = false;
		[ 'mousedown', 'wheel', 'touchstart', 'keydown' ].forEach( function ( type ) {
			el.addEventListener( type, function () {
				touched = true;
			}, { passive: true } );
		} );

		if ( 'ResizeObserver' in window ) {
			new window.ResizeObserver( function () {
				if ( ! el.offsetWidth || ! el.offsetHeight ) {
					return;
				}
				map.invalidateSize();
				if ( ! touched ) {
					initialView();
				}
			} ).observe( el );
		}
	}

	function initAll() {
		var maps = document.querySelectorAll( '.citykleta-destination-picker' );
		for ( var i = 0; i < maps.length; i++ ) {
			init( maps[ i ] );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', initAll );
	} else {
		initAll();
	}
} )();
