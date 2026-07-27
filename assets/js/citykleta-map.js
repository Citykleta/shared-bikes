/**
 * Reusable Leaflet map for showing bikes grouped in marker clusters.
 *
 * Exposes window.CityKletaMap.init( container, bikes, options ). Safe to
 * call more than once on the same container — Elementor's editor redraws
 * widgets by AJAX after every setting change, and Leaflet throws
 * "Map container is already initialized" if L.map() runs twice on the same
 * node without removing the previous instance first.
 */
( function () {
	'use strict';

	// Parque Central, Old Havana — used as the map center when there are no
	// bikes to fit bounds to and the widget didn't configure its own
	// default location (options.fallbackCenter).
	var DEFAULT_CENTER = [ 23.1367, -82.3589 ];
	var DEFAULT_ZOOM = 13;
	var USER_ZOOM = 14;

	function resolveContainer( container ) {
		return 'string' === typeof container ? document.querySelector( container ) : container;
	}

	function escapeHtml( value ) {
		var div = document.createElement( 'div' );
		div.textContent = null === value || 'undefined' === typeof value ? '' : String( value );
		return div.innerHTML;
	}

	function bikeIcon( bike, options ) {
		// Settings → Maps → Marker design → "Icon Size" — the only thing
		// about the marker that's still configurable; the circle + glyph
		// itself is fixed (see assets/css/citykleta-map.css).
		var size = options.iconSize || 30;
		var state = false === bike.available ? 'unavailable' : 'available';

		// Small price tag hanging off the top-right of the circle, tail
		// pointing back down-left at it (styled in citykleta-map.css). Only
		// shown when the bike carries a price; the circle itself is untouched.
		var priceBubble = bike.price_tag
			? '<span class="citykleta-map__price-bubble">' + escapeHtml( bike.price_tag ) + '</span>'
			: '';

		// Same two-layer ring shape as the cluster bubbles (citykleta-map.css):
		// a translucent outer circle plus a smaller solid inner one, so a lone
		// bike reads consistently with a cluster of bikes at the same spot.
		return L.divIcon( {
			className: 'citykleta-map__bike-icon',
			html:
				'<span class="citykleta-map__bike-icon-circle citykleta-map__bike-icon-circle--' + state + '">' +
					'<span class="citykleta-map__bike-icon-inner citykleta-map__bike-icon-inner--' + state + '">' +
						'<span class="citykleta-map__bike-icon-glyph"></span>' +
					'</span>' +
				'</span>' + priceBubble,
			iconSize: [ size, size ],
			iconAnchor: [ size / 2, size / 2 ],
			popupAnchor: [ 0, -( size / 2 + 3 ) ],
		} );
	}

	function userIcon() {
		return L.divIcon( {
			className: 'citykleta-map__user-icon',
			html: '<span class="citykleta-map__user-dot"></span>',
			iconSize: [ 18, 18 ],
			iconAnchor: [ 9, 9 ],
		} );
	}

	function popupContent( bike ) {
		var unavailable = false === bike.available;
		var html = '<div class="citykleta-map__popup">';

		if ( bike.image ) {
			html += '<img class="citykleta-map__popup-image" src="' + escapeHtml( bike.image ) + '" alt="" />';
		}

		html += '<div class="citykleta-map__popup-body">';
		html += '<strong class="citykleta-map__popup-title">' + escapeHtml( bike.title ) + '</strong>';

		if ( bike.location ) {
			html += '<span class="citykleta-map__popup-location">' + escapeHtml( bike.location ) + '</span>';
		}

		if ( bike.price ) {
			html += '<span class="citykleta-map__popup-price">' + escapeHtml( bike.price ) + '</span>';
		}

		// Visitor-facing copy stays in Spanish (Citykleta's actual audience),
		// same as the "Disponible"/"No disponible" badges elsewhere in the
		// plugin — only code/comments/admin-panel labels are English.
		html +=
			'<span class="citykleta-map__popup-status citykleta-map__popup-status--' + ( unavailable ? 'unavailable' : 'available' ) + '">' +
			( unavailable ? 'No disponible' : 'Disponible' ) +
			'</span>';

		if ( bike.url ) {
			html += '<a class="citykleta-map__popup-link" href="' + escapeHtml( bike.url ) + '">Ver bici</a>';
		}

		html += '</div></div>';

		return html;
	}

	function addUserMarker( map, options ) {
		// Denied, unavailable, or timed out — recenter to the widget's own
		// Default Location if it configured one, otherwise leave whatever
		// view fitBounds()/DEFAULT_CENTER already set, no error surfaced.
		function useFallback() {
			if ( options.fallbackCenter ) {
				map.setView( options.fallbackCenter, options.zoom || DEFAULT_ZOOM );
			}
		}

		if ( ! navigator.geolocation ) {
			useFallback();
			return;
		}

		navigator.geolocation.getCurrentPosition(
			function ( position ) {
				var userLatLng = [ position.coords.latitude, position.coords.longitude ];
				L.marker( userLatLng, { icon: userIcon(), zIndexOffset: 1000, keyboard: false } ).addTo( map );
				map.setView( userLatLng, USER_ZOOM );
			},
			useFallback,
			{ timeout: 8000 }
		);
	}

	function buildMap( container, bikes, options ) {
		var map = L.map( container, {
			// Off by default so scrolling the page past an inline map
			// doesn't get trapped by the map's own zoom.
			scrollWheelZoom: true === options.scrollWheelZoom,
		} );

		L.tileLayer( options.tileUrl || 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
			attribution: options.attribution || '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
			maxZoom: 19,
		} ).addTo( map );

		// Settings → Maps → Cluster Behavior: maxClusterRadius (px, how close
		// bikes need to be to group) and disableClusteringAtZoom (zoom level
		// past which every bike always shows on its own). Both fall back to
		// Leaflet.markercluster's own defaults (80, never disabled) when the
		// caller didn't pass them — e.g. a direct CityKletaMap.init() call.
		var clusterGroup = L.markerClusterGroup( {
			maxClusterRadius: options.maxClusterRadius || 80,
			disableClusteringAtZoom: options.disableClusteringAtZoom || null,
		} );
		var bounds = [];

		for ( var i = 0; i < bikes.length; i++ ) {
			var bike = bikes[ i ];
			var lat = parseFloat( bike.lat );
			var lng = parseFloat( bike.lng );

			if ( isNaN( lat ) || isNaN( lng ) ) {
				continue;
			}

			var marker = L.marker( [ lat, lng ], { icon: bikeIcon( bike, options ) } );
			marker.bindPopup( popupContent( bike ) );
			clusterGroup.addLayer( marker );
			bounds.push( [ lat, lng ] );
		}

		map.addLayer( clusterGroup );

		if ( bounds.length ) {
			map.fitBounds( bounds, { maxZoom: 15, padding: [ 30, 30 ] } );
		} else {
			map.setView( options.fallbackCenter || DEFAULT_CENTER, options.zoom || DEFAULT_ZOOM );
		}

		if ( false !== options.centerOnUser ) {
			addUserMarker( map, options );
		}

		// Elementor's editor redraws widgets inside its own iframe via AJAX,
		// often while the container is still mid-transition (drop animation,
		// panel not yet laid out). Leaflet sizes itself from the container's
		// dimensions *at this exact moment* and never rechecks on its own —
		// if the container was 0×0 (or wrong) right now, the map stays blank
		// forever even once it settles into its real size. A ResizeObserver
		// tells Leaflet to recalculate every time the container's size
		// actually changes, which also covers it appearing later inside a
		// closed accordion/tab.
		if ( 'undefined' !== typeof ResizeObserver ) {
			var observer = new ResizeObserver( function () {
				map.invalidateSize();
			} );
			observer.observe( container );
			map._citykletaResizeObserver = observer;
		}

		return map;
	}

	function init( container, bikes, options ) {
		var el = resolveContainer( container );

		if ( ! el || 'undefined' === typeof window.L || ! window.L.markerClusterGroup ) {
			return null;
		}

		if ( el._citykletaMap ) {
			if ( el._citykletaMap._citykletaResizeObserver ) {
				el._citykletaMap._citykletaResizeObserver.disconnect();
			}
			el._citykletaMap.remove();
			delete el._citykletaMap;
		}

		var map = buildMap( el, bikes || [], options || {} );
		el._citykletaMap = map;

		return map;
	}

	/**
	 * Scans the page for map containers rendered by the Elementor widget
	 * (a .citykleta-map element carrying its bikes/options as JSON
	 * attributes) and initializes each one that hasn't been initialized yet
	 * with its current data — skips containers whose payload hasn't
	 * changed, so re-running this on every "frontend/element_ready" event
	 * doesn't reset unrelated map instances on the same page.
	 */
	function initAll() {
		var containers = document.querySelectorAll( '.citykleta-map[data-bikes]' );

		for ( var i = 0; i < containers.length; i++ ) {
			var el = containers[ i ];
			var bikesJson = el.getAttribute( 'data-bikes' ) || '[]';
			var optionsJson = el.getAttribute( 'data-options' ) || '{}';
			var signature = bikesJson + '|' + optionsJson;

			if ( el.dataset.citykletaSignature === signature ) {
				continue;
			}

			var bikes;
			var options;

			try {
				bikes = JSON.parse( bikesJson );
				options = JSON.parse( optionsJson );
			} catch ( e ) {
				continue;
			}

			if ( init( el, bikes, options ) ) {
				el.dataset.citykletaSignature = signature;
			}
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', initAll );
	} else {
		initAll();
	}

	// Elementor's editor redraws widgets by AJAX when a setting changes;
	// re-scan when that happens so the map picks up the new data/options.
	if ( window.elementorFrontend && window.elementorFrontend.hooks ) {
		window.elementorFrontend.hooks.addAction( 'frontend/element_ready/citykleta_map.default', initAll );
	}

	window.CityKletaMap = { init: init, initAll: initAll };
} )();
