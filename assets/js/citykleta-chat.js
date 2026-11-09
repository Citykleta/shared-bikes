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

	function toArray( list ) {
		return Array.prototype.slice.call( list );
	}

	// The images of a container. A boxed Elementor container wraps them in
	// .e-con-inner, so look one level in when that is its only child.
	function stageChildren( container ) {
		var kids = toArray( container.children );
		if ( 1 === kids.length && kids[ 0 ].classList.contains( 'e-con-inner' ) ) {
			kids = toArray( kids[ 0 ].children );
		}
		return kids;
	}

	// "1, 4" / "all" / "#phrase" → elements. A number is a position inside the
	// grid, "all" is the whole grid, anything else a CSS selector.
	function resolveReveal( value, grid ) {
		var found = [];
		value.split( ',' ).forEach( function ( raw ) {
			var token = raw.trim();
			if ( ! token ) {
				return;
			}
			if ( /^\d+$/.test( token ) ) {
				if ( grid[ token - 1 ] ) {
					found.push( grid[ token - 1 ] );
				}
			} else if ( 'all' === token.toLowerCase() ) {
				found = found.concat( grid );
			} else {
				try {
					found = found.concat( toArray( document.querySelectorAll( token ) ) );
				} catch ( e ) {
					// An invalid selector typed in the editor: ignore it.
				}
			}
		} );
		return found;
	}

	// The images outside the chat that its messages reveal. Everything a message
	// can reveal starts hidden; null when there is nothing to drive (no container,
	// no message with the field, or the Elementor editor, where the images must
	// stay visible so the page can be designed).
	function buildStage( root, items ) {
		var selector = root.getAttribute( 'data-stage' );
		if ( ! selector || document.body.classList.contains( 'elementor-editor-active' ) ) {
			return null;
		}

		var container = null;
		try {
			container = document.querySelector( selector );
		} catch ( e ) {
			container = null;
		}
		if ( ! container ) {
			return null;
		}

		var grid = stageChildren( container );
		var effect = root.getAttribute( 'data-stage-effect' ) || 'scale';
		var duration = parseInt( root.getAttribute( 'data-stage-duration' ), 10 );
		var stagger = parseInt( root.getAttribute( 'data-stage-stagger' ), 10 );
		duration = isNaN( duration ) ? 700 : duration;
		stagger = isNaN( stagger ) ? 150 : stagger;

		var plan = items.map( function ( item ) {
			return resolveReveal( item.getAttribute( 'data-reveal' ) || '', grid );
		} );
		var all = [];
		plan.forEach( function ( list ) {
			list.forEach( function ( el ) {
				if ( -1 === all.indexOf( el ) ) {
					all.push( el );
				}
			} );
		} );
		if ( ! all.length ) {
			return null;
		}

		function hide( el ) {
			el.style.transitionDelay = '';
			el.classList.add( 'is-stage-hidden' );
		}

		all.forEach( function ( el ) {
			el.classList.add( 'citykleta-stage-item', 'citykleta-stage--' + effect );
			el.style.setProperty( '--citykleta-stage-duration', duration + 'ms' );
			hide( el );
		} );

		return {
			reveal: function ( item ) {
				var shown = 0;
				plan[ items.indexOf( item ) ].forEach( function ( el ) {
					if ( el.classList.contains( 'is-stage-hidden' ) ) {
						el.style.transitionDelay = shown * stagger + 'ms';
						el.classList.remove( 'is-stage-hidden' );
						shown++;
					}
				} );
			},
			reset: function () {
				all.forEach( hide );
			},
			// Leave the images exactly as they were (the chat is being replaced).
			release: function () {
				all.forEach( function ( el ) {
					el.style.transitionDelay = '';
					el.style.removeProperty( '--citykleta-stage-duration' );
					el.classList.remove( 'is-stage-hidden', 'citykleta-stage-item', 'citykleta-stage--' + effect );
				} );
			},
		};
	}

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
		var input = root.querySelector( '.citykleta-chat__input' );
		var send = root.querySelector( '.citykleta-chat__send' );
		var placeholder = input ? input.textContent : '';
		var speed = parseFloat( root.getAttribute( 'data-speed' ) ) || 1;
		var pause = parseInt( root.getAttribute( 'data-pause' ), 10 );
		var loop = 'yes' === root.getAttribute( 'data-loop' );
		var loopDelay = ( parseInt( root.getAttribute( 'data-loop-delay' ), 10 ) || 8 ) * 1000;
		var stage = buildStage( root, items );

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
			if ( stage ) {
				stage.reveal( item );
			}
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
			} else if ( input && item.classList.contains( 'is-out' ) ) {
				typeIntoComposer( item );
			} else {
				// A notice (or "me" with the message bar hidden): just a short beat.
				schedule( function () {
					reveal( item );
				}, 350 / speed );
			}
		}

		// "Me" messages are typed into the message bar first, then "sent": the
		// visitor gets the feeling that they are the one writing.
		function typeIntoComposer( item ) {
			var textEl = item.querySelector( '.citykleta-chat__text' );
			var chars = Array.from( textEl ? textEl.textContent : '' );
			var count = 0;

			function finish() {
				if ( send ) {
					send.classList.add( 'is-pressed' );
				}
				schedule( function () {
					resetComposer();
					reveal( item );
				}, 180 / speed );
			}

			function step() {
				count++;
				input.textContent = chars.slice( 0, count ).join( '' );
				if ( count < chars.length ) {
					schedule( step, ( 35 + Math.random() * 45 ) / speed );
				} else {
					schedule( finish, 450 / speed );
				}
			}

			if ( ! chars.length ) {
				reveal( item );
				return;
			}

			input.textContent = '';
			input.classList.add( 'is-typing' );
			schedule( step, 500 / speed );
		}

		function resetComposer() {
			if ( ! input ) {
				return;
			}
			input.textContent = placeholder;
			input.classList.remove( 'is-typing' );
			if ( send ) {
				send.classList.remove( 'is-pressed' );
			}
		}

		function restart() {
			resetComposer();
			if ( stage ) {
				stage.reset();
			}
			items.forEach( function ( item ) {
				item.classList.add( 'is-pending' );
				item.classList.remove( 'is-entering' );
			} );
			state.index = 0;
			// With hover, the first message is always already there (see below).
			if ( 'hover' === trigger ) {
				items[ 0 ].classList.remove( 'is-pending' );
				if ( stage ) {
					stage.reveal( items[ 0 ] );
				}
				state.index = 1;
			}
			body.scrollTop = 0;
			schedule( next, 400 );
		}

		// The chat lives in a tab panel (Elementor's Tabs, Dropdown Tabs, …): when
		// its tab is shown again, start over from zero, whatever point the chat
		// was at. Cancels the pending step first (restart() only schedules a new
		// one) and hides a "typing…" bubble left over from the middle of a message.
		function restartFromZero() {
			if ( ! document.body.contains( root ) ) {
				return;
			}
			if ( state.timer ) {
				window.clearTimeout( state.timer );
				state.timer = null;
			}
			state.fn = null;
			hideTyping();
			restart();
		}

		// Shown = it has a box. Whatever widget swaps the tabs (they all hide the
		// panel with display:none, at any nesting level) takes the chat's box away
		// and gives it back, and a ResizeObserver reports both without having to
		// know which tabs widget it is.
		function isShown() {
			return root.getClientRects().length > 0;
		}

		var wasShown = isShown();

		function onBoxChange() {
			var shown = isShown();
			if ( shown && ! wasShown ) {
				restartFromZero();
			}
			wasShown = shown;
		}

		items.forEach( function ( item ) {
			item.classList.add( 'is-pending' );
		} );

		// With hover, an empty chat would look broken until the mouse arrives,
		// so the first message is already there.
		if ( 'hover' === trigger ) {
			items[ 0 ].classList.remove( 'is-pending' );
			if ( stage ) {
				stage.reveal( items[ 0 ] );
			}
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

		if ( 'ResizeObserver' in window ) {
			var boxObserver = new ResizeObserver( onBoxChange );
			boxObserver.observe( root );
			cleanups.push( function () {
				boxObserver.disconnect();
			} );
		}

		root._citykletaChat = {
			stop: function () {
				pausePlayback();
				state.fn = null;
				resetComposer();
				if ( stage ) {
					stage.release();
				}
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
