<?php
/**
 * "Bike Categories" widget: the bike taxonomies as a horizontal list.
 *
 * Draws one of three sets of terms as a row of chips, so a page can offer
 * "browse by category" without hardcoding a list that goes stale the next
 * time Citykleta_Bike_Type_Sync runs:
 *   - Bike Types    — the top level of `bike_type` (Urbano, Carretera…).
 *   - Bike Subtypes — its second level (Road, MTB, Folding bike…).
 *   - Tags          — `bike_tag` (Tandem, Kids, Electric…).
 *
 * The control's stored VALUES are still `bike_usage` / `bike_type`, the names
 * of the two flat taxonomies the tree replaced — and they now mean "top level"
 * / "second level", not a taxonomy name. Kept so every widget and shortcode
 * already placed with them keeps working — see self::SOURCE_*.
 *
 * One widget with a taxonomy control instead of two near-identical widgets,
 * for the same reason Citykleta_Bike_Data_Widget is one widget with a field
 * control: the markup, the Style tab and the query are the same, only the
 * source of the terms changes.
 *
 * **Where each one links to is NOT the same:** a type is what the search bar
 * already filters by (`?usage={slug}` — see
 * Citykleta_Templates::request_filters()), so it links to the results archive
 * with that filter applied, keeping the visitor inside the page that has the
 * search bar and the map. A subtype or a tag links to its own term archive
 * (`get_term_link()`). Both land on a list of those bikes.
 *
 * @package Citykleta_Shared_Bikes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Citykleta_Bike_Terms_Widget.
 */
class Citykleta_Bike_Terms_Widget extends \Elementor\Widget_Base {

	/**
	 * Source: the top level of bike_type (Urbano…). Stored value kept from when
	 * it was the `bike_usage` taxonomy — see the file docblock.
	 */
	const SOURCE_TYPES = 'bike_usage';

	/**
	 * Source: the second level of bike_type (Road, MTB…).
	 */
	const SOURCE_SUBTYPES = 'bike_type';

	/**
	 * Source: bike_tag.
	 */
	const SOURCE_TAGS = 'bike_tag';

	/**
	 * Internal widget name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'citykleta_bike_terms';
	}

	/**
	 * Title shown in the Elementor editor.
	 *
	 * @return string
	 */
	public function get_title() {
		return 'Bike Categories';
	}

	/**
	 * Widget icon in the Elementor panel.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-tags';
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
		return array( 'bike', 'bici', 'bicicleta', 'citykleta', 'category', 'categoria', 'type', 'tipo', 'usage', 'uso', 'terms' );
	}

	/**
	 * Controls shown in Elementor's left panel.
	 */
	protected function register_controls() {
		$this->register_content_controls();
		$this->register_layout_style_controls();
		$this->register_item_style_controls();
		$this->register_count_style_controls();
	}

	/**
	 * Content tab: which terms, in what order, and what each one shows.
	 */
	private function register_content_controls() {
		$this->start_controls_section( 'section_content', array( 'label' => 'Content' ) );

		$this->add_control(
			'taxonomy',
			array(
				'label'   => 'Categories',
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => self::SOURCE_SUBTYPES,
				'options' => array(
					self::SOURCE_TYPES    => 'Bike Types (top level)',
					self::SOURCE_SUBTYPES => 'Bike Subtypes',
					self::SOURCE_TAGS     => 'Tags',
				),
			)
		);

		$this->add_control(
			'orderby',
			array(
				'label'   => 'Order By',
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'name',
				'options' => array(
					'name'  => 'Name',
					'count' => 'Number of bikes',
					'order' => 'Display order (Bike Types only)',
				),
			)
		);

		$this->add_control(
			'order',
			array(
				'label'   => 'Order',
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'ASC',
				'options' => array(
					'ASC'  => 'Ascending',
					'DESC' => 'Descending',
				),
			)
		);

		$this->add_control(
			'limit',
			array(
				'label'       => 'Max Categories',
				'type'        => \Elementor\Controls_Manager::NUMBER,
				'min'         => 0,
				'default'     => 0,
				'description' => '0 shows every category.',
			)
		);

		$this->add_control(
			'hide_empty',
			array(
				'label'        => 'Hide Empty',
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'default'      => '',
				'label_on'     => 'Yes',
				'label_off'    => 'No',
				'description'  => 'A type counts as empty when none of its subtypes has a bike.',
				'return_value' => 'yes',
			)
		);

		/*
		 * Works for every source now. With the old flat `bike_usage` it had to
		 * be hidden — a usage was never assigned to a bike, so its count was
		 * always 0. A type's count is now the sum of its subtypes' (see
		 * self::count_for()), which is the number a visitor expects.
		 */
		$this->add_control(
			'show_count',
			array(
				'label'        => 'Show Count',
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'default'      => '',
				'return_value' => 'yes',
			)
		);

		$this->add_control(
			'link',
			array(
				'label'        => 'Link Categories',
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style tab: the row itself — spacing, alignment, wrapping.
	 */
	private function register_layout_style_controls() {
		$this->start_controls_section(
			'section_style_layout',
			array(
				'label' => 'Layout',
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'align',
			array(
				'label'     => 'Alignment',
				'type'      => \Elementor\Controls_Manager::CHOOSE,
				'options'   => array(
					'flex-start' => array(
						'title' => 'Left',
						'icon'  => 'eicon-text-align-left',
					),
					'center'     => array(
						'title' => 'Center',
						'icon'  => 'eicon-text-align-center',
					),
					'flex-end'   => array(
						'title' => 'Right',
						'icon'  => 'eicon-text-align-right',
					),
				),
				'selectors' => array(
					'{{WRAPPER}} .citykleta-bike-terms' => 'justify-content: {{VALUE}};',
				),
			)
		);

		$this->add_responsive_control(
			'gap',
			array(
				'label'      => 'Spacing',
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em', 'rem' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 60,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .citykleta-bike-terms' => 'gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		/*
		 * Off means one single row that can overflow sideways, so the list
		 * scrolls instead of stacking — the horizontal reading stays intact
		 * on a narrow screen, which is the whole point of this widget.
		 */
		$this->add_responsive_control(
			'wrap',
			array(
				'label'        => 'Wrap Lines',
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'default'      => 'wrap',
				'return_value' => 'wrap',
				'selectors'    => array(
					'{{WRAPPER}} .citykleta-bike-terms' => 'flex-wrap: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style tab: each chip, with its hover state.
	 */
	private function register_item_style_controls() {
		$this->start_controls_section(
			'section_style_items',
			array(
				'label' => 'Categories',
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'item_typography',
				'selector' => '{{WRAPPER}} .citykleta-bike-terms__link',
			)
		);

		$this->add_responsive_control(
			'item_padding',
			array(
				'label'      => 'Padding',
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', 'rem' ),
				'selectors'  => array(
					'{{WRAPPER}} .citykleta-bike-terms__link' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'item_radius',
			array(
				'label'      => 'Border Radius',
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .citykleta-bike-terms__link' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->start_controls_tabs( 'item_tabs' );

		$this->start_controls_tab( 'item_tab_normal', array( 'label' => 'Normal' ) );

		$this->add_control(
			'item_color',
			array(
				'label'     => 'Text Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .citykleta-bike-terms__link' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'item_background',
			array(
				'label'     => 'Background Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .citykleta-bike-terms__link' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			array(
				'name'     => 'item_border',
				'selector' => '{{WRAPPER}} .citykleta-bike-terms__link',
			)
		);

		$this->end_controls_tab();

		$this->start_controls_tab( 'item_tab_hover', array( 'label' => 'Hover' ) );

		$this->add_control(
			'item_color_hover',
			array(
				'label'     => 'Text Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .citykleta-bike-terms__link:hover' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'item_background_hover',
			array(
				'label'     => 'Background Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .citykleta-bike-terms__link:hover' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'item_border_color_hover',
			array(
				'label'     => 'Border Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .citykleta-bike-terms__link:hover' => 'border-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->end_controls_section();
	}

	/**
	 * Style tab: the count after a category name.
	 */
	private function register_count_style_controls() {
		$this->start_controls_section(
			'section_style_count',
			array(
				'label'     => 'Count',
				'tab'       => \Elementor\Controls_Manager::TAB_STYLE,
				'condition' => array( 'show_count' => 'yes' ),
			)
		);

		$this->add_control(
			'count_color',
			array(
				'label'     => 'Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .citykleta-bike-terms__count' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'count_typography',
				'selector' => '{{WRAPPER}} .citykleta-bike-terms__count',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * The terms this instance should draw.
	 *
	 * @param array $settings Widget settings.
	 * @return array List of WP_Term (empty if none).
	 */
	private function get_terms_for( array $settings ) {
		$source = isset( $settings['taxonomy'] ) ? $settings['taxonomy'] : '';

		if ( ! in_array( $source, array( self::SOURCE_TYPES, self::SOURCE_SUBTYPES, self::SOURCE_TAGS ), true ) ) {
			$source = self::SOURCE_SUBTYPES;
		}

		if ( self::SOURCE_TYPES === $source ) {
			// Already in display order, and without the "Unclassified" holder.
			$terms = Citykleta_Bike_Type_Taxonomy::top_level();
		} elseif ( self::SOURCE_SUBTYPES === $source ) {
			$terms = Citykleta_Bike_Type_Taxonomy::all_subtypes();
		} else {
			$terms = get_terms(
				array(
					'taxonomy'   => Citykleta_Bike_Tag_Taxonomy::TAXONOMY,
					'hide_empty' => false,
				)
			);
			$terms = is_wp_error( $terms ) ? array() : $terms;
		}

		if ( isset( $settings['hide_empty'] ) && 'yes' === $settings['hide_empty'] ) {
			$terms = array_values(
				array_filter(
					$terms,
					function ( $term ) {
						return self::count_for( $term ) > 0;
					}
				)
			);
		}

		/*
		 * Sorted here rather than in get_terms(): a type's count isn't a column
		 * WordPress can sort by (it's its types' added up, see count_for()),
		 * and types already come in display order from top_level(). "Display
		 * order" on types or tags, which have none, falls back to the name.
		 */
		$orderby = isset( $settings['orderby'] ) ? $settings['orderby'] : 'name';

		if ( 'count' === $orderby ) {
			usort(
				$terms,
				function ( $a, $b ) {
					return self::count_for( $a ) <=> self::count_for( $b );
				}
			);
		} elseif ( 'order' !== $orderby || self::SOURCE_TYPES !== $source ) {
			usort(
				$terms,
				function ( $a, $b ) {
					return strcasecmp( $a->name, $b->name );
				}
			);
		}

		if ( isset( $settings['order'] ) && 'DESC' === $settings['order'] ) {
			$terms = array_reverse( $terms );
		}

		$limit = isset( $settings['limit'] ) ? absint( $settings['limit'] ) : 0;

		if ( $limit > 0 ) {
			$terms = array_slice( $terms, 0, $limit );
		}

		return $terms;
	}

	/**
	 * How many bikes a chip stands for.
	 *
	 * A subtype or a tag: its own count. A type: the sum of its subtypes' —
	 * a bike is assigned its subtype and never the type, so the type's own count
	 * is always 0. Each bike has exactly one type, so the sum counts no bike
	 * twice.
	 *
	 * @param WP_Term $term The term.
	 * @return int
	 */
	private static function count_for( $term ) {
		if ( Citykleta_Bike_Type_Taxonomy::TAXONOMY !== $term->taxonomy || 0 !== (int) $term->parent ) {
			return (int) $term->count;
		}

		return (int) array_sum( wp_list_pluck( Citykleta_Bike_Type_Taxonomy::children( $term->term_id ), 'count' ) );
	}

	/**
	 * Where a chip links to. See the file docblock: types go through the
	 * search filter, types and tags to their own term archive.
	 *
	 * @param WP_Term $term The category.
	 * @return string URL, or '' if there's nowhere to point at.
	 */
	private function term_url( $term ) {
		if ( Citykleta_Bike_Type_Taxonomy::TAXONOMY === $term->taxonomy && 0 === (int) $term->parent ) {
			$archive = get_post_type_archive_link( Citykleta_Bike_Post_Type::POST_TYPE );

			if ( ! $archive ) {
				return '';
			}

			return add_query_arg( 'usage', $term->slug, $archive );
		}

		$link = get_term_link( $term );

		return is_wp_error( $link ) ? '' : $link;
	}

	/**
	 * Prints the categories as a single horizontal list.
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();
		$terms    = $this->get_terms_for( $settings );

		if ( ! $terms ) {
			if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
				echo '<p class="citykleta-bike-terms__empty">Bike Categories: no categories to show yet.</p>';
			}

			return;
		}

		$show_count = isset( $settings['show_count'] ) && 'yes' === $settings['show_count'];
		$link       = isset( $settings['link'] ) && 'yes' === $settings['link'];

		echo '<ul class="citykleta-bike-terms">';

		foreach ( $terms as $term ) {
			$label = esc_html( $term->name );

			if ( $show_count ) {
				$label .= ' <span class="citykleta-bike-terms__count">' . esc_html( number_format_i18n( self::count_for( $term ) ) ) . '</span>';
			}

			$url = $link ? $this->term_url( $term ) : '';

			echo '<li class="citykleta-bike-terms__item">';

			if ( $url ) {
				printf(
					'<a class="citykleta-bike-terms__link" href="%1$s">%2$s</a>',
					esc_url( $url ),
					$label // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from escaped parts.
				);
			} else {
				printf(
					'<span class="citykleta-bike-terms__link">%1$s</span>',
					$label // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from escaped parts.
				);
			}

			echo '</li>';
		}

		echo '</ul>';
	}
}
