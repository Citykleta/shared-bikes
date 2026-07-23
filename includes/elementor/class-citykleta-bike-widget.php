<?php
/**
 * Elementor widget: bike grid.
 *
 * Can be dragged onto any page built with Elementor. The header/footer/
 * theme are still handled by Elementor; this widget only provides the
 * bike content.
 *
 * @package Citykleta_Shared_Bikes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Citykleta_Bike_Widget.
 */
class Citykleta_Bike_Widget extends \Elementor\Widget_Base {

	/**
	 * Internal widget name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'citykleta_bikes';
	}

	/**
	 * Title shown in the Elementor editor.
	 *
	 * @return string
	 */
	public function get_title() {
		return 'Bikes';
	}

	/**
	 * Widget icon in the Elementor panel.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-posts-grid';
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
		return array( 'bicicleta', 'bici', 'bike', 'citykleta' );
	}

	/**
	 * Controles que ve el usuario en el panel izquierdo de Elementor.
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'section_layout',
			array(
				'label' => 'Layout',
			)
		);

		$this->add_control(
			'columns',
			array(
				'label'   => 'Columns',
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => '3',
				'options' => array(
					'2' => '2',
					'3' => '3',
					'4' => '4',
					'5' => '5',
				),
			)
		);

		$this->add_control(
			'title_tag',
			array(
				'label'   => 'Title HTML Tag',
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'h2',
				'options' => array(
					'h2' => 'H2',
					'h3' => 'H3',
					'h4' => 'H4',
					'p'  => 'P',
				),
			)
		);

		$this->add_control(
			'show_location',
			array(
				'label'     => 'Location',
				'type'      => \Elementor\Controls_Manager::SWITCHER,
				'label_on'  => 'Show',
				'label_off' => 'Hide',
				'default'   => 'yes',
			)
		);

		$this->add_control(
			'show_price',
			array(
				'label'     => 'Price',
				'type'      => \Elementor\Controls_Manager::SWITCHER,
				'label_on'  => 'Show',
				'label_off' => 'Hide',
				'default'   => 'yes',
			)
		);

		$this->add_control(
			'show_owner_photo',
			array(
				'label'     => 'Owner Photo',
				'type'      => \Elementor\Controls_Manager::SWITCHER,
				'label_on'  => 'Show',
				'label_off' => 'Hide',
				'default'   => 'yes',
			)
		);

		$this->add_control(
			'heading_image',
			array(
				'label'     => 'Image',
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'image_resolution',
			array(
				'label'   => 'Image Resolution',
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'medium',
				'options' => array(
					'thumbnail' => 'Thumbnail',
					'medium'    => 'Medium',
					'large'     => 'Large',
					'full'      => 'Full',
				),
			)
		);

		$this->add_responsive_control(
			'image_ratio',
			array(
				'label'     => 'Image Ratio',
				'type'      => \Elementor\Controls_Manager::SLIDER,
				'range'     => array(
					'px' => array(
						'min'  => 0.5,
						'max'  => 2,
						'step' => 0.05,
					),
				),
				'default'   => array(
					'size' => 1.5,
				),
				'selectors' => array(
					'{{WRAPPER}} .citykleta-bike-card__image' => 'aspect-ratio: {{SIZE}};',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_content',
			array(
				'label' => 'Content',
			)
		);

		$this->add_control(
			'count',
			array(
				'label'   => 'Bike Count',
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'default' => 6,
				'min'     => 1,
				'max'     => 50,
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
			'section_filters',
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

		$this->add_control(
			'orderby',
			array(
				'label'   => 'Order By',
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'date',
				'options' => array(
					'date'  => 'Most Recent',
					'price' => 'Price',
					'title' => 'Name',
				),
			)
		);

		$this->add_control(
			'order',
			array(
				'label'   => 'Order',
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'DESC',
				'options' => array(
					'ASC'  => 'Ascending',
					'DESC' => 'Descending',
				),
			)
		);

		$this->end_controls_section();

		// From here on, "Style" tab controls — they generate CSS scoped to
		// this widget instance via "selectors", without touching render().
		$this->start_controls_section(
			'section_style_layout',
			array(
				'label' => 'Layout',
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'columns_gap',
			array(
				'label'      => 'Columns Gap',
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 100,
					),
				),
				'default'    => array(
					'size' => 24,
				),
				'selectors'  => array(
					'{{WRAPPER}} .citykleta-bike-grid' => 'column-gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'rows_gap',
			array(
				'label'      => 'Rows Gap',
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 100,
					),
				),
				'default'    => array(
					'size' => 24,
				),
				'selectors'  => array(
					'{{WRAPPER}} .citykleta-bike-grid' => 'row-gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'grid_alignment',
			array(
				'label'                => 'Alignment',
				'type'                 => \Elementor\Controls_Manager::CHOOSE,
				'options'              => array(
					'start'  => array(
						'title' => 'Left',
						'icon'  => 'eicon-h-align-left',
					),
					'center' => array(
						'title' => 'Center',
						'icon'  => 'eicon-h-align-center',
					),
					'end'    => array(
						'title' => 'Right',
						'icon'  => 'eicon-h-align-right',
					),
				),
				'default'              => 'start',
				'selectors'            => array(
					'{{WRAPPER}} .citykleta-bike-grid' => 'justify-content: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_card',
			array(
				'label' => 'Card',
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'card_border_width',
			array(
				'label'      => 'Border Width',
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px' ),
				'selectors'  => array(
					'{{WRAPPER}} .citykleta-bike-card' => 'border-style: solid; border-width: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'card_border_radius',
			array(
				'label'      => 'Border Radius',
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px', '%' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 60,
					),
					'%'  => array(
						'min' => 0,
						'max' => 50,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .citykleta-bike-card' => 'border-radius: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'card_padding',
			array(
				'label'      => 'Padding',
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px' ),
				'selectors'  => array(
					'{{WRAPPER}} .citykleta-bike-card' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'card_content_padding',
			array(
				'label'      => 'Content Padding',
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px' ),
				'default'    => array(
					'top'      => 16,
					'right'    => 16,
					'bottom'   => 16,
					'left'     => 16,
					'unit'     => 'px',
					'isLinked' => true,
				),
				'selectors'  => array(
					'{{WRAPPER}} .citykleta-bike-card__body' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->start_controls_tabs( 'card_style_tabs' );

		$this->start_controls_tab(
			'card_style_tab_normal',
			array(
				'label' => 'Normal',
			)
		);

		$this->add_control(
			'card_bg_color',
			array(
				'label'     => 'Background Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .citykleta-bike-card' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'card_border_color',
			array(
				'label'     => 'Border Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .citykleta-bike-card' => 'border-color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'card_shadow',
				'label'    => 'Box Shadow',
				'selector' => '{{WRAPPER}} .citykleta-bike-card',
			)
		);

		$this->end_controls_tab();

		$this->start_controls_tab(
			'card_style_tab_hover',
			array(
				'label' => 'Hover',
			)
		);

		$this->add_control(
			'card_bg_color_hover',
			array(
				'label'     => 'Background Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .citykleta-bike-card:hover' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'card_border_color_hover',
			array(
				'label'     => 'Border Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .citykleta-bike-card:hover' => 'border-color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'card_shadow_hover',
				'label'    => 'Box Shadow',
				'selector' => '{{WRAPPER}} .citykleta-bike-card:hover',
			)
		);

		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_content',
			array(
				'label' => 'Content',
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'heading_title',
			array(
				'label'     => 'Title',
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'title_color',
			array(
				'label'     => 'Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .citykleta-bike-card__title' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'title_typography',
				'label'    => 'Typography',
				'selector' => '{{WRAPPER}} .citykleta-bike-card__title',
			)
		);

		$this->add_responsive_control(
			'title_spacing',
			array(
				'label'      => 'Spacing',
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 40,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .citykleta-bike-card__title' => 'margin-bottom: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'heading_location',
			array(
				'label'     => 'Location',
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'location_color',
			array(
				'label'     => 'Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .citykleta-bike-card__location' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'location_typography',
				'label'    => 'Typography',
				'selector' => '{{WRAPPER}} .citykleta-bike-card__location',
			)
		);

		$this->add_responsive_control(
			'location_spacing',
			array(
				'label'      => 'Spacing',
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 40,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .citykleta-bike-card__location' => 'margin-bottom: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'heading_price',
			array(
				'label'     => 'Price',
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'price_color',
			array(
				'label'     => 'Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .citykleta-bike-card__price' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'price_typography',
				'label'    => 'Typography',
				'selector' => '{{WRAPPER}} .citykleta-bike-card__price',
			)
		);

		$this->add_responsive_control(
			'price_spacing',
			array(
				'label'      => 'Spacing (top)',
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 40,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .citykleta-bike-card__price' => 'margin-top: {{SIZE}}{{UNIT}}; margin-bottom: 0;',
				),
			)
		);

		$this->add_control(
			'heading_badge',
			array(
				'label'     => 'Availability Badge',
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'badge_available_color',
			array(
				'label'     => '"Available" Background',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .citykleta-bike-card__badge--available' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'badge_unavailable_color',
			array(
				'label'     => '"Unavailable" Background',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .citykleta-bike-card__badge--unavailable' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_owner',
			array(
				'label' => 'Owner Photo',
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'owner_photo_size',
			array(
				'label'      => 'Size',
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 12,
						'max' => 80,
					),
				),
				'default'    => array(
					'size' => 20,
					'unit' => 'px',
				),
				'selectors'  => array(
					'{{WRAPPER}} .citykleta-bike-card__owner' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'owner_photo_position',
			array(
				'label'                => 'Position',
				'type'                 => \Elementor\Controls_Manager::CHOOSE,
				'options'              => array(
					'left'   => array(
						'title' => 'Left',
						'icon'  => 'eicon-h-align-left',
					),
					'center' => array(
						'title' => 'Center',
						'icon'  => 'eicon-h-align-center',
					),
					'right'  => array(
						'title' => 'Right',
						'icon'  => 'eicon-h-align-right',
					),
				),
				'default'              => 'left',
				// Uses the --citykleta-owner-offset variable (set by the
				// owner_photo_padding control below) so position and spacing
				// work together without clobbering each other.
				'selectors_dictionary' => array(
					'left'   => 'left: var(--citykleta-owner-offset, 0.5rem); right: auto; bottom: var(--citykleta-owner-offset, 0.5rem); transform: none;',
					'center' => 'left: 50%; right: auto; bottom: var(--citykleta-owner-offset, 0.5rem); transform: translateX(-50%);',
					'right'  => 'right: var(--citykleta-owner-offset, 0.5rem); left: auto; bottom: var(--citykleta-owner-offset, 0.5rem); transform: none;',
				),
				'selectors'            => array(
					'{{WRAPPER}} .citykleta-bike-card__owner' => '{{VALUE}}',
				),
			)
		);

		$this->add_responsive_control(
			'owner_photo_padding',
			array(
				'label'      => 'Edge Spacing',
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 40,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .citykleta-bike-card__owner' => '--citykleta-owner-offset: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_image',
			array(
				'label' => 'Image',
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'image_border_radius',
			array(
				'label'      => 'Border Radius',
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .citykleta-bike-card__image img, {{WRAPPER}} .citykleta-bike-card__image-placeholder' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'image_spacing',
			array(
				'label'      => 'Spacing',
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 60,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .citykleta-bike-card__image' => 'margin-bottom: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->start_controls_tabs( 'image_style_tabs' );

		$this->start_controls_tab(
			'image_style_tab_normal',
			array(
				'label' => 'Normal',
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Css_Filter::get_type(),
			array(
				'name'     => 'image_css_filters',
				'selector' => '{{WRAPPER}} .citykleta-bike-card__image img',
			)
		);

		$this->end_controls_tab();

		$this->start_controls_tab(
			'image_style_tab_hover',
			array(
				'label' => 'Hover',
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Css_Filter::get_type(),
			array(
				'name'     => 'image_css_filters_hover',
				'selector' => '{{WRAPPER}} .citykleta-bike-card:hover .citykleta-bike-card__image img',
			)
		);

		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->end_controls_section();
	}

	/**
	 * Dibuja el grid de bicis en el front-end (y en la vista previa del editor).
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();

		$args = array(
			'post_type'      => Citykleta_Bike_Post_Type::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => (int) $settings['count'],
			'order'          => 'DESC' === $settings['order'] ? 'DESC' : 'ASC',
		);

		if ( 'price' === $settings['orderby'] ) {
			$args['orderby']  = 'meta_value_num';
			$args['meta_key'] = 'price_per_hour';
		} elseif ( 'title' === $settings['orderby'] ) {
			$args['orderby'] = 'title';
		} else {
			$args['orderby'] = 'date';
		}

		$meta_query = array();

		if ( 'yes' === $settings['only_available'] ) {
			$meta_query[] = array(
				'key'   => 'available',
				'value' => '1',
			);
		}

		if ( ! empty( $settings['location_filter'] ) ) {
			$meta_query[] = array(
				'key'     => 'location',
				'value'   => sanitize_text_field( $settings['location_filter'] ),
				'compare' => 'LIKE',
			);
		}

		if ( $meta_query ) {
			$args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		$query = new WP_Query( $args );

		if ( ! $query->have_posts() ) {
			echo '<p>No hay bicicletas para mostrar.</p>';
			return;
		}

		// The CSS is enqueued early in Citykleta_Elementor::enqueue_styles(),
		// not here — see that class for why.
		printf( '<div class="citykleta-bike-grid citykleta-bike-grid--cols-%d">', (int) $settings['columns'] );

		while ( $query->have_posts() ) {
			$query->the_post();

			$price       = get_post_meta( get_the_ID(), 'price_per_hour', true );
			$location    = get_post_meta( get_the_ID(), 'location', true );
			$available   = get_post_meta( get_the_ID(), 'available', true );
			$owner_name  = get_post_meta( get_the_ID(), 'owner_name', true );
			$owner_photo = get_post_meta( get_the_ID(), 'owner_photo', true );

			$allowed_tags = array( 'h2', 'h3', 'h4', 'p' );
			$title_tag    = in_array( $settings['title_tag'], $allowed_tags, true ) ? $settings['title_tag'] : 'h2';
			?>
			<a class="citykleta-bike-card" href="<?php the_permalink(); ?>">
				<div class="citykleta-bike-card__image">
					<?php if ( has_post_thumbnail() ) : ?>
						<?php the_post_thumbnail( $settings['image_resolution'] ); ?>
					<?php else : ?>
						<div class="citykleta-bike-card__image-placeholder"></div>
					<?php endif; ?>

					<?php if ( '1' === (string) $available ) : ?>
						<span class="citykleta-bike-card__badge citykleta-bike-card__badge--available">Disponible</span>
					<?php else : ?>
						<span class="citykleta-bike-card__badge citykleta-bike-card__badge--unavailable">No disponible</span>
					<?php endif; ?>

					<?php if ( $owner_photo && 'yes' === $settings['show_owner_photo'] ) : ?>
						<img class="citykleta-bike-card__owner" src="<?php echo esc_url( $owner_photo ); ?>" alt="<?php echo esc_attr( $owner_name ); ?>" width="20" height="20" />
					<?php endif; ?>
				</div>

				<div class="citykleta-bike-card__body">
					<<?php echo esc_html( $title_tag ); ?> class="citykleta-bike-card__title"><?php the_title(); ?></<?php echo esc_html( $title_tag ); ?>>

					<?php if ( $location && 'yes' === $settings['show_location'] ) : ?>
						<p class="citykleta-bike-card__location"><?php echo esc_html( $location ); ?></p>
					<?php endif; ?>

					<?php if ( $price && 'yes' === $settings['show_price'] ) : ?>
						<p class="citykleta-bike-card__price"><?php echo esc_html( $price ); ?> CUP/hora</p>
					<?php endif; ?>
				</div>
			</a>
			<?php
		}

		echo '</div>';

		wp_reset_postdata();
	}
}
