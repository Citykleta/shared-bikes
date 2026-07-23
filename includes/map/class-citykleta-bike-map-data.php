<?php
/**
 * Shared query helper: turns bike posts into the JSON-ready array
 * citykleta-map.js expects. Used by both Citykleta_Map_Widget (Elementor)
 * and Citykleta_Admin_Map (wp-admin page), so the query/payload logic only
 * lives in one place.
 *
 * @package Citykleta_Shared_Bikes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Citykleta_Bike_Map_Data.
 */
class Citykleta_Bike_Map_Data {

	/**
	 * Queries published bikes that have coordinates and returns them as a
	 * plain array ready for wp_json_encode(), matching the shape
	 * citykleta-map.js's bikeIcon()/popupContent() read.
	 *
	 * @param array $args {
	 *     @type int    $count          Max results. -1 for unlimited. Default 100.
	 *     @type bool   $only_available Only bikes with available = '1'. Default false.
	 *     @type string $location       Partial match against the location meta. Default ''.
	 * }
	 * @return array
	 */
	public static function query( array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'count'          => 100,
				'only_available' => false,
				'location'       => '',
			)
		);

		$meta_query = array(
			array(
				'key'     => 'lat',
				'compare' => 'EXISTS',
			),
			array(
				'key'     => 'lng',
				'compare' => 'EXISTS',
			),
		);

		if ( $args['only_available'] ) {
			$meta_query[] = array(
				'key'   => 'available',
				'value' => '1',
			);
		}

		if ( ! empty( $args['location'] ) ) {
			$meta_query[] = array(
				'key'     => 'location',
				'value'   => sanitize_text_field( $args['location'] ),
				'compare' => 'LIKE',
			);
		}

		$query = new WP_Query(
			array(
				'post_type'      => Citykleta_Bike_Post_Type::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => (int) $args['count'],
				'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);

		$bikes = array();

		while ( $query->have_posts() ) {
			$query->the_post();

			$lat = get_post_meta( get_the_ID(), 'lat', true );
			$lng = get_post_meta( get_the_ID(), 'lng', true );

			// meta_query's EXISTS already filters this, but a meta row can
			// exist with an empty value (e.g. leftover from manual editing).
			if ( '' === $lat || '' === $lng ) {
				continue;
			}

			$price     = get_post_meta( get_the_ID(), 'price_per_hour', true );
			$location  = get_post_meta( get_the_ID(), 'location', true );
			$available = get_post_meta( get_the_ID(), 'available', true );

			$bikes[] = array(
				'id'        => get_the_ID(),
				'lat'       => (float) $lat,
				'lng'       => (float) $lng,
				'title'     => get_the_title(),
				'location'  => $location,
				'price'     => $price ? $price . ' CUP/hora' : '',
				'available' => '1' === (string) $available,
				'url'       => get_permalink(),
				'image'     => has_post_thumbnail() ? get_the_post_thumbnail_url( get_the_ID(), 'medium' ) : '',
			);
		}

		wp_reset_postdata();

		return $bikes;
	}

	/**
	 * Total published bikes, regardless of whether they have coordinates —
	 * used to tell the admin how many are missing lat/lng (e.g. synced
	 * before this field existed, or whose source item had no coordinates).
	 *
	 * @return int
	 */
	public static function count_all() {
		$query = new WP_Query(
			array(
				'post_type'      => Citykleta_Bike_Post_Type::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		return (int) $query->found_posts;
	}
}
