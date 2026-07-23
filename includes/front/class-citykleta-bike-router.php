<?php
/**
 * Custom permalinks for a single bike: /bikes/{external_id}/{slug}/.
 *
 * The {external_id} segment is the bike's id in the app's API (the same key
 * the sync uses to avoid duplicates), so the URL matches what the app
 * understands; the {slug} segment (brand + model + owner) is only there to
 * make the URL human-friendly. WordPress resolves the post by external_id and
 * ignores the slug, so a stale slug in an old link still lands on the right
 * bike and gets canonical-redirected to the current URL for free.
 *
 * @package Citykleta_Shared_Bikes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Citykleta_Bike_Router.
 */
class Citykleta_Bike_Router {

	/**
	 * Query var carrying the external id captured from the URL.
	 */
	const QUERY_VAR = 'citykleta_bike_external_id';

	/**
	 * Option storing the rewrite version, so a rule change triggers one
	 * flush on the next load (same idea as citykleta_flush_rewrite_flag).
	 */
	const VERSION_OPTION = 'citykleta_router_version';

	/**
	 * Bumped whenever the rewrite rule below changes.
	 */
	const VERSION = '1';

	/**
	 * Connects the class to WordPress hooks.
	 */
	public function register() {
		// Called directly (not via add_action('init', ...)) because register()
		// runs from within the 'init' hook (see citykleta_init()); re-hooking
		// 'init' at the same priority wouldn't run in this same pass.
		$this->add_rewrite_rules();
		$this->maybe_flush();

		add_filter( 'query_vars', array( $this, 'add_query_var' ) );
		add_filter( 'request', array( $this, 'resolve_request' ) );
		add_filter( 'post_type_link', array( $this, 'bike_permalink' ), 10, 2 );
	}

	/**
	 * Registers the /{slug_base}/{external_id}[/{slug}]/ rules. 'top' priority
	 * so they win over the CPT's own single rule (which would otherwise treat
	 * the numeric id as the post slug).
	 */
	public function add_rewrite_rules() {
		$base = $this->slug_base();

		// With the human-friendly slug segment.
		add_rewrite_rule(
			'^' . $base . '/([0-9]+)/[^/]+/?$',
			'index.php?' . self::QUERY_VAR . '=$matches[1]',
			'top'
		);

		// Id only (e.g. what the app might build without knowing the slug).
		add_rewrite_rule(
			'^' . $base . '/([0-9]+)/?$',
			'index.php?' . self::QUERY_VAR . '=$matches[1]',
			'top'
		);
	}

	/**
	 * Flushes rewrite rules once after the rule set changes.
	 */
	public function maybe_flush() {
		if ( get_option( self::VERSION_OPTION ) === self::VERSION ) {
			return;
		}

		flush_rewrite_rules();
		update_option( self::VERSION_OPTION, self::VERSION );
	}

	/**
	 * Whitelists our query var so WordPress keeps it.
	 *
	 * @param array $vars Registered query vars.
	 * @return array
	 */
	public function add_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Turns the captured external id into a normal single-post query by the
	 * bike's real WordPress ID (`p`), which also enables redirect_canonical to
	 * fix a stale slug segment in the URL. A missing id resolves to a 404.
	 *
	 * @param array $query_vars Query vars WordPress parsed from the URL.
	 * @return array
	 */
	public function resolve_request( $query_vars ) {
		if ( empty( $query_vars[ self::QUERY_VAR ] ) ) {
			return $query_vars;
		}

		$external_id = (int) $query_vars[ self::QUERY_VAR ];

		$ids = get_posts(
			array(
				'post_type'      => Citykleta_Bike_Post_Type::POST_TYPE,
				'post_status'    => 'publish',
				'meta_key'       => 'external_id',
				'meta_value'     => $external_id,
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		if ( empty( $ids ) ) {
			// Force a 404 with an ID that can't exist.
			return array( 'p' => PHP_INT_MAX );
		}

		return array(
			'p'         => $ids[0],
			'post_type' => Citykleta_Bike_Post_Type::POST_TYPE,
		);
	}

	/**
	 * Builds the /{slug_base}/{external_id}/{slug}/ permalink so links, the
	 * canonical redirect and the sitemap all use the id-prefixed URL.
	 *
	 * @param string  $permalink Default permalink WordPress built.
	 * @param WP_Post $post      The post being linked.
	 * @return string
	 */
	public function bike_permalink( $permalink, $post ) {
		if ( Citykleta_Bike_Post_Type::POST_TYPE !== $post->post_type ) {
			return $permalink;
		}

		// Plain permalinks (no structure): leave WordPress' ?p=/?post_type= URL.
		if ( ! get_option( 'permalink_structure' ) ) {
			return $permalink;
		}

		$external_id = (int) get_post_meta( $post->ID, 'external_id', true );

		if ( ! $external_id ) {
			return $permalink;
		}

		return home_url(
			user_trailingslashit( $this->slug_base() . '/' . $external_id . '/' . $post->post_name )
		);
	}

	/**
	 * The bike archive/base slug, configurable under Shared Bikes → Sync.
	 *
	 * @return string
	 */
	private function slug_base() {
		return get_option( 'citykleta_bike_slug', 'bikes' );
	}
}
