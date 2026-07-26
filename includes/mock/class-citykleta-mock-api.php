<?php
/**
 * TEMPORARY fake API that simulates the future Bike Sharing app API.
 *
 * Only exists to develop the sync mechanism while the real app API
 * doesn't exist yet. Delete (and remove its load in
 * citykleta-shared-bikes.php) as soon as a real endpoint exists.
 *
 * @package Citykleta_Shared_Bikes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Citykleta_Mock_Api class.
 */
class Citykleta_Mock_Api {

	/**
	 * Hooks the class into REST route registration.
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the fake API routes under the citykleta-mock/v1 namespace.
	 */
	public function register_routes() {
		register_rest_route(
			'citykleta-mock/v1',
			'/bikes',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_bikes' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'citykleta-mock/v1',
			'/bikes/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_bike' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'citykleta-mock/v1',
			'/bike-types',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_bike_types' ),
				'permission_callback' => '__return_true',
			)
		);

		// TEMPORARY diagnostic route to check the last sync status without
		// entering wp-admin. Delete along with the rest of includes/mock/.
		register_rest_route(
			'citykleta-mock/v1',
			'/sync-status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_sync_status' ),
				'permission_callback' => '__return_true',
			)
		);

		// TEMPORARY route to force a sync without waiting for cron or
		// entering wp-admin, only for development testing. Delete along
		// with the rest of includes/mock/.
		register_rest_route(
			'citykleta-mock/v1',
			'/trigger-sync',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'trigger_sync' ),
				'permission_callback' => '__return_true',
			)
		);

		// TEMPORARY route to force a rewrite rules flush in development
		// without entering wp-admin. Delete along with the rest of includes/mock/.
		register_rest_route(
			'citykleta-mock/v1',
			'/flush-rewrite',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'flush_rewrite' ),
				'permission_callback' => '__return_true',
			)
		);

	}

	/**
	 * Forces a rewrite rules flush (debug/development).
	 *
	 * @return WP_REST_Response
	 */
	public function flush_rewrite() {
		flush_rewrite_rules();
		return new WP_REST_Response( array( 'flushed' => true ), 200 );
	}

	/**
	 * Forces an immediate sync (debug/development).
	 *
	 * @return WP_REST_Response
	 */
	public function trigger_sync() {
		delete_option( 'citykleta_last_image_error' ); // clears leftovers from previous tests.
		( new Citykleta_Bike_Sync() )->run();
		return $this->get_sync_status();
	}

	/**
	 * Returns the stored status of the last sync (debug).
	 *
	 * @return WP_REST_Response
	 */
	public function get_sync_status() {
		return new WP_REST_Response(
			array(
				'api_url'   => Citykleta_Settings::get_endpoint_url( 'bikes' ),
				'frequency' => get_option( 'citykleta_sync_frequency' ),
				'last_sync' => get_option( 'citykleta_last_sync' ),
				'count'     => get_option( 'citykleta_last_sync_count' ),
				'error'     => get_option( 'citykleta_last_sync_error' ),
				'next_cron' => wp_next_scheduled( Citykleta_Bike_Sync::CRON_HOOK ),
				'image_error' => get_option( 'citykleta_last_image_error' ),
			),
			200
		);
	}

	/**
	 * Returns the full list of test bikes, wrapped in the { data, meta } envelope.
	 *
	 * @return WP_REST_Response
	 */
	public function get_bikes() {
		$bikes = $this->get_sample_data();

		return new WP_REST_Response(
			array(
				'data' => $bikes,
				'meta' => array(
					'page'     => 1,
					'per_page' => 50,
					'total'    => count( $bikes ),
					'has_more' => false,
				),
			),
			200
		);
	}

	/**
	 * Returns a single test bike by id.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function get_bike( WP_REST_Request $request ) {
		$id = (int) $request['id'];

		foreach ( $this->get_sample_data() as $bike ) {
			if ( $bike['id'] === $id ) {
				return new WP_REST_Response( $bike, 200 );
			}
		}

		return new WP_REST_Response( array( 'message' => 'Bike not found' ), 404 );
	}

	/**
	 * Returns the canonical list of bike types, wrapped in the { data } envelope.
	 * Each bike's `bike_type` field (see get_sample_data()) references one of
	 * these by slug.
	 *
	 * @return WP_REST_Response
	 */
	public function get_bike_types() {
		return new WP_REST_Response(
			array( 'data' => $this->get_bike_types_data() ),
			200
		);
	}

	/**
	 * Fake data shaped like the future real API is expected to return.
	 *
	 * @return array
	 */
	private function get_sample_data() {
		return array(
			array(
				'id'             => 1,
				'title'          => 'Trek Marlin 7',
				'description'    => 'Mountain bike ideal for rides around El Vedado and the surrounding area.',
				'model'          => 'Trek Marlin 7',
				'brand'          => 'Trek',
				'bike_type'      => 'mtb',
				'location'       => 'Vedado, Havana',
				'coordinates'    => array( 'lat' => 23.1367, 'lng' => -82.3831 ),
				'price_per_hour' => 150,
				'available'      => true,
				'size'           => 'M',
				'wheel_size'     => '29"',
				'is_electric'    => false,
				'images'         => array( $this->placeholder_image( 'Trek Marlin 7' ) ),
				'owner'          => array( 'name' => 'Laura', 'photo' => $this->owner_avatar( 'L' ), 'phone' => '+53 53001001' ),
			),
			array(
				'id'             => 2,
				'title'          => 'Giant Escape 3',
				'description'    => 'Comfortable city bike for getting around Centro Habana.',
				'model'          => 'Giant Escape 3',
				'brand'          => 'Giant',
				'bike_type'      => 'hybrid',
				'location'       => 'Centro Habana, Havana',
				'coordinates'    => array( 'lat' => 23.1355, 'lng' => -82.3700 ),
				'price_per_hour' => 120,
				'available'      => true,
				'size'           => 'S',
				'wheel_size'     => '700c',
				'is_electric'    => false,
				'images'         => array( $this->placeholder_image( 'Giant Escape 3' ) ),
				'owner'          => array( 'name' => 'John', 'photo' => $this->owner_avatar( 'J' ), 'phone' => '+53 53002002' ),
			),
			array(
				'id'             => 3,
				'title'          => 'Xiaomi Himo C20',
				'description'    => 'Folding electric bike, perfect for long rides in Playa.',
				'model'          => 'Xiaomi Himo C20',
				'brand'          => 'Xiaomi',
				'bike_type'      => 'folding-bike',
				'location'       => 'Playa, Havana',
				'coordinates'    => array( 'lat' => 23.1039, 'lng' => -82.4304 ),
				'price_per_hour' => 250,
				'available'      => false,
				'size'           => 'One size',
				'wheel_size'     => '20"',
				'is_electric'    => true,
				'images'         => array( $this->placeholder_image( 'Xiaomi Himo C20' ) ),
				'owner'          => array( 'name' => 'Maria', 'photo' => $this->owner_avatar( 'M' ), 'phone' => '+53 53003003' ),
			),
			array(
				'id'             => 4,
				'title'          => 'Specialized Rockhopper',
				'description'    => 'Rugged mountain bike, available in Miramar.',
				'model'          => 'Specialized Rockhopper',
				'brand'          => 'Specialized',
				'bike_type'      => 'mtb',
				'location'       => 'Miramar, Havana',
				'coordinates'    => array( 'lat' => 23.1085, 'lng' => -82.4241 ),
				'price_per_hour' => 180,
				'available'      => true,
				'size'           => 'L',
				'wheel_size'     => '27.5"',
				'is_electric'    => false,
				'images'         => array( $this->placeholder_image( 'Specialized Rockhopper' ) ),
				'owner'          => array( 'name' => 'Alfonso', 'photo' => $this->owner_avatar( 'A' ), 'phone' => '+53 53004004' ),
			),
			array(
				'id'             => 5,
				'title'          => 'Bianchi Aria',
				'description'    => 'Lightweight road bike for touring Old Havana.',
				'model'          => 'Bianchi Aria',
				'brand'          => 'Bianchi',
				'bike_type'      => 'road',
				'location'       => 'Old Havana, Havana',
				'coordinates'    => array( 'lat' => 23.1367, 'lng' => -82.3589 ),
				'price_per_hour' => 200,
				'available'      => true,
				'size'           => 'M',
				'wheel_size'     => '700c',
				'is_electric'    => false,
				'images'         => array( $this->placeholder_image( 'Bianchi Aria' ) ),
				'owner'          => array( 'name' => 'Gustavo', 'photo' => $this->owner_avatar( 'G' ), 'phone' => '+53 53005005' ),
			),
			array(
				'id'             => 6,
				'title'          => 'Flying Pigeon PA-02',
				'description'    => 'Classic bike, sturdy and easy to maintain, very common in Marianao.',
				'model'          => 'Flying Pigeon PA-02',
				'brand'          => 'Flying Pigeon',
				'bike_type'      => 'city-bike',
				'location'       => 'Marianao, Havana',
				'coordinates'    => array( 'lat' => 23.0847, 'lng' => -82.4321 ),
				'price_per_hour' => 80,
				'available'      => true,
				'size'           => 'One size',
				'wheel_size'     => '28"',
				'is_electric'    => false,
				'images'         => array( $this->placeholder_image( 'Flying Pigeon PA-02' ) ),
				'owner'          => array( 'name' => 'Laura', 'photo' => $this->owner_avatar( 'L' ), 'phone' => '+53 53001001' ),
			),
			array(
				'id'             => 7,
				'title'          => 'Cannondale Trail 5',
				'description'    => 'Versatile mountain bike, currently reserved, in Plaza de la Revolucion.',
				'model'          => 'Cannondale Trail 5',
				'brand'          => 'Cannondale',
				'bike_type'      => 'mtb',
				'location'       => 'Plaza de la Revolucion, Havana',
				'coordinates'    => array( 'lat' => 23.1147, 'lng' => -82.3877 ),
				'price_per_hour' => 220,
				'available'      => false,
				'size'           => 'L',
				'wheel_size'     => '29"',
				'is_electric'    => false,
				'images'         => array( $this->placeholder_image( 'Cannondale Trail 5' ) ),
				'owner'          => array( 'name' => 'John', 'photo' => $this->owner_avatar( 'J' ), 'phone' => '+53 53002002' ),
			),
			array(
				'id'             => 8,
				'title'          => 'Merida Speeder',
				'description'    => 'Electric city bike, comfortable for daily commutes in Diez de Octubre.',
				'model'          => 'Merida Speeder',
				'brand'          => 'Merida',
				'bike_type'      => 'city-bike',
				'location'       => 'Diez de Octubre, Havana',
				'coordinates'    => array( 'lat' => 23.1080, 'lng' => -82.3620 ),
				'price_per_hour' => 260,
				'available'      => true,
				'size'           => 'M',
				'wheel_size'     => '700c',
				'is_electric'    => true,
				'images'         => array( $this->placeholder_image( 'Merida Speeder' ) ),
				'owner'          => array( 'name' => 'Maria', 'photo' => $this->owner_avatar( 'M' ), 'phone' => '+53 53003003' ),
			),
			array(
				'id'             => 9,
				'title'          => 'Scott Aspect 950',
				'description'    => 'Agile mountain bike, available in Cerro.',
				'model'          => 'Scott Aspect 950',
				'brand'          => 'Scott',
				'bike_type'      => 'mtb',
				'location'       => 'Cerro, Havana',
				'coordinates'    => array( 'lat' => 23.1180, 'lng' => -82.3800 ),
				'price_per_hour' => 190,
				'available'      => true,
				'size'           => 'S',
				'wheel_size'     => '27.5"',
				'is_electric'    => false,
				'images'         => array( $this->placeholder_image( 'Scott Aspect 950' ) ),
				'owner'          => array( 'name' => 'Alfonso', 'photo' => $this->owner_avatar( 'A' ), 'phone' => '+53 53004004' ),
			),
			array(
				'id'             => 10,
				'title'          => 'Orbea Gain',
				'description'    => 'High-end electric road bike, available in Boyeros.',
				'model'          => 'Orbea Gain',
				'brand'          => 'Orbea',
				'bike_type'      => 'road',
				'location'       => 'Boyeros, Havana',
				'coordinates'    => array( 'lat' => 22.9903, 'lng' => -82.4083 ),
				'price_per_hour' => 300,
				'available'      => true,
				'size'           => 'L',
				'wheel_size'     => '700c',
				'is_electric'    => true,
				'images'         => array( $this->placeholder_image( 'Orbea Gain' ) ),
				'owner'          => array( 'name' => 'Gustavo', 'photo' => $this->owner_avatar( 'G' ), 'phone' => '+53 53005005' ),
			),
			array(
				'id'             => 11,
				'title'          => 'Forever Urban Folding',
				'description'    => 'Budget-friendly folding bike, ideal for short trips in Regla.',
				'model'          => 'Forever Urban Folding',
				'brand'          => 'Forever',
				'bike_type'      => 'folding-bike',
				'location'       => 'Regla, Havana',
				'coordinates'    => array( 'lat' => 23.1497, 'lng' => -82.3384 ),
				'price_per_hour' => 90,
				'available'      => true,
				'size'           => 'One size',
				'wheel_size'     => '20"',
				'is_electric'    => false,
				'images'         => array( $this->placeholder_image( 'Forever Urban Folding' ) ),
				'owner'          => array( 'name' => 'Laura', 'photo' => $this->owner_avatar( 'L' ), 'phone' => '+53 53001001' ),
			),
		);
	}

	/**
	 * Fake canonical list of bike types. Slugs must match the `bike_type`
	 * values used in get_sample_data().
	 *
	 * @return array
	 */
	private function get_bike_types_data() {
		return array(
			array( 'id' => 1, 'slug' => 'mtb-xc', 'name' => 'MTB XC' ),
			array( 'id' => 2, 'slug' => 'hybrid', 'name' => 'Hybrid' ),
			array( 'id' => 3, 'slug' => 'folding-bike', 'name' => 'Folding bike' ),
			array( 'id' => 4, 'slug' => 'trail', 'name' => 'Trail' ),
			array( 'id' => 5, 'slug' => 'road', 'name' => 'Road' ),
			array( 'id' => 6, 'slug' => 'city-bike', 'name' => 'City bike' ),
		);
	}

	/**
	 * Builds a placeholder image URL (placehold.co) with the bike's name,
	 * to simulate the photos the real API will bring.
	 *
	 * @param string $label Text to show on the placeholder.
	 * @return string
	 */
	private function placeholder_image( $label ) {
		// The .png extension is required: WordPress decides the image type
		// from the URL extension when downloading it (media_sideload_image),
		// not from the response's actual content type.
		return 'https://placehold.co/600x400.png?text=' . rawurlencode( $label );
	}

	/**
	 * Builds a placeholder avatar URL (placehold.co) with the owner's
	 * initial, to simulate the photo the real API will bring.
	 *
	 * @param string $initial Initial to show on the avatar.
	 * @return string
	 */
	private function owner_avatar( $initial ) {
		return 'https://placehold.co/150x150.png?text=' . rawurlencode( $initial );
	}
}
