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
	 * A full Style tab for a widget that prints a block of text, matching what
	 * Elementor's own Paragraph offers: type, color, alignment, text shadow,
	 * background, border, radius, box shadow and spacing.
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
class Citykleta_Bike_Header_Widget extends Citykleta_Bike_Part_Widget {

	/**
	 * Internal widget name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'citykleta_bike_header';
	}

	/**
	 * Title shown in the Elementor editor.
	 *
	 * @return string
	 */
	public function get_title() {
		return 'Bike Title & Location';
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
	 * Prints the header.
	 */
	protected function render() {
		$bike_id = $this->bike_id();

		if ( ! $bike_id ) {
			$this->render_editor_note( 'Bike Title & Location: shown on a bike\'s page.' );
			return;
		}

		$settings = $this->get_settings_for_display();
		$location = get_post_meta( $bike_id, 'location', true );
		$allowed  = array( 'h1', 'h2', 'h3' );
		$tag      = in_array( $settings['title_tag'], $allowed, true ) ? $settings['title_tag'] : 'h1';

		$types = get_the_terms( $bike_id, Citykleta_Bike_Type_Taxonomy::TAXONOMY );
		$tags  = is_array( $types ) ? wp_list_pluck( $types, 'name' ) : array();

		if ( '1' === (string) get_post_meta( $bike_id, 'is_electric', true ) ) {
			$tags[] = 'Eléctrica';
		}
		?>
		<header class="citykleta-bike-single__header">
			<<?php echo esc_html( $tag ); ?> class="citykleta-bike-single__title"><?php echo esc_html( get_the_title( $bike_id ) ); ?></<?php echo esc_html( $tag ); ?>>

			<div class="citykleta-bike-single__subhead">
				<?php if ( $location && 'yes' === $settings['show_location'] ) : ?>
					<span class="citykleta-bike-single__location"><?php echo esc_html( $location ); ?></span>
				<?php endif; ?>

				<?php if ( $tags && 'yes' === $settings['show_tags'] ) : ?>
					<ul class="citykleta-bike-single__tags">
						<?php foreach ( $tags as $tag_name ) : ?>
							<li class="citykleta-bike-single__tag"><?php echo esc_html( $tag_name ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		</header>
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
	 * Prints the description, and nothing else.
	 *
	 * No section heading on purpose: that text is static, so it belongs to a
	 * plain Elementor Heading the user owns — editable, movable, removable —
	 * instead of a control of ours that only exists to print a fixed string.
	 * The default layout puts one in front of this widget.
	 */
	protected function register_controls() {
		// Everything Elementor's own Paragraph can style, minus its text field
		// — the text here is the bike's description, which comes from the API.
		$this->register_text_style_section( '.citykleta-bike-single__description', 'description', 'Paragraph' );
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
		?>
		<section class="citykleta-bike-single__section">
			<div class="citykleta-bike-single__description">
				<?php
				/*
				 * Formatted by hand instead of apply_filters( 'the_content' ):
				 * this runs INSIDE the template's own the_content pass, and
				 * re-entering that filter would hand the bike's text to every
				 * content filter again — Elementor's included, which decides
				 * what to render from the current global $post. Bike
				 * descriptions come from the API as plain text, so wpautop +
				 * shortcodes is all they need.
				 */
				echo wp_kses_post( wpautop( do_shortcode( $content ) ) );
				?>
			</div>
		</section>
		<?php
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
				'description' => 'Adds the bike\'s type and usage terms as rows.',
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
					'1' => '1',
					'2' => '2',
					'3' => '3',
					'4' => '4',
				),
				'selectors'      => array(
					'{{WRAPPER}} .citykleta-bike-single__specs' => 'display: grid; grid-template-columns: repeat({{VALUE}}, minmax(0, 1fr));',
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

		// Each row is its own box, so border/shadow/padding go on the row
		// rather than on the grid — that's what makes a table read as a table.
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
				'selector' => '{{WRAPPER}} .citykleta-bike-single__spec',
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			array(
				'name'     => 'spec_border',
				'selector' => '{{WRAPPER}} .citykleta-bike-single__spec',
			)
		);

		$this->add_responsive_control(
			'spec_radius',
			array(
				'label'      => 'Border Radius',
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .citykleta-bike-single__spec' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'spec_box_shadow',
				'selector' => '{{WRAPPER}} .citykleta-bike-single__spec',
			)
		);

		$this->add_responsive_control(
			'spec_padding',
			array(
				'label'      => 'Padding',
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array(
					'{{WRAPPER}} .citykleta-bike-single__spec' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
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

		?>
		<section class="citykleta-bike-single__section">
			<dl class="citykleta-bike-single__specs">
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
	 * The bike's classification, as rows: its type (synced from the app) and
	 * its usage (declared by hand in wp-admin).
	 *
	 * Labels come from the taxonomy's own singular/plural, so a bike with two
	 * usages reads "Tipos de uso" and one with a single type reads "Tipo de
	 * bicicleta", without those strings being repeated here.
	 *
	 * @param int $bike_id Bike post ID.
	 * @return array Label => comma-separated term names.
	 */
	private static function taxonomy_specs( $bike_id ) {
		$rows = array();

		foreach ( array( Citykleta_Bike_Type_Taxonomy::TAXONOMY, Citykleta_Bike_Usage_Taxonomy::TAXONOMY ) as $taxonomy ) {
			$terms = get_the_terms( $bike_id, $taxonomy );

			if ( ! $terms || is_wp_error( $terms ) ) {
				continue;
			}

			$object = get_taxonomy( $taxonomy );

			if ( ! $object ) {
				continue;
			}

			$label = count( $terms ) > 1 ? $object->labels->name : $object->labels->singular_name;

			$rows[ $label ] = implode( ', ', wp_list_pluck( $terms, 'name' ) );
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
		return 'Bike Price & Request';
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
			'sticky',
			array(
				'label'       => 'Stick On Scroll',
				'type'        => \Elementor\Controls_Manager::SWITCHER,
				'label_on'    => 'Yes',
				'label_off'   => 'No',
				'default'     => 'yes',
				'description' => 'Follows the visitor down the page. Ignored on narrow screens.',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Prints the price card + request form.
	 */
	protected function render() {
		$bike_id = $this->bike_id();

		if ( ! $bike_id ) {
			$this->render_editor_note( 'Bike Price & Request: shown on a bike\'s page.' );
			return;
		}

		$settings = $this->get_settings_for_display();
		$price    = Citykleta_Bike_Meta::price_html( $bike_id );
		$classes  = 'citykleta-bike-single__aside';

		if ( 'yes' === $settings['sticky'] ) {
			$classes .= ' citykleta-bike-single__aside--sticky';
		}
		?>
		<div class="<?php echo esc_attr( $classes ); ?>">
			<div class="citykleta-bike-single__price-card">
				<?php if ( $price && 'yes' === $settings['show_price'] ) : ?>
					<p class="citykleta-bike-single__price"><?php echo esc_html( $price ); ?></p>
				<?php endif; ?>

				<?php
				// Solicitud, no reserva: acá no se procesa ningún pago — el
				// dueño coordina el cobro (en efectivo) al recibirla. Ver
				// includes/requests/class-citykleta-bike-request.php.
				Citykleta_Bike_Request_Form::render( $bike_id );
				?>
			</div>
		</div>
		<?php
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

		// Same set Elementor's own Paragraph offers, minus its text field —
		// the text here is the bike's address, which comes from the API.
		$this->register_text_style_section( '.citykleta-bike-single__directions', 'directions', 'Paragraph' );
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
