/**
 * "Dropdown Tabs" widget (Citykleta_Dropdown_Tabs_Widget): a button that opens
 * a listbox; picking an option shows its panel and hides the others.
 *
 * Keyboard follows the WAI-ARIA "select-only combobox / listbox" pattern:
 * Enter, Space or the arrows open it; arrows, Home and End move; Enter or
 * Space picks; Escape or Tab closes.
 */
( function () {
	'use strict';

	function initTabs( root ) {
		if ( root._citykletaTabs ) {
			return;
		}

		var select = root.querySelector( '.citykleta-dtabs__select' );
		var toggle = root.querySelector( '.citykleta-dtabs__toggle' );
		var list = root.querySelector( '.citykleta-dtabs__list' );
		var current = root.querySelector( '.citykleta-dtabs__current' );
		var options = list ? Array.prototype.slice.call( list.querySelectorAll( '.citykleta-dtabs__option' ) ) : [];

		if ( ! select || ! toggle || ! options.length ) {
			return;
		}

		var selected = 0;
		var focused = 0;

		options.forEach( function ( option, index ) {
			if ( 'true' === option.getAttribute( 'aria-selected' ) ) {
				selected = index;
			}
		} );

		function isOpen() {
			return ! list.hidden;
		}

		function focusOption( index ) {
			focused = Math.max( 0, Math.min( options.length - 1, index ) );
			options.forEach( function ( option, i ) {
				option.classList.toggle( 'is-focused', i === focused );
			} );
			list.setAttribute( 'aria-activedescendant', options[ focused ].id );
			options[ focused ].scrollIntoView( { block: 'nearest' } );
		}

		function open() {
			list.hidden = false;
			toggle.setAttribute( 'aria-expanded', 'true' );
			focusOption( selected );
			list.focus();
		}

		function close( returnFocus ) {
			if ( ! isOpen() ) {
				return;
			}
			list.hidden = true;
			toggle.setAttribute( 'aria-expanded', 'false' );
			list.removeAttribute( 'aria-activedescendant' );
			if ( returnFocus ) {
				toggle.focus();
			}
		}

		function choose( index ) {
			selected = index;
			options.forEach( function ( option, i ) {
				option.setAttribute( 'aria-selected', i === index ? 'true' : 'false' );
			} );
			// Looked up now, not at start-up: in the editor the tabs' containers
			// are added after the widget draws. Only this widget's own panels
			// (direct children of its panels box) — a tab may hold another
			// Dropdown Tabs with panels of its own.
			var box = root.querySelector( ':scope > .citykleta-dtabs__panels' );

			if ( box ) {
				Array.prototype.forEach.call( box.children, function ( panel ) {
					panel.classList.toggle( 'is-active', parseInt( panel.getAttribute( 'data-tab-index' ), 10 ) === index + 1 );
				} );
			}
			if ( current ) {
				current.textContent = options[ index ].textContent;
			}
			close( true );

			// Content drawn while hidden (a Leaflet map, a carousel) measured
			// itself at 0px; a resize makes it measure again.
			window.dispatchEvent( new Event( 'resize' ) );
			root.dispatchEvent( new CustomEvent( 'citykleta:tab-change', { bubbles: true, detail: { index: index } } ) );
		}

		toggle.addEventListener( 'click', function () {
			if ( isOpen() ) {
				close( true );
			} else {
				open();
			}
		} );

		toggle.addEventListener( 'keydown', function ( event ) {
			if ( [ 'ArrowDown', 'ArrowUp', 'Enter', ' ' ].indexOf( event.key ) !== -1 ) {
				event.preventDefault();
				open();
			}
		} );

		list.addEventListener( 'keydown', function ( event ) {
			switch ( event.key ) {
				case 'ArrowDown':
					event.preventDefault();
					focusOption( focused + 1 );
					break;
				case 'ArrowUp':
					event.preventDefault();
					focusOption( focused - 1 );
					break;
				case 'Home':
					event.preventDefault();
					focusOption( 0 );
					break;
				case 'End':
					event.preventDefault();
					focusOption( options.length - 1 );
					break;
				case 'Enter':
				case ' ':
					event.preventDefault();
					choose( focused );
					break;
				case 'Escape':
					event.preventDefault();
					close( true );
					break;
				case 'Tab':
					close( false );
					break;
			}
		} );

		options.forEach( function ( option, index ) {
			option.addEventListener( 'click', function () {
				choose( index );
			} );
			option.addEventListener( 'mousemove', function () {
				if ( focused !== index ) {
					focusOption( index );
				}
			} );
		} );

		document.addEventListener( 'pointerdown', function ( event ) {
			if ( isOpen() && ! select.contains( event.target ) ) {
				close( false );
			}
		} );

		root._citykletaTabs = true;
	}

	function initAll( scope ) {
		( scope || document ).querySelectorAll( '[data-citykleta-dtabs]' ).forEach( initTabs );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			initAll();
		} );
	} else {
		initAll();
	}

	window.addEventListener( 'elementor/frontend/init', function () {
		window.elementorFrontend.hooks.addAction( 'frontend/element_ready/citykleta_dropdown_tabs.default', function ( $scope ) {
			initAll( $scope[ 0 ] );
		} );
	} );
}() );
