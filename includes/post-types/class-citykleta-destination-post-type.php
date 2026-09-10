<?php
/**
 * Registers the "Destination" content type (e.g. "Vedado", "Playa") — a
 * hand-written landing page for a place, showing the bikes available there.
 *
 * A post type, not a taxonomy — reconsidered after building it as a
 * taxonomy first (see CLAUDE.md, "Destinos"): a destination needs a real
 * editor (rich description, not a one-line term description), a featured
 * image, and a handful of its own fields (geofence, FAQ override), which is
 * exactly what a CPT + meta box gives for free and a taxonomy term screen
 * doesn't. The bike → destination relationship moved from
 * wp_set_object_terms() to a repeatable post meta row on the bike (see
 * Citykleta_Destination_Matcher) since there's no taxonomy to assign a term
 * in anymore.
 *
 * Combines registration + the fields meta box in one class (unlike
 * bike/Citykleta_Bike_Meta, split in two) because the field set here is
 * small and, unlike bikes, doesn't need a separate sync class writing to it.
 *
 * @package Citykleta_Shared_Bikes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Citykleta_Destination_Post_Type.
 */
class Citykleta_Destination_Post_Type {

	/**
	 * Internal identifier of the content type.
	 */
	const POST_TYPE = 'destination';

	/**
	 * Post meta keys. `faq_items_raw` stores the textarea's raw text as-is
	 * (same "Question :: Answer" per line format Citykleta_Settings' default
	 * FAQ field uses) — parsed on read by self::parse_faq_text(), not stored
	 * pre-parsed, so there's one parser instead of two places that could
	 * drift apart.
	 */
	const META_LAT          = 'citykleta_destination_lat';
	const META_LNG          = 'citykleta_destination_lng';
	const META_RADIUS_KM    = 'citykleta_destination_radius_km';
	const META_FAQ_OVERRIDE = 'citykleta_destination_faq_override';
	const META_FAQ_RAW      = 'citykleta_destination_faq_items_raw';

	/**
	 * Connects the class to WordPress hooks.
	 */
	public function register() {
		$this->register_post_type();
		$this->register_meta();

		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save_meta' ) );
	}

	/**
	 * Registers the content type. Nested under "Bicicletas" (same menu as
	 * bikes) via show_in_menu, instead of its own top-level entry — same
	 * place the old `destination` taxonomy used to show.
	 */
	private function register_post_type() {
		$labels = array(
			'name'          => 'Destinations',
			'singular_name' => 'Destination',
			'add_new'       => 'Add New',
			'add_new_item'  => 'Add New Destination',
			'edit_item'     => 'Edit Destination',
			'new_item'      => 'New Destination',
			'view_item'     => 'View Destination',
			'search_items'  => 'Search Destinations',
			'not_found'     => 'No destinations found',
			'menu_name'     => 'Destinations',
			'all_items'     => 'Destinations',
		);

		// Editable from Settings → Destinations → URL slug (Citykleta_Settings).
		// Dropping the prefix entirely was tried and reverted — see
		// Citykleta_Settings::sanitize_destination_slug() for why the option
		// can never actually be blank.
		$slug = get_option( 'citykleta_destination_slug', 'destination' );

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'       => $labels,
				'public'       => true,
				'has_archive'  => false, // Each destination is its own page; no blanket index needed.
				'show_in_menu' => 'edit.php?post_type=' . Citykleta_Bike_Post_Type::POST_TYPE,
				'supports'     => array( 'title', 'editor', 'thumbnail', 'custom-fields' ),
				'show_in_rest' => true,
				'rewrite'      => array( 'slug' => $slug ),
			)
		);
	}

	/**
	 * Registers the scalar fields (not the FAQ textarea — see self::META_FAQ_RAW)
	 * so they're readable via the REST API, same pattern as Citykleta_Bike_Meta.
	 */
	private function register_meta() {
		foreach ( array( self::META_LAT, self::META_LNG, self::META_RADIUS_KM ) as $key ) {
			register_post_meta(
				self::POST_TYPE,
				$key,
				array(
					'type'          => 'number',
					'single'        => true,
					'show_in_rest'  => true,
					'auth_callback' => array( $this, 'can_manage' ),
				)
			);
		}

		register_post_meta(
			self::POST_TYPE,
			self::META_FAQ_OVERRIDE,
			array(
				'type'          => 'boolean',
				'single'        => true,
				'default'       => false,
				'show_in_rest'  => true,
				'auth_callback' => array( $this, 'can_manage' ),
			)
		);
	}

	/**
	 * Capability check shared by every field here.
	 *
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Adds the fields box to the destination edit screen.
	 */
	public function add_meta_box() {
		add_meta_box(
			'citykleta_destination_data',
			'Destination data',
			array( $this, 'render_meta_box' ),
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Renders the fields box: the geofence used by Citykleta_Destination_Matcher,
	 * plus the FAQ override switch and its textarea.
	 *
	 * @param WP_Post $post The destination being edited.
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( 'citykleta_save_destination_meta', 'citykleta_destination_meta_nonce' );

		$lat      = get_post_meta( $post->ID, self::META_LAT, true );
		$lng      = get_post_meta( $post->ID, self::META_LNG, true );
		$radius   = get_post_meta( $post->ID, self::META_RADIUS_KM, true );
		$override = get_post_meta( $post->ID, self::META_FAQ_OVERRIDE, true );
		$faq_raw  = get_post_meta( $post->ID, self::META_FAQ_RAW, true );
		?>
		<table class="form-table">
			<tr>
				<th><label for="citykleta_destination_lat">Latitude</label></th>
				<td>
					<input type="number" step="any" id="citykleta_destination_lat" name="citykleta_destination_lat" value="<?php echo esc_attr( $lat ); ?>" class="regular-text" />
					<p class="description">Center of the geofence used to match bikes to this destination.</p>
				</td>
			</tr>
			<tr>
				<th><label for="citykleta_destination_lng">Longitude</label></th>
				<td>
					<input type="number" step="any" id="citykleta_destination_lng" name="citykleta_destination_lng" value="<?php echo esc_attr( $lng ); ?>" class="regular-text" />
				</td>
			</tr>
			<tr>
				<th><label for="citykleta_destination_radius_km">Radius (km)</label></th>
				<td>
					<input type="number" step="any" min="0" id="citykleta_destination_radius_km" name="citykleta_destination_radius_km" value="<?php echo esc_attr( $radius ); ?>" class="regular-text" />
					<p class="description">A bike inside this radius from the center above belongs to this destination. Recomputed on every sync — see Citykleta_Destination_Matcher. 0 = geofence not set yet, this destination is ignored.</p>
				</td>
			</tr>
			<tr>
				<th><label for="citykleta_destination_faq_override">Custom FAQ</label></th>
				<td>
					<label><input type="checkbox" id="citykleta_destination_faq_override" name="citykleta_destination_faq_override" value="1" <?php checked( $override, '1' ); ?> /> Use this destination's own FAQ instead of the default one</label>
					<p class="description">Off = shows the default FAQ (Settings → Destinations). On = shows only the list below, in full — the two are never merged.</p>
				</td>
			</tr>
			<tr>
				<th><label for="citykleta_destination_faq_items">FAQ items</label></th>
				<td>
					<textarea id="citykleta_destination_faq_items" name="citykleta_destination_faq_items" rows="6" class="large-text"><?php echo esc_textarea( $faq_raw ); ?></textarea>
					<p class="description">One question per line: "Question :: Answer". Only used when "Custom FAQ" above is checked.</p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Saves the field values when the destination is saved.
	 *
	 * @param int $post_id Destination ID.
	 */
	public function save_meta( $post_id ) {
		if ( ! isset( $_POST['citykleta_destination_meta_nonce'] ) ||
			! wp_verify_nonce( sanitize_key( $_POST['citykleta_destination_meta_nonce'] ), 'citykleta_save_destination_meta' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! $this->can_manage() ) {
			return;
		}

		$lat    = isset( $_POST['citykleta_destination_lat'] ) ? (float) wp_unslash( $_POST['citykleta_destination_lat'] ) : 0.0;
		$lng    = isset( $_POST['citykleta_destination_lng'] ) ? (float) wp_unslash( $_POST['citykleta_destination_lng'] ) : 0.0;
		$radius = isset( $_POST['citykleta_destination_radius_km'] ) ? (float) wp_unslash( $_POST['citykleta_destination_radius_km'] ) : 0.0;

		update_post_meta( $post_id, self::META_LAT, $lat );
		update_post_meta( $post_id, self::META_LNG, $lng );
		update_post_meta( $post_id, self::META_RADIUS_KM, max( 0.0, $radius ) );
		update_post_meta( $post_id, self::META_FAQ_OVERRIDE, isset( $_POST['citykleta_destination_faq_override'] ) ? '1' : '0' );

		$faq_raw = isset( $_POST['citykleta_destination_faq_items'] ) ? sanitize_textarea_field( wp_unslash( $_POST['citykleta_destination_faq_items'] ) ) : '';
		update_post_meta( $post_id, self::META_FAQ_RAW, $faq_raw );
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
	 * @param int $post_id Destination ID.
	 * @return array { 'question' => string, 'answer' => string }[].
	 */
	public static function faq_for( $post_id ) {
		$override = '1' === (string) get_post_meta( $post_id, self::META_FAQ_OVERRIDE, true );

		if ( $override ) {
			$items = self::parse_faq_text( get_post_meta( $post_id, self::META_FAQ_RAW, true ) );

			if ( $items ) {
				return $items;
			}
		}

		return Citykleta_Settings::destination_faq_default();
	}
}
