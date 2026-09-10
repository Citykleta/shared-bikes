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
		if ( is_singular( Citykleta_Bike_Post_Type::POST_TYPE ) ) {
			return (int) get_queried_object_id();
		}

		if ( self::is_elementor_canvas() || self::is_template_preview() ) {
			return self::preview_bike_id();
		}

		return 0;
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
	 * Controls shown in Elementor's left panel.
	 */
	protected function register_controls() {
		$this->start_controls_section( 'section_content', array( 'label' => 'Content' ) );

		$this->add_control(
			'heading',
			array(
				'label'       => 'Heading',
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => 'Sobre esta bicicleta',
				'description' => 'Leave empty for no heading.',
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

		$settings = $this->get_settings_for_display();
		?>
		<section class="citykleta-bike-single__section">
			<?php if ( ! empty( $settings['heading'] ) ) : ?>
				<h2 class="citykleta-bike-single__section-title"><?php echo esc_html( $settings['heading'] ); ?></h2>
			<?php endif; ?>
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
			'heading',
			array(
				'label'       => 'Heading',
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => 'Ficha técnica',
				'description' => 'Leave empty for no heading.',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Prints the spec grid.
	 */
	protected function render() {
		$bike_id = $this->bike_id();

		if ( ! $bike_id ) {
			$this->render_editor_note( 'Bike Specs: shown on a bike\'s page.' );
			return;
		}

		// Only fields that actually have a value are listed.
		$specs = array_filter(
			array(
				'Marca'           => get_post_meta( $bike_id, 'brand', true ),
				'Modelo'          => get_post_meta( $bike_id, 'model', true ),
				'Talla'           => get_post_meta( $bike_id, 'size', true ),
				'Tamaño de rueda' => get_post_meta( $bike_id, 'wheel_size', true ),
			)
		);

		if ( ! $specs ) {
			$this->render_editor_note( 'Bike Specs: this bike has no technical data filled in.' );
			return;
		}

		$settings = $this->get_settings_for_display();
		?>
		<section class="citykleta-bike-single__section">
			<?php if ( ! empty( $settings['heading'] ) ) : ?>
				<h2 class="citykleta-bike-single__section-title"><?php echo esc_html( $settings['heading'] ); ?></h2>
			<?php endif; ?>
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
 * "Dónde recogerla" — the bike's own spot on a small map.
 */
class Citykleta_Bike_Location_Widget extends Citykleta_Bike_Part_Widget {

	/**
	 * Internal widget name. Also the AJAX re-render hook name
	 * citykleta-map.js listens on ("frontend/element_ready/{name}.default").
	 *
	 * @return string
	 */
	public function get_name() {
		return 'citykleta_bike_location';
	}

	/**
	 * Title shown in the Elementor editor.
	 *
	 * @return string
	 */
	public function get_title() {
		return 'Bike Pickup Map';
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
	 * Controls shown in Elementor's left panel.
	 */
	protected function register_controls() {
		$this->start_controls_section( 'section_content', array( 'label' => 'Content' ) );

		$this->add_control(
			'heading',
			array(
				'label'       => 'Heading',
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => 'Dónde recogerla',
				'description' => 'Leave empty for no heading.',
			)
		);

		$this->add_control(
			'show_caption',
			array(
				'label'     => 'Location Text',
				'type'      => \Elementor\Controls_Manager::SWITCHER,
				'label_on'  => 'Show',
				'label_off' => 'Hide',
				'default'   => 'yes',
			)
		);

		$this->add_responsive_control(
			'map_height',
			array(
				'label'      => 'Map Height',
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 150, 'max' => 700 ) ),
				'default'    => array( 'size' => 220 ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Prints the mini map.
	 */
	protected function render() {
		$bike_id  = $this->bike_id();
		$map_bike = $bike_id ? Citykleta_Bike_Map_Data::for_bike( $bike_id ) : null;

		if ( ! $map_bike ) {
			$this->render_editor_note( 'Bike Pickup Map: this bike has no coordinates yet (run a sync).' );
			return;
		}

		$settings = $this->get_settings_for_display();
		$location = get_post_meta( $bike_id, 'location', true );

		// Height inline rather than through the control's `selectors`: that
		// only becomes CSS once Elementor writes the page's stylesheet, which
		// never happens for the shortcode form of this widget — and with no
		// explicit height Leaflet draws nothing at all. Same reasoning as
		// Citykleta_Map_Widget::render().
		$height = isset( $settings['map_height']['size'] ) && is_numeric( $settings['map_height']['size'] )
			? (float) $settings['map_height']['size']
			: 220;
		?>
		<section class="citykleta-bike-single__section">
			<?php if ( ! empty( $settings['heading'] ) ) : ?>
				<h2 class="citykleta-bike-single__section-title"><?php echo esc_html( $settings['heading'] ); ?></h2>
			<?php endif; ?>

			<div
				class="citykleta-map citykleta-bike-single__location-map"
				style="height:<?php echo esc_attr( $height ); ?>px;"
				data-bikes="<?php echo esc_attr( wp_json_encode( array( $map_bike ) ) ); ?>"
				data-options="<?php echo esc_attr( wp_json_encode( array( 'centerOnUser' => false ) ) ); ?>"
			></div>

			<?php if ( $location && 'yes' === $settings['show_caption'] ) : ?>
				<p class="citykleta-bike-single__location-caption"><?php echo esc_html( $location ); ?></p>
			<?php endif; ?>
		</section>
		<?php
	}
}
