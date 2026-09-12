<?php
/**
 * Loads the plugin's front-end templates (archive and bike detail).
 *
 * @package Citykleta_Shared_Bikes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Citykleta_Templates.
 *
 * Serves the templates bundled with the plugin, but lets the active theme
 * override them by placing a file with the same name in its own folder
 * (the same pattern WooCommerce and other plugins use).
 */
class Citykleta_Templates {

	/**
	 * Connects the class to WordPress hooks.
	 */
	public function register() {
		add_filter( 'template_include', array( $this, 'load_archive_template' ) );
		add_filter( 'template_include', array( $this, 'load_single_template' ) );
		add_filter( 'template_include', array( $this, 'load_destination_template' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );
		add_action( 'pre_get_posts', array( $this, 'apply_search_filters' ) );
	}

	/**
	 * Filters the bike archive by the `location` parameter sent
	 * by the search widget (Citykleta_Search_Widget), if present in the
	 * URL. Dates don't filter anything yet — there's no availability/booking
	 * system behind them — they just travel in the URL.
	 *
	 * @param WP_Query $query Current query.
	 */
	public function apply_search_filters( $query ) {
		if ( is_admin() || ! $query->is_main_query() || ! $query->is_post_type_archive( Citykleta_Bike_Post_Type::POST_TYPE ) ) {
			return;
		}

		$filters = self::request_filters();

		if ( $filters['meta_query'] ) {
			$query->set( 'meta_query', $filters['meta_query'] ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		if ( $filters['tax_query'] ) {
			$query->set( 'tax_query', $filters['tax_query'] ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}
	}

	/**
	 * Builds the meta_query / tax_query pieces from the current request's
	 * search params (location, electric, usage). Shared by
	 * apply_search_filters() (the paginated grid, via pre_get_posts) and the
	 * archive template's map query (Citykleta_Bike_Map_Data), so both views
	 * respond to exactly the same filters while each keeps its own behavior:
	 * the grid paginates/scrolls, the map plots everything matching at once.
	 *
	 * @return array Keys 'meta_query' and 'tax_query' (each an array, possibly empty).
	 */
	public static function request_filters() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filtering, no state change.
		$meta_query = array();

		if ( ! empty( $_GET['location'] ) ) {
			$meta_query[] = array(
				'key'     => 'location',
				'value'   => sanitize_text_field( wp_unslash( $_GET['location'] ) ),
				'compare' => 'LIKE',
			);
		}

		// The "include electric" checkbox sends a hidden input with "0"
		// right before itself, so if left unchecked, the received value
		// is "0" (not absent) — only then do we filter to non-electric only.
		// Checked (or the form was never submitted) = show all.
		if ( isset( $_GET['electric'] ) && '0' === $_GET['electric'] ) {
			$meta_query[] = array(
				'key'   => 'is_electric',
				'value' => '0',
			);
		}

		$usage = isset( $_GET['usage'] ) ? sanitize_title( wp_unslash( $_GET['usage'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$type_ids  = self::usage_to_bike_types( $usage );
		$tax_query = array();

		if ( $type_ids ) {
			$tax_query[] = array(
				'taxonomy' => Citykleta_Bike_Type_Taxonomy::TAXONOMY,
				'field'    => 'term_id',
				'terms'    => $type_ids,
			);
		}

		return array(
			'meta_query' => $meta_query,
			'tax_query'  => $tax_query,
		);
	}

	/**
	 * Option that used to hold the results Page's ID, back when the results
	 * layout lived in a normal WordPress Page. Kept only so the one-time
	 * migration below can find that page and clean it up — templates are
	 * their own private post type now (Citykleta_Template_Post_Type).
	 */
	const LEGACY_BIKES_PAGE_OPTION = 'citykleta_bikes_page_id';

	/**
	 * The template drawn at the bikes archive URL, created on first need.
	 *
	 * @return WP_Post|null
	 */
	public static function ensure_results_template() {
		self::migrate_legacy_bikes_page();

		return Citykleta_Template_Post_Type::ensure_template(
			'results',
			'Bike results',
			self::default_results_content(),
			array(
				'[citykleta_bikes]',
				"[citykleta_search]\n[citykleta_map filters=\"url\" map_height=\"450\"]\n[citykleta_grid filters=\"url\"]",
			),
			self::default_results_layout()
		);
	}

	/**
	 * The template drawn on a bike's own page, created on first need.
	 *
	 * @return WP_Post|null
	 */
	public static function ensure_single_bike_template() {
		return Citykleta_Template_Post_Type::ensure_template(
			'single_bike',
			'Single bike',
			self::default_single_bike_content(),
			array(
				'<div class="citykleta-bike-single"><div class="citykleta-bike-single__layout">'
					. '<div class="citykleta-bike-single__content">[citykleta_bike_gallery][citykleta_bike_header]'
					. '[citykleta_bike_owner][citykleta_bike_description][citykleta_bike_specs]'
					. '[citykleta_bike_location]</div>[citykleta_bike_request]</div></div>',
			),
			self::default_single_bike_layout()
		);
	}

	/**
	 * Moves the old results Page into the templates post type, once.
	 *
	 * The layout used to live in a regular Page ("Bicicletas (resultados)"),
	 * which meant it sat in the Pages list beside real content and had a URL
	 * of its own. Whatever was built there is carried over as-is — including
	 * an Elementor design, since _elementor_data travels with the meta — and
	 * the old Page is trashed (not deleted: if this migration ever gets
	 * something wrong, the original is still recoverable by hand).
	 */
	private static function migrate_legacy_bikes_page() {
		$page_id = (int) get_option( self::LEGACY_BIKES_PAGE_OPTION );

		if ( ! $page_id ) {
			return;
		}

		$page = get_post( $page_id );

		// Already migrated, or the page is gone: drop the pointer and stop.
		if ( ! $page || 'page' !== $page->post_type || Citykleta_Template_Post_Type::get_template( 'results' ) ) {
			delete_option( self::LEGACY_BIKES_PAGE_OPTION );
			return;
		}

		$template = Citykleta_Template_Post_Type::ensure_template(
			'results',
			$page->post_title,
			$page->post_content
		);

		if ( ! $template ) {
			return;
		}

		// Elementor keeps its layout in post meta, not post_content — copy it
		// across so a design built on the old Page survives the move.
		foreach ( get_post_meta( $page_id ) as $meta_key => $values ) {
			if ( 0 !== strpos( $meta_key, '_elementor' ) ) {
				continue;
			}

			foreach ( $values as $value ) {
				update_post_meta( $template->ID, $meta_key, maybe_unserialize( $value ) );
			}
		}

		wp_trash_post( $page_id );
		delete_option( self::LEGACY_BIKES_PAGE_OPTION );
	}

	/**
	 * Default results layout: search, map, then the grid, as three
	 * independent pieces (Citykleta_Search_Widget, Citykleta_Map_Widget,
	 * Citykleta_Bike_Widget — through their shortcode wrappers, see
	 * includes/elementor/class-citykleta-widget-shortcodes.php). The map and
	 * grid both read the URL's search filters (`filters="url"`); the map has
	 * no show/hide toggle, it's simply always there. A plain starting point
	 * meant to be redesigned in Elementor, not a fixed experience.
	 *
	 * @return string
	 */
	public static function default_results_content() {
		return self::shortcode_block( '[citykleta_search]' ) . "\n\n"
			. self::shortcode_block( '[citykleta_map filters="url" map_height="450"]' ) . "\n\n"
			. self::shortcode_block( '[citykleta_grid filters="url"]' );
	}

	/**
	 * The default layout for a view: whatever was captured with "Mark as
	 * ready", else the plain starting point built below.
	 *
	 * @param string $type A key of Citykleta_Template_Post_Type::TYPES.
	 * @return array
	 */
	public static function default_layout( $type ) {
		switch ( $type ) {
			case 'results':
				return self::default_results_layout();
			case 'single_bike':
				return self::default_single_bike_layout();
			case 'destination':
				return self::default_destination_layout();
		}

		return array();
	}

	/**
	 * The template drawn on a destination's page, created on first need.
	 *
	 * @return WP_Post|null
	 */
	public static function ensure_destination_template() {
		return Citykleta_Template_Post_Type::ensure_template(
			'destination',
			'Destination',
			self::default_destination_content(),
			array(),
			self::default_destination_layout()
		);
	}

	/**
	 * The destination layout as Elementor Containers: header, description and
	 * average price, then the bikes assigned to it, then related articles and
	 * the FAQ — the same reading order the fixed template had, with every part
	 * now movable.
	 *
	 * The section titles are native Elementor Headings, not something a widget
	 * prints: they are static text, so they belong to whoever is designing.
	 *
	 * @return array
	 */
	public static function default_destination_layout() {
		$captured = Citykleta_Template_Defaults::layout( 'destination' );

		if ( $captured ) {
			return $captured;
		}

		$t = 'Citykleta_Template_Post_Type';

		return array(
			$t::container(
				array(
					$t::widget( 'citykleta_destination_header' ),
					$t::widget( 'citykleta_destination_description' ),
					$t::widget( 'citykleta_destination_price' ),
				)
			),
			$t::container(
				array(
					$t::heading( 'Bicicletas disponibles aquí' ),
					// The grid widget, not a destination widget of its own —
					// see its "Destination Bikes" source. 'destination' is
					// spelled out rather than using the class constant for the
					// same reason as the bike layout: this runs on requests
					// where Elementor's widget classes are not loaded yet.
					$t::widget(
						'citykleta_bikes',
						array(
							'source'  => 'destination',
							'count'   => 24,
							'columns' => '3',
						)
					),
				)
			),
			$t::container(
				array(
					$t::heading( 'Artículos relacionados' ),
					$t::widget( 'citykleta_destination_articles' ),
				)
			),
			$t::container(
				array(
					$t::heading( 'Preguntas frecuentes' ),
					$t::widget( 'citykleta_destination_faq' ),
				)
			),
		);
	}

	/**
	 * The same destination layout as WordPress blocks — the readable fallback
	 * when Elementor is not what is rendering.
	 *
	 * @return string
	 */
	public static function default_destination_content() {
		return implode(
			"\n\n",
			array(
				self::shortcode_block( '[citykleta_destination_header]' ),
				self::shortcode_block( '[citykleta_destination_description]' ),
				self::shortcode_block( '[citykleta_destination_price]' ),
				self::heading_block( 'Bicicletas disponibles aquí' ),
				self::shortcode_block( '[citykleta_destination_bikes]' ),
				self::heading_block( 'Artículos relacionados' ),
				self::shortcode_block( '[citykleta_destination_articles]' ),
				self::heading_block( 'Preguntas frecuentes' ),
				self::shortcode_block( '[citykleta_destination_faq]' ),
			)
		);
	}

	/**
	 * The results layout as Elementor Containers: three stacked containers,
	 * one per piece, so the template opens on a working design that can be
	 * dragged, restyled and reordered.
	 *
	 * Once a design has been marked ready (see Citykleta_Template_Defaults),
	 * THAT is what the plugin ships with and the tree below is only the
	 * fallback for a checkout that has no captured layout yet.
	 *
	 * @return array
	 */
	public static function default_results_layout() {
		$captured = Citykleta_Template_Defaults::layout( 'results' );

		if ( $captured ) {
			return $captured;
		}

		$t = 'Citykleta_Template_Post_Type';

		return array(
			$t::container( array( $t::widget( 'citykleta_search' ) ) ),
			$t::container(
				array(
					$t::widget(
						'citykleta_map',
						array(
							'use_url_filters' => 'yes',
							'map_height'      => array(
								'unit'  => 'px',
								'size'  => 450,
								'sizes' => array(),
							),
						)
					),
				)
			),
			$t::container(
				array(
					$t::widget(
						'citykleta_bikes',
						array(
							'use_url_filters' => 'yes',
							'posts_per_page'  => 10,
							'columns'         => '4',
						)
					),
				)
			),
		);
	}

	/**
	 * The single-bike layout as Elementor Containers: the same two-column
	 * design the old fixed template had — content left, price/request card
	 * right — with every piece now its own widget on the canvas.
	 *
	 * Superseded by a captured design once one has been marked ready, same as
	 * default_results_layout().
	 *
	 * @return array
	 */
	public static function default_single_bike_layout() {
		$captured = Citykleta_Template_Defaults::layout( 'single_bike' );

		if ( $captured ) {
			return $captured;
		}

		$t = 'Citykleta_Template_Post_Type';

		return array(
			$t::container(
				array(
					$t::column(
						66,
						array(
							$t::widget( 'citykleta_bike_gallery' ),
							$t::widget( 'citykleta_bike_title' ),
							$t::widget( 'citykleta_bike_location_tags' ),
							$t::widget( 'citykleta_bike_owner' ),
							// The section titles are plain Elementor Headings,
							// not something the pieces print — see
							// Citykleta_Template_Post_Type::heading().
							$t::heading( 'Sobre esta bicicleta' ),
							$t::widget( 'citykleta_bike_description' ),
							$t::heading( 'Ficha técnica' ),
							$t::widget( 'citykleta_bike_specs' ),
							$t::heading( 'Dónde recogerla' ),
							$t::widget( 'citykleta_bike_directions' ),
							/*
							 * The map widget in "Bike Data" mode — the pickup
							 * map is no longer a widget of its own.
							 *
							 * 'bike' spelled out rather than
							 * Citykleta_Map_Widget::SOURCE_BIKE on purpose:
							 * this method is reached from
							 * ensure_single_bike_template() on any request,
							 * including ones that run before Elementor has
							 * loaded its widget classes — referencing the
							 * constant there is a fatal error.
							 */
							$t::widget(
								'citykleta_map',
								array(
									'source'     => 'bike',
									'map_height' => array(
										'unit'  => 'px',
										'size'  => 220,
										'sizes' => array(),
									),
								)
							),
						)
					),
					$t::column(
						34,
						array(
							// The price is its own piece since it left the
							// request card — 'price' spelled out rather than
							// the class constant for the same reason as
							// 'bike' below: this runs on requests where
							// Elementor hasn't loaded its widget classes.
							$t::widget( 'citykleta_bike_data', array( 'field' => 'price' ) ),
							$t::widget( 'citykleta_bike_request' ),
						)
					),
				),
				array( 'flex_direction' => 'row' )
			),
		);
	}

	/**
	 * One piece as a WordPress "Shortcode" block.
	 *
	 * The default layouts are written as real block markup, not as loose
	 * shortcode text, so the templates open in the block editor as an actual
	 * arrangement of blocks — movable, with blocks addable around them —
	 * the same as editing a page. Loose text would technically render the
	 * same on the front end while giving nothing to edit.
	 *
	 * @param string $shortcode The shortcode, brackets included.
	 * @return string
	 */
	private static function shortcode_block( $shortcode ) {
		return "<!-- wp:shortcode -->\n" . $shortcode . "\n<!-- /wp:shortcode -->";
	}

	/**
	 * One static section title as a WordPress "Heading" block.
	 *
	 * @param string $text Title text.
	 * @return string
	 */
	private static function heading_block( $text ) {
		return "<!-- wp:heading -->\n"
			. '<h2 class="wp-block-heading">' . esc_html( $text ) . '</h2>' . "\n"
			. '<!-- /wp:heading -->';
	}

	/**
	 * Default single-bike layout — the same two-column design the old fixed
	 * template had (content left, price/request card at the right),
	 * rebuilt out of the individual pieces so every part of it can now be
	 * moved, restyled or removed in Elementor. The wrapper divs reuse the
	 * classes single-bike.css already styles.
	 *
	 * @return string
	 */
	public static function default_single_bike_content() {
		$left = implode(
			"\n\n",
			array(
				self::shortcode_block( '[citykleta_bike_gallery]' ),
				self::shortcode_block( '[citykleta_bike_title]' ),
				self::shortcode_block( '[citykleta_bike_location_tags]' ),
				self::shortcode_block( '[citykleta_bike_owner]' ),
				// Section titles as real Heading blocks, for the same reason
				// the Elementor layout uses Heading widgets: the text is
				// static, so it belongs to the person editing the template.
				self::heading_block( 'Sobre esta bicicleta' ),
				self::shortcode_block( '[citykleta_bike_description]' ),
				self::heading_block( 'Ficha técnica' ),
				self::shortcode_block( '[citykleta_bike_specs]' ),
				self::heading_block( 'Dónde recogerla' ),
				self::shortcode_block( '[citykleta_bike_directions]' ),
				self::shortcode_block( '[citykleta_bike_location]' ),
			)
		);

		$right = self::shortcode_block( '[citykleta_bike_data field="price"]' )
			. "\n\n" . self::shortcode_block( '[citykleta_bike_request]' );

		// A real Columns block, so the two-column design opens in the editor
		// as two columns you can drag pieces between — not as markup the
		// editor can only show as raw HTML.
		return "<!-- wp:columns -->\n"
			. '<div class="wp-block-columns">' . "\n"
			. "<!-- wp:column {\"width\":\"66%\"} -->\n"
			. '<div class="wp-block-column" style="flex-basis:66%">' . "\n"
			. $left . "\n"
			. "</div>\n"
			. "<!-- /wp:column -->\n\n"
			. "<!-- wp:column {\"width\":\"34%\"} -->\n"
			. '<div class="wp-block-column" style="flex-basis:34%">' . "\n"
			. $right . "\n"
			. "</div>\n"
			. "<!-- /wp:column -->\n"
			. "</div>\n"
			. '<!-- /wp:columns -->';
	}

	/**
	 * Prints prev/next + numbered page links for a custom WP_Query, no JS.
	 * Shared by anything that paginates a bikes query outside the main
	 * query (Citykleta_Bike_Widget's grid in "Filter From Page URL" mode).
	 *
	 * Uses the documented paginate_links() pattern for a custom query: ask
	 * for the link to an "impossible" page number — get_pagenum_link()
	 * already builds the current URL's pretty-permalink form, querystring
	 * (search filters) included — and turn that into the base template.
	 *
	 * @param WP_Query $query Already-run, paginated query.
	 * @param int      $paged Current page number.
	 * @return string HTML, or '' when there's only one page.
	 */
	public static function pagination_links( WP_Query $query, $paged ) {
		if ( $query->max_num_pages <= 1 ) {
			return '';
		}

		$big = 999999999;

		$links = paginate_links(
			array(
				'base'      => str_replace( $big, '%#%', esc_url( get_pagenum_link( $big ) ) ),
				'format'    => '?paged=%#%',
				'current'   => max( 1, (int) $paged ),
				'total'     => $query->max_num_pages,
				'prev_text' => '&lsaquo; Anterior',
				'next_text' => 'Siguiente &rsaquo;',
				'type'      => 'list',
			)
		);

		if ( ! $links ) {
			return '';
		}

		return '<nav class="citykleta-pagination" aria-label="Paginación de resultados">' . $links . '</nav>';
	}

	/**
	 * Translates a "usage type" (a `bike_usage` taxonomy term, managed from
	 * wp-admin → Shared Bikes → Usage types) into the group of `bike_type`
	 * IDs it corresponds to — the relationship is declared by the admin by
	 * checking boxes when creating/editing each usage type (see
	 * Citykleta_Bike_Usage_Taxonomy), it doesn't live hardcoded in the code.
	 *
	 * @param string $usage `bike_usage` term slug.
	 * @return array List of `bike_type` term IDs (empty if the usage doesn't exist).
	 */
	public static function usage_to_bike_types( $usage ) {
		if ( ! $usage ) {
			return array();
		}

		$term = get_term_by( 'slug', $usage, Citykleta_Bike_Usage_Taxonomy::TAXONOMY );

		if ( ! $term ) {
			return array();
		}

		$type_ids = get_term_meta( $term->term_id, Citykleta_Bike_Usage_Taxonomy::META_KEY, true );

		return is_array( $type_ids ) ? array_map( 'intval', $type_ids ) : array();
	}

	/**
	 * Replaces the "bike" CPT's archive template with the plugin's own,
	 * unless the active theme already has its own archive-bike.php.
	 *
	 * @param string $template Path to the template WordPress was going to use.
	 * @return string
	 */
	public function load_archive_template( $template ) {
		if ( ! is_post_type_archive( Citykleta_Bike_Post_Type::POST_TYPE ) ) {
			return $template;
		}

		$theme_template = locate_template( 'archive-bike.php' );

		if ( $theme_template ) {
			return $theme_template;
		}

		return CITYKLETA_PATH . 'templates/archive-bike.php';
	}

	/**
	 * Replaces a bike's single-post template (teaser + sign-up wall, actual
	 * booking is handled by the app), unless the active theme already has
	 * its own single-bike.php.
	 *
	 * @param string $template Path to the template WordPress was going to use.
	 * @return string
	 */
	public function load_single_template( $template ) {
		if ( ! is_singular( Citykleta_Bike_Post_Type::POST_TYPE ) ) {
			return $template;
		}

		$theme_template = locate_template( 'single-bike.php' );

		if ( $theme_template ) {
			return $theme_template;
		}

		return CITYKLETA_PATH . 'templates/single-bike.php';
	}

	/**
	 * Replaces a destination term's archive page (/destination/{slug}/),
	 * unless the active theme already has its own single-destination.php —
	 * same pattern as self::load_archive_template()/load_single_template().
	 * Destinations are a post type, not a taxonomy (see
	 * Citykleta_Destination_Post_Type's docblock for why), so this hooks
	 * is_singular(), not is_tax().
	 *
	 * @param string $template Path to the template WordPress was going to use.
	 * @return string
	 */
	public function load_destination_template( $template ) {
		if ( ! is_singular( Citykleta_Destination_Post_Type::POST_TYPE ) ) {
			return $template;
		}

		$theme_template = locate_template( 'single-destination.php' );

		if ( $theme_template ) {
			return $theme_template;
		}

		return CITYKLETA_PATH . 'templates/single-destination.php';
	}

	/**
	 * Loads each front-end view's CSS: the card grid on the archive, the
	 * detail layout on a single bike. Designing the single view with
	 * Elementor would need the Theme Builder (Pro only), so it ships with
	 * its own stylesheet instead.
	 */
	public function enqueue_styles() {
		if ( is_post_type_archive( Citykleta_Bike_Post_Type::POST_TYPE ) ) {
			wp_enqueue_style(
				'citykleta-bike-cards',
				CITYKLETA_URL . 'assets/css/bike-cards.css',
				array(),
				$this->asset_version( 'assets/css/bike-cards.css' )
			);

			$this->enqueue_map_assets();

			// El mapa ya no tiene botón para esconderlo (queda siempre
			// visible en la página de resultados) y la paginación
			// (Citykleta_Templates::pagination_links()) es enlaces normales
			// — ninguno de los dos necesita JS propio acá.
		}

		if ( is_singular( Citykleta_Bike_Post_Type::POST_TYPE ) ) {
			// Splide (photo carousel) — vendor, doesn't ship with the plugin
			// (see CLAUDE.md). file_exists() so a fresh checkout without it
			// still shows the bike's photo, just without the slide mechanics
			// — same graceful-degradation pattern as Flatpickr/Leaflet below.
			if ( file_exists( CITYKLETA_PATH . 'assets/js/splide.min.js' ) ) {
				wp_enqueue_style(
					'splide',
					CITYKLETA_URL . 'assets/css/splide.min.css',
					array(),
					CITYKLETA_VERSION
				);

				wp_enqueue_script(
					'splide',
					CITYKLETA_URL . 'assets/js/splide.min.js',
					array(),
					CITYKLETA_VERSION,
					true
				);
			}

			wp_enqueue_style(
				'citykleta-single-bike',
				CITYKLETA_URL . 'assets/css/single-bike.css',
				array( 'splide' ),
				$this->asset_version( 'assets/css/single-bike.css' )
			);

			// Wires our own arrow/dot buttons to Splide's API — no-ops if
			// Splide didn't load (window.Splide undefined) or the bike has a
			// single photo (today's real case): the template doesn't print
			// those buttons at all in that case, nothing to wire up.
			wp_enqueue_script(
				'citykleta-bike-gallery',
				CITYKLETA_URL . 'assets/js/citykleta-bike-gallery.js',
				array( 'splide' ),
				$this->asset_version( 'assets/js/citykleta-bike-gallery.js' ),
				true
			);

			// "Dónde recogerla" mini-map (Citykleta_Bike_Map_Data::for_bike()
			// in the template) — same Leaflet setup the archive's results map
			// uses; no-op if the vendor files aren't installed (see CLAUDE.md).
			$this->enqueue_map_assets();
		}

		if ( is_singular( Citykleta_Destination_Post_Type::POST_TYPE ) ) {
			// Reuses the archive's card styles for the bike grid — same
			// .citykleta-bike-card markup, see templates/single-destination.php.
			wp_enqueue_style(
				'citykleta-bike-cards',
				CITYKLETA_URL . 'assets/css/bike-cards.css',
				array(),
				$this->asset_version( 'assets/css/bike-cards.css' )
			);

			wp_enqueue_style(
				'citykleta-destination',
				CITYKLETA_URL . 'assets/css/destination.css',
				array( 'citykleta-bike-cards' ),
				$this->asset_version( 'assets/css/destination.css' )
			);
		}
	}

	/**
	 * Asset version for cache-busting: filemtime() changes on every edit, so
	 * the browser always gets the latest file without a hard refresh. Falls
	 * back to CITYKLETA_VERSION if the file is missing (e.g. vendor not there).
	 *
	 * @param string $relative_path Path relative to the plugin folder.
	 * @return string|int
	 */
	private function asset_version( $relative_path ) {
		$file = CITYKLETA_PATH . $relative_path;

		return file_exists( $file ) ? filemtime( $file ) : CITYKLETA_VERSION;
	}

	/**
	 * Loads Leaflet + Leaflet.markercluster + the plugin's map module for the
	 * archive's results map, independently of Elementor (the archive can't
	 * depend on Elementor being active). Reuses the same script/style handles
	 * as Citykleta_Elementor, so if both enqueue them WordPress dedupes by
	 * handle and they only download once. No-op if the vendor files haven't
	 * been dropped in (they don't ship with the plugin — see CLAUDE.md).
	 */
	private function enqueue_map_assets() {
		if ( ! file_exists( CITYKLETA_PATH . 'assets/js/leaflet.js' ) ) {
			return;
		}

		wp_enqueue_style(
			'leaflet',
			CITYKLETA_URL . 'assets/css/leaflet.css',
			array(),
			CITYKLETA_VERSION
		);

		wp_enqueue_style(
			'leaflet-markercluster',
			CITYKLETA_URL . 'assets/css/MarkerCluster.css',
			array( 'leaflet' ),
			CITYKLETA_VERSION
		);

		wp_enqueue_style(
			'leaflet-markercluster-default',
			CITYKLETA_URL . 'assets/css/MarkerCluster.Default.css',
			array( 'leaflet-markercluster' ),
			CITYKLETA_VERSION
		);

		wp_enqueue_style(
			'citykleta-map',
			CITYKLETA_URL . 'assets/css/citykleta-map.css',
			array( 'leaflet-markercluster-default' ),
			$this->asset_version( 'assets/css/citykleta-map.css' )
		);

		wp_enqueue_script(
			'leaflet',
			CITYKLETA_URL . 'assets/js/leaflet.js',
			array(),
			CITYKLETA_VERSION,
			true
		);

		wp_enqueue_script(
			'leaflet-markercluster',
			CITYKLETA_URL . 'assets/js/leaflet.markercluster.js',
			array( 'leaflet' ),
			CITYKLETA_VERSION,
			true
		);

		wp_enqueue_script(
			'citykleta-map',
			CITYKLETA_URL . 'assets/js/citykleta-map.js',
			array( 'leaflet-markercluster' ),
			$this->asset_version( 'assets/js/citykleta-map.js' ),
			true
		);
	}
}
