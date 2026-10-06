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
	 *     @type array  $extra_meta     Extra meta_query conditions, AND-ed in. Default [].
	 *     @type array  $tax_query      A tax_query to apply. Default [].
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
				'extra_meta'     => array(),
				'tax_query'      => array(),
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

		// Condiciones de meta extra que traiga quien llama (ej. los filtros del
		// archivo: eléctricas), en AND con las de arriba.
		if ( ! empty( $args['extra_meta'] ) ) {
			$meta_query = array_merge( $meta_query, $args['extra_meta'] );
		}

		$query_args = array(
			'post_type'      => Citykleta_Bike_Post_Type::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => (int) $args['count'],
			'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		);

		if ( ! empty( $args['tax_query'] ) ) {
			$query_args['tax_query'] = $args['tax_query']; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}

		$query = new WP_Query( $query_args );

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

			$price     = Citykleta_Bike_Meta::price_html( get_the_ID() );
			$location  = get_post_meta( get_the_ID(), 'location', true );
			$available = get_post_meta( get_the_ID(), 'available', true );

			$bikes[] = array(
				'id'        => get_the_ID(),
				'lat'       => (float) $lat,
				'lng'       => (float) $lng,
				'title'     => get_the_title(),
				'location'  => $location,
				'price'     => $price,
				'price_tag' => $price,
				'available' => '1' === (string) $available,
				'url'       => get_permalink(),
				'image'     => has_post_thumbnail() ? get_the_post_thumbnail_url( get_the_ID(), 'medium' ) : '',
			) + self::card_fields( get_the_ID() );
		}

		wp_reset_postdata();

		return $bikes;
	}

	/**
	 * Same shape as query(), for exactly one bike — used by the single-bike
	 * page's "Dónde recogerla" mini-map (a single marker, not a whole
	 * WP_Query) instead of duplicating the field list there.
	 *
	 * @param int $post_id Bike post ID.
	 * @return array|null The bike's map data, or null when it has no
	 *                     coordinates yet (see the lat/lng note in query()).
	 */
	public static function for_bike( $post_id ) {
		$lat = get_post_meta( $post_id, 'lat', true );
		$lng = get_post_meta( $post_id, 'lng', true );

		if ( '' === $lat || '' === $lng ) {
			return null;
		}

		$price     = Citykleta_Bike_Meta::price_html( $post_id );
		$location  = get_post_meta( $post_id, 'location', true );
		$available = get_post_meta( $post_id, 'available', true );

		return array(
			'id'        => $post_id,
			'lat'       => (float) $lat,
			'lng'       => (float) $lng,
			'title'     => get_the_title( $post_id ),
			'location'  => $location,
			'price'     => $price,
			'price_tag' => $price,
			'available' => '1' === (string) $available,
			// No 'url': the visitor is already on this bike's page — a "Ver
			// bici" link back to itself in the popup would be dead weight.
			'url'       => '',
			'image'     => has_post_thumbnail( $post_id ) ? get_the_post_thumbnail_url( $post_id, 'medium' ) : '',
		) + self::card_fields( $post_id );
	}

	/**
	 * The extra field the map card shows (type), kept in one place so query()
	 * and for_bike() can't drift apart. The title already carries brand + model.
	 *
	 * "Type" is the top-level one (Urbano, Carretera…), the parent of the bike's
	 * subtype; a bike whose subtype is still Unclassified falls back to the
	 * subtype's own name rather than showing nothing.
	 *
	 * @param int $post_id Bike post ID.
	 * @return array
	 */
	private static function card_fields( $post_id ) {
		$types = wp_list_pluck( Citykleta_Bike_Type_Taxonomy::bike_types( $post_id ), 'name' );

		if ( ! $types ) {
			$types = wp_list_pluck( Citykleta_Bike_Type_Taxonomy::bike_subtypes( $post_id ), 'name' );
		}

		return array(
			'type' => implode( ', ', $types ),
		);
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
