<?php
/**
 * Keeps the `bike_type` taxonomy terms in step with the types the Sharing App
 * reports, so every `bike_type` slug a bike references already exists as a term
 * by the time the bikes themselves are saved.
 *
 * The types are read off the bike payload rather than fetched (the API's
 * /bike-types is staff-only) — see run(). Never deletes terms that disappear;
 * they're flagged inactive instead, see _citykleta_type_active below.
 *
 * @package Citykleta_Shared_Bikes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Citykleta_Bike_Type_Sync class.
 */
class Citykleta_Bike_Type_Sync {

	/**
	 * Term meta key used to flag whether a type was present in the last sync.
	 */
	const ACTIVE_META_KEY = '_citykleta_type_active';

	/**
	 * Creates/updates the taxonomy terms for the bike types seen in a sync.
	 *
	 * Takes the types instead of fetching them: the API's GET /bike-types is
	 * mounted after citykleta-sharing-api's global verifyAuth and answers 401
	 * to anyone without a staff session, so this plugin cannot read it. It
	 * doesn't need to — every row of GET /bikesPublic/sync carries the type's
	 * slug and display name, so Citykleta_Bike_Sync collects them off the bike
	 * payload it already fetched and hands them over. That also means this runs
	 * after the bike list is in hand, but still before the upsert loop that
	 * resolves each bike's type by slug.
	 *
	 * Terms are never deleted: a bike (or a usage mapping) may already
	 * reference one. Types absent from this run are instead flagged inactive
	 * via term meta, so admin screens can show them as no longer available
	 * without breaking existing references.
	 *
	 * @param array $types Display name keyed by type slug, as collected by
	 *                     Citykleta_Bike_Sync::collect_bike_types().
	 */
	public function run( array $types ) {
		// An empty map means the run brought no usable types at all (an empty
		// catalogue, or a payload with no bike_type on any row). Flagging
		// everything inactive off that would be wrong, so leave the terms be.
		if ( ! $types ) {
			return;
		}

		$seen_slugs = array();

		foreach ( $types as $slug => $name ) {
			$slug    = sanitize_title( $slug );
			$name    = sanitize_text_field( $name );
			$term_id = $slug && $name ? $this->upsert_term( $slug, $name ) : 0;

			if ( $term_id ) {
				update_term_meta( $term_id, self::ACTIVE_META_KEY, '1' );
				$seen_slugs[] = $slug;
			}
		}

		$this->flag_missing_as_inactive( $seen_slugs );
	}

	/**
	 * Finds a term by slug, creating it if missing or renaming it if the
	 * API's display name changed.
	 *
	 * @param string $slug Term slug.
	 * @param string $name Term display name.
	 * @return int Term id, or 0 on failure.
	 */
	private function upsert_term( $slug, $name ) {
		$term = get_term_by( 'slug', $slug, Citykleta_Bike_Type_Taxonomy::TAXONOMY );

		if ( ! $term ) {
			$inserted = wp_insert_term( $name, Citykleta_Bike_Type_Taxonomy::TAXONOMY, array( 'slug' => $slug ) );
			return is_wp_error( $inserted ) ? 0 : (int) $inserted['term_id'];
		}

		if ( $term->name !== $name ) {
			wp_update_term( $term->term_id, Citykleta_Bike_Type_Taxonomy::TAXONOMY, array( 'name' => $name ) );
		}

		return (int) $term->term_id;
	}

	/**
	 * Marks every existing term whose slug wasn't in this sync's response
	 * as inactive, without deleting it.
	 *
	 * @param array $seen_slugs Slugs returned by the API in this run.
	 */
	private function flag_missing_as_inactive( array $seen_slugs ) {
		$existing_terms = get_terms(
			array(
				'taxonomy'   => Citykleta_Bike_Type_Taxonomy::TAXONOMY,
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $existing_terms ) ) {
			return;
		}

		foreach ( $existing_terms as $existing_term ) {
			if ( ! in_array( $existing_term->slug, $seen_slugs, true ) ) {
				update_term_meta( $existing_term->term_id, self::ACTIVE_META_KEY, '0' );
			}
		}
	}
}
