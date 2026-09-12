<?php
/**
 * The pieces a single-bike layout is built from: gallery, header, owner,
 * description, specs, price + request form, and pickup map. Each one is an
 * Elementor widget (and a shortcode, via Citykleta_Widget_Shortcodes), so
 * the single-bike template is arranged by hand in Elementor instead of
 * being a fixed PHP file — see Citykleta_Template_Post_Type.
 *
 * **How one layout serves every bike:** none of these take a bike ID. They
 * each ask self::bike_id(), which reads the bike being viewed straight off
 * the main query (`get_queried_object_id()`). That's the whole reason this
 * works on the free Elementor: the plugin resolves the data, so Elementor
 * never has to (which is what its Pro-only Dynamic Tags are for).
 *
 * Careful with get_the_ID() in here: the template file swaps the global
 * $post to the *template* while printing it, so get_the_ID() would return
 * the template, not the bike. Always go through self::bike_id().
 *
 * All seven live in one file on purpose — they're ~40 lines each and only
 * make sense as a set; seven near-empty files would be more to keep track
 * of, not less.
 *
 * @package Citykleta_Shared_Bikes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared base: resolving the bike, and the editor's preview fallback.
 */
abstract class Citykleta_Bike_Part_Widget extends \Elementor\Widget_Base {

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
		return array( 'bike', 'bici', 'bicicleta', 'citykleta' );
	}

	/**
	 * The bike this instance should draw.
	 *
	 * On a bike's page that's the bike being viewed.
	 *
	 * When the TEMPLATE itself is what's on screen there is no bike in the
	 * query, and empty boxes would make the layout impossible to judge — so
	 * a real bike stands in, purely so the design can be seen. That covers
	 * the two ways a template gets looked at: Elementor's editor canvas, and
	 * viewing/previewing the template post directly. (Elementor's own Theme
	 * Builder solves the same problem with its "preview settings" picker.)
	 *
	 * Anywhere else — a widget dropped on some unrelated page — there's
	 * deliberately nothing to draw, rather than a random bike appearing.
	 *
	 * @return int Bike post ID, or 0 when there's nothing to draw.
	 */
	protected function bike_id() {
		return self::current_bike_id();
	}

	/**
	 * The same resolution, reachable from outside this class hierarchy.
	 *
	 * Citykleta_Map_Widget needs it for its "Bike Data" source, and it is not
	 * a bike part widget — rather than copy the rules (and let the two drift),
	 * the rules live here once and everyone asks.
	 *
	 * @return int Bike post ID, or 0 when there is no bike in context.
	 */
	public static function current_bike_id() {
		if ( is_singular( Citykleta_Bike_Post_Type::POST_TYPE ) ) {
			return (int) get_queried_object_id();
		}

		if ( self::is_elementor_canvas() || self::is_template_preview() ) {
			return self::preview_bike_id();
		}

		return 0;
	}

	/**
	 * Whether what's on screen is a template being designed or previewed
	 * rather than a real visitor's page — the two cases where a widget should
	 * stand in real data so the layout can be judged, and where an editor-only
	 * note is worth printing.
	 *
	 * Public because the destination widgets and the grid/map widgets need the
	 * same answer and are not part of this class hierarchy.
	 *
	 * @return bool
	 */
	public static function is_design_context() {
		return self::is_elementor_canvas() || self::is_template_preview();
	}

	/**
	 * Whether the template post itself is what's being displayed — WordPress
	 * "Preview", or its own URL.
	 *
	 * @return bool
	 */
	protected static function is_template_preview() {
		return class_exists( 'Citykleta_Template_Post_Type' )
			&& is_singular( Citykleta_Template_Post_Type::POST_TYPE );
	}

	/**
	 * Whether we're rendering inside Elementor's editor or its preview
	 * iframe, rather than for a real visitor.
	 *
	 * @return bool
	 */
	protected static function is_elementor_canvas() {
		if ( ! did_action( 'elementor/loaded' ) ) {
			return false;
		}

		$elementor = \Elementor\Plugin::$instance;

		return ( $elementor->editor && $elementor->editor->is_edit_mode() )
			|| ( $elementor->preview && $elementor->preview->is_preview_mode() );
	}

	/**
	 * Most recent published bike — the stand-in used while designing.
	 *
	 * @return int
	 */
	protected static function preview_bike_id() {
		$latest = get_posts(
			array(
				'post_type'      => Citykleta_Bike_Post_Type::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		return $latest ? (int) $latest[0] : 0;
	}

	/**
	 * A full Style tab for a widget that prints a single line of text,
	 * matching what Elementor's own Paragraph offers: type, color, alignment,
	 * text shadow, background, border, radius, box shadow and spacing. Used by
	 * Citykleta_Bike_Directions_Widget.
	 *
	 * (Citykleta_Bike_Description_Widget used this too, briefly, but earns its
	 * own full control set below matching Elementor's Text Editor instead —
	 * a description is a block of paragraphs, not a single line, so it wants
	 * Text Editor's columns/paragraph-spacing/drop-cap, not Paragraph's link.)
	 *
	 * **Why this is hand-rolled.** Elementor's Paragraph (`e-paragraph`) is a
	 * V4 "atomic" widget: it declares a props schema and gets its style panel
	 * from Atomic_Widget_Base, not from add_control()/selectors. Third-party
	 * widgets can't inherit that panel without being rewritten onto that API,
	 * which Elementor still ships as experimental. So the properties are
	 * declared here in the classic way — same capabilities, declared instead
	 * of inherited.
	 *
	 * @param string $selector Element the rules apply to, inside {{WRAPPER}}.
	 * @param string $prefix   Unique prefix for the control IDs.
	 * @param string $label    Section label in the panel.
	 */
	/**
	 * The Style tab of Elementor's own "Text Editor" widget, minus its Content
	 * tab (Drop Cap / Columns) — Alignment, Typography, Text Shadow, Paragraph
	 * Spacing, then a Normal/Hover tab pair for Text Color / Link Color.
	 *
	 * Shared by Citykleta_Bike_Description_Widget (which also has the Content
	 * side — Drop Cap and Columns, since it prints paragraphs) and
	 * Citykleta_Bike_Directions_Widget (which does not: an address is one
	 * line, not a paragraph block, so Drop Cap/Columns aren't offered — but
	 * it deserves the exact same styling power over the line it prints).
	 *
	 * A static method taking `$widget` explicitly, not an instance method
	 * like register_text_style_section() below, so it can be called with
	 * `self::` from either widget class without needing them to share more
	 * than this one file.
	 *
	 * @param \Elementor\Widget_Base $widget   The widget registering these controls.
	 * @param string                 $selector Element the rules apply to, inside {{WRAPPER}}.
	 */
	protected static function register_text_editor_style_section( \Elementor\Widget_Base $widget, $selector ) {
		$widget->start_controls_section(
			'section_style',
			array(
				'label' => 'Text Editor',
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$widget->add_responsive_control(
			'align',
			array(
				'label'     => 'Alignment',
				'type'      => \Elementor\Controls_Manager::CHOOSE,
				'options'   => array(
					'left'    => array(
						'title' => 'Left',
						'icon'  => 'eicon-text-align-left',
					),
					'center'  => array(
						'title' => 'Center',
						'icon'  => 'eicon-text-align-center',
					),
					'right'   => array(
						'title' => 'Right',
						'icon'  => 'eicon-text-align-right',
					),
					'justify' => array(
						'title' => 'Justified',
						'icon'  => 'eicon-text-align-justify',
					),
				),
				'selectors' => array(
					$selector => 'text-align: {{VALUE}};',
				),
				'separator' => 'after',
			)
		);

		$widget->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'typography',
				'selector' => $selector,
			)
		);

		$widget->add_group_control(
			\Elementor\Group_Control_Text_Shadow::get_type(),
			array(
				'name'     => 'text_shadow',
				'selector' => $selector,
			)
		);

		$widget->add_responsive_control(
			'paragraph_spacing',
			array(
				'label'      => 'Paragraph Spacing',
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em', 'rem', 'vh', 'custom' ),
				'range'      => array(
					'px' => array( 'max' => 100 ),
					'em' => array(
						'min' => 0.1,
						'max' => 20,
					),
				),
				'selectors'  => array(
					$selector . ' p' => 'margin-block-end: {{SIZE}}{{UNIT}}',
				),
			)
		);

		$widget->add_control( 'colors_divider', array( 'type' => \Elementor\Controls_Manager::DIVIDER ) );

		$widget->start_controls_tabs( 'link_colors' );

		$widget->start_controls_tab( 'colors_normal', array( 'label' => 'Normal' ) );

		$widget->add_control(
			'text_color',
			array(
				'label'     => 'Text Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					$selector => 'color: {{VALUE}};',
				),
			)
		);

		$widget->add_control(
			'link_color',
			array(
				'label'     => 'Link Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					$selector . ' a' => 'color: {{VALUE}};',
				),
			)
		);

		$widget->end_controls_tab();

		$widget->start_controls_tab( 'colors_hover', array( 'label' => 'Hover' ) );

		$widget->add_control(
			'link_hover_color',
			array(
				'label'     => 'Link Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					$selector . ' a:hover, ' . $selector . ' a:focus' => 'color: {{VALUE}};',
				),
			)
		);

		$widget->add_control(
			'link_hover_color_transition_duration',
			array(
				'label'      => 'Transition Duration',
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 's', 'ms', 'custom' ),
				'default'    => array( 'unit' => 's' ),
				'selectors'  => array(
					$selector . ' a' => 'transition-duration: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$widget->end_controls_tab();

		$widget->end_controls_tabs();

		$widget->end_controls_section();
	}

	/**
	 * A full Style tab for a widget that prints a single line of text — see
	 * the note at Citykleta_Bike_Directions_Widget's own call site for why it
	 * no longer uses this. Kept for now as a documented, working alternative
	 * in case a future single-line widget wants the plainer Paragraph set
	 * instead of the Text Editor one.
	 *
	 * @param string $selector Element the rules apply to, inside {{WRAPPER}}.
	 * @param string $prefix   Unique prefix for the control IDs.
	 * @param string $label    Section label in the panel.
	 */
	protected function register_text_style_section( $selector, $prefix = 'text', $label = 'Text' ) {
		$target = '{{WRAPPER}} ' . $selector;

		$this->start_controls_section(
			$prefix . '_style',
			array(
				'label' => $label,
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			$prefix . '_color',
			array(
				'label'     => 'Text Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( $target => 'color: {{VALUE}};' ),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => $prefix . '_typography',
				'selector' => $target,
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Text_Shadow::get_type(),
			array(
				'name'     => $prefix . '_text_shadow',
				'selector' => $target,
			)
		);

		$this->add_responsive_control(
			$prefix . '_align',
			array(
				'label'     => 'Alignment',
				'type'      => \Elementor\Controls_Manager::CHOOSE,
				'options'   => array(
					'left'    => array(
						'title' => 'Left',
						'icon'  => 'eicon-text-align-left',
					),
					'center'  => array(
						'title' => 'Center',
						'icon'  => 'eicon-text-align-center',
					),
					'right'   => array(
						'title' => 'Right',
						'icon'  => 'eicon-text-align-right',
					),
					'justify' => array(
						'title' => 'Justified',
						'icon'  => 'eicon-text-align-justify',
					),
				),
				'selectors' => array( $target => 'text-align: {{VALUE}};' ),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Background::get_type(),
			array(
				'name'     => $prefix . '_background',
				'types'    => array( 'classic', 'gradient' ),
				'selector' => $target,
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			array(
				'name'     => $prefix . '_border',
				'selector' => $target,
			)
		);

		$this->add_responsive_control(
			$prefix . '_radius',
			array(
				'label'      => 'Border Radius',
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%', 'em' ),
				'selectors'  => array(
					$target => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => $prefix . '_box_shadow',
				'selector' => $target,
			)
		);

		$this->add_responsive_control(
			$prefix . '_padding',
			array(
				'label'      => 'Padding',
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', '%' ),
				'selectors'  => array(
					$target => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			$prefix . '_margin',
			array(
				'label'      => 'Margin',
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', '%' ),
				'selectors'  => array(
					$target => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Note shown on the Elementor canvas when a widget has nothing to draw,
	 * so an empty spot is never mistaken for a broken widget. Never printed
	 * for a real visitor.
	 *
	 * @param string $message What's missing.
	 */
	protected function render_editor_note( $message ) {
		if ( ! self::is_elementor_canvas() ) {
			return;
		}

		printf(
			'<div style="padding:12px 16px;border:1px dashed #c3c4c7;border-radius:6px;color:#646970;font-size:13px;">%s</div>',
			esc_html( $message )
		);
	}
}

/**
 * Photo gallery (the Splide carousel).
 */
class Citykleta_Bike_Gallery_Widget extends Citykleta_Bike_Part_Widget {

	/**
	 * Internal widget name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'citykleta_bike_gallery';
	}

	/**
	 * Title shown in the Elementor editor.
	 *
	 * @return string
	 */
	public function get_title() {
		return 'Bike Gallery';
	}

	/**
	 * Widget icon in the Elementor panel.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-slider-push';
	}

	/**
	 * Controls shown in Elementor's left panel.
	 */
	protected function register_controls() {
		$this->start_controls_section( 'section_content', array( 'label' => 'Gallery' ) );

		$this->add_responsive_control(
			'ratio',
			array(
				'label'      => 'Aspect Ratio',
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'range'      => array(
					'px' => array(
						'min'  => 0.5,
						'max'  => 2.5,
						'step' => 0.05,
					),
				),
				'default'    => array( 'size' => 1.33 ),
				'selectors'  => array(
					'{{WRAPPER}} .citykleta-bike-single__gallery' => 'aspect-ratio: {{SIZE}};',
				),
			)
		);

		$this->add_control(
			'radius',
			array(
				'label'      => 'Border Radius',
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 40 ) ),
				'selectors'  => array(
					'{{WRAPPER}} .citykleta-bike-single__gallery' => 'border-radius: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Prints the carousel.
	 */
	protected function render() {
		$bike_id = $this->bike_id();
		$images  = $bike_id ? Citykleta_Bike_Meta::gallery_ids( $bike_id ) : array();

		if ( ! $images ) {
			$this->render_editor_note( 'Bike Gallery: this bike has no photos yet.' );
			return;
		}

		$multi = count( $images ) > 1;
		?>
		<div class="citykleta-bike-single__gallery splide" aria-label="Fotos de la bici">
			<div class="splide__track">
				<ul class="splide__list">
					<?php foreach ( $images as $index => $image_id ) : ?>
						<li class="splide__slide citykleta-bike-single__shot">
							<?php echo wp_get_attachment_image( $image_id, 'large', false, array( 'loading' => 0 === $index ? 'eager' : 'lazy' ) ); ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>

			<?php if ( $multi ) : ?>
				<button type="button" class="citykleta-bike-single__gallery-nav citykleta-bike-single__gallery-nav--prev" aria-label="Foto anterior">&lsaquo;</button>
				<button type="button" class="citykleta-bike-single__gallery-nav citykleta-bike-single__gallery-nav--next" aria-label="Foto siguiente">&rsaquo;</button>

				<div class="citykleta-bike-single__gallery-dots">
					<?php foreach ( $images as $index => $image_id ) : ?>
						<button type="button" class="citykleta-bike-single__gallery-dot" aria-label="Foto <?php echo (int) $index + 1; ?>" aria-current="<?php echo 0 === $index ? 'true' : 'false'; ?>"></button>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}

/**
 * Title + location + tags.
 */
class Citykleta_Bike_Title_Widget extends Citykleta_Bike_Part_Widget {

	/**
	 * Internal widget name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'citykleta_bike_title';
	}

	/**
	 * Title shown in the Elementor editor.
	 *
	 * @return string
	 */
	public function get_title() {
		return 'Bike Title';
	}

	/**
	 * Widget icon in the Elementor panel.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-post-title';
	}

	/**
	 * Controls shown in Elementor's left panel.
	 */
	protected function register_controls() {
		$this->start_controls_section( 'section_content', array( 'label' => 'Content' ) );

		$this->add_control(
			'title_tag',
			array(
				'label'   => 'Title HTML Tag',
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'h1',
				'options' => array(
					'h1' => 'H1',
					'h2' => 'H2',
					'h3' => 'H3',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style',
			array(
				'label' => 'Title',
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'title_color',
			array(
				'label'     => 'Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .citykleta-bike-single__title' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'title_typography',
				'selector' => '{{WRAPPER}} .citykleta-bike-single__title',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Prints the title, and nothing else — the location and its tags are
	 * Citykleta_Bike_Location_Tags_Widget's job, so the two can be placed,
	 * hidden or restyled independently on the canvas.
	 */
	protected function render() {
		$bike_id = $this->bike_id();

		if ( ! $bike_id ) {
			$this->render_editor_note( 'Bike Title: shown on a bike\'s page.' );
			return;
		}

		$settings = $this->get_settings_for_display();
		$allowed  = array( 'h1', 'h2', 'h3' );
		$tag      = in_array( $settings['title_tag'], $allowed, true ) ? $settings['title_tag'] : 'h1';

		printf(
			'<%1$s class="citykleta-bike-single__title">%2$s</%1$s>',
			esc_html( $tag ),
			esc_html( get_the_title( $bike_id ) )
		);
	}
}

/**
 * Location text + the bike's type tags (e.g. "Hybrid"). Split out of what
 * used to be one "Title & Location" widget, so the title can move/hide/
 * restyle on its own — a bike's title and its location/tags don't always
 * belong next to each other in a redesigned layout.
 *
 * The "Eléctrica" tag that used to live in this list moved to Bike Specs
 * instead (its "Categories" switch) — it's a spec of the bike (like brand or
 * wheel size), not something that reads well as a loose badge next to the
 * address.
 */
class Citykleta_Bike_Location_Tags_Widget extends Citykleta_Bike_Part_Widget {

	/**
	 * Internal widget name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'citykleta_bike_location_tags';
	}

	/**
	 * Title shown in the Elementor editor.
	 *
	 * @return string
	 */
	public function get_title() {
		return 'Bike Location';
	}

	/**
	 * Widget icon in the Elementor panel.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-map-pin';
	}

	/**
	 * Controls shown in Elementor's left panel.
	 */
	protected function register_controls() {
		$this->start_controls_section( 'section_content', array( 'label' => 'Content' ) );

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
			'show_tags',
			array(
				'label'     => 'Tags',
				'type'      => \Elementor\Controls_Manager::SWITCHER,
				'label_on'  => 'Show',
				'label_off' => 'Hide',
				'default'   => 'yes',
				'description' => 'The bike\'s type (e.g. "Hybrid"). "Eléctrica" lives on Bike Specs now.',
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style',
			array(
				'label' => 'Style',
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'location_color',
			array(
				'label'     => 'Location Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .citykleta-bike-single__location' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'location_typography',
				'selector' => '{{WRAPPER}} .citykleta-bike-single__location',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Prints the location and its tags.
	 */
	protected function render() {
		$bike_id = $this->bike_id();

		if ( ! $bike_id ) {
			$this->render_editor_note( 'Bike Location: shown on a bike\'s page.' );
			return;
		}

		$settings = $this->get_settings_for_display();
		$location = get_post_meta( $bike_id, 'location', true );

		$types = get_the_terms( $bike_id, Citykleta_Bike_Type_Taxonomy::TAXONOMY );
		$tags  = is_array( $types ) ? wp_list_pluck( $types, 'name' ) : array();

		$show_location = $location && 'yes' === $settings['show_location'];
		$show_tags     = $tags && 'yes' === $settings['show_tags'];

		if ( ! $show_location && ! $show_tags ) {
			$this->render_editor_note( 'Bike Location: nothing to show for this bike.' );
			return;
		}
		?>
		<div class="citykleta-bike-single__subhead">
			<?php if ( $show_location ) : ?>
				<span class="citykleta-bike-single__location"><?php echo esc_html( $location ); ?></span>
			<?php endif; ?>

			<?php if ( $show_tags ) : ?>
				<ul class="citykleta-bike-single__tags">
					<?php foreach ( $tags as $tag_name ) : ?>
						<li class="citykleta-bike-single__tag"><?php echo esc_html( $tag_name ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}
}

/**
 * "Publicado por" + owner photo.
 */
class Citykleta_Bike_Owner_Widget extends Citykleta_Bike_Part_Widget {

	/**
	 * Internal widget name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'citykleta_bike_owner';
	}

	/**
	 * Title shown in the Elementor editor.
	 *
	 * @return string
	 */
	public function get_title() {
		return 'Bike Owner';
	}

	/**
	 * Widget icon in the Elementor panel.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-person';
	}

	/**
	 * Controls shown in Elementor's left panel.
	 */
	protected function register_controls() {
		$this->start_controls_section( 'section_content', array( 'label' => 'Content' ) );

		$this->add_control(
			'label',
			array(
				'label'   => 'Label',
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => 'Publicado por',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Prints the owner block.
	 */
	protected function render() {
		$bike_id = $this->bike_id();
		$name    = $bike_id ? get_post_meta( $bike_id, 'owner_name', true ) : '';

		if ( ! $name ) {
			$this->render_editor_note( 'Bike Owner: this bike has no owner name.' );
			return;
		}

		$settings = $this->get_settings_for_display();
		$photo    = get_post_meta( $bike_id, 'owner_photo', true );
		?>
		<section class="citykleta-bike-single__owner">
			<?php if ( $photo ) : ?>
				<img class="citykleta-bike-single__owner-photo" src="<?php echo esc_url( $photo ); ?>" alt="<?php echo esc_attr( $name ); ?>" width="48" height="48" loading="lazy" />
			<?php endif; ?>
			<div>
				<span class="citykleta-bike-single__owner-label"><?php echo esc_html( $settings['label'] ); ?></span>
				<span class="citykleta-bike-single__owner-name"><?php echo esc_html( $name ); ?></span>
			</div>
		</section>
		<?php
	}
}

/**
 * The bike's own description (its post content).
 */
class Citykleta_Bike_Description_Widget extends Citykleta_Bike_Part_Widget {

	/**
	 * Internal widget name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'citykleta_bike_description';
	}

	/**
	 * Title shown in the Elementor editor.
	 *
	 * @return string
	 */
	public function get_title() {
		return 'Bike Description';
	}

	/**
	 * Widget icon in the Elementor panel.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-post-content';
	}

	/**
	 * Every control of Elementor's own "Text Editor" widget
	 * (elementor/includes/widgets/text-editor.php), minus its text field —
	 * the text here is the bike's description, from the API. Read straight
	 * off that file's register_controls() rather than approximated, IDs
	 * included, so a setting saved on this widget means the same thing it
	 * would on the native one.
	 *
	 * No section heading of its own on purpose, same as native: that text is
	 * static, so it belongs to a plain Elementor Heading the user owns —
	 * editable, movable, removable — instead of a control that only exists to
	 * print a fixed string. The default layout puts one in front of this
	 * widget.
	 */
	protected function register_controls() {
		$selector = '{{WRAPPER}} .citykleta-bike-single__description';

		$this->start_controls_section( 'section_editor', array( 'label' => 'Text Editor' ) );

		$this->add_control(
			'drop_cap',
			array(
				'label'        => 'Drop Cap',
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_off'    => 'Off',
				'label_on'     => 'On',
				'prefix_class' => 'elementor-drop-cap-',
			)
		);

		$this->add_responsive_control(
			'text_columns',
			array(
				'label'     => 'Columns',
				'type'      => \Elementor\Controls_Manager::SELECT,
				'separator' => 'before',
				'default'   => '',
				'options'   => array(
					''   => 'Default',
					'1'  => '1',
					'2'  => '2',
					'3'  => '3',
					'4'  => '4',
					'5'  => '5',
					'6'  => '6',
					'7'  => '7',
					'8'  => '8',
					'9'  => '9',
					'10' => '10',
				),
				'selectors' => array(
					$selector => 'columns: {{VALUE}};',
				),
			)
		);

		$this->add_responsive_control(
			'column_gap',
			array(
				'label'      => 'Columns Gap',
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px', '%', 'em', 'rem', 'vw', 'custom' ),
				'range'      => array(
					'px'  => array( 'max' => 100 ),
					'%'   => array(
						'max'  => 10,
						'step' => 0.1,
					),
					'vw'  => array(
						'max'  => 10,
						'step' => 0.1,
					),
					'em'  => array( 'max' => 10 ),
					'rem' => array( 'max' => 10 ),
				),
				'selectors'  => array(
					$selector => 'column-gap: {{SIZE}}{{UNIT}};',
				),
				'conditions' => array(
					'relation' => 'or',
					'terms'    => array(
						array(
							'name'     => 'text_columns',
							'operator' => '>',
							'value'    => 1,
						),
						array(
							'name'     => 'text_columns',
							'operator' => '===',
							'value'    => '',
						),
					),
				),
			)
		);

		$this->end_controls_section();

		self::register_text_editor_style_section( $this, $selector );

		/*
		 * A separate Style section, gated on drop_cap = yes — same structure
		 * as native. The visual pieces (.elementor-drop-cap /
		 * .elementor-drop-cap-letter) are the exact classes Elementor's own
		 * Text Editor uses, with the equivalent base CSS mirrored into
		 * assets/css/single-bike.css (its own widget-text-editor.css isn't
		 * enqueued for a widget that isn't Elementor's), so these controls'
		 * generated CSS overrides the same properties on the same elements.
		 */
		$this->start_controls_section(
			'section_drop_cap',
			array(
				'label'     => 'Drop Cap',
				'tab'       => \Elementor\Controls_Manager::TAB_STYLE,
				'condition' => array( 'drop_cap' => 'yes' ),
			)
		);

		$this->add_control(
			'drop_cap_view',
			array(
				'label'        => 'View',
				'type'         => \Elementor\Controls_Manager::SELECT,
				'options'      => array(
					'default' => 'Default',
					'stacked' => 'Stacked',
					'framed'  => 'Framed',
				),
				'default'      => 'default',
				'prefix_class' => 'elementor-drop-cap-view-',
			)
		);

		$this->add_control(
			'drop_cap_primary_color',
			array(
				'label'     => 'Primary Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}}.elementor-drop-cap-view-stacked .elementor-drop-cap' => 'background-color: {{VALUE}};',
					'{{WRAPPER}}.elementor-drop-cap-view-framed .elementor-drop-cap, {{WRAPPER}}.elementor-drop-cap-view-default .elementor-drop-cap' => 'color: {{VALUE}}; border-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'drop_cap_secondary_color',
			array(
				'label'     => 'Secondary Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}}.elementor-drop-cap-view-framed .elementor-drop-cap' => 'background-color: {{VALUE}};',
					'{{WRAPPER}}.elementor-drop-cap-view-stacked .elementor-drop-cap' => 'color: {{VALUE}};',
				),
				'condition' => array( 'drop_cap_view!' => 'default' ),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Text_Shadow::get_type(),
			array(
				'name'     => 'drop_cap_shadow',
				'selector' => '{{WRAPPER}} .elementor-drop-cap',
			)
		);

		$this->add_control(
			'drop_cap_size',
			array(
				'label'      => 'Size',
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em', 'rem', 'custom' ),
				'default'    => array( 'size' => 5 ),
				'range'      => array(
					'px'  => array( 'max' => 30 ),
					'em'  => array( 'max' => 3 ),
					'rem' => array( 'max' => 3 ),
				),
				'selectors'  => array(
					'{{WRAPPER}} .elementor-drop-cap' => 'padding: {{SIZE}}{{UNIT}};',
				),
				'condition'  => array( 'drop_cap_view!' => 'default' ),
			)
		);

		$this->add_control(
			'drop_cap_space',
			array(
				'label'      => 'Space',
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em', 'rem', 'custom' ),
				'default'    => array( 'size' => 10 ),
				'range'      => array(
					'px'  => array( 'max' => 50 ),
					'em'  => array( 'max' => 5 ),
					'rem' => array( 'max' => 5 ),
				),
				'selectors'  => array(
					'{{WRAPPER}} .elementor-drop-cap' => 'margin-inline-end: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'drop_cap_border_radius',
			array(
				'label'      => 'Border Radius',
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px', '%', 'em', 'rem', 'custom' ),
				'default'    => array( 'unit' => '%' ),
				'range'      => array( '%' => array( 'max' => 50 ) ),
				'selectors'  => array(
					'{{WRAPPER}} .elementor-drop-cap' => 'border-radius: {{SIZE}}{{UNIT}};',
				),
				'condition'  => array( 'drop_cap_view!' => 'default' ),
			)
		);

		$this->add_control(
			'drop_cap_border_width',
			array(
				'label'      => 'Border Width',
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%', 'em', 'rem', 'vw', 'custom' ),
				'selectors'  => array(
					'{{WRAPPER}} .elementor-drop-cap' => 'border-width: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
				'condition'  => array( 'drop_cap_view' => 'framed' ),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'drop_cap_typography',
				'selector' => '{{WRAPPER}} .elementor-drop-cap-letter',
				'exclude'  => array( 'letter_spacing' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Prints the description.
	 */
	protected function render() {
		$bike_id = $this->bike_id();
		$content = $bike_id ? get_post_field( 'post_content', $bike_id ) : '';

		if ( ! trim( (string) $content ) ) {
			$this->render_editor_note( 'Bike Description: this bike has no description.' );
			return;
		}

		/*
		 * Formatted by hand instead of apply_filters( 'the_content' ): this
		 * runs INSIDE the template's own the_content pass, and re-entering
		 * that filter would hand the bike's text to every content filter
		 * again — Elementor's included, which decides what to render from
		 * the current global $post. Bike descriptions come from the API as
		 * plain text, so wpautop + shortcodes is all they need.
		 */
		$html = wpautop( do_shortcode( $content ) );

		$settings = $this->get_settings_for_display();

		if ( 'yes' === $settings['drop_cap'] ) {
			$html = self::apply_drop_cap( $html );
		}

		printf(
			'<section class="citykleta-bike-single__section"><div class="citykleta-bike-single__description">%s</div></section>',
			wp_kses_post( $html )
		);
	}

	/**
	 * Wraps the first letter of the first paragraph in the same two spans
	 * Elementor's own Text Editor drop cap uses — same class names, so the
	 * generated CSS from the controls above applies to them unchanged.
	 *
	 * The native widget does this on the client (its TextEditor frontend
	 * handler, `wrapDropCap()`) because its text is an inline-editable rich
	 * field. This description isn't — render() runs once per request with
	 * the final HTML already in hand — so the same regex runs here in PHP
	 * instead: no extra script to enqueue, and it still works with
	 * JavaScript off.
	 *
	 * @param string $html wpautop()'d description HTML.
	 * @return string
	 */
	private static function apply_drop_cap( $html ) {
		if ( ! preg_match( '/<p\b[^>]*>(.*?)<\/p>/s', $html, $p_match, PREG_OFFSET_CAPTURE ) ) {
			return $html;
		}

		$paragraph_html = str_replace( '&nbsp;', ' ', $p_match[1][0] );

		if ( ! preg_match( '/^ *([^ ] ?)/', $paragraph_html, $letter_match ) ) {
			return $html;
		}

		$first_letter = $letter_match[1];

		// Content starting with an HTML tag (e.g. an inline image) isn't a
		// letter to drop-cap — same guard as the native JS version.
		if ( '<' === trim( $first_letter ) ) {
			return $html;
		}

		$rest = substr( $paragraph_html, strlen( $letter_match[0] ) );
		$rest = preg_replace_callback(
			'/^ */',
			static function ( $spaces ) {
				return str_repeat( '&nbsp;', strlen( $spaces[0] ) );
			},
			$rest
		);

		$wrapped = '<span class="elementor-drop-cap"><span class="elementor-drop-cap-letter">'
			. esc_html( trim( $first_letter ) )
			. '</span></span>' . $rest;

		return substr_replace( $html, $wrapped, $p_match[1][1], strlen( $p_match[1][0] ) );
	}
}

/**
 * Brand / model / size / wheel size.
 */
class Citykleta_Bike_Specs_Widget extends Citykleta_Bike_Part_Widget {

	/**
	 * Internal widget name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'citykleta_bike_specs';
	}

	/**
	 * Title shown in the Elementor editor.
	 *
	 * @return string
	 */
	public function get_title() {
		return 'Bike Specs';
	}

	/**
	 * Widget icon in the Elementor panel.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-table';
	}

	/**
	 * Controls shown in Elementor's left panel.
	 */
	protected function register_controls() {
		$this->start_controls_section( 'section_content', array( 'label' => 'Content' ) );

		$this->add_control(
			'show_taxonomies',
			array(
				'label'       => 'Categories',
				'type'        => \Elementor\Controls_Manager::SWITCHER,
				'label_on'    => 'Show',
				'label_off'   => 'Hide',
				'default'     => 'yes',
				'description' => 'Adds the bike\'s type, usage and Eléctrica (electric) as rows.',
			)
		);

		$this->add_responsive_control(
			'columns',
			array(
				'label'          => 'Columns',
				'type'           => \Elementor\Controls_Manager::SELECT,
				'default'        => '2',
				'tablet_default' => '2',
				'mobile_default' => '1',
				'options'        => array(
					''  => 'Auto (one per item)',
					'1' => '1',
					'2' => '2',
					'3' => '3',
					'4' => '4',
					'5' => '5',
					'6' => '6',
				),
				'description'    => 'Auto gives every spec its own column — as many columns as this bike has data for.',
				'selectors'      => array(
					// Nothing is emitted here for "Auto" (an empty value): Elementor
					// skips a selector rule whose {{VALUE}} resolves empty, which is
					// exactly the gap render() fills — see the CSS variable it writes
					// inline, and the fallback in single-bike.css that reads it.
					'{{WRAPPER}} .citykleta-bike-single__specs' => 'grid-template-columns: repeat({{VALUE}}, minmax(0, 1fr));',
				),
			)
		);

		$this->add_responsive_control(
			'gap',
			array(
				'label'      => 'Gap',
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 60 ) ),
				'selectors'  => array(
					'{{WRAPPER}} .citykleta-bike-single__specs' => 'gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();

		/*
		 * Border/background/radius/shadow/padding target the WHOLE table
		 * (.citykleta-bike-single__specs), never a single row. Applying them
		 * per row was the first version of this and it looked broken: with
		 * cells touching (gap: 0) each row's own 1px border summed with its
		 * neighbour's into a double-thick shared line, and five rows each
		 * casting their own box-shadow stacked into one hazy halo around the
		 * whole table instead of a single shadow under it. The cell dividers
		 * now come from single-bike.css (a background peeking through a 1px
		 * gap/padding, not a border) — see the note there.
		 */
		$this->start_controls_section(
			'section_box_style',
			array(
				'label' => 'Box',
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Background::get_type(),
			array(
				'name'     => 'spec_background',
				'types'    => array( 'classic', 'gradient' ),
				'selector' => '{{WRAPPER}} .citykleta-bike-single__specs',
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			array(
				'name'     => 'spec_border',
				'selector' => '{{WRAPPER}} .citykleta-bike-single__specs',
			)
		);

		$this->add_responsive_control(
			'spec_radius',
			array(
				'label'      => 'Border Radius',
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .citykleta-bike-single__specs' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'spec_box_shadow',
				'selector' => '{{WRAPPER}} .citykleta-bike-single__specs',
			)
		);

		$this->add_responsive_control(
			'spec_padding',
			array(
				'label'      => 'Padding',
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array(
					'{{WRAPPER}} .citykleta-bike-single__specs' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();

		$this->register_cell_style_section( 'Label', 'label', '{{WRAPPER}} .citykleta-bike-single__spec dt' );
		$this->register_cell_style_section( 'Value', 'value', '{{WRAPPER}} .citykleta-bike-single__spec dd' );
	}

	/**
	 * Colour + typography for one side of a row.
	 *
	 * Label and value get the same set, declared once: a technical table needs
	 * them styled apart (a muted label against a solid value), and having the
	 * two definitions drift would be the easiest way to break that.
	 *
	 * @param string $label    Section label in the panel.
	 * @param string $prefix   Unique prefix for the control IDs.
	 * @param string $selector Full selector the rules apply to.
	 */
	private function register_cell_style_section( $label, $prefix, $selector ) {
		$this->start_controls_section(
			$prefix . '_style',
			array(
				'label' => $label,
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			$prefix . '_color',
			array(
				'label'     => 'Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( $selector => 'color: {{VALUE}};' ),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => $prefix . '_typography',
				'selector' => $selector,
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Text_Shadow::get_type(),
			array(
				'name'     => $prefix . '_text_shadow',
				'selector' => $selector,
			)
		);

		$this->add_responsive_control(
			$prefix . '_align',
			array(
				'label'     => 'Alignment',
				'type'      => \Elementor\Controls_Manager::CHOOSE,
				'options'   => array(
					'left'   => array(
						'title' => 'Left',
						'icon'  => 'eicon-text-align-left',
					),
					'center' => array(
						'title' => 'Center',
						'icon'  => 'eicon-text-align-center',
					),
					'right'  => array(
						'title' => 'Right',
						'icon'  => 'eicon-text-align-right',
					),
				),
				'selectors' => array( $selector => 'text-align: {{VALUE}};' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Prints the spec grid, with no heading of its own — same reasoning as
	 * Citykleta_Bike_Description_Widget::render().
	 */
	protected function render() {
		$bike_id = $this->bike_id();

		if ( ! $bike_id ) {
			$this->render_editor_note( 'Bike Specs: shown on a bike\'s page.' );
			return;
		}

		$settings = $this->get_settings_for_display();

		// Only fields that actually have a value are listed.
		$specs = array_filter(
			array(
				'Marca'           => get_post_meta( $bike_id, 'brand', true ),
				'Modelo'          => get_post_meta( $bike_id, 'model', true ),
				'Talla'           => get_post_meta( $bike_id, 'size', true ),
				'Tamaño de rueda' => get_post_meta( $bike_id, 'wheel_size', true ),
			)
		);

		if ( 'yes' === $settings['show_taxonomies'] ) {
			$specs = array_merge( $specs, self::taxonomy_specs( $bike_id ) );
		}

		if ( ! $specs ) {
			$this->render_editor_note( 'Bike Specs: this bike has no technical data filled in.' );
			return;
		}

		/*
		 * "Auto" (the Columns control's empty option) has no fixed number to
		 * give Elementor's own {{VALUE}} selector — how many specs THIS bike
		 * has is only known here, at render time, and varies bike to bike
		 * under the very same saved setting. So instead of a column count,
		 * Auto asks for one column per item: this writes that count into a
		 * CSS custom property read by the grid-template-columns fallback in
		 * single-bike.css. A bike with an explicit column count set for any
		 * breakpoint never reads this — that rule is more specific (it's
		 * scoped to this widget's own element) and simply wins there instead.
		 */
		$columns_style = '';

		if ( empty( $settings['columns'] ) ) {
			$columns_style = ' style="--citykleta-specs-columns: ' . count( $specs ) . ';"';
		}
		?>
		<section class="citykleta-bike-single__section">
			<dl class="citykleta-bike-single__specs"<?php echo $columns_style; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from count(), a plain integer, above. ?>>
				<?php foreach ( $specs as $label => $value ) : ?>
					<div class="citykleta-bike-single__spec">
						<dt><?php echo esc_html( $label ); ?></dt>
						<dd><?php echo esc_html( $value ); ?></dd>
					</div>
				<?php endforeach; ?>
			</dl>
		</section>
		<?php
	}

	/**
	 * The bike's classification, as rows: its type (synced from the app), its
	 * usage (declared by hand in wp-admin), and whether it's electric.
	 *
	 * "Eléctrica" used to be a loose tag next to the title/location — it
	 * belongs here instead, alongside brand/model/wheel size: it's a spec of
	 * the bike, not something that reads well as a badge next to the address.
	 * It needs its own line rather than array_filter()'ing it in with the
	 * scalar meta above: `is_electric` = "0" (a real "no") is falsy in PHP,
	 * so array_filter() would silently treat "not electric" as "no data" and
	 * drop the row — the one meta value here where empty and false aren't
	 * the same thing.
	 *
	 * The labels are written here in Spanish rather than read from the
	 * taxonomy's own labels->singular_name: those are wp-admin labels and are
	 * therefore in English by convention, which would put "Bike type" in a
	 * table that otherwise reads "Marca", "Modelo", "Talla" to a visitor.
	 *
	 * @param int $bike_id Bike post ID.
	 * @return array Label => comma-separated term names (or Sí/No for Eléctrica).
	 */
	private static function taxonomy_specs( $bike_id ) {
		$taxonomies = array(
			Citykleta_Bike_Type_Taxonomy::TAXONOMY  => 'Tipo',
			Citykleta_Bike_Usage_Taxonomy::TAXONOMY => 'Uso',
		);

		$rows = array();

		foreach ( $taxonomies as $taxonomy => $label ) {
			$terms = get_the_terms( $bike_id, $taxonomy );

			if ( ! $terms || is_wp_error( $terms ) ) {
				continue;
			}

			$rows[ $label ] = implode( ', ', wp_list_pluck( $terms, 'name' ) );
		}

		$is_electric = get_post_meta( $bike_id, 'is_electric', true );

		if ( '' !== $is_electric ) {
			$rows['Eléctrica'] = '1' === (string) $is_electric ? 'Sí' : 'No';
		}

		return $rows;
	}
}

/**
 * Price + the "Solicitar esta bicicleta" form.
 */
class Citykleta_Bike_Request_Widget extends Citykleta_Bike_Part_Widget {

	/**
	 * Internal widget name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'citykleta_bike_request';
	}

	/**
	 * Title shown in the Elementor editor.
	 *
	 * @return string
	 */
	public function get_title() {
		return 'Bike Request';
	}

	/**
	 * Widget icon in the Elementor panel.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-form-horizontal';
	}

	/**
	 * Controls shown in Elementor's left panel.
	 */
	protected function register_controls() {
		$this->start_controls_section( 'section_content', array( 'label' => 'Content' ) );

		$this->add_control(
			'show_title',
			array(
				'label'       => 'Form Title',
				'type'        => \Elementor\Controls_Manager::SWITCHER,
				'label_on'    => 'Show',
				'label_off'   => 'Hide',
				'default'     => 'yes',
				'description' => 'Static text, so it can also be hidden here and done with a normal Heading widget instead.',
			)
		);

		$this->add_control(
			'title',
			array(
				'label'     => 'Title',
				'type'      => \Elementor\Controls_Manager::TEXT,
				'default'   => 'Solicitar esta bicicleta',
				'condition' => array( 'show_title' => 'yes' ),
			)
		);

		$this->add_control(
			'dates_label',
			array(
				'label'   => 'Rental Period Label',
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => 'Periodo de renta',
			)
		);

		$this->add_control(
			'dates_hint',
			array(
				'label'   => 'Rental Period Placeholder',
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => 'Llegada — Salida',
			)
		);

		$this->end_controls_section();

		/*
		 * Step 1 asks for the code; step 2 (name, contact, message) only opens
		 * after it. Nothing here validates the code — the plugin has no route
		 * to validate it against, see
		 * Citykleta_Bike_Request::META_VERIFICATION_CODE — which is also why
		 * "Required" is a choice and not a given.
		 */
		$this->start_controls_section( 'section_verification', array( 'label' => 'Verification' ) );

		$this->add_control(
			'show_code',
			array(
				'label'     => 'Verification Code Field',
				'type'      => \Elementor\Controls_Manager::SWITCHER,
				'label_on'  => 'Show',
				'label_off' => 'Hide',
				'default'   => 'yes',
			)
		);

		$this->add_control(
			'code_label',
			array(
				'label'     => 'Field Label',
				'type'      => \Elementor\Controls_Manager::TEXT,
				'default'   => 'Código de verificación',
				'condition' => array( 'show_code' => 'yes' ),
			)
		);

		$this->add_control(
			'require_code',
			array(
				'label'       => 'Required',
				'type'        => \Elementor\Controls_Manager::SWITCHER,
				'label_on'    => 'Yes',
				'label_off'   => 'No',
				'default'     => 'yes',
				'description' => 'Blocks the step until a code is typed. It is stored as typed — nothing checks whether it is real.',
				'condition'   => array( 'show_code' => 'yes' ),
			)
		);

		$this->add_control(
			'verify_url',
			array(
				'label'         => 'Verification Site URL',
				'type'          => \Elementor\Controls_Manager::URL,
				'placeholder'   => 'https://…',
				'description'   => 'Opens in a new tab so the half-filled form is still here on the way back. Leave empty to hide the link.',
				'options'       => false,
				'default'       => array( 'url' => '' ),
				'condition'     => array( 'show_code' => 'yes' ),
			)
		);

		$this->add_control(
			'verify_text',
			array(
				'label'     => 'Link Text',
				'type'      => \Elementor\Controls_Manager::TEXT,
				'default'   => 'Verificarme ahora',
				'condition' => array( 'show_code' => 'yes' ),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section( 'section_buttons', array( 'label' => 'Buttons' ) );

		$this->add_control(
			'next_text',
			array(
				'label'   => 'Continue',
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => 'Continuar',
			)
		);

		$this->add_control(
			'back_text',
			array(
				'label'   => 'Back',
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => 'Atrás',
			)
		);

		$this->add_control(
			'submit_text',
			array(
				'label'   => 'Submit',
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => 'Enviar solicitud',
			)
		);

		$this->end_controls_section();

		$this->register_style_controls();
	}

	/**
	 * The Style tab.
	 *
	 * Written by hand, one section per visible part of the card, for the same
	 * reason the description widget's is (see
	 * Citykleta_Bike_Part_Widget::register_text_editor_style_section()):
	 * Elementor's own form widget is Pro, and its V4 atomic controls aren't
	 * something a classic third-party widget can inherit. The selectors point
	 * at the classes bike-request.css already defines, so every control here
	 * overrides a real default rather than inventing one.
	 */
	private function register_style_controls() {
		$card   = '{{WRAPPER}} .citykleta-bike-single__price-card';
		$fields = '{{WRAPPER}} .citykleta-bike-request__field input, {{WRAPPER}} .citykleta-bike-request__field textarea, {{WRAPPER}} .citykleta-bike-request__input';
		$labels = '{{WRAPPER}} .citykleta-bike-request__field > span, {{WRAPPER}} .citykleta-bike-request__label';
		$primary = '{{WRAPPER}} .citykleta-bike-request__submit, {{WRAPPER}} .citykleta-bike-request__next';

		// --- Card -----------------------------------------------------------
		$this->start_controls_section(
			'section_style_card',
			array(
				'label' => 'Card',
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'card_background',
			array(
				'label'     => 'Background Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( $card => 'background-color: {{VALUE}};' ),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			array(
				'name'     => 'card_border',
				'selector' => $card,
			)
		);

		$this->add_responsive_control(
			'card_radius',
			array(
				'label'      => 'Border Radius',
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array( $card => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'card_shadow',
				'selector' => $card,
			)
		);

		$this->add_responsive_control(
			'card_padding',
			array(
				'label'      => 'Padding',
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', '%' ),
				'selectors'  => array( $card => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);

		$this->end_controls_section();

		// --- Title ----------------------------------------------------------
		$this->start_controls_section(
			'section_style_title',
			array(
				'label'     => 'Form Title',
				'tab'       => \Elementor\Controls_Manager::TAB_STYLE,
				'condition' => array( 'show_title' => 'yes' ),
			)
		);

		$this->add_control(
			'title_color',
			array(
				'label'     => 'Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .citykleta-bike-request__title' => 'color: {{VALUE}};' ),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'title_typography',
				'selector' => '{{WRAPPER}} .citykleta-bike-request__title',
			)
		);

		$this->add_responsive_control(
			'title_spacing',
			array(
				'label'      => 'Space Below',
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em' ),
				'range'      => array( 'px' => array( 'max' => 80 ) ),
				'selectors'  => array( '{{WRAPPER}} .citykleta-bike-request__title' => 'margin-bottom: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->end_controls_section();

		// --- Labels & help text ---------------------------------------------
		$this->start_controls_section(
			'section_style_labels',
			array(
				'label' => 'Labels',
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'label_color',
			array(
				'label'     => 'Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( $labels => 'color: {{VALUE}};' ),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'label_typography',
				'selector' => $labels,
			)
		);

		$this->add_control(
			'help_color',
			array(
				'label'     => 'Help Text Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'separator' => 'before',
				'selectors' => array( '{{WRAPPER}} .citykleta-bike-request__field small' => 'color: {{VALUE}};' ),
			)
		);

		$this->add_responsive_control(
			'field_gap',
			array(
				'label'      => 'Space Between Fields',
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em' ),
				'range'      => array( 'px' => array( 'max' => 60 ) ),
				'separator'  => 'before',
				'selectors'  => array(
					'{{WRAPPER}} .citykleta-bike-request__form' => 'gap: {{SIZE}}{{UNIT}};',
					'{{WRAPPER}} .citykleta-bike-request__step' => 'gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();

		// --- Fields ---------------------------------------------------------
		$this->start_controls_section(
			'section_style_fields',
			array(
				'label' => 'Fields',
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'field_text_color',
			array(
				'label'     => 'Text Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( $fields => 'color: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'field_background',
			array(
				'label'     => 'Background Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( $fields => 'background-color: {{VALUE}};' ),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'field_typography',
				'selector' => $fields,
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			array(
				'name'     => 'field_border',
				'selector' => $fields,
			)
		);

		$this->add_responsive_control(
			'field_radius',
			array(
				'label'      => 'Border Radius',
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array( $fields => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'field_padding',
			array(
				'label'      => 'Padding',
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array( $fields => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);

		$this->end_controls_section();

		// --- Primary buttons (Continue / Submit) ----------------------------
		$this->start_controls_section(
			'section_style_button',
			array(
				'label' => 'Buttons',
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'button_typography',
				'selector' => $primary,
			)
		);

		$this->start_controls_tabs( 'button_tabs' );

		$this->start_controls_tab( 'button_tab_normal', array( 'label' => 'Normal' ) );

		$this->add_control(
			'button_color',
			array(
				'label'     => 'Text Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( $primary => 'color: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'button_background',
			array(
				'label'     => 'Background Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( $primary => 'background-color: {{VALUE}};' ),
			)
		);

		$this->end_controls_tab();

		$this->start_controls_tab( 'button_tab_hover', array( 'label' => 'Hover' ) );

		$this->add_control(
			'button_color_hover',
			array(
				'label'     => 'Text Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .citykleta-bike-request__submit:hover, {{WRAPPER}} .citykleta-bike-request__next:hover' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'button_background_hover',
			array(
				'label'     => 'Background Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .citykleta-bike-request__submit:hover, {{WRAPPER}} .citykleta-bike-request__next:hover' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			array(
				'name'      => 'button_border',
				'selector'  => $primary,
				'separator' => 'before',
			)
		);

		$this->add_responsive_control(
			'button_radius',
			array(
				'label'      => 'Border Radius',
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array( $primary => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'button_padding',
			array(
				'label'      => 'Padding',
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array( $primary => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'button_shadow',
				'selector' => $primary,
			)
		);

		$this->add_control(
			'back_heading',
			array(
				'label'     => 'Back Button',
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'back_color',
			array(
				'label'     => 'Text Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .citykleta-bike-request__back' => 'color: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'back_border_color',
			array(
				'label'     => 'Border Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .citykleta-bike-request__back' => 'border-color: {{VALUE}};' ),
			)
		);

		$this->end_controls_section();

		// --- Verify link -----------------------------------------------------
		$this->start_controls_section(
			'section_style_verify',
			array(
				'label'     => 'Verification Link',
				'tab'       => \Elementor\Controls_Manager::TAB_STYLE,
				'condition' => array( 'show_code' => 'yes' ),
			)
		);

		$this->add_control(
			'verify_color',
			array(
				'label'     => 'Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .citykleta-bike-request__verify' => 'color: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'verify_color_hover',
			array(
				'label'     => 'Hover Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .citykleta-bike-request__verify:hover' => 'color: {{VALUE}};' ),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'verify_typography',
				'selector' => '{{WRAPPER}} .citykleta-bike-request__verify',
			)
		);

		$this->end_controls_section();

		// --- Note ------------------------------------------------------------
		$this->start_controls_section(
			'section_style_note',
			array(
				'label' => 'Note',
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'note_color',
			array(
				'label'     => 'Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .citykleta-bike-request__note' => 'color: {{VALUE}};' ),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'note_typography',
				'selector' => '{{WRAPPER}} .citykleta-bike-request__note',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Prints the request card.
	 *
	 * The price is NOT here any more: it's one value of the bike like any
	 * other, so it belongs to the "Bike Data" widget, where it can be placed
	 * and styled anywhere instead of only at the top of this card.
	 */
	protected function render() {
		$bike_id = $this->bike_id();

		if ( ! $bike_id ) {
			$this->render_editor_note( 'Bike Request: shown on a bike\'s page.' );
			return;
		}

		$settings = $this->get_settings_for_display();
		?>
		<div class="citykleta-bike-single__aside">
			<div class="citykleta-bike-single__price-card">
				<?php
				// Solicitud, no reserva: acá no se procesa ningún pago — el
				// dueño coordina el cobro (en efectivo) al recibirla. Ver
				// includes/requests/class-citykleta-bike-request.php.
				Citykleta_Bike_Request_Form::render( $bike_id, self::form_args( $settings ) );
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Turns this widget's settings into the args the form helper takes.
	 *
	 * Only the keys the form knows about are passed through
	 * (Citykleta_Bike_Request_Form::defaults() fills in the rest), so the
	 * form stays usable from its shortcode and from templates/single-bike.php
	 * without a widget instance behind it.
	 *
	 * @param array $settings Widget settings.
	 * @return array
	 */
	private static function form_args( array $settings ) {
		/*
		 * Two lists, and the split is the whole point: an empty TEXT control
		 * means "I didn't fill this in", so it has to fall through to the
		 * form's own default — but an empty SWITCHER means "off", which is a
		 * decision and must be passed on.
		 *
		 * Lumping them together is exactly what broke "Form Title": Elementor
		 * stores an off switcher as '', the '' !== $value guard dropped the
		 * key, and array_merge() in Citykleta_Bike_Request_Form::render() then
		 * filled the hole back in with the 'yes' default. The switcher moved
		 * and nothing ever happened.
		 */
		$text_keys = array(
			'title',
			'dates_label',
			'dates_hint',
			'code_label',
			'verify_text',
			'next_text',
			'back_text',
			'submit_text',
		);

		$switcher_keys = array(
			'show_title',
			'show_code',
			'require_code',
		);

		$args = array();

		foreach ( $text_keys as $key ) {
			if ( isset( $settings[ $key ] ) && '' !== $settings[ $key ] ) {
				$args[ $key ] = $settings[ $key ];
			}
		}

		foreach ( $switcher_keys as $key ) {
			if ( isset( $settings[ $key ] ) ) {
				$args[ $key ] = $settings[ $key ];
			}
		}

		// Elementor's URL control stores {url, is_external, nofollow}; the form
		// only needs the address — it always opens the link in a new tab with
		// rel="noopener noreferrer", which is not a per-link decision here.
		if ( isset( $settings['verify_url']['url'] ) ) {
			$args['verify_url'] = $settings['verify_url']['url'];
		}

		return $args;
	}
}

/**
 * The address the bike is at, as plain text.
 *
 * Split out of the pickup map on purpose: the map draws the map, and the
 * address is a line of text that wants to be placed and styled on its own —
 * above the map, below it, or in the request card.
 */
class Citykleta_Bike_Directions_Widget extends Citykleta_Bike_Part_Widget {

	/**
	 * Internal widget name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'citykleta_bike_directions';
	}

	/**
	 * Title shown in the Elementor editor.
	 *
	 * @return string
	 */
	public function get_title() {
		return 'Bike Directions';
	}

	/**
	 * Widget icon in the Elementor panel.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-map-pin';
	}

	/**
	 * Controls shown in Elementor's left panel.
	 */
	protected function register_controls() {
		$this->start_controls_section( 'section_content', array( 'label' => 'Content' ) );

		$this->add_control(
			'tag',
			array(
				'label'   => 'HTML Tag',
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'p',
				'options' => array(
					'p'    => 'p',
					'span' => 'span',
					'div'  => 'div',
					'h3'   => 'H3',
					'h4'   => 'H4',
				),
			)
		);

		$this->add_control(
			'prefix',
			array(
				'label'       => 'Prefix',
				'type'        => \Elementor\Controls_Manager::TEXT,
				'placeholder' => 'E.g. Recogida en',
				'description' => 'Optional text before the address.',
			)
		);

		$this->end_controls_section();

		/*
		 * Elementor's own Text Editor Style tab (Alignment, Typography, Text
		 * Shadow, Paragraph Spacing, Normal/Hover colors) — shared with
		 * Citykleta_Bike_Description_Widget so the two widgets style their
		 * text identically. Not its Content tab: Drop Cap and Columns are
		 * for a block of paragraphs, and an address is one line — that half
		 * of the request doesn't apply here, so the Content tab above stays
		 * exactly as it was (HTML Tag, Prefix).
		 */
		self::register_text_editor_style_section( $this, '{{WRAPPER}} .citykleta-bike-single__directions' );
	}

	/**
	 * Prints the address.
	 */
	protected function render() {
		$bike_id = $this->bike_id();
		$address = $bike_id ? trim( (string) get_post_meta( $bike_id, 'location', true ) ) : '';

		if ( '' === $address ) {
			$this->render_editor_note( 'Bike Directions: this bike has no address yet.' );
			return;
		}

		$settings = $this->get_settings_for_display();
		$allowed  = array( 'p', 'span', 'div', 'h3', 'h4' );
		$tag      = in_array( $settings['tag'], $allowed, true ) ? $settings['tag'] : 'p';
		$prefix   = trim( (string) $settings['prefix'] );
		$text     = '' !== $prefix ? $prefix . ' ' . $address : $address;

		printf(
			'<%1$s class="citykleta-bike-single__directions">%2$s</%1$s>',
			esc_html( $tag ),
			esc_html( $text )
		);
	}
}

/*
 * "Dónde recogerla" used to be Citykleta_Bike_Location_Widget, a seventh part
 * widget that drew the bike's own spot on a small map. It is gone: the map
 * widget (Citykleta_Map_Widget) now does it through its "Bike Data" source.
 *
 * Keeping two map widgets had already cost something real — the pickup map
 * built its own data-options by hand and so never went through
 * Citykleta_Settings::tile_layer() / cluster_options() / marker_icon_size(),
 * which meant it silently ignored the map configuration synced from Back
 * Office while every other map on the site honoured it.
 *
 * [citykleta_bike_location] still works: Citykleta_Widget_Shortcodes maps it
 * onto the map widget with source=bike.
 */

/**
 * Any one value of the bike, chosen from a dropdown.
 *
 * The counterpart to Bike Specs: that one prints the whole technical table,
 * this one prints a single datum wherever the layout wants it — the price next
 * to the request card, the address under the map, the view count in a corner.
 * It exists because a value with no widget of its own is a value the layout
 * simply cannot show, and writing one class per field would be a dozen
 * near-identical classes (the same reasoning that merged the two map widgets
 * into one with a Source control).
 *
 * It is also where the price lives now that Bike Request no longer prints it.
 *
 * Description, Address and Owner keep their dedicated widgets: those carry
 * presentation of their own (the full Text Editor panel with its drop cap, the
 * owner's photo) that a generic field picker would either duplicate badly or
 * flatten. Here they are the plain value, for when that is all the layout
 * needs.
 */
class Citykleta_Bike_Data_Widget extends Citykleta_Bike_Part_Widget {

	/**
	 * The values this widget can print: key => [admin label, front-end label].
	 *
	 * Two labels on purpose, and it isn't redundancy. The first is what the
	 * Elementor dropdown shows, and wp-admin is in English by convention; the
	 * second is what the visitor reads, and the front-end is in Spanish. Bike
	 * Specs already learned this the hard way, where reading the taxonomy's own
	 * wp-admin label printed "Bike type" in a table whose other rows said
	 * "Marca" and "Modelo".
	 */
	const FIELDS = array(
		'price'        => array( 'Price', 'Precio' ),
		'description'  => array( 'Description', 'Descripción' ),
		'address'      => array( 'Address (Directions)', 'Dirección' ),
		'owner'        => array( 'Owner', 'Propietario' ),
		'availability' => array( 'Availability', 'Disponibilidad' ),
		'destinations' => array( 'Destinations', 'Destinos' ),
		'views'        => array( 'Views', 'Vistas' ),
		'brand'        => array( 'Brand', 'Marca' ),
		'model'        => array( 'Model', 'Modelo' ),
		'size'         => array( 'Frame Size', 'Talla' ),
		'wheel_size'   => array( 'Wheel Size', 'Tamaño de rueda' ),
		'electric'     => array( 'Electric', 'Eléctrica' ),
		'bike_type'    => array( 'Bike Type', 'Tipo' ),
		'bike_usage'   => array( 'Usage Type', 'Uso' ),
	);

	/**
	 * Internal widget name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'citykleta_bike_data';
	}

	/**
	 * Title shown in the Elementor editor.
	 *
	 * @return string
	 */
	public function get_title() {
		return 'Bike Data';
	}

	/**
	 * Widget icon in the Elementor panel.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-database';
	}

	/**
	 * Controls shown in Elementor's left panel.
	 */
	protected function register_controls() {
		$options = array();

		foreach ( self::FIELDS as $key => $labels ) {
			$options[ $key ] = $labels[0];
		}

		$this->start_controls_section( 'section_content', array( 'label' => 'Content' ) );

		$this->add_control(
			'field',
			array(
				'label'   => 'Data',
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => $options,
				'default' => 'price',
			)
		);

		$this->add_control(
			'show_label',
			array(
				'label'     => 'Label',
				'type'      => \Elementor\Controls_Manager::SWITCHER,
				'label_on'  => 'Show',
				'label_off' => 'Hide',
				'default'   => 'no',
			)
		);

		$this->add_control(
			'label',
			array(
				'label'       => 'Label Text',
				'type'        => \Elementor\Controls_Manager::TEXT,
				'placeholder' => 'Leave empty to use the field name',
				'default'     => '',
				'condition'   => array( 'show_label' => 'yes' ),
			)
		);

		$this->add_control(
			'tag',
			array(
				'label'     => 'HTML Tag',
				'type'      => \Elementor\Controls_Manager::SELECT,
				'options'   => array(
					'p'    => 'p',
					'div'  => 'div',
					'span' => 'span',
					'h2'   => 'H2',
					'h3'   => 'H3',
					'h4'   => 'H4',
					'h5'   => 'H5',
					'h6'   => 'H6',
				),
				'default'   => 'p',
				// Description is rich HTML (its own paragraphs) and Owner has
				// block markup of its own, so neither gets wrapped in a tag
				// chosen here.
				'condition' => array( 'field!' => array( 'description', 'owner' ) ),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_label',
			array(
				'label'     => 'Label',
				'tab'       => \Elementor\Controls_Manager::TAB_STYLE,
				'condition' => array( 'show_label' => 'yes' ),
			)
		);

		$this->add_control(
			'label_color',
			array(
				'label'     => 'Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .citykleta-bike-single__data-label' => 'color: {{VALUE}};' ),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'label_typography',
				'selector' => '{{WRAPPER}} .citykleta-bike-single__data-label',
			)
		);

		$this->end_controls_section();

		// The same Style panel the description and address widgets use, so a
		// value styled here means exactly what it means there.
		self::register_text_editor_style_section( $this, '{{WRAPPER}} .citykleta-bike-single__data' );
	}

	/**
	 * Prints the chosen value.
	 */
	protected function render() {
		$bike_id = $this->bike_id();

		if ( ! $bike_id ) {
			$this->render_editor_note( 'Bike Data: shown on a bike\'s page.' );
			return;
		}

		$settings = $this->get_settings_for_display();
		$field    = isset( $settings['field'] ) ? $settings['field'] : 'price';

		if ( ! isset( self::FIELDS[ $field ] ) ) {
			return;
		}

		$value = self::value_html( $bike_id, $field );

		if ( '' === $value ) {
			$this->render_editor_note(
				sprintf( 'Bike Data: this bike has no "%s" value.', self::FIELDS[ $field ][0] )
			);
			return;
		}

		$label = '';

		if ( 'yes' === $settings['show_label'] ) {
			$label = '' !== trim( (string) $settings['label'] )
				? $settings['label']
				: self::FIELDS[ $field ][1];
		}

		echo '<div class="citykleta-bike-single__data citykleta-bike-single__data--' . esc_attr( $field ) . '">';

		if ( '' !== $label ) {
			echo '<span class="citykleta-bike-single__data-label">' . esc_html( $label ) . '</span>';
		}

		if ( in_array( $field, array( 'description', 'owner' ), true ) ) {
			// Already complete markup — see value_html().
			echo $value; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			$tag = isset( $settings['tag'] ) ? $settings['tag'] : 'p';

			printf(
				'<%1$s class="citykleta-bike-single__data-value">%2$s</%1$s>',
				esc_html( $tag ),
				// Escaped per branch inside value_html(): every branch either
				// runs through esc_html() or builds its own links.
				$value // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
		}

		echo '</div>';
	}

	/**
	 * One field's value, already escaped, or '' when the bike hasn't got it.
	 *
	 * @param int    $bike_id Bike post ID.
	 * @param string $field   A key of self::FIELDS.
	 * @return string HTML.
	 */
	private static function value_html( $bike_id, $field ) {
		switch ( $field ) {
			case 'price':
				return esc_html( (string) Citykleta_Bike_Meta::price_html( $bike_id ) );

			case 'description':
				$content = get_post_field( 'post_content', $bike_id );

				if ( '' === trim( (string) $content ) ) {
					return '';
				}

				// wpautop( do_shortcode() ) rather than the_content: this runs
				// inside a page render that is already inside that filter.
				return wp_kses_post( wpautop( do_shortcode( $content ) ) );

			case 'address':
				return esc_html( (string) get_post_meta( $bike_id, 'location', true ) );

			case 'owner':
				return self::owner_html( $bike_id );

			case 'availability':
				/*
				 * '0' is a real answer ("not available"), not a missing value —
				 * the same falsy trap is_electric has in Bike Specs, so what's
				 * compared here is the empty string, never truthiness.
				 */
				$available = get_post_meta( $bike_id, 'available', true );

				if ( '' === $available ) {
					return '';
				}

				return '1' === (string) $available ? 'Disponible' : 'No disponible';

			case 'electric':
				$electric = get_post_meta( $bike_id, 'is_electric', true );

				if ( '' === $electric ) {
					return '';
				}

				return '1' === (string) $electric ? 'Sí' : 'No';

			case 'destinations':
				return self::destinations_html( $bike_id );

			case 'views':
				$views = class_exists( 'Citykleta_View_Stats' ) ? Citykleta_View_Stats::count( $bike_id ) : 0;

				return $views ? esc_html( (string) $views ) : '';

			case 'bike_type':
			case 'bike_usage':
				return esc_html( self::term_names( $bike_id, $field ) );

			default:
				// brand / model / size / wheel_size — plain meta, same key.
				return esc_html( (string) get_post_meta( $bike_id, $field, true ) );
		}
	}

	/**
	 * The owner block: photo (when the app sent one) plus name.
	 *
	 * Deliberately the same markup and classes as Citykleta_Bike_Owner_Widget,
	 * so the two can't drift into looking different for the same data.
	 *
	 * @param int $bike_id Bike post ID.
	 * @return string HTML.
	 */
	private static function owner_html( $bike_id ) {
		$name = (string) get_post_meta( $bike_id, 'owner_name', true );

		if ( '' === $name ) {
			return '';
		}

		$photo = (string) get_post_meta( $bike_id, 'owner_photo', true );
		$html  = '<section class="citykleta-bike-single__owner">';

		if ( '' !== $photo ) {
			$html .= '<img class="citykleta-bike-single__owner-photo" src="' . esc_url( $photo )
				. '" alt="' . esc_attr( $name ) . '" width="48" height="48" loading="lazy" />';
		}

		$html .= '<div><span class="citykleta-bike-single__owner-name">' . esc_html( $name ) . '</span></div>';
		$html .= '</section>';

		return $html;
	}

	/**
	 * The destinations whose geofence contains this bike, as links.
	 *
	 * This is the first screen anywhere to show that assignment: the matcher
	 * has been writing it on every sync (Citykleta_Destination_Matcher) and
	 * destinations_for_bike() had no caller until now.
	 *
	 * @param int $bike_id Bike post ID.
	 * @return string HTML.
	 */
	private static function destinations_html( $bike_id ) {
		if ( ! class_exists( 'Citykleta_Destination_Matcher' ) ) {
			return '';
		}

		$links = array();

		foreach ( Citykleta_Destination_Matcher::destinations_for_bike( $bike_id ) as $destination_id ) {
			if ( 'publish' !== get_post_status( $destination_id ) ) {
				continue;
			}

			$links[] = '<a href="' . esc_url( (string) get_permalink( $destination_id ) ) . '">'
				. esc_html( get_the_title( $destination_id ) ) . '</a>';
		}

		return implode( ', ', $links );
	}

	/**
	 * A taxonomy's terms for this bike, as a comma-separated list.
	 *
	 * @param int    $bike_id  Bike post ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @return string
	 */
	private static function term_names( $bike_id, $taxonomy ) {
		$terms = get_the_terms( $bike_id, $taxonomy );

		if ( ! $terms || is_wp_error( $terms ) ) {
			return '';
		}

		return implode( ', ', wp_list_pluck( $terms, 'name' ) );
	}
}
