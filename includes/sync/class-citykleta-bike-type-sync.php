<?php
/**
 * Syncs the canonical bike type list from the Sharing App API (or the mock
 * while it doesn't exist yet) into the `bike_type` taxonomy terms.
 *
 * Runs before Citykleta_Bike_Sync so every `bike_type` slug a bike may
 * reference already exists as a term. Never deletes terms that disappear
 * from the API — see _citykleta_type_active below.
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
	 * Fetches the bike types API and creates/updates matching terms.
	 *
	 * Terms are never deleted: a bike (or a usage mapping) may already
	 * reference one. Terms missing from the current response are instead
	 * flagged inactive via term meta, so admin screens can show them as
	 * no longer available without breaking existing references.
	 */
	public function run() {
		$url = Citykleta_Settings::get_endpoint_url( 'bike_types' );

		if ( empty( $url ) ) {
			return;
		}

		$response = wp_remote_get( $url, array( 'timeout' => 15 ) );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || ! isset( $body['data'] ) || ! is_array( $body['data'] ) ) {
			return;
		}

		$seen_slugs = array();

		foreach ( $body['data'] as $type ) {
			if ( empty( $type['slug'] ) || empty( $type['name'] ) ) {
				continue;
			}

			$slug    = sanitize_title( $type['slug'] );
			$name    = sanitize_text_field( $type['name'] );
			$term_id = $this->upsert_term( $slug, $name );

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
