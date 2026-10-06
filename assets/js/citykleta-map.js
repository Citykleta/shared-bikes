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

	function bikeIcon( bike, options, hidePrice ) {
		// Settings → Maps → Marker design → "Icon Size" — the only thing
		// about the marker that's still configurable; the circle + glyph
		// itself is fixed (see assets/css/citykleta-map.css).
		var size = options.iconSize || 30;
		var state = false === bike.available ? 'unavailable' : 'available';

		// Small price tag hanging off the top-right of the circle, tail
		// pointing back down-left at it (styled in citykleta-map.css). Only
		// shown when the bike carries a price; the circle itself is untouched.
		var priceBubble = bike.price_tag && ! hidePrice
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
		// Square card: the photo on top and, under it, only the title (brand +
		// model), type and price. The whole card is the link (it replaces the
		// old "Ver bici" text link).
		var tag = bike.url ? 'a' : 'div';
		var html = '<' + tag + ' class="citykleta-map__popup"' + ( bike.url ? ' href="' + escapeHtml( bike.url ) + '"' : '' ) + '>';

		html += '<span class="citykleta-map__popup-photo">';
		if ( bike.image ) {
			html += '<img class="citykleta-map__popup-image" src="' + escapeHtml( bike.image ) + '" alt="" />';
		}
		html += '</span>';

		html += '<span class="citykleta-map__popup-body">';
		html += '<strong class="citykleta-map__popup-title">' + escapeHtml( bike.title ) + '</strong>';

		if ( bike.type ) {
			html += '<span class="citykleta-map__popup-line">' + escapeHtml( bike.type ) + '</span>';
		}

		if ( bike.price ) {
			html += '<span class="citykleta-map__popup-price">' + escapeHtml( bike.price ) + '</span>';
		}

		html += '</span></' + tag + '>';

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

	// Toggle button (top-right, next to Leaflet's own +/- control which sits
	// top-left) that locks/unlocks every way of zooming the map at once — the
	// +/- control, mouse wheel, double-click, and touch pinch — so a visitor
	// scrolling or swiping past the map on a long page doesn't get stuck
	// zooming it by accident. Panning is left alone either way, this is only
	// about zoom. Starts unlocked, respecting whatever each handler's own
	// starting state already was (e.g. scrollWheelZoom, off by default here)
	// instead of force-enabling everything on unlock.
	function addZoomLockControl( map ) {
		var wasEnabled = {
			scrollWheelZoom: map.scrollWheelZoom.enabled(),
			doubleClickZoom: map.doubleClickZoom.enabled(),
			touchZoom: map.touchZoom.enabled(),
			boxZoom: map.boxZoom.enabled(),
		};
		var locked = false;

		var ZoomLockControl = L.Control.extend( {
			options: { position: 'topright' },
			onAdd: function () {
				var container = L.DomUtil.create( 'div', 'leaflet-bar citykleta-map__lock-control' );
				var link = L.DomUtil.create( 'a', '', container );

				link.href = '#';
				link.setAttribute( 'role', 'button' );

				L.DomEvent.disableClickPropagation( container );
				L.DomEvent.disableScrollPropagation( container );

				// Leaflet already added map.zoomControl to the map when it was
				// built (zoomControl defaults to true) — only touch it on an
				// actual toggle, never on this initial render, or .addTo()
				// would insert a second +/- control next to the first one.
				function toggle() {
					locked = ! locked;

					var method = locked ? 'disable' : 'enable';

					if ( wasEnabled.scrollWheelZoom ) {
						map.scrollWheelZoom[ method ]();
					}
					if ( wasEnabled.doubleClickZoom ) {
						map.doubleClickZoom[ method ]();
					}
					if ( wasEnabled.touchZoom ) {
						map.touchZoom[ method ]();
					}
					if ( wasEnabled.boxZoom ) {
						map.boxZoom[ method ]();
					}

					if ( map.zoomControl ) {
						if ( locked ) {
							map.zoomControl.remove();
						} else {
							map.zoomControl.addTo( map );
						}
					}

					updateLabel();
				}

				function updateLabel() {
					link.innerHTML = locked ? '&#128274;' : '&#128275;';
					link.title = locked ? 'Desbloquear zoom' : 'Bloquear zoom';
					link.setAttribute( 'aria-label', link.title );
				}

				L.DomEvent.on( link, 'click', function ( e ) {
					L.DomEvent.stop( e );
					toggle();
				} );

				updateLabel();

				return container;
			},
		} );

		new ZoomLockControl().addTo( map );
	}

	// Demo mode: every `interval` seconds pick a random bike (never the same
	// one twice in a row), center the map on it and open its card for
	// `duration` seconds. The timers die with the map (init() calls remove()).
	function shuffle( list ) {
		var copy = list.slice();

		for ( var i = copy.length - 1; i > 0; i-- ) {
			var j = Math.floor( Math.random() * ( i + 1 ) );
			var tmp = copy[ i ];
			copy[ i ] = copy[ j ];
			copy[ j ] = tmp;
		}

		return copy;
	}

	// The demo's fixed tour: `size` bikes chosen once at load, as varied by type
	// as the catalogue allows — one from each type in turn (types in random
	// order, a random bike within each) until the pool is full. Touring a
	// small set keeps revisiting the same few map areas, so their tiles are
	// already cached after the first lap — which matters on a slow connection.
	function pickPool( markers, size ) {
		var count = Math.max( 2, Number( size ) || 8 );

		if ( markers.length <= count ) {
			return markers.slice();
		}

		var groups = {};

		shuffle( markers ).forEach( function ( marker ) {
			var type = marker._citykletaBike.type || '';

			( groups[ type ] = groups[ type ] || [] ).push( marker );
		} );

		var lists = shuffle( Object.keys( groups ) ).map( function ( type ) {
			return groups[ type ];
		} );
		var pool = [];

		while ( pool.length < count ) {
			for ( var i = 0; i < lists.length && pool.length < count; i++ ) {
				if ( lists[ i ].length ) {
					pool.push( lists[ i ].shift() );
				}
			}
		}

		return pool;
	}

	// Puts the pool in a loop route: start anywhere, always hop to the nearest
	// bike not visited yet. The tour then walks this loop in order, so it only
	// ever makes `pool size` distinct legs (the last one closing the loop) and
	// the tiles of every leg are cached after a single lap — random hops would
	// make size × (size - 1) different legs — and each leg is short.
	function orderRoute( pool, map ) {
		if ( pool.length < 3 ) {
			return pool;
		}

		var left = pool.slice();
		var route = [ left.shift() ];

		while ( left.length ) {
			var from = route[ route.length - 1 ].getLatLng();
			var best = 0;

			for ( var i = 1; i < left.length; i++ ) {
				if ( map.distance( from, left[ i ].getLatLng() ) < map.distance( from, left[ best ].getLatLng() ) ) {
					best = i;
				}
			}

			route.push( left.splice( best, 1 )[ 0 ] );
		}

		return route;
	}

	// Optional: fetch the tiles around each stop up front (3×3 at the stop's
	// zoom, plus the zoom-out level when used), a few at a time, so the first
	// lap doesn't wait on the network. Costs data on load, hence opt-in.
	function preloadTiles( map, route, zooms ) {
		var layer = map._citykletaTiles;

		if ( ! layer ) {
			return;
		}

		var urls = [];

		route.forEach( function ( marker ) {
			zooms.forEach( function ( z ) {
				var tile = map.project( marker.getLatLng(), z ).divideBy( 256 ).floor();

				for ( var dx = -1; dx <= 1; dx++ ) {
					for ( var dy = -1; dy <= 1; dy++ ) {
						urls.push( layer.getTileUrl( { x: tile.x + dx, y: tile.y + dy, z: z } ) );
					}
				}
			} );
		} );

		var next = 0;

		function worker() {
			if ( next >= urls.length ) {
				return;
			}

			var img = new Image();

			img.onload = img.onerror = worker;
			img.src = urls[ next++ ];
		}

		for ( var w = 0; w < 4; w++ ) {
			worker();
		}
	}

	function startDemo( map, clusterGroup, allMarkers, demo ) {
		// Clicks work on every bike; the automatic tour only visits the pool.
		var markers = orderRoute( pickPool( allMarkers, demo.pool ), map );
		var DEMO_ZOOM = 16;
		var interval = Math.max( 1, Number( demo.interval ) || 6 ) * 1000;
		var shown = null;
		var move = isNaN( Number( demo.move ) ) ? 2 : Math.max( 0, Number( demo.move ) );
		var zoomOut = null !== demo.zoomOut && 'undefined' !== typeof demo.zoomOut && ! isNaN( Number( demo.zoomOut ) ) ? Number( demo.zoomOut ) : null;
		var last = -1;
		var timers = [];

		// Bumped whenever a visitor takes over by clicking a bike. Every
		// callback of a flight in progress checks it, so a cancelled flight
		// can't open its card on top of the one the visitor just chose.
		var run = 0;

		function later( fn, ms ) {
			timers.push( window.setTimeout( fn, ms ) );
		}

		function clearTimers() {
			timers.forEach( window.clearTimeout );
			timers = [];
		}

		// flyTo, but always calls back — a flight to where the map already is
		// fires no moveend, which would stall the chain.
		function fly( latlng, zoom, seconds, done ) {
			var here = map.getCenter();

			if ( map.getZoom() === zoom && map.distance( here, latlng ) < 1 ) {
				done();
				return;
			}

			map.once( 'moveend', done );
			map.flyTo( latlng, zoom, { duration: Math.max( 0.1, seconds ) } );
		}

		// The one place a card opens, for the demo and for a visitor's click
		// alike. A map holds a single popup, so opening one closes the other.
		// It's a map-level popup, not bound to its marker, so it also survives
		// that marker being clustered while we zoom out.
		function openCard( marker ) {
			// The card replaces the little price tag floating at the marker:
			// restore the previous bike's tag, hide this one's.
			if ( shown ) {
				shown.setIcon( shown._citykletaIcon );
			}
			shown = marker;
			marker.setIcon( marker._citykletaIconNoPrice );

			L.popup( {
				autoPan: false,
				closeOnClick: false,
				offset: [ 0, -( marker._citykletaSize / 2 + 3 ) ],
			} )
				.setLatLng( marker.getLatLng() )
				.setContent( popupContent( marker._citykletaBike ) )
				.openOn( map );
		}

		function step() {
			var token = run;
			// Next stop on the loop route (see orderRoute()).
			var index = ( last + 1 ) % markers.length;

			last = index;

			var marker = markers[ index ];
			var latlng = marker.getLatLng();
			var zoom = Math.max( map.getZoom(), DEMO_ZOOM );

			// The previous card is NOT closed here: it stays up while the map
			// travels and goes away only when the next card opens.
			function guard( fn ) {
				return function () {
					if ( token === run ) {
						fn();
					}
				};
			}

			var show = guard( function () {
				// Past clustering the marker is already visible; otherwise this
				// unfolds whatever cluster is hiding it first.
				clusterGroup.zoomToShowLayer( marker, guard( function () {
					map.panTo( latlng, { animate: false } );
					openCard( marker );

					// The stay timer starts once the card is up.
					later( step, interval );
				} ) );
			} );

			if ( move > 0 && null !== zoomOut && zoomOut < zoom ) {
				// Three beats sharing the movement duration: out, across, in.
				fly( map.getCenter(), zoomOut, move * 0.3, guard( function () {
					fly( latlng, zoomOut, move * 0.4, guard( function () {
						fly( latlng, zoom, move * 0.3, show );
					} ) );
				} ) );
			} else if ( move > 0 ) {
				// flyTo's duration is what sets the travel speed.
				fly( latlng, zoom, move, show );
			} else {
				map.setView( latlng, zoom, { animate: false } );
				show();
			}
		}

		// A visitor clicking a bike takes over: whatever the demo was doing is
		// cancelled, that bike's card opens right away (replacing the current
		// one), and the demo carries on from there after its usual stay time.
		allMarkers.forEach( function ( marker ) {
			marker.on( 'click', function () {
				run++;
				clearTimers();
				map.stop();

				openCard( marker );
				map.panTo( marker.getLatLng(), { animate: true } );

				// Carry on from the stop on the route closest to the clicked
				// bike (the bike itself, if it's on the route).
				var here = marker.getLatLng();
				var nearest = 0;

				for ( var i = 1; i < markers.length; i++ ) {
					if ( map.distance( here, markers[ i ].getLatLng() ) < map.distance( here, markers[ nearest ].getLatLng() ) ) {
						nearest = i;
					}
				}
				last = nearest;

				later( step, interval );
			} );
		} );

		map.on( 'unload', clearTimers );

		if ( demo.preload ) {
			preloadTiles( map, markers, null === zoomOut ? [ DEMO_ZOOM ] : [ DEMO_ZOOM, zoomOut ] );
		}

		step();
	}

	function buildMap( container, bikes, options ) {
		// "interactive: false" freezes the map into a static backdrop: no
		// +/- control, no zoom by any route, and no panning by mouse or
		// touch. Markers stay clickable — that's not zoom or pan. Defaults
		// to true so a direct CityKletaMap.init() call, or a caller that
		// doesn't know the option (e.g. Citykleta_Admin_Map), behaves as
		// it always did.
		var interactive = false !== options.interactive;

		var map = L.map( container, {
			// Off by default so scrolling the page past an inline map
			// doesn't get trapped by the map's own zoom.
			scrollWheelZoom: interactive && true === options.scrollWheelZoom,
			zoomControl: interactive,
			dragging: interactive,
			touchZoom: interactive,
			doubleClickZoom: interactive,
			boxZoom: interactive,
			keyboard: interactive,
		} );

		// Kept on the map so the demo can ask it for tile URLs to preload.
		map._citykletaTiles = L.tileLayer( options.tileUrl || 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
			attribution: options.attribution || '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
			maxZoom: 19,
		} ).addTo( map );

		if ( options.zoomLockButton ) {
			addZoomLockControl( map );
		}

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
		var markers = [];
		var demo = options.demo || null;

		for ( var i = 0; i < bikes.length; i++ ) {
			var bike = bikes[ i ];
			var lat = parseFloat( bike.lat );
			var lng = parseFloat( bike.lng );

			if ( isNaN( lat ) || isNaN( lng ) ) {
				continue;
			}

			var marker = L.marker( [ lat, lng ], { icon: bikeIcon( bike, options ) } );
			marker._citykletaBike = bike;
			marker._citykletaSize = options.iconSize || 30;
			marker._citykletaIcon = marker.options.icon;
			marker._citykletaIconNoPrice = bikeIcon( bike, options, true );

			if ( ! demo ) {
				marker.bindPopup( popupContent( bike ) );
				// An open card replaces the floating price tag.
				marker.on( 'popupopen', function ( e ) {
					e.target.setIcon( e.target._citykletaIconNoPrice );
				} );
				marker.on( 'popupclose', function ( e ) {
					e.target.setIcon( e.target._citykletaIcon );
				} );
			}
			clusterGroup.addLayer( marker );
			markers.push( marker );
			bounds.push( [ lat, lng ] );
		}

		map.addLayer( clusterGroup );

		if ( bounds.length ) {
			map.fitBounds( bounds, { maxZoom: 15, padding: [ 30, 30 ] } );
		} else {
			map.setView( options.fallbackCenter || DEFAULT_CENTER, options.zoom || DEFAULT_ZOOM );
		}

		if ( demo && markers.length ) {
			startDemo( map, clusterGroup, markers, demo );
		} else if ( false !== options.centerOnUser ) {
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
	// Kept as a fast path, but NOT relied on alone — some Elementor editor
	// builds (confirmed on 4.2.0 with the AI "Angie" panel) never fire this
	// hook for this widget, so the map would stay stuck as a blank div with
	// the right data-bikes/data-options but no Leaflet instance on it.
	if ( window.elementorFrontend && window.elementorFrontend.hooks ) {
		window.elementorFrontend.hooks.addAction( 'frontend/element_ready/citykleta_map.default', initAll );
	}

	// Real fallback: watch the DOM directly for map containers showing up,
	// regardless of which Elementor internals put them there. initAll()'s
	// own signature check keeps this cheap — a mutation that doesn't touch
	// any .citykleta-map payload is a no-op.
	if ( 'undefined' !== typeof MutationObserver ) {
		var pendingScan = null;
		var domObserver = new MutationObserver( function () {
			if ( pendingScan ) {
				window.clearTimeout( pendingScan );
			}
			pendingScan = window.setTimeout( initAll, 150 );
		} );

		domObserver.observe( document.body, { childList: true, subtree: true } );
	}

	window.CityKletaMap = { init: init, initAll: initAll };
} )();
