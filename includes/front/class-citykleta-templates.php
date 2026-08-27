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
	 * URL. Dates and quantity don't filter anything yet — there's no
	 * availability/booking system behind them — they just travel in the URL.
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

			// Alterna entre la vista de tarjetas y la de mapa (un botón). No
			// declara dependencia dura de citykleta-map: si Leaflet no está,
			// el toggle igual funciona (comprueba window.CityKletaMap antes de
			// tocar el mapa) y solo se pierde el dibujado del mapa.
			wp_enqueue_script(
				'citykleta-view-toggle',
				CITYKLETA_URL . 'assets/js/citykleta-view-toggle.js',
				array(),
				$this->asset_version( 'assets/js/citykleta-view-toggle.js' ),
				true
			);

			// Carga infinita del grid: reemplaza la paginación clásica,
			// trayendo la página siguiente por fetch al acercarse el final.
			wp_enqueue_script(
				'citykleta-infinite-scroll',
				CITYKLETA_URL . 'assets/js/citykleta-infinite-scroll.js',
				array(),
				$this->asset_version( 'assets/js/citykleta-infinite-scroll.js' ),
				true
			);
		}

		if ( is_singular( Citykleta_Bike_Post_Type::POST_TYPE ) ) {
			wp_enqueue_style(
				'citykleta-single-bike',
				CITYKLETA_URL . 'assets/css/single-bike.css',
				array(),
				$this->asset_version( 'assets/css/single-bike.css' )
			);
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
