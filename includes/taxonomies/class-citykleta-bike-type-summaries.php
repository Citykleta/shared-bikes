<?php
/**
 * Wikipedia-based summaries for bike types and subtypes.
 *
 * Kept in their own term meta, NOT in the term description: the description of
 * a top-level type is already used elsewhere (the search bar's usage dropdown
 * prints it under each option), where a paragraph of up to 1000 characters
 * would be far too long. The summary is for the term's own archive page.
 *
 * The texts live in includes/data/bike-type-summaries.json (adapted from
 * Wikipedia, CC BY-SA 4.0, each one naming its source article). They are
 * seeded once into EMPTY term meta only, so anything edited by hand later is
 * never overwritten, and the archive page always prints the attribution.
 *
 * @package Citykleta_Shared_Bikes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Citykleta_Bike_Type_Summaries.
 */
class Citykleta_Bike_Type_Summaries {

	const META_TEXT   = 'citykleta_summary';
	const META_TITLE  = 'citykleta_summary_source_title';
	const META_URL    = 'citykleta_summary_source_url';
	const SEEDED_FLAG = 'citykleta_bike_type_summaries_seeded';
	const DATA_FILE   = 'includes/data/bike-type-summaries.json';

	/**
	 * Hooks the archive description. Call from citykleta_init() after the
	 * taxonomies are registered.
	 */
	public function register() {
		self::maybe_seed();
		add_filter( 'get_the_archive_description', array( $this, 'archive_description' ) );
	}

	/**
	 * Writes the bundled summaries into terms that don't have one yet, once per
	 * data-file version.
	 */
	public static function maybe_seed() {
		$data = self::load();

		if ( ! $data || (int) get_option( self::SEEDED_FLAG, 0 ) >= (int) $data['version'] ) {
			return;
		}

		$terms = get_terms(
			array(
				'taxonomy'   => Citykleta_Bike_Type_Taxonomy::TAXONOMY,
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) ) {
			return;
		}

		foreach ( $terms as $term ) {
			$entry = self::entry_for( $term, $data['terms'] );

			if ( ! $entry || '' !== (string) get_term_meta( $term->term_id, self::META_TEXT, true ) ) {
				continue;
			}

			update_term_meta( $term->term_id, self::META_TEXT, $entry['summary'] );
			update_term_meta( $term->term_id, self::META_TITLE, $entry['title'] );
			update_term_meta( $term->term_id, self::META_URL, $entry['url'] );
		}

		update_option( self::SEEDED_FLAG, (int) $data['version'], false );
	}

	/**
	 * The bundled data, or null if the file is missing or broken.
	 *
	 * @return array|null
	 */
	private static function load() {
		$path = CITYKLETA_PATH . self::DATA_FILE;

		if ( ! is_readable( $path ) ) {
			return null;
		}

		$data = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin file.

		return is_array( $data ) && isset( $data['version'], $data['terms'] ) && is_array( $data['terms'] ) ? $data : null;
	}

	/**
	 * Finds a term's entry by name, then by slug. The name is lowercased and
	 * stripped of a trailing "(…)" so "Mountain bike (MTB)" matches
	 * "mountain bike".
	 *
	 * @param WP_Term $term  Term.
	 * @param array   $terms The data file's `terms` map.
	 * @return array|null
	 */
	private static function entry_for( $term, array $terms ) {
		$name = strtolower( trim( preg_replace( '/\s*\(.*?\)\s*/', ' ', $term->name ) ) );

		foreach ( array( $name, strtolower( $term->slug ), str_replace( '-', ' ', strtolower( $term->slug ) ) ) as $key ) {
			if ( isset( $terms[ $key ]['summary'], $terms[ $key ]['title'], $terms[ $key ]['url'] ) ) {
				return $terms[ $key ];
			}
		}

		return null;
	}

	/**
	 * Replaces the archive description of a bike type / subtype page with the
	 * summary and its attribution. Pages without a summary keep whatever the
	 * theme would print.
	 *
	 * @param string $description Description the theme would print.
	 * @return string
	 */
	public function archive_description( $description ) {
		if ( ! is_tax( Citykleta_Bike_Type_Taxonomy::TAXONOMY ) ) {
			return $description;
		}

		$term = get_queried_object();

		if ( ! $term instanceof WP_Term ) {
			return $description;
		}

		$text = (string) get_term_meta( $term->term_id, self::META_TEXT, true );

		if ( '' === $text ) {
			return $description;
		}

		$title = (string) get_term_meta( $term->term_id, self::META_TITLE, true );
		$url   = (string) get_term_meta( $term->term_id, self::META_URL, true );

		$html = '<div class="citykleta-term-summary"><p>' . esc_html( $text ) . '</p>';

		if ( $url ) {
			$html .= '<p class="citykleta-term-summary__source">Fuente: <a href="' . esc_url( $url ) . '" rel="noopener" target="_blank">'
				. esc_html( $title ? $title : $url ) . ' — Wikipedia</a> (CC BY-SA 4.0)</p>';
		}

		return $html . '</div>';
	}
}
