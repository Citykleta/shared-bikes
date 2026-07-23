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

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only, just filters results, no state change.
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
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( $meta_query ) {
			$query->set( 'meta_query', $meta_query ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$usage    = isset( $_GET['usage'] ) ? sanitize_title( wp_unslash( $_GET['usage'] ) ) : '';
		$type_ids = $this->usage_to_bike_types( $usage );

		if ( $type_ids ) {
			$query->set(
				'tax_query', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					array(
						'taxonomy' => Citykleta_Bike_Type_Taxonomy::TAXONOMY,
						'field'    => 'term_id',
						'terms'    => $type_ids,
					),
				)
			);
		}
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
	public function usage_to_bike_types( $usage ) {
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
	 * Loads the bike archive's CSS. The single page intentionally has no
	 * CSS of its own: its design is built with Elementor templates.
	 */
	public function enqueue_styles() {
		if ( ! is_post_type_archive( Citykleta_Bike_Post_Type::POST_TYPE ) ) {
			return;
		}

		wp_enqueue_style(
			'citykleta-bike-cards',
			CITYKLETA_URL . 'assets/css/bike-cards.css',
			array(),
			CITYKLETA_VERSION
		);
	}
}
