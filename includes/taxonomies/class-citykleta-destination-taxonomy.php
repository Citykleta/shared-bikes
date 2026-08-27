<?php
/**
 * "destination" taxonomy: hand-created pages for a place (e.g. "Vedado",
 * "Playa") that show every bike available there. Unlike `bike_type`, terms
 * ARE created by hand in wp-admin — there's no canonical destinations list
 * anywhere to sync from. Unlike `bike_usage`, a term IS assigned per bike —
 * just not by a human: Citykleta_Destination_Matcher computes it on every
 * sync from each destination's geofence (center lat/lng + radius, stored as
 * term meta here) against each bike's own lat/lng. The standard taxonomy
 * meta box stays on the bike editor so admins can see the result, even
 * though checking a box there would just get overwritten next sync — same
 * "read-only in effect" situation as `bike_type`.
 *
 * Also carries the per-destination FAQ override (a switch + its own list),
 * read by templates/taxonomy-destination.php: off by default (shows
 * Citykleta_Settings::destination_faq_default()), on replaces it entirely.
 *
 * @package Citykleta_Shared_Bikes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Citykleta_Destination_Taxonomy.
 */
class Citykleta_Destination_Taxonomy {

	/**
	 * Internal taxonomy identifier.
	 */
	const TAXONOMY = 'destination';

	/**
	 * Term meta keys for the geofence (Citykleta_Destination_Matcher reads
	 * these) and the FAQ override.
	 */
	const META_LAT          = 'citykleta_destination_lat';
	const META_LNG          = 'citykleta_destination_lng';
	const META_RADIUS_KM    = 'citykleta_destination_radius_km';
	const META_FAQ_OVERRIDE = 'citykleta_destination_faq_override';
	const META_FAQ_ITEMS    = 'citykleta_destination_faq_items';

	/**
	 * Connects the class to WordPress hooks.
	 */
	public function register() {
		$this->register_taxonomy();
		$this->register_meta();

		add_action( self::TAXONOMY . '_add_form_fields', array( $this, 'render_add_fields' ) );
		add_action( self::TAXONOMY . '_edit_form_fields', array( $this, 'render_edit_fields' ) );
		add_action( 'created_' . self::TAXONOMY, array( $this, 'save_fields' ) );
		add_action( 'edited_' . self::TAXONOMY, array( $this, 'save_fields' ) );
	}

	/**
	 * Registers the taxonomy in WordPress. Public + rewrite so each term
	 * gets a real page (/destination/{slug}/) — see
	 * templates/taxonomy-destination.php, loaded via Citykleta_Templates.
	 */
	private function register_taxonomy() {
		$labels = array(
			'name'          => 'Destinations',
			'singular_name' => 'Destination',
			'search_items'  => 'Search destinations',
			'all_items'     => 'All destinations',
			'edit_item'     => 'Edit destination',
			'update_item'   => 'Update destination',
			'add_new_item'  => 'Add new destination',
			'new_item_name' => 'New destination name',
			'menu_name'     => 'Destinations',
		);

		register_taxonomy(
			self::TAXONOMY,
			Citykleta_Bike_Post_Type::POST_TYPE,
			array(
				'labels'            => $labels,
				'hierarchical'      => false,
				'public'            => true,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'rewrite'           => array( 'slug' => 'destination' ),
			)
		);
	}

	/**
	 * Registers the term meta fields. Not exposed in REST with a typed
	 * schema — they're only ever read/written from wp-admin's own term
	 * screens (self::render_*_fields()/save_fields()), same as
	 * Citykleta_Bike_Usage_Taxonomy's meta.
	 */
	private function register_meta() {
		foreach ( array( self::META_LAT, self::META_LNG, self::META_RADIUS_KM ) as $key ) {
			register_term_meta(
				self::TAXONOMY,
				$key,
				array(
					'type'          => 'number',
					'single'        => true,
					'show_in_rest'  => true,
					'auth_callback' => array( $this, 'can_manage' ),
				)
			);
		}

		register_term_meta(
			self::TAXONOMY,
			self::META_FAQ_OVERRIDE,
			array(
				'type'          => 'boolean',
				'single'        => true,
				'default'       => false,
				'show_in_rest'  => true,
				'auth_callback' => array( $this, 'can_manage' ),
			)
		);

		register_term_meta(
			self::TAXONOMY,
			self::META_FAQ_ITEMS,
			array(
				'type'          => 'array',
				'single'        => true,
				'show_in_rest'  => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'question' => array( 'type' => 'string' ),
								'answer'   => array( 'type' => 'string' ),
							),
						),
					),
				),
				'auth_callback' => array( $this, 'can_manage' ),
			)
		);
	}

	/**
	 * Capability check shared by every term meta field here.
	 *
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( 'manage_categories' );
	}

	/**
	 * Renders the geofence + FAQ fields on the "Add New Destination" screen.
	 */
	public function render_add_fields() {
		$this->render_fields( 0.0, 0.0, 1.0, false, array() );
	}

	/**
	 * Renders the geofence + FAQ fields on the "Edit Destination" screen.
	 *
	 * @param WP_Term $term Term being edited.
	 */
	public function render_edit_fields( $term ) {
		$lat      = (float) get_term_meta( $term->term_id, self::META_LAT, true );
		$lng      = (float) get_term_meta( $term->term_id, self::META_LNG, true );
		$radius   = (float) get_term_meta( $term->term_id, self::META_RADIUS_KM, true );
		$override = (bool) get_term_meta( $term->term_id, self::META_FAQ_OVERRIDE, true );
		$items    = get_term_meta( $term->term_id, self::META_FAQ_ITEMS, true );
		$items    = is_array( $items ) ? $items : array();

		$this->render_fields( $lat, $lng, $radius ? $radius : 1.0, $override, $items, true );
	}

	/**
	 * Prints the geofence number fields plus the FAQ override switch and its
	 * textarea, in whichever markup the current screen (add vs edit) needs.
	 * The FAQ textarea uses one "Question :: Answer" pair per line — no JS
	 * repeater, same "plain textarea over a rich widget" choice
	 * Citykleta_Settings already makes for the WhatsApp message template.
	 *
	 * @param float $lat        Center latitude.
	 * @param float $lng        Center longitude.
	 * @param float $radius     Radius in kilometers.
	 * @param bool  $override   Whether this destination's own FAQ replaces the default.
	 * @param array $items      { 'question' => string, 'answer' => string }[].
	 * @param bool  $is_edit    True on the "Edit" screen (table layout), false on "Add New" (div layout).
	 */
	private function render_fields( $lat, $lng, $radius, $override, array $items, $is_edit = false ) {
		wp_nonce_field( 'citykleta_save_destination', 'citykleta_destination_nonce' );

		$faq_text = implode(
			"\n",
			array_map(
				function ( $item ) {
					return trim( $item['question'] ?? '' ) . ' :: ' . trim( $item['answer'] ?? '' );
				},
				$items
			)
		);

		$fields = array(
			'citykleta_destination_lat'    => array(
				'label'       => 'Latitude',
				'input'       => '<input type="text" inputmode="decimal" id="citykleta-destination-lat" name="citykleta_destination_lat" value="' . esc_attr( $lat ) . '" />',
				'description' => 'Center of the geofence used to match bikes to this destination.',
			),
			'citykleta_destination_lng'    => array(
				'label'       => 'Longitude',
				'input'       => '<input type="text" inputmode="decimal" id="citykleta-destination-lng" name="citykleta_destination_lng" value="' . esc_attr( $lng ) . '" />',
				'description' => '',
			),
			'citykleta_destination_radius' => array(
				'label'       => 'Radius (km)',
				'input'       => '<input type="text" inputmode="decimal" id="citykleta-destination-radius" name="citykleta_destination_radius" value="' . esc_attr( $radius ) . '" />',
				'description' => 'A bike inside this radius from the center above belongs to this destination. Recomputed on every sync — see Citykleta_Destination_Matcher.',
			),
			'citykleta_destination_faq_override' => array(
				'label'       => 'Custom FAQ',
				'input'       => '<label><input type="checkbox" id="citykleta-destination-faq-override" name="citykleta_destination_faq_override" value="1" ' . checked( $override, true, false ) . ' /> Use this destination\'s own FAQ instead of the default one</label>',
				'description' => 'Off = shows the default FAQ (Settings → Destinations). On = shows only the list below, in full — the two are never merged.',
			),
			'citykleta_destination_faq_items'    => array(
				'label'       => 'FAQ items',
				'input'       => '<textarea id="citykleta-destination-faq" name="citykleta_destination_faq_items" rows="6" style="width:95%;max-width:500px;">' . esc_textarea( $faq_text ) . '</textarea>',
				'description' => 'One question per line: "Question :: Answer". Only used when "Custom FAQ" above is checked.',
			),
		);

		if ( $is_edit ) {
			foreach ( $fields as $key => $field ) {
				printf(
					'<tr class="form-field"><th scope="row"><label for="%1$s">%2$s</label></th><td>%3$s%4$s</td></tr>',
					esc_attr( str_replace( '_', '-', $key ) ),
					esc_html( $field['label'] ),
					$field['input'], // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped pieces above.
					$field['description'] ? '<p class="description">' . esc_html( $field['description'] ) . '</p>' : ''
				);
			}
			return;
		}

		foreach ( $fields as $key => $field ) {
			printf(
				'<div class="form-field"><label for="%1$s">%2$s</label>%3$s%4$s</div>',
				esc_attr( str_replace( '_', '-', $key ) ),
				esc_html( $field['label'] ),
				$field['input'], // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped pieces above.
				$field['description'] ? '<p>' . esc_html( $field['description'] ) . '</p>' : ''
			);
		}
	}

	/**
	 * Saves the geofence + FAQ fields when a destination is created or edited.
	 *
	 * @param int $term_id Saved term ID.
	 */
	public function save_fields( $term_id ) {
		if ( ! isset( $_POST['citykleta_destination_nonce'] ) ||
			! wp_verify_nonce( sanitize_key( $_POST['citykleta_destination_nonce'] ), 'citykleta_save_destination' ) ) {
			return;
		}

		if ( ! $this->can_manage() ) {
			return;
		}

		$lat    = isset( $_POST['citykleta_destination_lat'] ) ? (float) wp_unslash( $_POST['citykleta_destination_lat'] ) : 0.0;
		$lng    = isset( $_POST['citykleta_destination_lng'] ) ? (float) wp_unslash( $_POST['citykleta_destination_lng'] ) : 0.0;
		$radius = isset( $_POST['citykleta_destination_radius'] ) ? (float) wp_unslash( $_POST['citykleta_destination_radius'] ) : 0.0;

		update_term_meta( $term_id, self::META_LAT, $lat );
		update_term_meta( $term_id, self::META_LNG, $lng );
		update_term_meta( $term_id, self::META_RADIUS_KM, max( 0.0, $radius ) );

		$override = isset( $_POST['citykleta_destination_faq_override'] );
		update_term_meta( $term_id, self::META_FAQ_OVERRIDE, $override );

		$faq_raw = isset( $_POST['citykleta_destination_faq_items'] ) ? sanitize_textarea_field( wp_unslash( $_POST['citykleta_destination_faq_items'] ) ) : '';
		update_term_meta( $term_id, self::META_FAQ_ITEMS, self::parse_faq_text( $faq_raw ) );
	}

	/**
	 * Parses the "Question :: Answer" per-line textarea format into a list of
	 * { question, answer } pairs. Shared with Citykleta_Settings' global
	 * default FAQ field, which uses the exact same format.
	 *
	 * @param string $text Raw textarea value.
	 * @return array
	 */
	public static function parse_faq_text( $text ) {
		$items = array();

		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
			$line = trim( $line );

			if ( '' === $line || false === strpos( $line, '::' ) ) {
				continue;
			}

			list( $question, $answer ) = array_map( 'trim', explode( '::', $line, 2 ) );

			if ( '' === $question ) {
				continue;
			}

			$items[] = array(
				'question' => $question,
				'answer'   => $answer,
			);
		}

		return $items;
	}

	/**
	 * The FAQ list a destination page should show: its own override if
	 * turned on and non-empty, otherwise Citykleta_Settings' global default.
	 *
	 * @param int $term_id Destination term ID.
	 * @return array { 'question' => string, 'answer' => string }[].
	 */
	public static function faq_for( $term_id ) {
		$override = (bool) get_term_meta( $term_id, self::META_FAQ_OVERRIDE, true );

		if ( $override ) {
			$items = get_term_meta( $term_id, self::META_FAQ_ITEMS, true );

			if ( is_array( $items ) && $items ) {
				return $items;
			}
		}

		return Citykleta_Settings::destination_faq_default();
	}
}
