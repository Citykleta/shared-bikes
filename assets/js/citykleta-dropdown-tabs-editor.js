/**
 * Dropdown Tabs, editor side (Citykleta_Dropdown_Tabs_Widget).
 *
 * The widget is a nested element: each tab owns a container the user fills on
 * the canvas. Elementor's editor needs a JS element type for any nested widget
 * to know how to draw those children; this is the same shape as Elementor's
 * own modules/nested-tabs/assets/js/editor, which is the reference to compare
 * against if a future Elementor release changes the nested-elements API.
 *
 * Loaded only inside the editor, after Elementor's `nested-elements` script.
 */
( function () {
	'use strict';

	elementorCommon.elements.$window.on( 'elementor/nested-element-type-loaded', function () {
		var NestedView = $e.components.get( 'nested-elements' ).exports.NestedView;

		class DropdownTabsView extends NestedView {
			// Remembers each child's 1-based position, matching `data-tab-index`.
			filter( child, index ) {
				child.attributes.dataIndex = index + 1;

				return true;
			}

			// Stamps a tab's container as it is added. Only the first tab starts
			// visible; the selector on the canvas shows the others.
			onAddChild( childView ) {
				var index = childView.model.attributes.dataIndex;

				childView.$el.attr( {
					role: 'region',
					'data-tab-index': index,
				} );

				if ( elementor.previewView.isBuffering && 1 === index ) {
					childView.$el.addClass( 'is-active' );
				}
			}
		}

		class DropdownTabs extends elementor.modules.elements.types.NestedElementBase {
			getType() {
				return 'citykleta_dropdown_tabs';
			}

			getView() {
				return DropdownTabsView;
			}
		}

		elementor.elementsManager.registerElementType( new DropdownTabs() );
	} );
}() );
