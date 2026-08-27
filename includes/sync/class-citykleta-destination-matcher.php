<?php
/**
 * Assigns each bike to the destination post(s) whose geofence it falls
 * inside — the "bici → destino" half of the Destinos feature (see
 * Citykleta_Destination_Post_Type for the "destino" half: post meta holding
 * each geofence's center + radius).
 *
 * Destinations are a post type, not a taxonomy (see Citykleta_Destination_Post_Type's
 * docblock for why), so there's no wp_set_object_terms() to lean on: the
 * relationship is a repeatable post meta row on the bike instead —
 * self::META_KEY, added once per matched destination (not a single
 * serialized array), so a plain meta_query on the destination page's
 * WP_Query can match it directly.
 *
 * Runs on every bike sync, AFTER bikes are upserted (needs their current
 * lat/lng already saved) — see Citykleta_Bike_Sync::run(). Fully
 * recomputed each time, not incremental: a bike that moved changes
 * destination on its own, and one that no longer falls inside any geofence
 * loses every destination it had.
 *
 * @package Citykleta_Shared_Bikes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Citykleta_Destination_Matcher class.
 */
class Citykleta_Destination_Matcher {

	/**
	 * Earth's mean radius in kilometers, for the haversine distance below.
	 */
	const EARTH_RADIUS_KM = 6371;

	/**
	 * Bike post meta key holding one destination post ID per row (repeatable,
	 * not a single serialized array) — read with
	 * get_post_meta( $bike_id, self::META_KEY, false ), matched in a
	 * WP_Query with a plain meta_query on this key.
	 */
	const META_KEY = '_citykleta_destination_id';

	/**
	 * Matches every bike with coordinates against every destination's
	 * geofence and saves the result as repeatable bike post meta. No-ops
	 * quietly if there are no destinations with a usable geofence yet
	 * (radius > 0) — same "nothing to do" pattern as the other sync classes.
	 */
	public function run() {
		$geofences = $this->geofences();

		if ( ! $geofences ) {
			return;
		}

		// Reuses the same "bikes with lat/lng" query Citykleta_Admin_Map and
		// the map widgets already run, instead of writing another WP_Query
		// for the same thing.
		$bikes = Citykleta_Bike_Map_Data::query( array( 'count' => -1 ) );

		foreach ( $bikes as $bike ) {
			$matched = array();

			foreach ( $geofences as $geofence ) {
				if ( self::distance_km( $bike['lat'], $bike['lng'], $geofence['lat'], $geofence['lng'] ) <= $geofence['radius_km'] ) {
					$matched[] = $geofence['post_id'];
				}
			}

			// Always clears first: a bike that drifted out of every geofence
			// since the last sync needs its old destinations removed, not
			// left stale. delete_post_meta() with no value removes every row
			// for this key before the fresh ones (if any) get added.
			delete_post_meta( $bike['id'], self::META_KEY );

			foreach ( $matched as $post_id ) {
				add_post_meta( $bike['id'], self::META_KEY, $post_id );
			}
		}
	}

	/**
	 * Every published destination whose geofence is actually usable
	 * (radius > 0 — the default for a brand-new destination with nothing
	 * filled in yet).
	 *
	 * @return array { 'post_id' => int, 'lat' => float, 'lng' => float, 'radius_km' => float }[].
	 */
	private function geofences() {
		$posts = get_posts(
			array(
				'post_type'      => Citykleta_Destination_Post_Type::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		$geofences = array();

		foreach ( $posts as $post_id ) {
			$radius = (float) get_post_meta( $post_id, Citykleta_Destination_Post_Type::META_RADIUS_KM, true );

			if ( $radius <= 0 ) {
				continue;
			}

			$geofences[] = array(
				'post_id'   => $post_id,
				'lat'       => (float) get_post_meta( $post_id, Citykleta_Destination_Post_Type::META_LAT, true ),
				'lng'       => (float) get_post_meta( $post_id, Citykleta_Destination_Post_Type::META_LNG, true ),
				'radius_km' => $radius,
			);
		}

		return $geofences;
	}

	/**
	 * Every destination a bike currently belongs to. Used by the bike editor
	 * (nice-to-have visibility, not required) and anywhere else that needs
	 * the reverse lookup instead of querying bikes by destination.
	 *
	 * @param int $bike_id Bike post ID.
	 * @return int[] Destination post IDs.
	 */
	public static function destinations_for_bike( $bike_id ) {
		$ids = get_post_meta( $bike_id, self::META_KEY, false );

		return array_map( 'intval', $ids );
	}

	/**
	 * Great-circle distance between two points, in kilometers.
	 *
	 * @param float $lat1 First point's latitude.
	 * @param float $lng1 First point's longitude.
	 * @param float $lat2 Second point's latitude.
	 * @param float $lng2 Second point's longitude.
	 * @return float
	 */
	public static function distance_km( $lat1, $lng1, $lat2, $lng2 ) {
		$delta_lat = deg2rad( $lat2 - $lat1 );
		$delta_lng = deg2rad( $lng2 - $lng1 );

		$a = sin( $delta_lat / 2 ) ** 2
			+ cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) ) * sin( $delta_lng / 2 ) ** 2;

		return self::EARTH_RADIUS_KM * 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );
	}
}
