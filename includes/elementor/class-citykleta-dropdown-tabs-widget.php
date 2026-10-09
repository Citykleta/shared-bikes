<?php
/**
 * "Dropdown Tabs" widget: tabs switched from a dropdown selector instead of a
 * row of buttons.
 *
 * It is a NESTED widget, like Elementor's own Tabs: each option owns a
 * container the user fills right on the canvas — e.g. one Group Chat per tab
 * ("Rodada del sábado", "Taller comunitario"…). No saved templates involved.
 * The containers are the widget's children; Elementor creates one per
 * repeater row. The editor also needs a JS element type for that —
 * assets/js/citykleta-dropdown-tabs-editor.js, the same shape as Elementor's
 * modules/nested-tabs. Both lean on Elementor's nested-elements module, which
 * is internal API: after an Elementor update, check that tabs can still be
 * added and filled in the editor.
 *
 * Every panel is printed by the server and only the first is visible, so
 * without JS the visitor still gets the first tab. citykleta-dropdown-tabs.js
 * drives the selector (a listbox, keyboard included).
 *
 * @package Citykleta_Shared_Bikes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Citykleta_Dropdown_Tabs_Widget.
 */
class Citykleta_Dropdown_Tabs_Widget extends \Elementor\Modules\NestedElements\Base\Widget_Nested_Base {

	/**
	 * Internal widget name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'citykleta_dropdown_tabs';
	}

	/**
	 * Title shown in the Elementor editor.
	 *
	 * @return string
	 */
	public function get_title() {
		return 'Dropdown Tabs';
	}

	/**
	 * Widget icon in the Elementor panel.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-select';
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
		return array( 'tabs', 'pestañas', 'dropdown', 'select', 'selector', 'combobox', 'desplegable', 'nested' );
	}

	/**
	 * One empty container, the content area of a tab.
	 *
	 * @param int $index 1-based tab position.
	 * @return array
	 */
	private function tab_container( $index ) {
		return array(
			'elType'   => 'container',
			'settings' => array(
				'_title'        => sprintf( 'Tab #%d', $index ),
				'content_width' => 'full',
			),
		);
	}

	/**
	 * Containers a freshly dropped widget starts with, one per default tab.
	 *
	 * @return array
	 */
	protected function get_default_children_elements() {
		return array( $this->tab_container( 1 ), $this->tab_container( 2 ) );
	}

	/**
	 * Repeater field that names a tab (what the Navigator shows).
	 *
	 * @return string
	 */
	protected function get_default_repeater_title_setting_key() {
		return 'label';
	}

	/**
	 * Title of a container created for a new tab; %d is its position.
	 *
	 * @return string
	 */
	protected function get_default_children_title() {
		return 'Tab #%d';
	}

	/**
	 * Where the editor puts the tabs' containers inside the widget's markup.
	 *
	 * @return string
	 */
	protected function get_default_children_placeholder_selector() {
		return '.citykleta-dtabs__panels';
	}

	/**
	 * Controls shown in Elementor's left panel.
	 */
	protected function register_controls() {
		$this->register_content_controls();
		$this->register_selector_style_controls();
		$this->register_menu_style_controls();
		$this->register_panel_style_controls();
	}

	/**
	 * Content tab: the tabs and the selector's own text.
	 */
	private function register_content_controls() {
		$this->start_controls_section( 'section_tabs', array( 'label' => 'Tabs' ) );

		$repeater = new \Elementor\Repeater();

		$repeater->add_control(
			'label',
			array(
				'label'       => 'Label',
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => 'Tab',
				'label_block' => true,
			)
		);

		// Nested repeater: adding a row also adds the container that tab's
		// content goes into, right on the canvas.
		$this->add_control(
			'tabs',
			array(
				'label'       => 'Tabs',
				'type'        => \Elementor\Modules\NestedElements\Controls\Control_Nested_Repeater::CONTROL_TYPE,
				'fields'      => $repeater->get_controls(),
				'default'     => array(
					array( 'label' => 'Rodada del sábado' ),
					array( 'label' => 'Taller comunitario' ),
				),
				'title_field' => '{{{ label }}}',
				'button_text' => 'Add Tab',
			)
		);

		$this->add_control(
			'selector_label',
			array(
				'label'       => 'Text Before Selector',
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => '',
				'placeholder' => 'e.g. Ver conversación:',
				'description' => 'Optional. Also used as the selector\'s accessible name.',
				'separator'   => 'before',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style → Selector: the closed dropdown button.
	 */
	private function register_selector_style_controls() {
		$this->start_controls_section(
			'section_style_selector',
			array(
				'label' => 'Selector',
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'bar_align',
			array(
				'label'     => 'Alignment',
				'type'      => \Elementor\Controls_Manager::CHOOSE,
				'options'   => array(
					'flex-start' => array(
						'title' => 'Left',
						'icon'  => 'eicon-h-align-left',
					),
					'center'     => array(
						'title' => 'Center',
						'icon'  => 'eicon-h-align-center',
					),
					'flex-end'   => array(
						'title' => 'Right',
						'icon'  => 'eicon-h-align-right',
					),
				),
				'default'   => 'flex-start',
				'selectors' => array( '{{WRAPPER}} .citykleta-dtabs__bar' => 'justify-content: {{VALUE}};' ),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'toggle_typography',
				'selector' => '{{WRAPPER}} .citykleta-dtabs__toggle',
			)
		);

		$this->start_controls_tabs( 'toggle_states' );

		$this->start_controls_tab( 'toggle_normal', array( 'label' => 'Normal' ) );

		$this->add_control(
			'toggle_color',
			array(
				'label'     => 'Text Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#ffffff',
				// The theme styles every button's hover/focus (Hello Elementor
				// paints them pink); repeating the states here keeps ours.
				'selectors' => array( '{{WRAPPER}} .citykleta-dtabs__toggle, {{WRAPPER}} .citykleta-dtabs__toggle:focus' => 'color: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'toggle_bg',
			array(
				'label'     => 'Background Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#151b1d',
				'selectors' => array( '{{WRAPPER}} .citykleta-dtabs__toggle, {{WRAPPER}} .citykleta-dtabs__toggle:focus' => 'background-color: {{VALUE}};' ),
			)
		);

		$this->end_controls_tab();

		$this->start_controls_tab( 'toggle_hover', array( 'label' => 'Hover / Open' ) );

		$this->add_control(
			'toggle_color_hover',
			array(
				'label'     => 'Text Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#ffffff',
				'selectors' => array( '{{WRAPPER}} .citykleta-dtabs__toggle:hover, {{WRAPPER}} .citykleta-dtabs__toggle[aria-expanded="true"]' => 'color: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'toggle_bg_hover',
			array(
				'label'     => 'Background Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#2a3235',
				'selectors' => array( '{{WRAPPER}} .citykleta-dtabs__toggle:hover, {{WRAPPER}} .citykleta-dtabs__toggle[aria-expanded="true"]' => 'background-color: {{VALUE}};' ),
			)
		);

		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			array(
				'name'      => 'toggle_border',
				'selector'  => '{{WRAPPER}} .citykleta-dtabs__toggle',
				'separator' => 'before',
			)
		);

		$this->add_responsive_control(
			'toggle_radius',
			array(
				'label'      => 'Border Radius',
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
					'unit' => 'px',
				),
				'selectors'  => array( '{{WRAPPER}} .citykleta-dtabs__toggle' => 'border-radius: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'toggle_padding',
			array(
				'label'      => 'Padding',
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'default'    => array(
					'top'      => 10,
					'right'    => 14,
					'bottom'   => 10,
					'left'     => 16,
					'unit'     => 'px',
					'isLinked' => false,
				),
				'selectors'  => array( '{{WRAPPER}} .citykleta-dtabs__toggle' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);

		$this->add_control(
			'label_heading',
			array(
				'label'     => 'Text Before Selector',
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
				'condition' => array( 'selector_label!' => '' ),
			)
		);

		$this->add_control(
			'label_color',
			array(
				'label'     => 'Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#5c5546',
				'selectors' => array( '{{WRAPPER}} .citykleta-dtabs__label' => 'color: {{VALUE}};' ),
				'condition' => array( 'selector_label!' => '' ),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'      => 'label_typography',
				'selector'  => '{{WRAPPER}} .citykleta-dtabs__label',
				'condition' => array( 'selector_label!' => '' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style → Menu: the open list of options.
	 */
	private function register_menu_style_controls() {
		$this->start_controls_section(
			'section_style_menu',
			array(
				'label' => 'Menu',
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'menu_bg',
			array(
				'label'     => 'Background Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#151b1d',
				'selectors' => array( '{{WRAPPER}} .citykleta-dtabs__list' => 'background-color: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'option_color',
			array(
				'label'     => 'Text Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#ffffff',
				'selectors' => array( '{{WRAPPER}} .citykleta-dtabs__option' => 'color: {{VALUE}};' ),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'option_typography',
				'selector' => '{{WRAPPER}} .citykleta-dtabs__option',
			)
		);

		$this->add_control(
			'option_bg_hover',
			array(
				'label'     => 'Hover Background',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#232b2e',
				'selectors' => array( '{{WRAPPER}} .citykleta-dtabs__option:hover, {{WRAPPER}} .citykleta-dtabs__option.is-focused' => 'background-color: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'option_bg_active',
			array(
				'label'     => 'Selected Background',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#2a3235',
				'selectors' => array( '{{WRAPPER}} .citykleta-dtabs__option[aria-selected="true"]' => 'background-color: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'check_color',
			array(
				'label'     => 'Check Mark Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#fed94c',
				'selectors' => array( '{{WRAPPER}} .citykleta-dtabs__check' => 'color: {{VALUE}};' ),
			)
		);

		$this->add_responsive_control(
			'menu_radius',
			array(
				'label'      => 'Border Radius',
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 32,
					),
				),
				'default'    => array(
					'size' => 14,
					'unit' => 'px',
				),
				'selectors'  => array( '{{WRAPPER}} .citykleta-dtabs__list' => 'border-radius: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'menu_min_width',
			array(
				'label'      => 'Min Width',
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 120,
						'max' => 600,
					),
				),
				'default'    => array(
					'size' => 260,
					'unit' => 'px',
				),
				'selectors'  => array( '{{WRAPPER}} .citykleta-dtabs__list' => 'min-width: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			array(
				'name'           => 'menu_shadow',
				'selector'       => '{{WRAPPER}} .citykleta-dtabs__list',
				'fields_options' => array(
					'box_shadow_type' => array( 'default' => 'yes' ),
					'box_shadow'      => array(
						'default' => array(
							'horizontal' => 0,
							'vertical'   => 12,
							'blur'       => 32,
							'spread'     => -6,
							'color'      => 'rgba(21, 27, 29, 0.35)',
						),
					),
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style → Content: the space between the selector and the tab content.
	 */
	private function register_panel_style_controls() {
		$this->start_controls_section(
			'section_style_panel',
			array(
				'label' => 'Content',
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'panel_spacing',
			array(
				'label'      => 'Spacing',
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 120,
					),
				),
				'default'    => array(
					'size' => 20,
					'unit' => 'px',
				),
				'selectors'  => array( '{{WRAPPER}} .citykleta-dtabs__panels' => 'margin-top: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Draws the widget.
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();
		$tabs     = array();

		// Every row keeps its position — it is also the position of its
		// container among the widget's children — so a row with no label is
		// named "Tab N" rather than dropped.
		foreach ( array_values( (array) $settings['tabs'] ) as $index => $tab ) {
			$label = trim( (string) $tab['label'] );

			$tabs[] = array( 'label' => '' !== $label ? $label : 'Tab ' . ( $index + 1 ) );
		}

		if ( ! $tabs ) {
			return;
		}

		$base  = 'citykleta-dtabs-' . $this->get_id();
		$label = trim( (string) $settings['selector_label'] );

		echo '<div class="citykleta-dtabs" data-citykleta-dtabs>';
		echo '<div class="citykleta-dtabs__bar">';

		if ( '' !== $label ) {
			printf( '<span class="citykleta-dtabs__label" id="%s-label">%s</span>', esc_attr( $base ), esc_html( $label ) );
		}

		echo '<div class="citykleta-dtabs__select">';
		printf(
			'<button type="button" class="citykleta-dtabs__toggle" aria-haspopup="listbox" aria-expanded="false" aria-controls="%1$s-list"%2$s><span class="citykleta-dtabs__current" id="%1$s-current">%3$s</span><svg class="citykleta-dtabs__chevron" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M7.4 8.6 12 13.2l4.6-4.6L18 10l-6 6-6-6z"/></svg></button>',
			esc_attr( $base ),
			'' !== $label ? ' aria-labelledby="' . esc_attr( $base ) . '-label ' . esc_attr( $base ) . '-current"' : '',
			esc_html( $tabs[0]['label'] )
		);

		printf(
			'<ul class="citykleta-dtabs__list" id="%1$s-list" role="listbox" tabindex="-1"%2$s hidden>',
			esc_attr( $base ),
			'' !== $label ? ' aria-labelledby="' . esc_attr( $base ) . '-label"' : ''
		);
		foreach ( $tabs as $index => $tab ) {
			printf(
				'<li class="citykleta-dtabs__option" id="%1$s-option-%2$d" role="option" aria-selected="%3$s" data-index="%2$d"><span>%4$s</span><svg class="citykleta-dtabs__check" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z"/></svg></li>',
				esc_attr( $base ),
				(int) $index,
				0 === $index ? 'true' : 'false',
				esc_html( $tab['label'] )
			);
		}
		echo '</ul></div></div>';

		// The tabs' containers: Elementor prints each child element itself.
		echo '<div class="citykleta-dtabs__panels">';

		foreach ( $tabs as $index => $tab ) {
			$this->print_panel( $index, $tab['label'], $base );
		}

		echo '</div></div>';
	}

	/**
	 * Prints one tab's container, stamped with the attributes the selector
	 * script and assistive tech need. Same technique as Elementor's Tabs:
	 * a filter on the container's render, limited to this widget's children.
	 *
	 * @param int    $index Zero-based position.
	 * @param string $label Tab label.
	 * @param string $base  Id prefix of this widget instance.
	 */
	private function print_panel( $index, $label, $base ) {
		$children = $this->get_children();

		if ( empty( $children[ $index ] ) ) {
			return;
		}

		$child_id = $children[ $index ]->get_id();

		$stamp = function ( $should_render, $container ) use ( $child_id, $index, $label, $base ) {
			if ( $container->get_id() === $child_id ) {
				$container->add_render_attribute(
					'_wrapper',
					array(
						'id'             => $base . '-panel-' . $index,
						'role'           => 'region',
						'aria-label'     => $label,
						'data-tab-index' => $index + 1,
						'class'          => 0 === $index ? 'is-active' : '',
					)
				);
			}

			return $should_render;
		};

		add_filter( 'elementor/frontend/container/should_render', $stamp, 10, 2 );
		$children[ $index ]->print_element();
		remove_filter( 'elementor/frontend/container/should_render', $stamp, 10 );
	}

	/**
	 * Editor-side markup (Backbone template). It mirrors render() for the
	 * selector; the tabs' containers are inserted by the editor into
	 * `.citykleta-dtabs__panels`.
	 */
	protected function content_template() {
		?>
		<#
		var base = 'citykleta-dtabs-' + view.getIDInt(),
			tabs = settings.tabs || [],
			label = ( settings.selector_label || '' ).trim(),
			names = _.map( tabs, function ( tab, i ) {
				return ( tab.label || '' ).trim() || 'Tab ' + ( i + 1 );
			} );
		#>
		<# if ( names.length ) { #>
		<div class="citykleta-dtabs" data-citykleta-dtabs>
			<div class="citykleta-dtabs__bar">
				<# if ( label ) { #>
				<span class="citykleta-dtabs__label" id="{{ base }}-label">{{{ _.escape( label ) }}}</span>
				<# } #>
				<div class="citykleta-dtabs__select">
					<button type="button" class="citykleta-dtabs__toggle" aria-haspopup="listbox" aria-expanded="false" aria-controls="{{ base }}-list">
						<span class="citykleta-dtabs__current" id="{{ base }}-current">{{{ _.escape( names[0] ) }}}</span>
						<svg class="citykleta-dtabs__chevron" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M7.4 8.6 12 13.2l4.6-4.6L18 10l-6 6-6-6z"/></svg>
					</button>
					<ul class="citykleta-dtabs__list" id="{{ base }}-list" role="listbox" tabindex="-1" hidden>
						<# _.each( names, function ( name, i ) { #>
						<li class="citykleta-dtabs__option" id="{{ base }}-option-{{ i }}" role="option" aria-selected="{{ 0 === i ? 'true' : 'false' }}" data-index="{{ i }}"><span>{{{ _.escape( name ) }}}</span><svg class="citykleta-dtabs__check" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z"/></svg></li>
						<# } ); #>
					</ul>
				</div>
			</div>
			<div class="citykleta-dtabs__panels"></div>
		</div>
		<# } #>
		<?php
	}
}
