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
			'count',
			array(
				'label'       => 'Bike Count',
				'type'        => \Elementor\Controls_Manager::NUMBER,
				'min'         => 1,
				'max'         => 200,
				'placeholder' => 'All',
				'description' => 'Leave empty to show all bikes matching the filters below, with no limit.',
			)
		);

		$this->add_control(
			'only_available',
			array(
				'label'     => 'Show Only Available',
				'type'      => \Elementor\Controls_Manager::SWITCHER,
				'label_on'  => 'Yes',
				'label_off' => 'No',
				'default'   => '',
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_query',
			array(
				'label' => 'Query',
			)
		);

		$this->add_control(
			'location_filter',
			array(
				'label'       => 'Location',
				'type'        => \Elementor\Controls_Manager::TEXT,
				'placeholder' => 'E.g. Vedado',
				'description' => 'Leave empty to show bikes from any location. Matches partial text.',
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
				'description' => 'Initial zoom level, used only as a fallback when there are no bikes to fit the map to (e.g. a filter above matches none). Otherwise the map auto-zooms to fit the bikes shown.',
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
				'condition'  => array(
					'cover_mode!' => 'yes',
				),
				'selectors'  => array(
					'{{WRAPPER}} .citykleta-map' => 'height: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'cover_mode',
			array(
				'label'       => 'Cover Mode',
				'type'        => \Elementor\Controls_Manager::SWITCHER,
				'label_on'    => 'Yes',
				'label_off'   => 'No',
				'default'     => '',
				'separator'   => 'before',
				'description' => 'Fills the parent Container as a background layer (position: absolute, covering the full container) instead of taking up its own space. Drop this widget into an Elementor Container first, set that Container\'s own Advanced → Position to "Relative" so this has something to size against, then add other widgets on top of it in the same Container. The map stays interactive (draggable/zoomable) anywhere the widgets on top don\'t cover it — the on-screen +/- zoom control is hidden too, since it doesn\'t make sense floating over a background.',
				'selectors'   => array(
					'{{WRAPPER}}'                        => 'position: absolute; inset: 0; width: 100%; height: 100%;',
					'{{WRAPPER}} .citykleta-map'          => 'width: 100%; height: 100%;',
					'{{WRAPPER}} .leaflet-control-zoom'   => 'display: none;',
				),
			)
		);

		$this->add_control(
			'cover_z_index',
			array(
				'label'       => 'Z-Index',
				'type'        => \Elementor\Controls_Manager::NUMBER,
				'default'     => -1,
				'condition'   => array(
					'cover_mode' => 'yes',
				),
				'description' => 'Keeps the map behind the widgets stacked on top of it in the same Container (lower number = further back).',
				'selectors'   => array(
					'{{WRAPPER}}' => 'z-index: {{VALUE}};',
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
				'description' => 'Asks the visitor\'s browser for their location and centers the map there, with a "you are here" marker. Falls back to fitting all bikes on screen if denied or unavailable, or to the Default Location below if set.',
			)
		);

		$this->add_control(
			'default_lat',
			array(
				'label'       => 'Default Latitude',
				'type'        => \Elementor\Controls_Manager::TEXT,
				'placeholder' => 'e.g. 23.1367',
				'description' => 'Optional, used together with Default Longitude. Recenters the map here if the visitor\'s browser can\'t get or rejects their location (only relevant while "Center On Visitor" is on). Leave both empty to just keep fitting the bikes shown.',
				'condition'   => array(
					'center_on_user' => 'yes',
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

		$count = is_numeric( $settings['count'] ) ? (int) $settings['count'] : -1;

		$bikes = Citykleta_Bike_Map_Data::query(
			array(
				'count'          => $count,
				'only_available' => 'yes' === $settings['only_available'],
				'location'       => $settings['location_filter'],
			)
		);

		// Unlike Citykleta_Bike_Widget's grid, the map container always
		// prints even with zero matching bikes — this widget is meant to
		// stay on screen as a persistent layer (see Cover Mode), so an empty
		// result should show an empty map, not make the whole widget
		// disappear.
		$options = array_merge(
			array( 'centerOnUser' => 'yes' === $settings['center_on_user'] ),
			Citykleta_Settings::tile_layer(),
			Citykleta_Settings::cluster_options()
		);

		if ( ! empty( $settings['zoom']['size'] ) ) {
			$options['zoom'] = (int) $settings['zoom']['size'];
		}

		if ( is_numeric( $settings['default_lat'] ) && is_numeric( $settings['default_lng'] ) ) {
			$options['fallbackCenter'] = array( (float) $settings['default_lat'], (float) $settings['default_lng'] );
		}

		$options['iconSize'] = Citykleta_Settings::marker_icon_size();

		printf(
			'<div id="citykleta-map-%1$s" class="citykleta-map" style="%4$s" data-bikes="%2$s" data-options="%3$s"></div>',
			esc_attr( $this->get_id() ),
			esc_attr( wp_json_encode( $bikes ) ),
			esc_attr( wp_json_encode( $options ) ),
			esc_attr( Citykleta_Settings::map_color_style_attr() )
		);
	}
}
