/*
 * Drives the photo carousel on a bike's single page. The slide mechanics
 * (touch/drag, transitions, index tracking) are Splide's — this file only
 * mounts it and wires our own arrow/dot buttons to its API, so the carousel
 * keeps its own look (see .citykleta-bike-single__gallery-nav/-dot in
 * single-bike.css) instead of Splide's default theme. Splide's own
 * arrows/pagination stay off (arrows:false, pagination:false in the config
 * below) — we already print our buttons in templates/single-bike.php.
 *
 * A gallery with a single photo has no arrow/dot buttons in the markup at
 * all — nothing to wire up. Splide still mounts (harmless with one slide,
 * and keeps this file free of a separate code path), it just never gets
 * asked to move anywhere.
 *
 * Needs assets/js/splide.min.js — doesn't ship with the plugin (see
 * CLAUDE.md), so this no-ops if it isn't there: the one real photo bikes
 * have today still shows, it just isn't wrapped in a working carousel until
 * the vendor file is downloaded.
 */
( function () {
	'use strict';

	function initGallery( root ) {
		if ( 'undefined' === typeof window.Splide ) {
			return;
		}

		var prevBtn = root.querySelector( '.citykleta-bike-single__gallery-nav--prev' );
		var nextBtn = root.querySelector( '.citykleta-bike-single__gallery-nav--next' );
		var dots = root.querySelectorAll( '.citykleta-bike-single__gallery-dot' );

		var splide = new window.Splide( root, {
			type: 'slide',
			arrows: false,
			pagination: false,
			pauseOnHover: false,
			speed: 400,
		} );

		function update() {
			var index = splide.index;

			for ( var i = 0; i < dots.length; i++ ) {
				dots[ i ].setAttribute( 'aria-current', i === index ? 'true' : 'false' );
			}

			if ( prevBtn ) {
				prevBtn.disabled = 0 === index;
			}

			if ( nextBtn ) {
				nextBtn.disabled = index === splide.length - 1;
			}
		}

		if ( prevBtn ) {
			prevBtn.addEventListener( 'click', function () {
				splide.go( '<' );
			} );
		}

		if ( nextBtn ) {
			nextBtn.addEventListener( 'click', function () {
				splide.go( '>' );
			} );
		}

		Array.prototype.forEach.call( dots, function ( dot, index ) {
			dot.addEventListener( 'click', function () {
				splide.go( index );
			} );
		} );

		splide.on( 'mounted move', update );
		splide.mount();
	}

	function init() {
		var galleries = document.querySelectorAll( '.citykleta-bike-single__gallery.splide' );
		Array.prototype.forEach.call( galleries, initGallery );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
