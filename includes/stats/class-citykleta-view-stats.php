<?php
/**
 * Counts how many times each bike's page is viewed in the browser.
 *
 * WordPress core doesn't track post views, so this adds a lightweight counter.
 * Views are aggregated per day into a single post meta (self::META_KEY), an
 * associative array of 'Y-m-d' => count. One meta row per bike (not one per
 * view), so it stays light even at high view volume, while still preserving
 * per-day demand — the total is the sum, and each day's bucket shows which
 * days draw the most interest. The key starts with "_" so it's internal: the
 * sync never touches it and it stays out of the native custom-fields UI.
 *
 * Counting happens browser-side: the single-bike view enqueues a script that
 * fires a navigator.sendBeacon() to a REST route on load. Doing it in the
 * browser (instead of on the PHP render) means it survives full-page caching
 * and skips most bots (they don't run JS).
 *
 * @package Citykleta_Shared_Bikes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Citykleta_View_Stats.
 */
class Citykleta_View_Stats {

	/**
	 * REST namespace for the view endpoint.
	 */
	const REST_NAMESPACE = 'citykleta/v1';

	/**
	 * Single post meta: an associative array of 'Y-m-d' => view count.
	 */
	const META_KEY = '_citykleta_views_by_day';

	/**
	 * Transient caching the site-wide view total (see site_total()).
	 */
	const SITE_TOTAL_TRANSIENT = 'citykleta_views_site_total';

	/**
	 * Connects the class to WordPress hooks.
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_script' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );

		$post_type = Citykleta_Bike_Post_Type::POST_TYPE;
		add_filter( "manage_{$post_type}_posts_columns", array( $this, 'add_column' ) );
		add_action( "manage_{$post_type}_posts_custom_column", array( $this, 'render_column' ), 10, 2 );
	}

	/**
	 * Registers the REST route the beacon posts to.
	 */
	public function register_route() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/bikes/(?P<id>\d+)/view',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'record_view' ),
				// Public action: visitors aren't logged in, and a per-user nonce
				// can't be verified here anyway — sendBeacon can't send the
				// X-WP-Nonce header, so REST sees the request as anonymous and a
				// nonce minted for a logged-in admin never matches. Anonymous
				// nonces are shared across all logged-out visitors (near-zero
				// protection), so instead the callback just validates the target
				// is a real published bike. Abuse is limited to inflating a stat;
				// add rate-limiting by IP if that ever matters.
				'permission_callback' => '__return_true',
				'args'                => array(
					'id' => array(
						'validate_callback' => function ( $value ) {
							return is_numeric( $value );
						},
					),
				),
			)
		);
	}

	/**
	 * Records one view for a bike: validates the target is a published bike,
	 * then increments today's bucket in the per-day meta.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function record_view( WP_REST_Request $request ) {
		$post_id = absint( $request['id'] );
		$post    = get_post( $post_id );

		if ( ! $post || Citykleta_Bike_Post_Type::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			return new WP_REST_Response( array( 'ok' => false ), 404 );
		}

		$by_day = self::views_by_day( $post_id );
		$today  = current_time( 'Y-m-d' );

		$by_day[ $today ] = isset( $by_day[ $today ] ) ? $by_day[ $today ] + 1 : 1;

		update_post_meta( $post_id, self::META_KEY, $by_day );

		return new WP_REST_Response( array( 'ok' => true ), 201 );
	}

	/**
	 * Loads the view-tracking script on a single bike view and hands it the
	 * REST base plus this bike's ID to report.
	 */
	public function enqueue_script() {
		if ( ! is_singular( Citykleta_Bike_Post_Type::POST_TYPE ) ) {
			return;
		}

		wp_enqueue_script(
			'citykleta-view-stats',
			CITYKLETA_URL . 'assets/js/citykleta-view-stats.js',
			array(),
			CITYKLETA_VERSION,
			true
		);

		wp_localize_script(
			'citykleta-view-stats',
			'CitykletaViews',
			array(
				'restBase' => esc_url_raw( rest_url( self::REST_NAMESPACE ) ),
				'bikeId'   => get_queried_object_id(),
			)
		);
	}

	/**
	 * A bike's views broken down by day ('Y-m-d' => count), newest day first.
	 *
	 * @param int $post_id Bike ID.
	 * @return array<string,int>
	 */
	public static function views_by_day( $post_id ) {
		$by_day = get_post_meta( $post_id, self::META_KEY, true );

		if ( ! is_array( $by_day ) ) {
			return array();
		}

		krsort( $by_day );

		return $by_day;
	}

	/**
	 * A bike's total view count (the sum across all days).
	 *
	 * @param int $post_id Bike ID.
	 * @return int
	 */
	public static function count( $post_id ) {
		return array_sum( self::views_by_day( $post_id ) );
	}

	/**
	 * Total views across every bike (the sum of all per-bike counts), for the
	 * top of the Stats funnel. Cached in a transient for 10 minutes so the
	 * dashboard doesn't re-sum every bike on each load.
	 *
	 * @return int
	 */
	public static function site_total() {
		$cached = get_transient( self::SITE_TOTAL_TRANSIENT );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		$ids = get_posts(
			array(
				'post_type'      => Citykleta_Bike_Post_Type::POST_TYPE,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'meta_key'       => self::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			)
		);

		$total = 0;
		foreach ( $ids as $id ) {
			$total += self::count( $id );
		}

		set_transient( self::SITE_TOTAL_TRANSIENT, $total, 10 * MINUTE_IN_SECONDS );

		return $total;
	}

	/**
	 * Adds the "Views" column to the bikes list.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_column( $columns ) {
		$columns['citykleta_views'] = 'Views';
		return $columns;
	}

	/**
	 * Renders the view count in the list column.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Bike ID.
	 */
	public function render_column( $column, $post_id ) {
		if ( 'citykleta_views' !== $column ) {
			return;
		}

		echo esc_html( (string) self::count( $post_id ) );
	}

	/**
	 * Adds a read-only box on the bike edit screen with the per-day views.
	 */
	public function add_meta_box() {
		add_meta_box(
			'citykleta_views_stats',
			'Views',
			array( $this, 'render_meta_box' ),
			Citykleta_Bike_Post_Type::POST_TYPE,
			'side',
			'default'
		);
	}

	/**
	 * Renders the view stats: total plus a per-day breakdown.
	 *
	 * @param WP_Post $post The bike being viewed.
	 */
	public function render_meta_box( $post ) {
		$by_day = self::views_by_day( $post->ID );

		if ( empty( $by_day ) ) {
			echo '<p>No views yet.</p>';
			return;
		}

		echo '<p><strong>' . (int) array_sum( $by_day ) . '</strong> view(s) total.</p>';
		echo '<ul style="max-height:200px;overflow:auto;margin:0;">';
		foreach ( $by_day as $day => $count ) {
			$label = date_i18n( 'M j, Y', strtotime( $day ) );
			echo '<li>' . esc_html( $label ) . ': <strong>' . (int) $count . '</strong></li>';
		}
		echo '</ul>';
	}
}
