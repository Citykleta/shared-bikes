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
		add_shortcode( 'citykleta_bike_categories', array( $this, 'render_bike_categories' ) );

		// Pieces of a single-bike layout — each resolves the bike it's about
		// from the current query (Citykleta_Bike_Part_Widget::bike_id()), so
		// they take no bike id, only presentation attributes.
		foreach ( self::BIKE_PART_SHORTCODES as $tag => $class ) {
			add_shortcode(
				$tag,
				function ( $atts ) use ( $class ) {
					return self::render_widget( $class, (array) $atts );
				}
			);
		}

		foreach ( self::DESTINATION_PART_SHORTCODES as $tag => $class ) {
			add_shortcode(
				$tag,
				function ( $atts ) use ( $class ) {
					return self::render_widget( $class, (array) $atts );
				}
			);
		}

		// Not in the loops above: this one is no longer a part widget of its
		// own, it is the map widget in "Bike Data" mode. The tag stays so
		// existing content keeps working.
		add_shortcode( 'citykleta_bike_location', array( $this, 'render_bike_location' ) );

		// Likewise, a destination's bikes are the grid widget with its
		// "Destination Bikes" source, not a widget of their own.
		add_shortcode( 'citykleta_destination_bikes', array( $this, 'render_destination_bikes' ) );
	}

	/**
	 * [citykleta_destination_bikes] — the bikes the geofence assigned to the
	 * destination whose page this is, drawn by the normal grid.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_destination_bikes( $atts ) {
		$atts = (array) $atts;

		$atts['source'] = Citykleta_Bike_Widget::SOURCE_DESTINATION;

		if ( ! isset( $atts['count'] ) ) {
			$atts['count'] = 24;
		}

		return self::render_widget( 'Citykleta_Bike_Widget', $atts );
	}

	/**
	 * Shortcode tag => widget class, for the single-bike pieces.
	 */
	const BIKE_PART_SHORTCODES = array(
		'citykleta_bike_gallery'     => 'Citykleta_Bike_Gallery_Widget',
		'citykleta_bike_title'         => 'Citykleta_Bike_Title_Widget',
		'citykleta_bike_location_tags' => 'Citykleta_Bike_Location_Tags_Widget',
		'citykleta_bike_owner'       => 'Citykleta_Bike_Owner_Widget',
		'citykleta_bike_description' => 'Citykleta_Bike_Description_Widget',
		'citykleta_bike_specs'       => 'Citykleta_Bike_Specs_Widget',
		'citykleta_bike_request'     => 'Citykleta_Bike_Request_Widget',
		'citykleta_bike_directions'  => 'Citykleta_Bike_Directions_Widget',
		// [citykleta_bike_data field="price"] — any single value; see
		// Citykleta_Bike_Data_Widget::FIELDS for the list.
		'citykleta_bike_data'        => 'Citykleta_Bike_Data_Widget',
	);

	/**
	 * Shortcode tag => widget class, for the destination pieces.
	 */
	const DESTINATION_PART_SHORTCODES = array(
		'citykleta_destination_header'      => 'Citykleta_Destination_Header_Widget',
		'citykleta_destination_description' => 'Citykleta_Destination_Description_Widget',
		'citykleta_destination_price'       => 'Citykleta_Destination_Price_Widget',
		'citykleta_destination_articles'    => 'Citykleta_Destination_Articles_Widget',
		'citykleta_destination_faq'         => 'Citykleta_Destination_Faq_Widget',
		'citykleta_destination_weather'     => 'Citykleta_Destination_Weather_Widget',
	);

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
	 * [citykleta_bike_categories] — Citykleta_Bike_Terms_Widget: the bike
	 * taxonomies as a horizontal list. Attributes are the widget's own
	 * setting IDs, e.g. [citykleta_bike_categories taxonomy="bike_usage"
	 * show_count="yes"].
	 *
	 * The require_once isn't redundant: widget classes are only loaded on
	 * `elementor/widgets/register`, and render_widget()'s class_exists()
	 * guard would return '' without a word if this ran before that — the
	 * gotcha CLAUDE.md already documents for Bike Data.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_bike_categories( $atts ) {
		if ( did_action( 'elementor/loaded' ) ) {
			require_once CITYKLETA_PATH . 'includes/elementor/class-citykleta-bike-terms-widget.php';
		}

		return self::render_widget( 'Citykleta_Bike_Terms_Widget', (array) $atts );
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
	 * [citykleta_bike_location] — the same map widget, locked to the bike
	 * whose page it is on ("where to pick it up").
	 *
	 * Kept as its own tag because that is what existing templates and content
	 * already say; it is no longer a widget of its own. The height default is
	 * lower than the map widget's, because this one sits inside a column next
	 * to the request card rather than spanning a results page.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_bike_location( $atts ) {
		$atts = (array) $atts;

		$atts['source'] = Citykleta_Map_Widget::SOURCE_BIKE;

		if ( ! isset( $atts['map_height'] ) ) {
			$atts['map_height'] = 220;
		}

		return $this->render_map( $atts );
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
