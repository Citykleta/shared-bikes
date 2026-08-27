<?php
/**
 * Assigns each bike to the `destination` term(s) whose geofence it falls
 * inside — the "bici → destino" half of the Destinos feature (see
 * Citykleta_Destination_Taxonomy for the "destino" half: term meta holding
 * each geofence's center + radius).
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
	 * Matches every bike with coordinates against every destination's
	 * geofence and saves the result via wp_set_object_terms(). No-ops
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
					$matched[] = $geofence['term_id'];
				}
			}

			// Always sets, even to an empty array: a bike that drifted out of
			// every geofence since the last sync needs its old destinations
			// cleared, not left stale.
			wp_set_object_terms( $bike['id'], $matched, Citykleta_Destination_Taxonomy::TAXONOMY );
		}
	}

	/**
	 * Every destination term whose geofence is actually usable (radius > 0
	 * — the default for a brand-new term with nothing filled in yet).
	 *
	 * @return array { 'term_id' => int, 'lat' => float, 'lng' => float, 'radius_km' => float }[].
	 */
	private function geofences() {
		$terms = get_terms(
			array(
				'taxonomy'   => Citykleta_Destination_Taxonomy::TAXONOMY,
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) || ! $terms ) {
			return array();
		}

		$geofences = array();

		foreach ( $terms as $term ) {
			$radius = (float) get_term_meta( $term->term_id, Citykleta_Destination_Taxonomy::META_RADIUS_KM, true );

			if ( $radius <= 0 ) {
				continue;
			}

			$geofences[] = array(
				'term_id'   => $term->term_id,
				'lat'       => (float) get_term_meta( $term->term_id, Citykleta_Destination_Taxonomy::META_LAT, true ),
				'lng'       => (float) get_term_meta( $term->term_id, Citykleta_Destination_Taxonomy::META_LNG, true ),
				'radius_km' => $radius,
			);
		}

		return $geofences;
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
