<?php
/**
 * The pieces a Destination layout is built from: header, description, average
 * price, related articles and weather. Each one is an Elementor widget (and a
 * shortcode, via Citykleta_Widget_Shortcodes), so a destination page is
 * arranged by hand in Elementor instead of being a fixed PHP file — the same
 * arrangement already used for bikes, see Citykleta_Bike_Part_Widgets.
 *
 * **How one layout serves every destination:** none of these take an ID. They
 * each ask self::destination_id(), which reads the destination being viewed
 * straight off the main query. That is what makes this work on the free
 * Elementor — the plugin resolves the data, so Elementor never has to.
 *
 * There is deliberately no "bikes of this destination" widget here: the grid
 * widget (Citykleta_Bike_Widget) grew a "Destination Bikes" source instead, so
 * the card markup and its whole Style tab are shared rather than copied. Same
 * reasoning that merged the pickup map into Citykleta_Map_Widget.
 *
 * Static section titles ("Artículos relacionados") are
 * NOT printed by these widgets — they are native Elementor Headings placed by
 * the default layout, so they can be restyled, renamed or removed like any
 * other element. See the note in Citykleta_Bike_Part_Widgets.
 *
 * @package Citykleta_Shared_Bikes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared base: resolving the destination, and the editor's preview fallback.
 */
abstract class Citykleta_Destination_Part_Widget extends \Elementor\Widget_Base {

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
		return array( 'destination', 'destino', 'citykleta' );
	}

	/**
	 * The destination this instance should draw.
	 *
	 * @return int
	 */
	protected function destination_id() {
		return self::current_destination_id();
	}

	/**
	 * Same resolution, reachable from outside this class hierarchy (the grid
	 * widget needs it for its "Destination Bikes" source).
	 *
	 * On a destination's page that's the destination being viewed. When the
	 * TEMPLATE itself is on screen there is nothing in the query, so a real
	 * destination stands in purely so the design can be judged — exactly the
	 * fallback Citykleta_Bike_Part_Widget uses for bikes. Anywhere else there
	 * is deliberately nothing to draw, rather than a random destination
	 * appearing on an unrelated page.
	 *
	 * @return int Destination post ID, or 0 when there is none in context.
	 */
	public static function current_destination_id() {
		if ( is_singular( Citykleta_Destination_Post_Type::POST_TYPE ) ) {
			return (int) get_queried_object_id();
		}

		if ( Citykleta_Bike_Part_Widget::is_design_context() ) {
			$latest = get_posts(
				array(
					'post_type'      => Citykleta_Destination_Post_Type::POST_TYPE,
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'fields'         => 'ids',
				)
			);

			return $latest ? (int) $latest[0] : 0;
		}

		return 0;
	}

	/**
	 * Query args for the published bikes assigned to a destination.
	 *
	 * The assignment is post meta on the BIKE (one row per destination, written
	 * by Citykleta_Destination_Matcher from the geofence), which is why this is
	 * a plain meta_query and not a taxonomy lookup.
	 *
	 * @param int $destination_id Destination post ID.
	 * @return array
	 */
	public static function bikes_query_args( $destination_id ) {
		return array(
			'post_type'   => Citykleta_Bike_Post_Type::POST_TYPE,
			'post_status' => 'publish',
			'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => Citykleta_Destination_Matcher::META_KEY,
					'value' => (int) $destination_id,
				),
			),
		);
	}

	/**
	 * Note shown on the Elementor canvas when a widget has nothing to draw, so
	 * an empty spot is never mistaken for a broken widget. Never printed for a
	 * real visitor.
	 *
	 * @param string $message What's missing.
	 */
	protected function render_editor_note( $message ) {
		if ( ! Citykleta_Bike_Part_Widget::is_design_context() ) {
			return;
		}

		printf(
			'<div style="padding:12px 16px;border:1px dashed #c3c4c7;border-radius:6px;color:#646970;font-size:13px;">%s</div>',
			esc_html( $message )
		);
	}
}

/**
 * Destination title + hero image.
 */
class Citykleta_Destination_Header_Widget extends Citykleta_Destination_Part_Widget {

	/**
	 * Internal widget name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'citykleta_destination_header';
	}

	/**
	 * Title shown in the Elementor editor.
	 *
	 * @return string
	 */
	public function get_title() {
		return 'Destination Header';
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
					'h1'   => 'H1',
					'h2'   => 'H2',
					'h3'   => 'H3',
					'div'  => 'div',
					'span' => 'span',
				),
			)
		);

		$this->add_control(
			'show_image',
			array(
				'label'     => 'Hero Image',
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
				'label' => 'Style',
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'title_color',
			array(
				'label'     => 'Title Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .citykleta-destination__title' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'title_typography',
				'selector' => '{{WRAPPER}} .citykleta-destination__title',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Prints the header.
	 */
	protected function render() {
		$destination_id = $this->destination_id();

		if ( ! $destination_id ) {
			$this->render_editor_note( 'Destination Header: shown on a destination page.' );
			return;
		}

		$settings   = $this->get_settings_for_display();
		$allowed    = array( 'h1', 'h2', 'h3', 'div', 'span' );
		$tag        = in_array( $settings['title_tag'], $allowed, true ) ? $settings['title_tag'] : 'h1';
		$show_image = 'yes' === $settings['show_image'] && has_post_thumbnail( $destination_id );
		?>
		<header class="citykleta-destination__header">
			<<?php echo esc_html( $tag ); ?> class="citykleta-destination__title"><?php echo esc_html( get_the_title( $destination_id ) ); ?></<?php echo esc_html( $tag ); ?>>

			<?php if ( $show_image ) : ?>
				<div class="citykleta-destination__hero">
					<?php echo get_the_post_thumbnail( $destination_id, 'large' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core-escaped markup. ?>
				</div>
			<?php endif; ?>
		</header>
		<?php
	}
}

/**
 * The destination's own description (its post content).
 */
class Citykleta_Destination_Description_Widget extends Citykleta_Destination_Part_Widget {

	/**
	 * Internal widget name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'citykleta_destination_description';
	}

	/**
	 * Title shown in the Elementor editor.
	 *
	 * @return string
	 */
	public function get_title() {
		return 'Destination Description';
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
	 * No controls: it prints one thing.
	 */
	protected function register_controls() {
		$this->start_controls_section( 'section_content', array( 'label' => 'Content' ) );
		$this->end_controls_section();
	}

	/**
	 * Prints the description.
	 */
	protected function render() {
		$destination_id = $this->destination_id();
		$content        = $destination_id ? get_post_field( 'post_content', $destination_id, 'raw' ) : '';

		if ( ! trim( (string) $content ) ) {
			$this->render_editor_note( 'Destination Description: this destination has no description.' );
			return;
		}

		/*
		 * Formatted by hand instead of apply_filters( 'the_content' ): this
		 * runs INSIDE the template's own the_content pass, and re-entering that
		 * filter hands the text to every content filter again — Elementor's
		 * included, which decides what to render from the current global $post.
		 * Same reasoning as the bike description widget.
		 */
		printf(
			'<div class="citykleta-destination__description">%s</div>',
			wp_kses_post( wpautop( do_shortcode( $content ) ) )
		);
	}
}

/**
 * Average daily price across the bikes assigned to this destination.
 */
class Citykleta_Destination_Price_Widget extends Citykleta_Destination_Part_Widget {

	/**
	 * Internal widget name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'citykleta_destination_price';
	}

	/**
	 * Title shown in the Elementor editor.
	 *
	 * @return string
	 */
	public function get_title() {
		return 'Destination Average Price';
	}

	/**
	 * Widget icon in the Elementor panel.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-price-list';
	}

	/**
	 * Controls shown in Elementor's left panel.
	 */
	protected function register_controls() {
		$this->start_controls_section( 'section_content', array( 'label' => 'Content' ) );

		$this->add_control(
			'prefix',
			array(
				'label'   => 'Prefix',
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => 'Precio promedio:',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Prints the average, or nothing when no bike here has a price.
	 */
	protected function render() {
		$destination_id = $this->destination_id();

		if ( ! $destination_id ) {
			$this->render_editor_note( 'Destination Average Price: shown on a destination page.' );
			return;
		}

		/*
		 * Averaged in PHP over the destination's own bikes rather than with an
		 * AVG() of its own: the list is small (one geofence), so this avoids a
		 * second trip for a number those same rows already carry.
		 */
		$args                   = self::bikes_query_args( $destination_id );
		$args['posts_per_page'] = -1;
		$args['fields']         = 'ids';

		$prices = array();

		foreach ( get_posts( $args ) as $bike_id ) {
			$price = Citykleta_Bike_Meta::price_amount( $bike_id );

			if ( null !== $price ) {
				$prices[] = $price;
			}
		}

		if ( ! $prices ) {
			$this->render_editor_note( 'Destination Average Price: no bike here has a price yet.' );
			return;
		}

		$settings = $this->get_settings_for_display();
		$average  = array_sum( $prices ) / count( $prices );

		printf(
			'<p class="citykleta-destination__avg-price">%1$s %2$s</p>',
			esc_html( $settings['prefix'] ),
			esc_html( Citykleta_Bike_Meta::format_price( $average ) )
		);
	}
}

/**
 * Blog posts tagged with this destination's slug.
 */
class Citykleta_Destination_Articles_Widget extends Citykleta_Destination_Part_Widget {

	/**
	 * Internal widget name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'citykleta_destination_articles';
	}

	/**
	 * Title shown in the Elementor editor.
	 *
	 * @return string
	 */
	public function get_title() {
		return 'Destination Articles';
	}

	/**
	 * Widget icon in the Elementor panel.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-post-list';
	}

	/**
	 * Controls shown in Elementor's left panel.
	 */
	protected function register_controls() {
		$this->start_controls_section( 'section_content', array( 'label' => 'Content' ) );

		$this->add_control(
			'count',
			array(
				'label'   => 'Articles',
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'min'     => 1,
				'max'     => 20,
				'default' => 6,
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Prints the list.
	 */
	protected function render() {
		$destination_id = $this->destination_id();

		if ( ! $destination_id ) {
			$this->render_editor_note( 'Destination Articles: shown on a destination page.' );
			return;
		}

		$settings = $this->get_settings_for_display();

		// Convention, not a taxonomy of its own: a post belongs to a
		// destination when it carries a tag whose slug matches it.
		$articles = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => (int) $settings['count'],
				'tag'            => get_post_field( 'post_name', $destination_id ),
			)
		);

		if ( ! $articles ) {
			$this->render_editor_note( 'Destination Articles: no post carries this destination tag yet.' );
			return;
		}
		?>
		<ul class="citykleta-destination__article-list">
			<?php foreach ( $articles as $article ) : ?>
				<li>
					<a href="<?php echo esc_url( get_permalink( $article ) ); ?>"><?php echo esc_html( get_the_title( $article ) ); ?></a>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}
}

/**
 * Weather forecast at the destination's geofence center (Citykleta_Weather).
 */
class Citykleta_Destination_Weather_Widget extends Citykleta_Destination_Part_Widget {

	/**
	 * Spanish weekday abbreviations, by date( 'w' ). Written out instead of
	 * read from the site locale: the visitor-facing text is Spanish whatever
	 * language wp-admin runs in.
	 */
	const WEEKDAYS = array( 'Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb' );

	/**
	 * Internal widget name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'citykleta_destination_weather';
	}

	/**
	 * Title shown in the Elementor editor.
	 *
	 * @return string
	 */
	public function get_title() {
		return 'Destination Weather';
	}

	/**
	 * Widget icon in the Elementor panel.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-flash';
	}

	/**
	 * Keywords used to find it when searching in Elementor.
	 *
	 * @return array
	 */
	public function get_keywords() {
		return array_merge( parent::get_keywords(), array( 'weather', 'tiempo', 'clima', 'forecast' ) );
	}

	/**
	 * Controls shown in Elementor's left panel.
	 */
	protected function register_controls() {
		$this->start_controls_section( 'section_content', array( 'label' => 'Content' ) );

		$this->add_control(
			'days',
			array(
				'label'   => 'Days',
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'min'     => 1,
				'max'     => Citykleta_Weather::MAX_DAYS,
				'default' => 3,
			)
		);

		$this->add_control(
			'show_label',
			array(
				'label'   => 'Show Description',
				'type'    => \Elementor\Controls_Manager::SWITCHER,
				'default' => 'yes',
			)
		);

		$this->add_control(
			'show_rain',
			array(
				'label'   => 'Show Chance Of Rain',
				'type'    => \Elementor\Controls_Manager::SWITCHER,
				'default' => 'yes',
			)
		);

		$this->add_control(
			'show_uv',
			array(
				'label'   => 'Show UV Index',
				'type'    => \Elementor\Controls_Manager::SWITCHER,
				'default' => 'yes',
			)
		);

		$this->add_control(
			'credit_note',
			array(
				'type'            => \Elementor\Controls_Manager::RAW_HTML,
				'raw'             => 'Forecast from Open-Meteo, at this destination\'s geofence center, refreshed every 3 hours on the server. The "Open-Meteo" credit under it is required by its license (CC BY 4.0).',
				'content_classes' => 'elementor-descriptor',
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_days',
			array(
				'label' => 'Days',
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'gap',
			array(
				'label'      => 'Gap',
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'rem' ),
				'selectors'  => array(
					'{{WRAPPER}} .citykleta-weather__days' => 'gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'day_bg',
			array(
				'label'     => 'Background',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .citykleta-weather__day' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			array(
				'name'     => 'day_border',
				'selector' => '{{WRAPPER}} .citykleta-weather__day',
			)
		);

		$this->add_responsive_control(
			'day_radius',
			array(
				'label'      => 'Border Radius',
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .citykleta-weather__day' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'day_padding',
			array(
				'label'      => 'Padding',
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'rem' ),
				'selectors'  => array(
					'{{WRAPPER}} .citykleta-weather__day' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'text_color',
			array(
				'label'     => 'Text Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .citykleta-weather' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'text_typography',
				'label'    => 'Typography',
				'selector' => '{{WRAPPER}} .citykleta-weather',
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'temp_typography',
				'label'    => 'Temperature Typography',
				'selector' => '{{WRAPPER}} .citykleta-weather__temp',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Prints the forecast, or nothing when there is no geofence center or no
	 * forecast to show (never an error to the visitor).
	 */
	protected function render() {
		$destination_id = $this->destination_id();

		if ( ! $destination_id ) {
			$this->render_editor_note( 'Destination Weather: shown on a destination page.' );
			return;
		}

		$lat = (float) get_post_meta( $destination_id, Citykleta_Destination_Post_Type::META_LAT, true );
		$lng = (float) get_post_meta( $destination_id, Citykleta_Destination_Post_Type::META_LNG, true );

		// 0,0 is what an empty field saves as.
		if ( 0.0 === $lat && 0.0 === $lng ) {
			$this->render_editor_note( 'Destination Weather: this destination has no geofence center yet.' );
			return;
		}

		$settings = $this->get_settings_for_display();
		$days     = Citykleta_Weather::daily( $lat, $lng, (int) $settings['days'] );

		if ( ! $days ) {
			$this->render_editor_note( 'Destination Weather: no forecast available right now (Open-Meteo unreachable?).' );
			return;
		}

		$zone     = new DateTimeZone( Citykleta_Weather::TIMEZONE );
		$today    = wp_date( 'Y-m-d', null, $zone );
		$tomorrow = wp_date( 'Y-m-d', time() + DAY_IN_SECONDS, $zone );
		?>
		<div class="citykleta-weather">
			<ul class="citykleta-weather__days">
				<?php
				foreach ( $days as $day ) :
					$about = Citykleta_Weather::describe( $day['code'] );

					if ( $day['date'] === $today ) {
						$name = 'Hoy';
					} elseif ( $day['date'] === $tomorrow ) {
						$name = 'Mañana';
					} else {
						$stamp = strtotime( $day['date'] . ' 12:00:00 UTC' );
						$name  = self::WEEKDAYS[ (int) gmdate( 'w', $stamp ) ] . ' ' . gmdate( 'j', $stamp );
					}
					?>
					<li class="citykleta-weather__day">
						<span class="citykleta-weather__name"><?php echo esc_html( $name ); ?></span>
						<?php if ( $about['icon'] ) : ?>
							<span class="citykleta-weather__icon" role="img" aria-label="<?php echo esc_attr( $about['label'] ); ?>"><?php echo esc_html( $about['icon'] ); ?></span>
						<?php endif; ?>
						<span class="citykleta-weather__temp"><?php echo esc_html( round( $day['max'] ) ); ?>° <span class="citykleta-weather__min"><?php echo esc_html( round( $day['min'] ) ); ?>°</span></span>
						<?php if ( 'yes' === $settings['show_label'] && $about['label'] ) : ?>
							<span class="citykleta-weather__label"><?php echo esc_html( $about['label'] ); ?></span>
						<?php endif; ?>
						<?php if ( 'yes' === $settings['show_rain'] && null !== $day['rain'] ) : ?>
							<span class="citykleta-weather__rain">Lluvia <?php echo esc_html( round( $day['rain'] ) ); ?>%</span>
						<?php endif; ?>
						<?php if ( 'yes' === $settings['show_uv'] && null !== $day['uv'] ) : ?>
							<span class="citykleta-weather__uv">UV <?php echo esc_html( round( $day['uv'] ) ); ?></span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
			<p class="citykleta-weather__credit">Pronóstico: <a href="<?php echo esc_url( Citykleta_Weather::CREDIT_URL ); ?>" target="_blank" rel="noopener noreferrer">Open-Meteo</a></p>
		</div>
		<?php
	}
}
