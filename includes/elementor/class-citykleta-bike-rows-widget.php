<?php
/**
 * Elementor widget: one scrollable row of bikes per top-level bike type.
 *
 * "Urbano → [cards…]", "Carretera → [cards…]"… Each row title links to the
 * type's own archive page, and a row that doesn't fit the screen scrolls
 * sideways (arrows on hover, plus touch / wheel / keyboard).
 *
 * It extends the Bikes Grid widget on purpose: the card markup
 * (Citykleta_Bike_Widget::render_card()) and the whole card Style tab are
 * the grid's own, so a row and a grid can never drift apart. Only what makes
 * no sense in a row (columns, masonry, the query source) is removed.
 *
 * Rows come from the taxonomy, so a type created later in wp-admin gets its
 * row without touching the widget. A type with no bikes draws no row, and the
 * "Types" control narrows it to a hand-picked set.
 *
 * @package Citykleta_Shared_Bikes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Citykleta_Bike_Rows_Widget.
 */
class Citykleta_Bike_Rows_Widget extends Citykleta_Bike_Widget {

	/**
	 * Internal widget name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'citykleta_bike_rows';
	}

	/**
	 * Title shown in the Elementor editor.
	 *
	 * @return string
	 */
	public function get_title() {
		return 'Bike Rows';
	}

	/**
	 * Widget icon in the Elementor panel.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-carousel';
	}

	/**
	 * Keywords used to find it when searching in Elementor.
	 *
	 * @return array
	 */
	public function get_keywords() {
		return array( 'bicicleta', 'bici', 'bike', 'citykleta', 'row', 'fila', 'carousel', 'carrusel', 'type', 'tipo', 'category' );
	}

	/**
	 * Top-level types as a choice list for the "Types" control.
	 *
	 * @return array term_id => name.
	 */
	private static function type_options() {
		if ( ! class_exists( 'Citykleta_Bike_Type_Taxonomy' ) ) {
			return array();
		}

		$options = array();

		foreach ( Citykleta_Bike_Type_Taxonomy::top_level() as $term ) {
			$options[ $term->term_id ] = $term->name;
		}

		return $options;
	}

	/**
	 * Types with their subtypes under them, for the "Type or Subtype" control.
	 *
	 * @return array term_id => label.
	 */
	private static function type_tree_options() {
		if ( ! class_exists( 'Citykleta_Bike_Type_Taxonomy' ) ) {
			return array();
		}

		$options = array( '' => '— Select —' );

		foreach ( Citykleta_Bike_Type_Taxonomy::top_level() as $type ) {
			$options[ $type->term_id ] = $type->name;

			foreach ( Citykleta_Bike_Type_Taxonomy::children( $type->term_id ) as $child ) {
				$options[ $child->term_id ] = $type->name . ' › ' . $child->name;
			}
		}

		return $options;
	}

	/**
	 * Bike tags, for the "Tag" control.
	 *
	 * @return array term_id => name.
	 */
	private static function tag_options() {
		$options = array( '' => '— Select —' );
		$terms   = get_terms(
			array(
				'taxonomy'   => Citykleta_Bike_Tag_Taxonomy::TAXONOMY,
				'hide_empty' => false,
			)
		);

		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$options[ $term->term_id ] = $term->name;
			}
		}

		return $options;
	}

	/**
	 * Destinations, for the "Destination" control.
	 *
	 * @return array post_id => title.
	 */
	private static function destination_options() {
		$options = array( '' => '— Select —' );

		foreach ( get_posts(
			array(
				'post_type'      => Citykleta_Destination_Post_Type::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		) as $post ) {
			$options[ $post->ID ] = $post->post_title;
		}

		return $options;
	}

	/**
	 * The rows this instance draws, each as title / link / extra query args.
	 * "All types" gives one per type; every other source gives exactly one.
	 *
	 * @param array $settings Widget settings.
	 * @param array $wanted   Hand-picked type ids for "All types" (empty = every type).
	 * @return array List of array( 'title' => string, 'url' => string, 'args' => array ).
	 */
	private function build_rows( array $settings, array $wanted ) {
		$source = isset( $settings['rows_source'] ) ? $settings['rows_source'] : 'all';

		if ( 'all' === $source ) {
			$rows = array();

			foreach ( Citykleta_Bike_Type_Taxonomy::top_level() as $type ) {
				if ( $wanted && ! in_array( (int) $type->term_id, $wanted, true ) ) {
					continue;
				}

				$rows[] = $this->term_row( $type );
			}

			return $rows;
		}

		$row = null;

		if ( 'type' === $source || 'tag' === $source ) {
			$term_id  = (int) ( 'type' === $source ? $settings['row_type'] : $settings['row_tag'] );
			$taxonomy = 'type' === $source ? Citykleta_Bike_Type_Taxonomy::TAXONOMY : Citykleta_Bike_Tag_Taxonomy::TAXONOMY;
			$term     = $term_id ? get_term( $term_id, $taxonomy ) : null;
			$row      = $term instanceof WP_Term ? $this->term_row( $term ) : null;
		} elseif ( 'destination' === $source ) {
			$row = $this->destination_row( (int) $settings['row_destination'] );
		} elseif ( 'current' === $source ) {
			$row = $this->current_page_row();
		} elseif ( 'nearby' === $source ) {
			/*
			 * Every bike that has coordinates is printed and the visitor's
			 * browser sorts them by distance once it has their position (see
			 * citykleta-bike-rows.js). The server can't know where the visitor
			 * is, and the position never leaves their device. Bikes without
			 * lat/lng can't be ranked, so they're left out.
			 */
			$row = array(
				'title'  => 'Bicicletas cerca de ti',
				'url'    => '',
				'nearby' => true,
				'args'   => array(
					'posts_per_page' => 100,
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						'relation' => 'AND',
						array(
							'key'     => 'lat',
							'compare' => 'EXISTS',
						),
						array(
							'key'     => 'lng',
							'compare' => 'EXISTS',
						),
					),
				),
			);
		} else {
			$args = array();

			if ( ! empty( $settings['row_location'] ) ) {
				$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => 'location',
						'value'   => sanitize_text_field( $settings['row_location'] ),
						'compare' => 'LIKE',
					),
				);
			}

			$row = array(
				'title' => '',
				'url'   => '',
				'args'  => $args,
			);
		}

		if ( ! $row ) {
			return array();
		}

		$show = ! isset( $settings['show_row_title'] ) || 'yes' === $settings['show_row_title'];
		$text = isset( $settings['row_title'] ) ? trim( (string) $settings['row_title'] ) : '';

		$row['title'] = $show ? ( '' !== $text ? $text : $row['title'] ) : '';

		return array( $row );
	}

	/**
	 * A row for a type, subtype or tag: its bikes (a type includes its
	 * subtypes') and a link to its archive page.
	 *
	 * @param WP_Term $term Term.
	 * @return array
	 */
	private function term_row( $term ) {
		$link = get_term_link( $term );

		return array(
			'title' => $term->name,
			'url'   => is_wp_error( $link ) ? '' : $link,
			'args'  => array(
				'tax_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					array(
						'taxonomy' => $term->taxonomy,
						'field'    => 'term_id',
						'terms'    => (int) $term->term_id,
					),
				),
			),
		);
	}

	/**
	 * A row for a destination: the bikes its geofence holds.
	 *
	 * @param int $destination_id Destination post ID.
	 * @return array|null
	 */
	private function destination_row( $destination_id ) {
		if ( ! $destination_id || Citykleta_Destination_Post_Type::POST_TYPE !== get_post_type( $destination_id ) ) {
			return null;
		}

		return array(
			'title' => get_the_title( $destination_id ),
			'url'   => (string) get_permalink( $destination_id ),
			'args'  => Citykleta_Destination_Part_Widget::bikes_query_args( $destination_id ),
		);
	}

	/**
	 * A row that follows the page it sits on: a type/tag archive shows that
	 * term's bikes, a destination page its geofence, a bike page the bikes of
	 * its own subtype (itself left out), anything else every bike.
	 *
	 * @return array
	 */
	private function current_page_row() {
		$object = get_queried_object();

		if ( $object instanceof WP_Term && in_array( $object->taxonomy, array( Citykleta_Bike_Type_Taxonomy::TAXONOMY, Citykleta_Bike_Tag_Taxonomy::TAXONOMY ), true ) ) {
			return $this->term_row( $object );
		}

		if ( is_singular( Citykleta_Destination_Post_Type::POST_TYPE ) ) {
			$row = $this->destination_row( (int) get_queried_object_id() );

			if ( $row ) {
				return $row;
			}
		}

		if ( is_singular( Citykleta_Bike_Post_Type::POST_TYPE ) ) {
			$subtypes = Citykleta_Bike_Type_Taxonomy::bike_subtypes( (int) get_queried_object_id() );

			if ( $subtypes ) {
				$row                     = $this->term_row( reset( $subtypes ) );
				$row['title']            = 'Bicicletas similares';
				$row['args']['post__not_in'] = array( (int) get_queried_object_id() );

				return $row;
			}
		}

		return array(
			'title' => '',
			'url'   => '',
			'args'  => array(),
		);
	}

	/**
	 * Controls: its own first, then everything the grid has minus what a row
	 * has no use for.
	 */
	protected function register_controls() {
		$this->start_controls_section( 'section_rows', array( 'label' => 'Rows' ) );

		$this->add_control(
			'rows_heading',
			array(
				'label'       => 'Heading',
				'type'        => \Elementor\Controls_Manager::TEXT,
				'placeholder' => 'Optional heading above the rows',
			)
		);

		$this->add_control(
			'rows_heading_tag',
			array(
				'label'     => 'Heading HTML Tag',
				'type'      => \Elementor\Controls_Manager::SELECT,
				'default'   => 'h2',
				'options'   => array(
					'h1' => 'H1',
					'h2' => 'H2',
					'h3' => 'H3',
				),
				'condition' => array( 'rows_heading!' => '' ),
			)
		);

		$this->add_control(
			'row_title_tag',
			array(
				'label'       => 'Row Title HTML Tag',
				'type'        => \Elementor\Controls_Manager::SELECT,
				'default'     => 'h3',
				'options'     => array(
					'h2' => 'H2',
					'h3' => 'H3',
					'h4' => 'H4',
					'p'  => 'P',
				),
				'description' => 'Keep it one level below the heading above (or the page title).',
			)
		);

		$this->add_control(
			'rows_source',
			array(
				'label'       => 'Source',
				'type'        => \Elementor\Controls_Manager::SELECT,
				'default'     => 'all',
				'options'     => array(
					'all'         => 'All types (one row each)',
					'type'        => 'A type or subtype',
					'tag'         => 'A tag',
					'destination' => 'A destination',
					'nearby'      => 'Nearby (visitor\'s location)',
					'current'     => 'This page',
					'query'       => 'Custom query',
				),
				'description' => 'All types draws a row for every type that has bikes. Anything else draws a single row.',
			)
		);

		$this->add_control(
			'types',
			array(
				'label'       => 'Types',
				'type'        => \Elementor\Controls_Manager::SELECT2,
				'multiple'    => true,
				'options'     => self::type_options(),
				'description' => 'Leave empty to draw a row for every type that has bikes — a type added later gets its row on its own.',
				'condition'   => array( 'rows_source' => 'all' ),
			)
		);

		$this->add_control(
			'row_type',
			array(
				'label'     => 'Type or Subtype',
				'type'      => \Elementor\Controls_Manager::SELECT,
				'options'   => self::type_tree_options(),
				'condition' => array( 'rows_source' => 'type' ),
			)
		);

		$this->add_control(
			'row_tag',
			array(
				'label'     => 'Tag',
				'type'      => \Elementor\Controls_Manager::SELECT,
				'options'   => self::tag_options(),
				'condition' => array( 'rows_source' => 'tag' ),
			)
		);

		$this->add_control(
			'row_destination',
			array(
				'label'     => 'Destination',
				'type'      => \Elementor\Controls_Manager::SELECT,
				'options'   => self::destination_options(),
				'condition' => array( 'rows_source' => 'destination' ),
			)
		);

		$this->add_control(
			'row_location',
			array(
				'label'       => 'Location',
				'type'        => \Elementor\Controls_Manager::TEXT,
				'placeholder' => 'E.g. Vedado',
				'description' => 'Leave empty for bikes from any location. Matches partial text.',
				'condition'   => array( 'rows_source' => 'query' ),
			)
		);

		$this->add_control(
			'show_row_title',
			array(
				'label'        => 'Row Title',
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
				'condition'    => array( 'rows_source!' => 'all' ),
			)
		);

		$this->add_control(
			'row_title',
			array(
				'label'       => 'Title Text',
				'type'        => \Elementor\Controls_Manager::TEXT,
				'placeholder' => 'Empty uses the name of the source',
				'condition'   => array(
					'rows_source!'   => 'all',
					'show_row_title' => 'yes',
				),
			)
		);

		$this->add_control(
			'bikes_per_row',
			array(
				'label'   => 'Bikes Per Row',
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'default' => 12,
				'min'     => 1,
				'max'     => 50,
			)
		);

		$this->add_control(
			'show_arrows',
			array(
				'label'        => 'Arrows',
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
				'description'  => 'Only on a row that has more to scroll to. Touch screens just swipe.',
			)
		);

		$this->add_control(
			'arrows_visibility',
			array(
				'label'        => 'Show Arrows',
				'type'         => \Elementor\Controls_Manager::SELECT,
				'default'      => 'hover',
				'options'      => array(
					'hover'  => 'On hover',
					'always' => 'Always',
				),
				'prefix_class' => 'citykleta-arrows-',
				'condition'    => array( 'show_arrows' => 'yes' ),
			)
		);

		$this->add_control(
			'arrow_icon_prev',
			array(
				'label'       => 'Previous Icon',
				'type'        => \Elementor\Controls_Manager::ICONS,
				'skin'        => 'inline',
				'label_block' => false,
				'description' => 'Empty uses the built-in chevron.',
				'condition'   => array( 'show_arrows' => 'yes' ),
			)
		);

		$this->add_control(
			'arrow_icon_next',
			array(
				'label'       => 'Next Icon',
				'type'        => \Elementor\Controls_Manager::ICONS,
				'skin'        => 'inline',
				'label_block' => false,
				'description' => 'Empty uses the built-in chevron.',
				'condition'   => array( 'show_arrows' => 'yes' ),
			)
		);

		$this->end_controls_section();

		parent::register_controls();

		foreach ( array( 'columns', 'masonry', 'count', 'use_url_filters', 'posts_per_page', 'location_filter', 'grid_alignment' ) as $id ) {
			$this->remove_control( $id );
		}

		// The grid's gaps target .citykleta-bike-grid, which a row doesn't have.
		foreach ( array( 'columns_gap', 'rows_gap' ) as $id ) {
			foreach ( array( '', '_tablet', '_mobile', '_laptop', '_widescreen', '_tablet_extra', '_mobile_extra' ) as $suffix ) {
				$this->remove_control( $id . $suffix );
			}
		}

		$this->remove_control( 'section_style_layout' );

		// Kept (the Query section's order controls depend on it) but fixed.
		$this->update_control(
			'source',
			array(
				'type'    => \Elementor\Controls_Manager::HIDDEN,
				'default' => self::SOURCE_QUERY,
			)
		);

		// Card titles sit one level under the row titles.
		$this->update_control( 'title_tag', array( 'default' => 'h4' ) );

		$this->register_rows_style_controls();
	}

	/**
	 * Style tab: the rows themselves (spacing, card width, row titles, fade).
	 */
	private function register_rows_style_controls() {
		$this->start_controls_section(
			'section_style_rows',
			array(
				'label' => 'Rows',
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'rows_gap_between',
			array(
				'label'      => 'Space Between Rows',
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 120,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 40,
				),
				'selectors'  => array(
					'{{WRAPPER}} .citykleta-bike-rows' => 'gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'row_card_width',
			array(
				'label'          => 'Card Width',
				'type'           => \Elementor\Controls_Manager::SLIDER,
				'size_units'     => array( 'px' ),
				'range'          => array(
					'px' => array(
						'min' => 140,
						'max' => 600,
					),
				),
				'default'        => array(
					'unit' => 'px',
					'size' => 280,
				),
				'tablet_default' => array(
					'unit' => 'px',
					'size' => 240,
				),
				'mobile_default' => array(
					'unit' => 'px',
					'size' => 220,
				),
				'selectors'      => array(
					'{{WRAPPER}} .citykleta-bike-row__item' => 'flex: 0 0 {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'row_card_gap',
			array(
				'label'      => 'Space Between Cards',
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 80,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 24,
				),
				'selectors'  => array(
					'{{WRAPPER}} .citykleta-bike-row__track' => 'gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'heading_row_title',
			array(
				'label'     => 'Row Title',
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'row_title_color',
			array(
				'label'     => 'Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#151b1d',
				'selectors' => array(
					'{{WRAPPER}} .citykleta-bike-row__title, {{WRAPPER}} .citykleta-bike-row__title a' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'           => 'row_title_typography',
				'selector'       => '{{WRAPPER}} .citykleta-bike-row__title',
				'fields_options' => array(
					'typography'  => array( 'default' => 'custom' ),
					'font_family' => array( 'default' => 'Poppins' ),
					'font_weight' => array( 'default' => '600' ),
					'font_size'   => array(
						'default' => array(
							'unit' => 'rem',
							'size' => 1.25,
						),
					),
				),
			)
		);

		$this->add_responsive_control(
			'row_cards_top_spacing',
			array(
				'label'      => 'Spacing (top of cards)',
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 60,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 12,
				),
				'selectors'  => array(
					'{{WRAPPER}} .citykleta-bike-row__viewport' => 'margin-top: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'heading_row_edges',
			array(
				'label'     => 'Edge Fade & Arrows',
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'row_fade_color',
			array(
				'label'       => 'Fade Color',
				'type'        => \Elementor\Controls_Manager::COLOR,
				'default'     => '#ffffff',
				'description' => 'Set it to the page background so the fade melts into it.',
				'selectors'   => array(
					'{{WRAPPER}} .citykleta-bike-rows' => '--citykleta-row-fade: {{VALUE}};',
				),
			)
		);

		$this->add_responsive_control(
			'row_fade_width',
			array(
				'label'       => 'Fade Width',
				'type'        => \Elementor\Controls_Manager::SLIDER,
				'size_units'  => array( 'px' ),
				'range'       => array(
					'px' => array(
						'min' => 0,
						'max' => 240,
					),
				),
				'default'     => array(
					'unit' => 'px',
					'size' => 96,
				),
				'description' => '0 turns the edge fade off.',
				'selectors'   => array(
					'{{WRAPPER}} .citykleta-bike-row__viewport::before, {{WRAPPER}} .citykleta-bike-row__viewport::after' => 'width: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();

		$this->register_arrow_style_controls();
	}

	/**
	 * Style tab: the scroll buttons — size, shape, colors, border, shadow and
	 * where they sit.
	 */
	private function register_arrow_style_controls() {
		$btn   = '{{WRAPPER}} .citykleta-bike-row__arrow';
		$hover = '{{WRAPPER}} .citykleta-bike-row__arrow:hover';

		$this->start_controls_section(
			'section_style_arrows',
			array(
				'label'     => 'Arrows',
				'tab'       => \Elementor\Controls_Manager::TAB_STYLE,
				'condition' => array( 'show_arrows' => 'yes' ),
			)
		);

		$this->add_responsive_control(
			'arrow_size',
			array(
				'label'      => 'Button Size',
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 24,
						'max' => 96,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 44,
				),
				'selectors'  => array(
					$btn => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'arrow_icon_size',
			array(
				'label'      => 'Icon Size',
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 8,
						'max' => 64,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 20,
				),
				'selectors'  => array(
					$btn . ' svg' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
					$btn . ' i'   => 'font-size: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'arrow_edge_offset',
			array(
				'label'       => 'Distance From Edge',
				'type'        => \Elementor\Controls_Manager::SLIDER,
				'size_units'  => array( 'px' ),
				'range'       => array(
					'px' => array(
						'min' => -40,
						'max' => 80,
					),
				),
				'default'     => array(
					'unit' => 'px',
					'size' => 12,
				),
				'description' => 'Negative values push the button outside the row.',
				'selectors'   => array(
					$btn . '--prev' => 'left: {{SIZE}}{{UNIT}};',
					$btn . '--next' => 'right: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->start_controls_tabs( 'arrow_tabs' );

		$this->start_controls_tab( 'arrow_tab_normal', array( 'label' => 'Normal' ) );

		$this->add_control(
			'arrow_color',
			array(
				'label'     => 'Icon Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#151b1d',
				'selectors' => array(
					$btn => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'arrow_background',
			array(
				'label'     => 'Background Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#ffffff',
				'selectors' => array(
					$btn => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_tab();

		$this->start_controls_tab( 'arrow_tab_hover', array( 'label' => 'Hover' ) );

		$this->add_control(
			'arrow_color_hover',
			array(
				'label'     => 'Icon Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					$hover => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'arrow_background_hover',
			array(
				'label'     => 'Background Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					$hover => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'arrow_border_color_hover',
			array(
				'label'     => 'Border Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					$hover => 'border-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			array(
				'name'      => 'arrow_border',
				'selector'  => $btn,
				'separator' => 'before',
			)
		);

		$this->add_responsive_control(
			'arrow_radius',
			array(
				'label'       => 'Border Radius',
				'type'        => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units'  => array( 'px', '%' ),
				'default'     => array(
					'top'      => '50',
					'right'    => '50',
					'bottom'   => '50',
					'left'     => '50',
					'unit'     => '%',
					'isLinked' => true,
				),
				'description' => '50% is a circle, 0 a square, anything between a rounded square.',
				'selectors'   => array(
					$btn => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			array(
				'name'           => 'arrow_shadow',
				'selector'       => $btn,
				'fields_options' => array(
					'box_shadow_type' => array( 'default' => 'yes' ),
					'box_shadow'      => array(
						'default' => array(
							'horizontal' => 0,
							'vertical'   => 2,
							'blur'       => 10,
							'spread'     => 0,
							'color'      => 'rgba(0,0,0,0.18)',
						),
					),
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Prints the rows.
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();

		$wanted = ! empty( $settings['types'] ) ? array_map( 'intval', (array) $settings['types'] ) : array();
		$limit  = max( 1, (int) $settings['bikes_per_row'] );
		$tags   = array( 'h1', 'h2', 'h3', 'h4', 'p' );

		$row_tag     = in_array( $settings['row_title_tag'], $tags, true ) ? $settings['row_title_tag'] : 'h3';
		$heading_tag = in_array( $settings['rows_heading_tag'], $tags, true ) ? $settings['rows_heading_tag'] : 'h2';
		$arrows      = isset( $settings['show_arrows'] ) && 'yes' === $settings['show_arrows'];

		$order_args = array(
			'post_type'   => Citykleta_Bike_Post_Type::POST_TYPE,
			'post_status' => 'publish',
			'order'       => 'ASC' === $settings['order'] ? 'ASC' : 'DESC',
		);

		if ( 'price' === $settings['orderby'] ) {
			$order_args['orderby']  = 'meta_value_num';
			$order_args['meta_key'] = Citykleta_Bike_Meta::PRICE_META;
		} elseif ( 'title' === $settings['orderby'] ) {
			$order_args['orderby'] = 'title';
		} else {
			$order_args['orderby'] = 'date';
		}

		$rows_html = '';

		foreach ( $this->build_rows( $settings, $wanted ) as $row ) {
			$query = new WP_Query(
				array_merge(
					$order_args,
					array( 'posts_per_page' => $limit ),
					$row['args']
				)
			);

			if ( ! $query->have_posts() ) {
				continue;
			}

			$link = $row['url'];

			ob_start();

			while ( $query->have_posts() ) {
				$query->the_post();
				if ( ! empty( $row['nearby'] ) ) {
					printf(
						'<div class="citykleta-bike-row__item" data-lat="%s" data-lng="%s">',
						esc_attr( (string) get_post_meta( get_the_ID(), 'lat', true ) ),
						esc_attr( (string) get_post_meta( get_the_ID(), 'lng', true ) )
					);
				} else {
					echo '<div class="citykleta-bike-row__item">';
				}
				$this->render_card( $settings );
				echo '</div>';
			}

			wp_reset_postdata();

			$cards = ob_get_clean();

			$chevron = '<svg class="citykleta-bike-row__chevron" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M9 6l6 6-6 6"/></svg>';
			$name    = esc_html( $row['title'] );

			$rows_html .= ! empty( $row['nearby'] )
				? '<section class="citykleta-bike-row" data-citykleta-nearby="' . (int) $limit . '" hidden>'
				: '<section class="citykleta-bike-row">';

			if ( '' !== $name ) {
				$rows_html .= '<' . $row_tag . ' class="citykleta-bike-row__title">'
					. ( '' === $link ? $name : '<a href="' . esc_url( $link ) . '">' . $name . $chevron . '</a>' )
					. '</' . $row_tag . '>';
			}

			$rows_html .= '<div class="citykleta-bike-row__viewport">';

			if ( $arrows ) {
				$rows_html .= $this->arrow_button( 'prev', $settings );
			}

			$rows_html .= '<div class="citykleta-bike-row__track" tabindex="0">' . $cards . '</div>';

			if ( $arrows ) {
				$rows_html .= $this->arrow_button( 'next', $settings );
			}

			$rows_html .= '</div></section>';
		}

		if ( '' === $rows_html ) {
			if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
				echo '<p>Bike Rows: no type has bikes to show yet.</p>';
			}

			return;
		}

		echo '<div class="citykleta-bike-rows">';

		if ( '' !== (string) $settings['rows_heading'] ) {
			echo '<' . esc_html( $heading_tag ) . ' class="citykleta-bike-rows__heading">' . esc_html( $settings['rows_heading'] ) . '</' . esc_html( $heading_tag ) . '>';
		}

		echo $rows_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every piece escaped above or printed by render_card().
		echo '</div>';
	}

	/**
	 * One scroll arrow. Starts hidden: citykleta-bike-rows.js reveals it only
	 * on the side that actually has more cards.
	 *
	 * @param string $direction 'prev' or 'next'.
	 * @param array  $settings  Widget settings (for the custom icon).
	 * @return string
	 */
	private function arrow_button( $direction, array $settings ) {
		$path = 'prev' === $direction ? 'M15 6l-6 6 6 6' : 'M9 6l6 6-6 6';
		$text = 'prev' === $direction ? 'Anterior' : 'Siguiente';
		$icon = isset( $settings[ 'arrow_icon_' . $direction ] ) ? $settings[ 'arrow_icon_' . $direction ] : array();

		if ( ! empty( $icon['value'] ) ) {
			ob_start();
			\Elementor\Icons_Manager::render_icon( $icon, array( 'aria-hidden' => 'true' ) );
			$glyph = ob_get_clean();
		} else {
			$glyph = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="' . esc_attr( $path ) . '"/></svg>';
		}

		return '<button type="button" class="citykleta-bike-row__arrow citykleta-bike-row__arrow--' . esc_attr( $direction ) . '" aria-label="' . esc_attr( $text ) . '" hidden>'
			. $glyph
			. '</button>';
	}
}
