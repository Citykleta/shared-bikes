/**
 * "Scroll Story" widget (Citykleta_Story_Widget): drives every scene from the
 * card's own scroll.
 *
 * Scene 0 is the cover, 1…n the slides, the last one the closing. x = progress
 * through the story in scenes (0 → N).
 *   - Cover → slide 1: the cover photo zooms in on the person (top edge fixed)
 *     while its window closes to the left half; it then IS slide 1's image.
 *   - Slides alternate sides; each image slides in, and out, through its own side.
 *   - The closing photo takes the whole card.
 *   - Every text comes up from below the card and leaves over the top.
 *
 * Positions are recomputed on each scroll frame, never animated by transitions,
 * so the scroll is the only clock. With prefers-reduced-motion scenes just switch.
 */
( function () {
	'use strict';

	var reduce = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	function clamp( v, a, b ) {
		return Math.min( undefined === b ? 1 : b, Math.max( a || 0, v ) );
	}

	function seg( t, a, b ) {
		return clamp( ( t - a ) / ( b - a ) );
	}

	function lerp( a, b, t ) {
		return a + ( b - a ) * t;
	}

	function ease( t ) {
		return t < 0.5 ? 2 * t * t : 1 - Math.pow( -2 * t + 2, 2 ) / 2;
	}

	function initStory( root ) {
		if ( root._citykletaStory ) {
			root._citykletaStory.stop();
		}

		var scroller = root.querySelector( '.citykleta-story__scroller' );
		var copy = root.querySelector( '.citykleta-story__copy' );
		var finalEl = root.querySelector( '.citykleta-story__final' );
		var hint = root.querySelector( '.citykleta-story__hint' );
		var dotsEl = root.querySelector( '.citykleta-story__dots' );
		if ( ! scroller || ! copy || ! finalEl ) {
			return;
		}

		// Shots and steps, indexed by scene.
		var shots = [];
		root.querySelectorAll( '.citykleta-story__shot' ).forEach( function ( el ) {
			shots[ +el.getAttribute( 'data-scene' ) ] = el;
		} );
		var steps = [];
		root.querySelectorAll( '.citykleta-story__step' ).forEach( function ( el ) {
			steps[ +el.getAttribute( 'data-scene' ) ] = el;
		} );

		var N = steps.length;
		var hasSlides = N > 2;
		var cover = shots[ 0 ] ? shots[ 0 ].querySelector( 'img' ) : null;
		var person = ( root.getAttribute( 'data-person' ) || '0.44,0.72' ).split( ',' ).map( Number );
		var lastActive = -1;
		var ticking = false;
		var dots = [];

		root.classList.add( 'is-ready' );

		if ( dotsEl ) {
			dotsEl.innerHTML = '';
			steps.forEach( function ( step, i ) {
				var dot = document.createElement( 'button' );
				dot.type = 'button';
				dot.setAttribute( 'aria-label', 'Scene ' + ( i + 1 ) );
				dot.addEventListener( 'click', function () {
					var max = scroller.scrollHeight - scroller.clientHeight;
					scroller.scrollTo( { top: max * ( 0 === i ? 0 : ( i + 0.6 ) / N ), behavior: reduce ? 'auto' : 'smooth' } );
				} );
				dotsEl.appendChild( dot );
				dots.push( dot );
			} );
		}

		function show( el, on ) {
			el.style.opacity = on ? 1 : 0;
			el.style.visibility = on ? 'visible' : 'hidden';
		}

		function render() {
			ticking = false;
			var max = scroller.scrollHeight - scroller.clientHeight;
			var p = max > 0 ? clamp( scroller.scrollTop / max ) : 0;
			var x = p * N;
			var active = Math.min( N - 1, Math.floor( x + 0.08 ) );
			var W = root.clientWidth;
			var H = root.clientHeight;

			/* Cover → slide 1. Shown whole (top edge on the card's top, centred);
			   scrolling, it zooms in on the person with its top edge fixed while
			   its window closes to the left half. */
			if ( shots[ 0 ] ) {
				var m = 0;
				var half = W / 2;
				if ( cover && cover.naturalWidth ) {
					var iw = cover.naturalWidth;
					var ih = cover.naturalHeight;
					var s0 = Math.min( W / iw, H / ih );
					var x0 = ( W - iw * s0 ) / 2;
					var s1 = Math.max( half / ( ( person[ 1 ] - person[ 0 ] ) * iw ), H / ih );
					var x1 = half / 2 - ( person[ 0 ] + person[ 1 ] ) / 2 * iw * s1;
					x1 = Math.min( 0, Math.max( half - iw * s1, x1 ) ); // no gap inside the half
					if ( hasSlides ) {
						m = reduce ? ( x >= 1 ? 1 : 0 ) : ease( seg( x, 0.1, 1 ) );
					}
					cover.style.transform = 'translate(' + lerp( x0, x1, m ) + 'px, 0) scale(' + lerp( s0, s1, m ) + ')';
				}
				// Leaves through the left when slide 2 (or the closing) comes in.
				var leave = reduce ? ( active > 1 ? 1 : 0 ) : ease( seg( x - 1, 0.8, 1.1 ) );
				shots[ 0 ].style.clipPath = 'inset(0 ' + lerp( 0, W - half, m ) + 'px 0 0)';
				shots[ 0 ].style.transform = 'translateX(' + -leave * half + 'px)';
				show( shots[ 0 ], leave < 1 );
			}

			// Slides from the second on, and the closing: in, and out, through their own side.
			for ( var i = 2; i < N; i++ ) {
				var shot = shots[ i ];
				if ( ! shot ) {
					continue;
				}
				var u = x - i;
				var last = N - 1 === i;
				var enter = reduce ? ( i <= active ? 1 : 0 ) : ease( seg( u, -0.2, 0.1 ) );
				var out = last ? 0 : ( reduce ? ( i < active ? 1 : 0 ) : ease( seg( u, 0.8, 1.1 ) ) );
				var on = enter > 0 && out < 1;
				show( shot, on );
				if ( on ) {
					var side = shot.classList.contains( 'is-left' ) ? -1 : 1;
					shot.style.transform = 'translateX(' + ( 1 - enter + out ) * 100 * side + '%)';
					var pic = shot.firstElementChild;
					if ( pic && last ) {
						// A slow push-in on the closing photo.
						pic.style.transform = 'scale(' + lerp( 1.12, 1, reduce ? 1 : clamp( ( u + 0.2 ) / 0.8 ) ) + ')';
					}
				}
			}

			// Texts: in from below the card, out over the top.
			var cardBox = root.getBoundingClientRect();
			var copyBox = copy.getBoundingClientRect();
			var top = copyBox.top - cardBox.top;
			var below = cardBox.height - top + 8;
			var above = -( top + copyBox.height + 8 );
			var finalBelow = cardBox.height - ( finalEl.getBoundingClientRect().top - cardBox.top ) + 8;

			steps.forEach( function ( el, n ) {
				if ( reduce ) {
					show( el, n === active );
					el.style.transform = 'none';
					return;
				}
				var t = x - n;
				var y;
				if ( N - 1 === n ) {
					y = lerp( finalBelow, 0, ease( seg( t, -0.15, 0.4 ) ) );
					show( el, y < finalBelow );
					el.style.transform = 'translateY(' + y + 'px)';
					return;
				}
				if ( 0 === n ) {
					y = lerp( 0, above, ease( seg( t, 0.1, 0.45 ) ) );
				} else {
					y = lerp( below, above, seg( t, -0.15, 1.05 ) );
				}
				// Slides with their image on the left keep their text in the right half.
				var xo = n > 0 && n % 2 ? cardBox.width / 2 : 0;
				show( el, y < below && y > above );
				el.style.transform = 'translate(' + xo + 'px, ' + y + 'px)';
			} );

			if ( hint ) {
				hint.style.opacity = x < 0.05 ? 1 : 0;
			}

			if ( active !== lastActive ) {
				steps.forEach( function ( el, n ) {
					el.classList.toggle( 'is-active', n === active );
				} );
				dots.forEach( function ( el, n ) {
					el.classList.toggle( 'is-active', n === active );
				} );
				lastActive = active;
			}
		}

		function request() {
			if ( ! ticking ) {
				ticking = true;
				window.requestAnimationFrame( render );
			}
		}

		scroller.addEventListener( 'scroll', request, { passive: true } );
		window.addEventListener( 'resize', request );
		if ( cover ) {
			cover.addEventListener( 'load', request );
		}
		// Inside a tab that starts hidden the card measures 0 × 0; redraw when it
		// is shown or changes size (no window resize happens then).
		var observer = 'ResizeObserver' in window ? new ResizeObserver( request ) : null;
		if ( observer ) {
			observer.observe( root );
		}
		render();

		root._citykletaStory = {
			stop: function () {
				scroller.removeEventListener( 'scroll', request );
				window.removeEventListener( 'resize', request );
				if ( cover ) {
					cover.removeEventListener( 'load', request );
				}
				if ( observer ) {
					observer.disconnect();
				}
			},
		};
	}

	function initAll( scope ) {
		( scope || document ).querySelectorAll( '.citykleta-story' ).forEach( initStory );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			initAll();
		} );
	} else {
		initAll();
	}

	window.addEventListener( 'elementor/frontend/init', function () {
		window.elementorFrontend.hooks.addAction( 'frontend/element_ready/citykleta_story.default', function ( $scope ) {
			initAll( $scope[ 0 ] );
		} );
	} );
}() );
