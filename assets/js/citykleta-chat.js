/**
 * "Group Chat" widget (Citykleta_Chat_Widget): plays the conversation back one
 * message at a time, with a "typing…" bubble before each one from somebody else.
 *
 * The server prints every message visible; this script is what hides them, so
 * without it (or with prefers-reduced-motion) the whole chat simply shows.
 *
 * data-trigger on the root:
 *   viewport — plays while the chat is on screen, pauses when scrolled away.
 *   hover    — plays while the mouse (or keyboard focus) is on it. Falls back
 *              to viewport on touch screens, which have no hover.
 *   none     — nothing to do.
 */
( function () {
	'use strict';

	var reduceMotion = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
	var canHover = window.matchMedia && window.matchMedia( '(hover: hover)' ).matches;

	function initChat( root ) {
		if ( root._citykletaChat ) {
			root._citykletaChat.stop();
		}

		var trigger = root.getAttribute( 'data-trigger' );
		if ( ! trigger || 'none' === trigger || reduceMotion ) {
			return;
		}
		if ( 'hover' === trigger && ! canHover ) {
			trigger = 'viewport';
		}

		// The scrolling element inside the fixed window (see chat.css).
		var body = root.querySelector( '.citykleta-chat__scroll' );
		var items = body ? Array.prototype.slice.call( body.querySelectorAll( '.citykleta-chat__item' ) ) : [];
		if ( ! items.length ) {
			return;
		}

		var typing = body.querySelector( '.citykleta-chat__typing' );
		var typingAuthor = typing ? typing.querySelector( '.citykleta-chat__author' ) : null;
		var speed = parseFloat( root.getAttribute( 'data-speed' ) ) || 1;
		var pause = parseInt( root.getAttribute( 'data-pause' ), 10 );
		var loop = 'yes' === root.getAttribute( 'data-loop' );
		var loopDelay = ( parseInt( root.getAttribute( 'data-loop-delay' ), 10 ) || 8 ) * 1000;

		if ( isNaN( pause ) ) {
			pause = 900;
		}

		// One pending step at a time, pausable: on pause the remaining time is
		// kept, so resuming continues the same message instead of skipping it.
		var state = { index: 0, fn: null, remaining: 0, timer: null, armedAt: 0, paused: true };
		var cleanups = [];

		function arm() {
			state.armedAt = Date.now();
			state.timer = window.setTimeout( function () {
				var fn = state.fn;
				state.timer = null;
				state.fn = null;
				// Elementor's editor replaces the markup on every change; a
				// detached chat must not keep ticking.
				if ( fn && document.body.contains( root ) ) {
					fn();
				}
			}, Math.max( 0, state.remaining ) );
		}

		function schedule( fn, ms ) {
			state.fn = fn;
			state.remaining = ms;
			if ( ! state.paused ) {
				arm();
			}
		}

		function pausePlayback() {
			if ( state.paused ) {
				return;
			}
			state.paused = true;
			if ( state.timer ) {
				window.clearTimeout( state.timer );
				state.timer = null;
				state.remaining -= Date.now() - state.armedAt;
			}
		}

		function resumePlayback() {
			if ( ! state.paused ) {
				return;
			}
			state.paused = false;
			if ( state.fn && ! state.timer ) {
				arm();
			}
		}

		function scrollDown() {
			if ( body.scrollTo ) {
				body.scrollTo( { top: body.scrollHeight, behavior: 'smooth' } );
			} else {
				body.scrollTop = body.scrollHeight;
			}
		}

		function hideTyping() {
			if ( typing ) {
				typing.hidden = true;
			}
		}

		function showTyping( item, author ) {
			if ( ! typing ) {
				return;
			}
			body.insertBefore( typing, item );
			typing.style.setProperty( '--citykleta-chat-author', item.style.getPropertyValue( '--citykleta-chat-author' ) );
			// The name goes on the typing bubble only when the message itself
			// will show it (first of a run), so the two never disagree.
			if ( typingAuthor ) {
				typingAuthor.textContent = item.classList.contains( 'is-first' ) ? author : '';
			}
			typing.classList.toggle( 'is-first', item.classList.contains( 'is-first' ) );
			typing.hidden = false;
			scrollDown();
		}

		function typingTime( item ) {
			var text = item.querySelector( '.citykleta-chat__text' );
			var length = text ? text.textContent.length : 20;

			return Math.min( 2800, Math.max( 700, length * 45 ) ) / speed;
		}

		function reveal( item ) {
			hideTyping();
			item.classList.remove( 'is-pending' );
			item.classList.add( 'is-entering' );
			state.index++;
			scrollDown();
			schedule( next, pause / speed );
		}

		function next() {
			if ( state.index >= items.length ) {
				if ( loop ) {
					schedule( restart, loopDelay );
				}
				return;
			}

			var item = items[ state.index ];
			var author = item.getAttribute( 'data-author' );

			if ( author ) {
				showTyping( item, author );
				schedule( function () {
					reveal( item );
				}, typingTime( item ) );
			} else {
				// "Me" or a notice: no typing bubble, just a short beat.
				schedule( function () {
					reveal( item );
				}, 350 / speed );
			}
		}

		function restart() {
			items.forEach( function ( item ) {
				item.classList.add( 'is-pending' );
				item.classList.remove( 'is-entering' );
			} );
			state.index = 0;
			body.scrollTop = 0;
			schedule( next, 400 );
		}

		items.forEach( function ( item ) {
			item.classList.add( 'is-pending' );
		} );

		// With hover, an empty chat would look broken until the mouse arrives,
		// so the first message is already there.
		if ( 'hover' === trigger ) {
			items[ 0 ].classList.remove( 'is-pending' );
			state.index = 1;
		}

		schedule( next, 400 );

		if ( 'hover' === trigger ) {
			[ 'mouseenter', 'focusin' ].forEach( function ( type ) {
				root.addEventListener( type, resumePlayback );
				cleanups.push( function () {
					root.removeEventListener( type, resumePlayback );
				} );
			} );
			[ 'mouseleave', 'focusout' ].forEach( function ( type ) {
				root.addEventListener( type, pausePlayback );
				cleanups.push( function () {
					root.removeEventListener( type, pausePlayback );
				} );
			} );
		} else if ( 'IntersectionObserver' in window ) {
			var observer = new IntersectionObserver( function ( entries ) {
				entries.forEach( function ( entry ) {
					if ( entry.isIntersecting ) {
						resumePlayback();
					} else {
						pausePlayback();
					}
				} );
			}, { threshold: 0.35 } );
			observer.observe( root );
			cleanups.push( function () {
				observer.disconnect();
			} );
		} else {
			resumePlayback();
		}

		root._citykletaChat = {
			stop: function () {
				pausePlayback();
				state.fn = null;
				cleanups.forEach( function ( fn ) {
					fn();
				} );
			},
		};
	}

	function initAll( scope ) {
		( scope || document ).querySelectorAll( '.citykleta-chat' ).forEach( initChat );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			initAll();
		} );
	} else {
		initAll();
	}

	window.addEventListener( 'elementor/frontend/init', function () {
		window.elementorFrontend.hooks.addAction( 'frontend/element_ready/citykleta_chat.default', function ( $scope ) {
			initAll( $scope[ 0 ] );
		} );
	} );
}() );
