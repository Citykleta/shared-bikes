<?php
/**
 * Elementor widget: bike search bar.
 *
 * Sends a GET to the bike archive (/bikes/) with location and dates as
 * parameters. Only location actually filters results today — dates don't
 * have an availability/booking system behind them yet, so they just travel
 * in the URL, ready for when one exists.
 *
 * The widget's interface text (labels, sections, descriptions) is in
 * English to feel consistent with Elementor's native widgets. The text
 * seen by the site's visitors (default values like "Dónde", "Buscar",
 * etc.) stays in Spanish.
 *
 * @package Citykleta_Shared_Bikes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Citykleta_Search_Widget.
 */
class Citykleta_Search_Widget extends \Elementor\Widget_Base {

	/**
	 * Internal widget name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'citykleta_search';
	}

	/**
	 * Title shown in the Elementor editor.
	 *
	 * @return string
	 */
	public function get_title() {
		return 'Bike Search';
	}

	/**
	 * Widget icon in the Elementor panel.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-site-search';
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
		return array( 'buscar', 'búsqueda', 'search', 'bicicleta', 'bici', 'bike', 'citykleta' );
	}

	/**
	 * Controles que ve el usuario en el panel izquierdo de Elementor.
	 */
	protected function register_controls() {
		$this->register_content_controls();
		$this->register_style_controls();
	}

	/**
	 * Controles de la pestaña Content.
	 */
	private function register_content_controls() {
		$this->start_controls_section(
			'section_content',
			array(
				'label' => 'Content',
			)
		);

		$this->add_control(
			'results_url',
			array(
				'label'       => 'Results page',
				'type'        => \Elementor\Controls_Manager::URL,
				'placeholder' => 'Leave empty to use /bikes/',
				'description' => 'Where the search is sent. Empty = the bikes archive.',
			)
		);

		$this->add_control(
			'show_labels',
			array(
				'label'     => 'Show field labels',
				'type'      => \Elementor\Controls_Manager::SWITCHER,
				'label_on'  => 'Show',
				'label_off' => 'Hide',
				'default'   => 'yes',
			)
		);

		$this->add_control(
			'heading_location',
			array(
				'label' => 'Location',
				'type'  => \Elementor\Controls_Manager::HEADING,
			)
		);

		$this->add_control(
			'location_label',
			array(
				'label'     => 'Label',
				'type'      => \Elementor\Controls_Manager::TEXT,
				'default'   => 'Dónde',
				'condition' => array(
					'show_labels' => 'yes',
				),
			)
		);

		$this->add_control(
			'location_placeholder',
			array(
				'label'   => 'Placeholder',
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => 'Ciudad, barrio, dirección',
			)
		);

		$this->add_control(
			'heading_dates',
			array(
				'label'     => 'Dates',
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'show_dates',
			array(
				'label'     => 'Show dates',
				'type'      => \Elementor\Controls_Manager::SWITCHER,
				'label_on'  => 'Show',
				'label_off' => 'Hide',
				'default'   => 'yes',
			)
		);

		$this->add_control(
			'dates_label',
			array(
				'label'     => 'Label',
				'type'      => \Elementor\Controls_Manager::TEXT,
				'default'   => 'Fechas',
				'condition' => array(
					'show_dates'  => 'yes',
					'show_labels' => 'yes',
				),
			)
		);

		$this->add_control(
			'dates_placeholder',
			array(
				'label'     => 'Placeholder',
				'type'      => \Elementor\Controls_Manager::TEXT,
				'default'   => 'Selecciona fechas',
				'condition' => array(
					'show_dates' => 'yes',
				),
			)
		);

		$this->add_control(
			'dates_note',
			array(
				'type'            => \Elementor\Controls_Manager::RAW_HTML,
				'raw'             => 'Dates don\'t filter results yet — there is no date-based availability system. They are sent in the URL, ready for when it exists.',
				'content_classes' => 'elementor-descriptor',
				'condition'       => array(
					'show_dates' => 'yes',
				),
			)
		);

		$this->add_control(
			'heading_electric',
			array(
				'label'     => 'Electric bikes',
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'show_electric_filter',
			array(
				'label'     => 'Show electric bikes checkbox',
				'type'      => \Elementor\Controls_Manager::SWITCHER,
				'label_on'  => 'Show',
				'label_off' => 'Hide',
				'default'   => 'yes',
			)
		);

		$this->add_control(
			'electric_filter_style',
			array(
				'label'     => 'Filter style',
				'type'      => \Elementor\Controls_Manager::CHOOSE,
				'options'   => array(
					'text' => array(
						'title' => 'With text',
						'icon'  => 'eicon-t-letter',
					),
					'icon' => array(
						'title' => 'Icon only',
						'icon'  => 'eicon-star',
					),
				),
				'default'   => 'text',
				'condition' => array(
					'show_electric_filter' => 'yes',
				),
			)
		);

		$this->add_control(
			'electric_filter_label',
			array(
				'label'       => 'Label',
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => 'Eléctricas',
				'description' => 'Also used as the icon\'s accessible label when "Icon only" is selected above.',
				'condition'   => array(
					'show_electric_filter' => 'yes',
				),
			)
		);

		$this->add_control(
			'electric_filter_icon',
			array(
				'label'     => 'Icon',
				'type'      => \Elementor\Controls_Manager::ICONS,
				'default'   => array(
					'value'   => 'fas fa-bolt',
					'library' => 'fa-solid',
				),
				'condition' => array(
					'show_electric_filter' => 'yes',
					'electric_filter_style' => 'icon',
				),
			)
		);

		$this->add_control(
			'electric_filter_default',
			array(
				'label'     => 'Checked by default',
				'type'      => \Elementor\Controls_Manager::SWITCHER,
				'label_on'  => 'Yes',
				'label_off' => 'No',
				'default'   => 'yes',
				'condition' => array(
					'show_electric_filter' => 'yes',
				),
			)
		);

		$this->add_control(
			'heading_usage',
			array(
				'label'     => 'Usage type',
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'show_usage_filter',
			array(
				'label'     => 'Show usage type field',
				'type'      => \Elementor\Controls_Manager::SWITCHER,
				'label_on'  => 'Show',
				'label_off' => 'Hide',
				'default'   => 'yes',
			)
		);

		$this->add_control(
			'usage_label',
			array(
				'label'     => 'Label',
				'type'      => \Elementor\Controls_Manager::TEXT,
				'default'   => 'Categoría',
				'condition' => array(
					'show_usage_filter' => 'yes',
					'show_labels'       => 'yes',
				),
			)
		);

		$this->add_control(
			'heading_button',
			array(
				'label'     => 'Button',
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'button_style',
			array(
				'label'   => 'Button style',
				'type'    => \Elementor\Controls_Manager::CHOOSE,
				'options' => array(
					'icon' => array(
						'title' => 'Icon only',
						'icon'  => 'eicon-editor-arrow-right',
					),
					'text' => array(
						'title' => 'With text',
						'icon'  => 'eicon-t-letter',
					),
				),
				'default' => 'icon',
			)
		);

		$this->add_control(
			'button_text',
			array(
				'label'     => 'Button text',
				'type'      => \Elementor\Controls_Manager::TEXT,
				'default'   => 'Buscar',
				'condition' => array(
					'button_style' => 'text',
				),
			)
		);

		$this->add_control(
			'button_icon',
			array(
				'label'     => 'Button icon',
				'type'      => \Elementor\Controls_Manager::ICONS,
				'default'   => array(
					'value'   => 'fas fa-search',
					'library' => 'fa-solid',
				),
				'condition' => array(
					'button_style' => 'icon',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Controles de la pestaña Style.
	 */
	private function register_style_controls() {
		$this->start_controls_section(
			'section_style_container',
			array(
				'label' => 'Container',
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'container_bg_color',
			array(
				'label'     => 'Background color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#ffffff',
				'selectors' => array(
					'{{WRAPPER}} .citykleta-search-bar' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			array(
				'name'     => 'container_border',
				'label'    => 'Border',
				'selector' => '{{WRAPPER}} .citykleta-search-bar',
			)
		);

		$this->add_responsive_control(
			'container_border_radius',
			array(
				'label'      => 'Border radius',
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'default'    => array(
					'top'      => 999,
					'right'    => 999,
					'bottom'   => 999,
					'left'     => 999,
					'unit'     => 'px',
					'isLinked' => true,
				),
				'selectors'  => array(
					'{{WRAPPER}} .citykleta-search-bar' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'container_shadow',
				'label'    => 'Shadow',
				'selector' => '{{WRAPPER}} .citykleta-search-bar',
			)
		);

		$this->add_responsive_control(
			'container_padding',
			array(
				'label'      => 'Padding',
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 40,
					),
				),
				'default'    => array(
					'size' => 10,
				),
				'selectors'  => array(
					'{{WRAPPER}} .citykleta-search-bar' => 'padding: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'container_gap',
			array(
				'label'          => 'Spacing',
				'type'           => \Elementor\Controls_Manager::SLIDER,
				'size_units'     => array( 'px' ),
				'range'          => array(
					'px' => array(
						'min' => 0,
						'max' => 40,
					),
				),
				'default'        => array(
					'size' => 10,
				),
				// On mobile the bar stacks vertically and the dividers are
				// hidden (see search-bar.css), so this gap is the only thing
				// separating one field from the next — it can't start at 0.
				'mobile_default' => array(
					'size' => 8,
				),
				'description'    => 'Space between fields, and between the last field and the button.',
				'selectors'      => array(
					'{{WRAPPER}} .citykleta-search-bar' => 'gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_fields',
			array(
				'label' => 'Fields',
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'label_color',
			array(
				'label'     => 'Label color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .citykleta-search-bar__label' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'label_typography',
				'label'    => 'Label typography',
				'selector' => '{{WRAPPER}} .citykleta-search-bar__label',
			)
		);

		$this->add_control(
			'input_color',
			array(
				'label'     => 'Text color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .citykleta-search-bar__input' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'placeholder_color',
			array(
				'label'     => 'Placeholder color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .citykleta-search-bar__input::placeholder' => 'color: {{VALUE}}; opacity: 1;',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'input_typography',
				'label'    => 'Text typography',
				'selector' => '{{WRAPPER}} .citykleta-search-bar__input',
			)
		);

		$this->add_control(
			'divider_color',
			array(
				'label'     => 'Divider color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .citykleta-search-bar__divider' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_responsive_control(
			'divider_height',
			array(
				'label'      => 'Divider height',
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px', '%' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 100,
					),
					'%'  => array(
						'min' => 0,
						'max' => 100,
					),
				),
				'default'    => array(
					'size' => 32,
					'unit' => 'px',
				),
				'selectors'  => array(
					'{{WRAPPER}} .citykleta-search-bar__divider' => 'height: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			array(
				'name'           => 'field_border',
				'label'          => 'Field border',
				// The electric checkbox is excluded here rather than undone
				// later in CSS: it has no input inside, so a box around it
				// reads as an empty field. Fighting it from search-bar.css
				// doesn't work — {{WRAPPER}} expands to three classes
				// (.elementor-N .elementor-element.elementor-element-ID), so
				// Elementor's rule outweighs anything reasonable there.
				'selector'       => '{{WRAPPER}} .citykleta-search-bar__field:not(.citykleta-search-bar__field--electric)',
				'fields_options' => array(
					'border' => array(
						'default' => 'solid',
					),
					'width'  => array(
						'default' => array(
							'top'      => '1',
							'right'    => '1',
							'bottom'   => '1',
							'left'     => '1',
							'unit'     => 'px',
							'isLinked' => true,
						),
					),
					'color'  => array(
						'default' => '#e5e0d3',
					),
				),
			)
		);

		$this->add_responsive_control(
			'field_border_radius',
			array(
				'label'      => 'Field border radius',
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .citykleta-search-bar__field' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_button',
			array(
				'label' => 'Button',
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'button_bg_color',
			array(
				'label'     => 'Background color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#fed94c',
				'selectors' => array(
					'{{WRAPPER}} .citykleta-search-bar__button' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'button_bg_color_hover',
			array(
				'label'     => 'Background color (hover)',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .citykleta-search-bar__button:hover' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'button_color',
			array(
				'label'     => 'Text/icon color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#151b1d',
				'selectors' => array(
					'{{WRAPPER}} .citykleta-search-bar__button'    => 'color: {{VALUE}};',
					'{{WRAPPER}} .citykleta-search-bar__button svg' => 'fill: {{VALUE}};',
					'{{WRAPPER}} .citykleta-search-bar__button i'  => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'button_color_hover',
			array(
				'label'     => 'Text/icon color (hover)',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .citykleta-search-bar__button:hover'     => 'color: {{VALUE}};',
					'{{WRAPPER}} .citykleta-search-bar__button:hover svg' => 'fill: {{VALUE}};',
					'{{WRAPPER}} .citykleta-search-bar__button:hover i'   => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'      => 'button_typography',
				'label'     => 'Typography',
				'selector'  => '{{WRAPPER}} .citykleta-search-bar__button',
				'condition' => array(
					'button_style' => 'text',
				),
			)
		);

		$this->add_responsive_control(
			'button_icon_size',
			array(
				'label'      => 'Icon size',
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 10,
						'max' => 40,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .citykleta-search-bar__button svg' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
					'{{WRAPPER}} .citykleta-search-bar__button i'   => 'font-size: {{SIZE}}{{UNIT}};',
				),
				'condition'  => array(
					'button_style' => 'icon',
				),
			)
		);

		$this->add_responsive_control(
			'button_size',
			array(
				'label'      => 'Button size (icon style)',
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 30,
						'max' => 100,
					),
				),
				'default'    => array(
					'size' => 52,
				),
				'selectors'  => array(
					'{{WRAPPER}} .citykleta-search-bar__button--icon' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
				),
				'condition'  => array(
					'button_style' => 'icon',
				),
			)
		);

		$this->add_responsive_control(
			'button_border_radius',
			array(
				'label'      => 'Border radius',
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'default'    => array(
					'top'      => 999,
					'right'    => 999,
					'bottom'   => 999,
					'left'     => 999,
					'unit'     => 'px',
					'isLinked' => true,
				),
				'selectors'  => array(
					'{{WRAPPER}} .citykleta-search-bar__button' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Dibuja la barra de búsqueda en el front-end (y en la vista previa del editor).
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();

		$action = ! empty( $settings['results_url']['url'] )
			? $settings['results_url']['url']
			: get_post_type_archive_link( Citykleta_Bike_Post_Type::POST_TYPE );

		if ( empty( $action ) ) {
			$action = home_url( '/' );
		}
		?>
		<form class="citykleta-search-bar" method="get" action="<?php echo esc_url( $action ); ?>">
			<div class="citykleta-search-bar__field citykleta-search-bar__field--location">
				<div class="citykleta-search-bar__field-inner">
					<?php if ( 'yes' === $settings['show_labels'] ) : ?>
						<label class="citykleta-search-bar__label" for="citykleta-location-<?php echo esc_attr( $this->get_id() ); ?>"><?php echo esc_html( $settings['location_label'] ); ?></label>
					<?php endif; ?>
					<input
						class="citykleta-search-bar__input"
						type="text"
						id="citykleta-location-<?php echo esc_attr( $this->get_id() ); ?>"
						name="location"
						placeholder="<?php echo esc_attr( $settings['location_placeholder'] ); ?>"
					/>
				</div>
			</div>

			<?php if ( 'yes' === $settings['show_usage_filter'] ) : ?>
				<div class="citykleta-search-bar__divider"></div>
				<div class="citykleta-search-bar__field citykleta-search-bar__field--usage">
					<div class="citykleta-search-bar__field-inner">
						<?php if ( 'yes' === $settings['show_labels'] ) : ?>
							<label class="citykleta-search-bar__label" for="citykleta-usage-<?php echo esc_attr( $this->get_id() ); ?>"><?php echo esc_html( $settings['usage_label'] ); ?></label>
						<?php endif; ?>
						<select
							class="citykleta-search-bar__input citykleta-search-bar__usage"
							id="citykleta-usage-<?php echo esc_attr( $this->get_id() ); ?>"
							name="usage"
						>
							<option value="">Todas</option>
							<?php
							$usage_terms = get_terms(
								array(
									'taxonomy'   => Citykleta_Bike_Usage_Taxonomy::TAXONOMY,
									'hide_empty' => false,
								)
							);

							// Sorted in PHP instead of via 'meta_key' in the query above —
							// 'meta_key' turns into an INNER JOIN in WP_Term_Query, which
							// silently drops any usage term that doesn't have a
							// "display_order" meta value saved yet.
							if ( ! is_wp_error( $usage_terms ) ) {
								usort(
									$usage_terms,
									function ( $a, $b ) {
										$order_a = (int) get_term_meta( $a->term_id, Citykleta_Bike_Usage_Taxonomy::META_KEY_ORDER, true );
										$order_b = (int) get_term_meta( $b->term_id, Citykleta_Bike_Usage_Taxonomy::META_KEY_ORDER, true );

										// 0 means "no order set" (default) — those go after
										// everything with an explicit order, instead of tying
										// for first place.
										if ( 0 === $order_a && 0 !== $order_b ) {
											return 1;
										}
										if ( 0 !== $order_a && 0 === $order_b ) {
											return -1;
										}

										if ( $order_a === $order_b ) {
											return strcasecmp( $a->name, $b->name );
										}

										return $order_a <=> $order_b;
									}
								);
							}

							if ( ! is_wp_error( $usage_terms ) ) :
								foreach ( $usage_terms as $usage_term ) :
									// Appends the bike types included in this usage (checked by
									// the admin on the term's edit screen, see
									// Citykleta_Bike_Usage_Taxonomy::META_KEY) after the manual
									// description, so visitors see what's covered without the
									// admin having to type it out by hand.
									$description = $usage_term->description;
									$type_ids    = get_term_meta( $usage_term->term_id, Citykleta_Bike_Usage_Taxonomy::META_KEY, true );
									$type_ids    = is_array( $type_ids ) ? array_map( 'intval', $type_ids ) : array();

									if ( $type_ids ) {
										$types = get_terms(
											array(
												'taxonomy'   => Citykleta_Bike_Type_Taxonomy::TAXONOMY,
												'include'    => $type_ids,
												'orderby'    => 'include',
												'hide_empty' => false,
											)
										);

										if ( ! is_wp_error( $types ) && $types ) {
											$description = implode(
												' ',
												array_filter(
													array(
														$description,
														'Inc. ' . implode( ', ', wp_list_pluck( $types, 'name' ) ),
													)
												)
											);
										}
									}

									$description = rtrim( $description );
									if ( '' !== $description && '.' !== substr( $description, -1 ) ) {
										$description .= '.';
									}
									?>
									<option value="<?php echo esc_attr( $usage_term->slug ); ?>" data-description="<?php echo esc_attr( $description ); ?>">
										<?php echo esc_html( $usage_term->name ); ?>
									</option>
									<?php
								endforeach;
							endif;
							?>
						</select>
					</div>
				</div>
			<?php endif; ?>

			<?php if ( 'yes' === $settings['show_dates'] ) : ?>
				<div class="citykleta-search-bar__divider"></div>
				<div class="citykleta-search-bar__field citykleta-search-bar__field--date">
					<?php // data-citykleta-range / data-citykleta-date: el contrato que lee citykleta-search-widget.js para armar el calendario de rango. El formulario de solicitud imprime los mismos atributos, así que los dos campos son literalmente el mismo selector. ?>
					<div class="citykleta-search-bar__field-inner" data-citykleta-range>
						<?php if ( 'yes' === $settings['show_labels'] ) : ?>
							<label class="citykleta-search-bar__label" for="citykleta-daterange-<?php echo esc_attr( $this->get_id() ); ?>"><?php echo esc_html( $settings['dates_label'] ); ?></label>
						<?php endif; ?>
						<input
							class="citykleta-search-bar__input citykleta-search-bar__daterange citykleta-daterange"
							type="text"
							id="citykleta-daterange-<?php echo esc_attr( $this->get_id() ); ?>"
							placeholder="<?php echo esc_attr( $settings['dates_placeholder'] ); ?>"
							autocomplete="off"
							readonly="readonly"
						/>
						<input type="hidden" class="citykleta-search-bar__date-from" name="from" data-citykleta-date="from" />
						<input type="hidden" class="citykleta-search-bar__date-to" name="to" data-citykleta-date="to" />
					</div>
				</div>
			<?php endif; ?>

			<?php if ( 'yes' === $settings['show_electric_filter'] ) : ?>
				<div class="citykleta-search-bar__divider"></div>
				<div class="citykleta-search-bar__field citykleta-search-bar__field--electric">
					<label
						class="citykleta-search-bar__checkbox-label<?php echo 'icon' === $settings['electric_filter_style'] ? ' citykleta-search-bar__checkbox-label--icon' : ''; ?>"
						for="citykleta-electric-<?php echo esc_attr( $this->get_id() ); ?>"
						<?php if ( 'icon' === $settings['electric_filter_style'] ) : ?>
							title="<?php echo esc_attr( $settings['electric_filter_label'] ); ?>"
						<?php endif; ?>
					>
						<?php /* Input oculto con valor "0" antes del checkbox: si el checkbox queda
						sin marcar, este es el único valor que se manda para "electric",
						así el servidor puede distinguir "sin marcar" de "formulario nunca enviado". */ ?>
						<input type="hidden" name="electric" value="0" />
						<input
							class="citykleta-search-bar__checkbox"
							type="checkbox"
							id="citykleta-electric-<?php echo esc_attr( $this->get_id() ); ?>"
							name="electric"
							value="1"
							<?php checked( 'yes', $settings['electric_filter_default'] ); ?>
						/>
						<?php if ( 'icon' === $settings['electric_filter_style'] ) : ?>
							<span class="citykleta-search-bar__checkbox-icon" aria-hidden="true">
								<?php \Elementor\Icons_Manager::render_icon( $settings['electric_filter_icon'] ); ?>
							</span>
							<span class="citykleta-search-bar__visually-hidden"><?php echo esc_html( $settings['electric_filter_label'] ); ?></span>
						<?php else : ?>
							<span class="citykleta-search-bar__checkbox-text"><?php echo esc_html( $settings['electric_filter_label'] ); ?></span>
						<?php endif; ?>
					</label>
				</div>
			<?php endif; ?>

			<?php if ( 'text' === $settings['button_style'] ) : ?>
				<button type="submit" class="citykleta-search-bar__button citykleta-search-bar__button--text">
					<?php echo esc_html( $settings['button_text'] ); ?>
				</button>
			<?php else : ?>
				<button type="submit" class="citykleta-search-bar__button citykleta-search-bar__button--icon" aria-label="<?php esc_attr_e( 'Buscar', 'citykleta-shared-bikes' ); ?>">
					<?php \Elementor\Icons_Manager::render_icon( $settings['button_icon'], array( 'aria-hidden' => 'true' ) ); ?>
				</button>
			<?php endif; ?>
		</form>
		<?php
	}
}
