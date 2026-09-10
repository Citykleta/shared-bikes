<?php
/**
 * Exposes the plugin's Elementor widgets as shortcodes too — [citykleta_search],
 * [citykleta_grid], [citykleta_map] — so the results page (or any page) can be
 * composed from independent, freely-arranged pieces instead of one bundled
 * block, and so they also work outside Elementor.
 *
 * Reuses each widget's own render() UNMODIFIED, through Elementor's own public
 * render_content() — the exact method Elementor's frontend controller calls
 * for a normal, saved widget instance. Nothing about the widgets' markup is
 * re-implemented here: whatever a Content/Query control does inside the
 * widget works the same from its shortcode, automatically, with nothing to
 * keep in sync by hand. Elementor fills in any setting the shortcode didn't
 * pass with that control's own registered default, the same way it does for
 * a widget instance saved with only some settings customized.
 *
 * Style-tab controls are the one thing this can't carry over: those work by
 * generating CSS into a file tied to the *page* the widget was saved on
 * (Elementor's Post_CSS_File), which doesn't exist for a bare shortcode
 * call. A shortcode-rendered widget always looks like its plain defaults
 * (the plugin's own bike-cards.css/search-bar.css/citykleta-map.css) —
 * exactly what the old templates/archive-bike.php already looked like, so
 * this isn't a regression for the results page, just a real limit worth
 * knowing about before reaching for a shortcode elsewhere expecting custom
 * widget styling to show up.
 *
 * @package Citykleta_Shared_Bikes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Citykleta_Widget_Shortcodes.
 */
class Citykleta_Widget_Shortcodes {

	/**
	 * Connects the class to WordPress hooks.
	 */
	public function register() {
		add_shortcode( 'citykleta_search', array( $this, 'render_search' ) );
		add_shortcode( 'citykleta_grid', array( $this, 'render_grid' ) );
		add_shortcode( 'citykleta_map', array( $this, 'render_map' ) );
	}

	/**
	 * [citykleta_search] — Citykleta_Search_Widget, no attribute mapping
	 * needed (its shortcode attributes are already the widget's own setting
	 * IDs, e.g. [citykleta_search show_dates="no"]).
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_search( $atts ) {
		return self::render_widget( 'Citykleta_Search_Widget', (array) $atts );
	}

	/**
	 * [citykleta_grid] — Citykleta_Bike_Widget. `filters="url"` is the
	 * shortcode-friendly name for the widget's "Filter From Page URL"
	 * switcher (use_url_filters); every other attribute passes straight
	 * through (e.g. columns="4", masonry="yes").
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_grid( $atts ) {
		return self::render_widget( 'Citykleta_Bike_Widget', self::map_filters_attr( (array) $atts ) );
	}

	/**
	 * [citykleta_map] — Citykleta_Map_Widget. Same `filters="url"` mapping
	 * as the grid, plus map_height (a plain number here, e.g. map_height="450")
	 * converted to the {size, unit} shape Elementor's SLIDER control expects
	 * internally for its {{SIZE}}{{UNIT}} selector.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_map( $atts ) {
		$atts = self::map_filters_attr( (array) $atts );

		if ( isset( $atts['map_height'] ) && is_numeric( $atts['map_height'] ) ) {
			$atts['map_height'] = array(
				'size' => (float) $atts['map_height'],
				'unit' => 'px',
			);
		}

		return self::render_widget( 'Citykleta_Map_Widget', $atts );
	}

	/**
	 * Translates the shortcode-friendly `filters="url"` into the widget's
	 * actual setting ID, shared by the grid and the map shortcodes.
	 *
	 * @param array $atts Raw shortcode attributes.
	 * @return array
	 */
	private static function map_filters_attr( array $atts ) {
		if ( isset( $atts['filters'] ) && 'url' === $atts['filters'] ) {
			$atts['use_url_filters'] = 'yes';
		}

		unset( $atts['filters'] );

		return $atts;
	}

	/**
	 * Renders one Elementor widget class outside the editor, with the given
	 * settings layered over that widget's own registered control defaults —
	 * see the class docblock for how and why.
	 *
	 * @param string $class    Fully-qualified widget class name.
	 * @param array  $settings Partial settings (shortcode attributes); any
	 *                         key not present falls back to that control's
	 *                         own default.
	 * @return string Rendered HTML, or '' if Elementor isn't active.
	 */
	private static function render_widget( $class, array $settings ) {
		if ( ! did_action( 'elementor/loaded' ) || ! class_exists( $class ) ) {
			return '';
		}

		$widget = new $class(
			array(
				// Elementor scopes {{WRAPPER}} CSS and element IDs by this —
				// unique per call so two shortcodes on the same page (or two
				// calls to the same one) never collide.
				'id'       => substr( md5( wp_rand() . $class ), 0, 7 ),
				'settings' => $settings,
			),
			// Widget_Base::__construct() throws if $args is null whenever
			// $data isn't empty (see Element_Base::__construct() — a
			// non-empty $data always means "this is a full instance, not
			// just a type instance", and Widget_Base requires $args for
			// that case). $args itself only matters when $data is EMPTY,
			// which is never true here, so an empty array satisfies the
			// check without doing anything else.
			array()
		);

		ob_start();
		$widget->render_content();
		return ob_get_clean();
	}
}
