<?php
/**
 * Elementor widget: bikes map.
 *
 * Queries bikes server-side (same WP_Query pattern as Citykleta_Bike_Widget)
 * and hands the result as a JSON array to assets/js/citykleta-map.js, which
 * draws them on a Leaflet map grouped in marker clusters.
 *
 * Elementor editor labels/control text are in English, to feel consistent
 * with Elementor's native widgets. Text seen by site visitors (popup
 * status, empty state) stays in Spanish, same split already used by
 * Citykleta_Search_Widget and Citykleta_Bike_Widget.
 *
 * @package Citykleta_Shared_Bikes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Citykleta_Map_Widget.
 */
class Citykleta_Map_Widget extends \Elementor\Widget_Base {

	/**
	 * The two things this widget can draw: the result of a bike query, or the
	 * one bike whose page it is being rendered on.
	 */
	const SOURCE_QUERY = 'query';
	const SOURCE_BIKE  = 'bike';

	/**
	 * Internal widget name. Also the AJAX re-render hook name that
	 * citykleta-map.js listens on ("frontend/element_ready/{name}.default").
	 *
	 * @return string
	 */
	public function get_name() {
		return 'citykleta_map';
	}

	/**
	 * Title shown in the Elementor editor.
	 *
	 * @return string
	 */
	public function get_title() {
		return 'Bikes Map';
	}

	/**
	 * Widget icon in the Elementor panel.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-google-maps';
	}

	/**
	 * Category the widget appears under in the widgets panel.
	 *
	 * @return array
	 */
	public function get_categories() {
		return array( 'citykleta' );
	}

	/**
	 * Keywords used to find it when searching in Elementor.
	 *
	 * @return array
	 */
	public function get_keywords() {
		return array( 'map', 'mapa', 'cluster', 'bike', 'bicicleta', 'citykleta' );
	}

	/**
	 * Controls shown in Elementor's left panel.
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'section_content',
			array(
				'label' => 'Content',
			)
		);

		$this->add_control(
			'source',
			array(
				'label'       => 'Source',
				'type'        => \Elementor\Controls_Manager::SELECT,
				'default'     => self::SOURCE_QUERY,
				'options'     => array(
					self::SOURCE_QUERY => 'Query',
					self::SOURCE_BIKE  => 'Bike Data',
				),
				'description' => 'Query draws every bike matching the settings below. Bike Data draws the single bike whose page this is — the "where to pick it up" map.',
			)
		);

		$this->add_control(
			'count',
			array(
				'label'       => 'Bike Count',
				'type'        => \Elementor\Controls_Manager::NUMBER,
				'min'         => 1,
				'max'         => 200,
				'placeholder' => 'All',
				'description' => 'Leave empty for no limit.',
				'condition'   => array(
					'use_url_filters!' => 'yes',
					'source'           => self::SOURCE_QUERY,
				),
			)
		);

		// No location text here: the map draws the map. The address is its own
		// piece now (Citykleta_Bike_Directions_Widget), so it can be placed,
		// styled and removed on its own instead of riding along with this one.

		$this->end_controls_section();

		$this->start_controls_section(
			'section_query',
			array(
				'label'     => 'Query',
				'condition' => array( 'source' => self::SOURCE_QUERY ),
			)
		);

		$this->add_control(
			'use_url_filters',
			array(
				'label'       => 'Filter From Page URL',
				'type'        => \Elementor\Controls_Manager::SWITCHER,
				'label_on'    => 'Yes',
				'label_off'   => 'No',
				'default'     => '',
				'description' => 'For a results page: reads location/electric from the URL (the Bike Search widget/shortcode sends these) instead of the fixed location below, and always shows every match instead of Bike Count.',
			)
		);

		$this->add_control(
			'location_filter',
			array(
				'label'       => 'Location',
				'type'        => \Elementor\Controls_Manager::TEXT,
				'placeholder' => 'E.g. Vedado',
				'description' => 'Partial match. Leave empty for any location.',
				'condition'   => array(
					'use_url_filters!' => 'yes',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_layout',
			array(
				'label' => 'Layout',
			)
		);

		$this->add_control(
			'zoom',
			array(
				'label'       => 'Zoom',
				'type'        => \Elementor\Controls_Manager::SLIDER,
				'size_units'  => array( 'px' ),
				'range'       => array(
					'px' => array(
						'min' => 1,
						'max' => 19,
					),
				),
				'default'     => array(
					'size' => 13,
				),
				'description' => 'Fallback only, for when no bikes match. Otherwise the map auto-zooms to fit them.',
			)
		);

		$this->add_control(
			'interactive',
			array(
				'label'       => 'Zoom & Pan',
				'type'        => \Elementor\Controls_Manager::SWITCHER,
				'label_on'    => 'On',
				'label_off'   => 'Off',
				'default'     => 'yes',
				'description' => 'Off hides the +/- control and freezes the map: no zoom, no dragging, no touch panning. Markers stay clickable.',
			)
		);

		$this->add_responsive_control(
			'map_height',
			array(
				'label'      => 'Map Height',
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 200,
						'max' => 900,
					),
				),
				'default'    => array(
					'size' => 400,
				),
				'selectors'  => array(
					'{{WRAPPER}} .citykleta-map' => 'height: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'center_on_user',
			array(
				'label'       => 'Center On Visitor',
				'type'        => \Elementor\Controls_Manager::SWITCHER,
				'label_on'    => 'Yes',
				'label_off'   => 'No',
				'default'     => 'yes',
				'description' => 'Centers on the visitor with a "you are here" marker. If denied, falls back to the coordinates below, or to fitting all bikes.',
				'condition'   => array(
					'source' => self::SOURCE_QUERY,
				),
			)
		);

		$this->add_control(
			'default_lat',
			array(
				'label'       => 'Default Latitude',
				'type'        => \Elementor\Controls_Manager::TEXT,
				'placeholder' => 'e.g. 23.1367',
				'description' => 'Optional, needs both fields. Leave empty to fit the bikes instead.',
				'condition'   => array(
					'center_on_user' => 'yes',
					'source'         => self::SOURCE_QUERY,
				),
			)
		);

		$this->add_control(
			'default_lng',
			array(
				'label'       => 'Default Longitude',
				'type'        => \Elementor\Controls_Manager::TEXT,
				'placeholder' => 'e.g. -82.3589',
				'condition'   => array(
					'center_on_user' => 'yes',
					'source'         => self::SOURCE_QUERY,
				),
			)
		);

		$this->end_controls_section();

		// Marker/cluster colors and the base map tile style used to live
		// here as per-instance Content/Style controls; they're now global
		// (Settings → Maps tab, see Citykleta_Settings::MAP_COLORS and
		// Citykleta_Settings::tile_layer()) so every map on the site —
		// this widget and the wp-admin overview page — stays on the same
		// look from one place instead of being set per widget instance.
		// The bike marker itself (a circle showing the built-in glyph,
		// colored by availability) isn't configurable at all anymore —
		// see assets/css/citykleta-map.css.
	}

	/**
	 * Queries the bikes (via Citykleta_Bike_Map_Data) and prints the map
	 * container with its data as JSON attributes — citykleta-map.js scans
	 * the page for these on load (and on every Elementor editor redraw)
	 * and initializes them.
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();
		$source   = isset( $settings['source'] ) ? $settings['source'] : self::SOURCE_QUERY;

		if ( self::SOURCE_BIKE === $source ) {
			/*
			 * "Where to pick it up": the one bike whose page this is. It used
			 * to be a widget of its own (Citykleta_Bike_Location_Widget), and
			 * that duplication cost something real — it built its data-options
			 * by hand and so never passed through tile_layer() /
			 * cluster_options() / marker_icon_size(), which meant the pickup
			 * map silently ignored the map settings synced from Back Office
			 * while every other map on the site honoured them. Sharing this
			 * render is what keeps that from happening again.
			 */
			$bike_id  = Citykleta_Bike_Part_Widget::current_bike_id();
			$map_bike = $bike_id ? Citykleta_Bike_Map_Data::for_bike( $bike_id ) : null;

			if ( ! $map_bike ) {
				return;
			}

			$bikes = array( $map_bike );

		} elseif ( 'yes' === $settings['use_url_filters'] ) {
			// Página de resultados: mismos filtros que la grilla, leídos de
			// la URL (Citykleta_Templates::request_filters()) en vez de la
			// ubicación fija del panel — y sin tope, se muestran todas las
			// bicis que matcheen, no solo Bike Count.
			$filters = Citykleta_Templates::request_filters();

			$bikes = Citykleta_Bike_Map_Data::query(
				array(
					'count'      => -1,
					'extra_meta' => $filters['meta_query'],
					'tax_query'  => $filters['tax_query'],
				)
			);
		} else {
			$count = is_numeric( $settings['count'] ) ? (int) $settings['count'] : -1;

			$bikes = Citykleta_Bike_Map_Data::query(
				array(
					'count'    => $count,
					'location' => $settings['location_filter'],
				)
			);
		}

		// Every bike gets the same default marker color here, whatever its
		// availability today: a visitor planning to rent a month from now
		// would read a greyed-out marker as "something's wrong with this
		// bike". The wp-admin map (Citykleta_Admin_Map) keeps the real
		// availability color, since that one is for the team.
		foreach ( $bikes as &$bike ) {
			$bike['available'] = true;
		}
		unset( $bike );

		// Unlike Citykleta_Bike_Widget's grid, the map container always
		// prints even with zero matching bikes — an empty result should show
		// an empty map, not make the whole widget disappear.
		$options = array_merge(
			// Never centre on the visitor for a single bike: the whole point
			// of that map is where THE BIKE is, and re-centring would push it
			// out of view for anyone not standing next to it.
			array( 'centerOnUser' => self::SOURCE_BIKE !== $source && 'yes' === $settings['center_on_user'] ),
			Citykleta_Settings::tile_layer(),
			Citykleta_Settings::cluster_options()
		);

		if ( ! empty( $settings['zoom']['size'] ) ) {
			$options['zoom'] = (int) $settings['zoom']['size'];
		}

		if ( is_numeric( $settings['default_lat'] ) && is_numeric( $settings['default_lng'] ) ) {
			$options['fallbackCenter'] = array( (float) $settings['default_lat'], (float) $settings['default_lng'] );
		}

		// No zoom lock button here on purpose: that on-map toggle is a
		// wp-admin-only affordance (see Citykleta_Admin_Map). On the public
		// side the decision is the editor's, via the "Zoom & Pan" control.
		$options['iconSize']    = Citykleta_Settings::marker_icon_size();
		$options['interactive'] = 'yes' === $settings['interactive'];

		// Height as an inline style, not just the "map_height" control's own
		// `selectors` rule: that rule only becomes real CSS when Elementor
		// generates the page's stylesheet, which never happens for a widget
		// rendered through [citykleta_map] (Citykleta_Widget_Shortcodes) —
		// there's no "page" to generate it against. Without an explicit
		// height Leaflet draws nothing at all (0px container), so this has
		// to work unconditionally, the same way map_color_style_attr()
		// already does for the cluster colors below.
		$height_size  = isset( $settings['map_height']['size'] ) ? $settings['map_height']['size'] : '';
		$height_unit  = ! empty( $settings['map_height']['unit'] ) ? $settings['map_height']['unit'] : 'px';
		$height_style = is_numeric( $height_size ) ? 'height:' . $height_size . $height_unit . ';' : '';

		printf(
			'<div id="citykleta-map-%1$s" class="citykleta-map%6$s" style="%4$s%5$s" data-bikes="%2$s" data-options="%3$s"></div>',
			esc_attr( $this->get_id() ),
			esc_attr( wp_json_encode( $bikes ) ),
			esc_attr( wp_json_encode( $options ) ),
			esc_attr( $height_style ),
			esc_attr( Citykleta_Settings::map_color_style_attr() ),
			self::SOURCE_BIKE === $source ? ' citykleta-bike-single__location-map' : ''
		);

	}
}
