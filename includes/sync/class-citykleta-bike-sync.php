<?php
/**
 * Syncs bikes from an external API (the app's, or the mock while it
 * doesn't exist yet) into the "bike" custom post type.
 *
 * @package Citykleta_Shared_Bikes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Citykleta_Bike_Sync.
 */
class Citykleta_Bike_Sync {

	/**
	 * WP-Cron hook name used for the periodic sync.
	 */
	const CRON_HOOK = 'citykleta_sync_bikes';

	/**
	 * Connects the class to WordPress hooks.
	 */
	public function register() {
		add_filter( 'cron_schedules', array( $this, 'add_cron_schedules' ) );

		// Called directly (not via add_action('init', ...)) because register()
		// is already invoked from within the 'init' hook (see citykleta_init()).
		// Hooking 'init' again at the same priority wouldn't run in that
		// same pass.
		$this->register_external_id_meta();
		$this->maybe_schedule();

		add_action( self::CRON_HOOK, array( $this, 'run' ) );
		add_action( 'admin_post_citykleta_manual_sync', array( $this, 'handle_manual_sync' ) );
	}

	/**
	 * Adds cron intervals WordPress doesn't ship with by default.
	 *
	 * @param array $schedules Existing intervals.
	 * @return array
	 */
	public function add_cron_schedules( $schedules ) {
		$schedules['every_15_minutes'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => 'Every 15 minutes',
		);
		$schedules['every_30_minutes'] = array(
			'interval' => 30 * MINUTE_IN_SECONDS,
			'display'  => 'Every 30 minutes',
		);
		return $schedules;
	}

	/**
	 * Registers the "external_id" meta linking each "bike" post to its id
	 * in the external API. Not manually editable, which is why it isn't
	 * in Citykleta_Bike_Meta.
	 */
	public function register_external_id_meta() {
		register_post_meta(
			Citykleta_Bike_Post_Type::POST_TYPE,
			'external_id',
			array(
				'type'          => 'integer',
				'single'        => true,
				'show_in_rest'  => true,
				'auth_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
	}

	/**
	 * Schedules the cron event if none is scheduled yet. Doesn't change
	 * the frequency of an existing one (that's what reschedule() is for).
	 */
	public function maybe_schedule() {
		$frequency = get_option( 'citykleta_sync_frequency', 'hourly' );

		if ( 'manual' === $frequency ) {
			return;
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), $frequency, self::CRON_HOOK );
		}
	}

	/**
	 * Clears the scheduled event and reschedules it with a new frequency.
	 * Called when the citykleta_sync_frequency option changes.
	 *
	 * @param string $frequency New frequency slug.
	 */
	public function reschedule( $frequency ) {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}

		if ( 'manual' !== $frequency ) {
			wp_schedule_event( time(), $frequency, self::CRON_HOOK );
		}
	}

	/**
	 * Clears any scheduled event. Used when the plugin is deactivated.
	 */
	public function clear_schedule() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Runs the sync: fetches bikes from the configured API and creates or
	 * updates the corresponding "bike" posts.
	 */
	public function run() {
		// Must run first: bikes reference their type by slug, and every
		// slug needs a matching term before upsert_bike() looks it up.
		( new Citykleta_Bike_Type_Sync() )->run();

		$url = Citykleta_Settings::get_endpoint_url( 'bikes' );

		if ( empty( $url ) ) {
			update_option( 'citykleta_last_sync_error', 'No API URL configured.' );
			return;
		}

		$response = wp_remote_get( $url, array( 'timeout' => 15 ) );

		if ( is_wp_error( $response ) ) {
			update_option( 'citykleta_last_sync_error', $response->get_error_message() );
			return;
		}

		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			update_option( 'citykleta_last_sync_error', 'API responded with status code ' . wp_remote_retrieve_response_code( $response ) . '.' );
			return;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		// The API wraps the list in a { data, meta } envelope, not a bare array.
		if ( ! is_array( $body ) || ! isset( $body['data'] ) || ! is_array( $body['data'] ) ) {
			update_option( 'citykleta_last_sync_error', 'API response is not valid JSON.' );
			return;
		}

		$items = $body['data'];

		$count = 0;

		foreach ( $items as $item ) {
			if ( empty( $item['id'] ) ) {
				continue;
			}

			$this->upsert_bike( $item );
			++$count;
		}

		delete_option( 'citykleta_last_sync_error' );
		update_option( 'citykleta_last_sync', current_time( 'mysql' ) );
		update_option( 'citykleta_last_sync_count', $count );
	}

	/**
	 * Creates or updates the "bike" post matching a bike from the API.
	 *
	 * @param array $item Bike data, as returned by the API.
	 */
	private function upsert_bike( array $item ) {
		$existing = get_posts(
			array(
				'post_type'      => Citykleta_Bike_Post_Type::POST_TYPE,
				'post_status'    => 'any',
				'meta_key'       => 'external_id',
				'meta_value'     => (int) $item['id'],
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		$title = $this->build_title( $item );

		$post_data = array(
			'post_type'    => Citykleta_Bike_Post_Type::POST_TYPE,
			'post_title'   => $title,
			// Recalculated every sync so the human-friendly slug stays in sync
			// with brand/model/owner; the id-prefixed URL (see
			// Citykleta_Bike_Router) keeps old links working via canonical
			// redirect when it changes.
			'post_name'    => sanitize_title( $title ),
			'post_content' => isset( $item['description'] ) ? sanitize_textarea_field( $item['description'] ) : '',
			'post_status'  => 'publish',
		);

		if ( ! empty( $existing ) ) {
			$post_data['ID'] = $existing[0];
			$post_id         = wp_update_post( $post_data );
		} else {
			$post_id = wp_insert_post( $post_data );
		}

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return;
		}

		update_post_meta( $post_id, 'external_id', (int) $item['id'] );

		$boolean_fields = array( 'available', 'is_electric' );
		$meta_fields    = array( 'model', 'location', 'price_per_hour', 'available', 'brand', 'size', 'wheel_size', 'is_electric' );

		foreach ( $meta_fields as $key ) {
			if ( ! array_key_exists( $key, $item ) ) {
				continue;
			}

			$value = $item[ $key ];

			if ( in_array( $key, $boolean_fields, true ) ) {
				$value = $value ? '1' : '0';
			}

			update_post_meta( $post_id, $key, $value );
		}

		// Owner comes as a nested object ({ name, photo }), not flat owner_name/owner_photo.
		if ( isset( $item['owner']['name'] ) ) {
			update_post_meta( $post_id, 'owner_name', sanitize_text_field( $item['owner']['name'] ) );
		}

		if ( isset( $item['owner']['photo'] ) ) {
			update_post_meta( $post_id, 'owner_photo', esc_url_raw( $item['owner']['photo'] ) );
		}

		// Only the first image is used until gallery support exists.
		if ( ! empty( $item['images'][0] ) ) {
			$this->sync_image( $post_id, esc_url_raw( $item['images'][0] ) );
		}

		if ( ! empty( $item['bike_type'] ) ) {
			// bike_type is a slug (see Citykleta_Bike_Type_Sync) — look up the
			// matching term instead of creating one from the raw string.
			$type_term = get_term_by( 'slug', sanitize_title( $item['bike_type'] ), Citykleta_Bike_Type_Taxonomy::TAXONOMY );

			if ( $type_term ) {
				wp_set_object_terms( $post_id, array( $type_term->term_id ), Citykleta_Bike_Type_Taxonomy::TAXONOMY );
			}
		}

		// Coordinates come as a nested object ({ lat, lng }), not flat lat/lng.
		if ( isset( $item['coordinates']['lat'], $item['coordinates']['lng'] ) ) {
			update_post_meta( $post_id, 'lat', floatval( $item['coordinates']['lat'] ) );
			update_post_meta( $post_id, 'lng', floatval( $item['coordinates']['lng'] ) );
		}
	}

	/**
	 * Builds the bike's post title from brand + model + owner name
	 * (e.g. "COGA Supersonic — Betty"), which also drives the human-friendly
	 * URL slug. Falls back to the API's own title, then to the id, so the post
	 * is never left untitled.
	 *
	 * @param array $item Bike data, as returned by the API.
	 * @return string
	 */
	private function build_title( array $item ) {
		$parts = array_filter(
			array(
				isset( $item['brand'] ) ? sanitize_text_field( $item['brand'] ) : '',
				isset( $item['model'] ) ? sanitize_text_field( $item['model'] ) : '',
			)
		);

		$title = implode( ' ', $parts );

		if ( ! empty( $item['owner']['name'] ) ) {
			$owner = sanitize_text_field( $item['owner']['name'] );
			$title = '' !== $title ? $title . ' — ' . $owner : $owner;
		}

		if ( '' !== $title ) {
			return $title;
		}

		if ( ! empty( $item['title'] ) ) {
			return sanitize_text_field( $item['title'] );
		}

		return 'Bike #' . (int) $item['id'];
	}

	/**
	 * Downloads the bike's image (if it changed) and sets it as the post's
	 * featured image. Doesn't re-download if the URL hasn't changed since
	 * the last sync, to avoid wasting extra bandwidth.
	 *
	 * @param int    $post_id   "bike" post ID.
	 * @param string $image_url Image URL on the external API.
	 */
	private function sync_image( $post_id, $image_url ) {
		$previous_url = get_post_meta( $post_id, '_citykleta_image_source_url', true );

		if ( $previous_url === $image_url && has_post_thumbnail( $post_id ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = media_sideload_image( $image_url, $post_id, null, 'id' );

		if ( is_wp_error( $attachment_id ) ) {
			update_option( 'citykleta_last_image_error', $attachment_id->get_error_message() . ' (' . $image_url . ')' );
			return;
		}

		delete_option( 'citykleta_last_image_error' );
		set_post_thumbnail( $post_id, $attachment_id );
		update_post_meta( $post_id, '_citykleta_image_source_url', $image_url );
	}

	/**
	 * Handles the "Sync now" button on the settings page.
	 */
	public function handle_manual_sync() {
		if ( ! current_user_can( 'manage_options' ) ||
			! isset( $_POST['citykleta_sync_nonce'] ) ||
			! wp_verify_nonce( sanitize_key( $_POST['citykleta_sync_nonce'] ), 'citykleta_manual_sync' ) ) {
			wp_die( 'Unauthorized.' );
		}

		$this->run();

		wp_safe_redirect( add_query_arg( 'citykleta_synced', '1', wp_get_referer() ) );
		exit;
	}
}
