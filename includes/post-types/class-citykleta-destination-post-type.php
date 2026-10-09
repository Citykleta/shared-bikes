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
	 * Post meta keys: the geofence. (A per-destination FAQ override lived here
	 * too — `citykleta_destination_faq_override` / `_faq_items_raw` — and was
	 * removed: every destination shows the default FAQ from Settings.)
	 */
	const META_LAT       = 'citykleta_destination_lat';
	const META_LNG       = 'citykleta_destination_lng';
	const META_RADIUS_KM = 'citykleta_destination_radius_km';

	/**
	 * Connects the class to WordPress hooks.
	 */
	public function register() {
		$this->register_post_type();
		$this->register_meta();

		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save_meta' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_picker_assets' ) );
	}

	/**
	 * Loads Leaflet and the geofence picker, only on a destination's edit screen.
	 *
	 * Leaflet is a vendor file that is not in the repo (see the "Mapa de bicis"
	 * notes in CLAUDE.md); without it the box keeps working as plain number
	 * inputs, which is why the picker JS is skipped instead of failing.
	 */
	public function enqueue_picker_assets() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'post' !== $screen->base || self::POST_TYPE !== $screen->post_type ) {
			return;
		}

		if ( ! file_exists( CITYKLETA_PATH . 'assets/js/leaflet.js' ) ) {
			return;
		}

		wp_enqueue_style( 'leaflet', CITYKLETA_URL . 'assets/css/leaflet.css', array(), CITYKLETA_VERSION );
		wp_enqueue_script( 'leaflet', CITYKLETA_URL . 'assets/js/leaflet.js', array(), CITYKLETA_VERSION, true );
		wp_enqueue_script( 'citykleta-destination-picker', CITYKLETA_URL . 'assets/js/citykleta-destination-picker.js', array( 'leaflet' ), CITYKLETA_VERSION, true );
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
				/*
				 * A destination is a content page in every way that matters, so
				 * it gets exactly what a Page gets — parent/order, revisions,
				 * excerpt, comments, author, whatever this install has added to
				 * pages — instead of a hand-written list that slowly falls
				 * behind. See self::page_supports().
				 */
				'hierarchical' => true,
				'supports'     => self::page_supports(),
				'show_in_rest' => true,
				'rewrite'      => array( 'slug' => $slug ),
			)
		);
	}

	/**
	 * Everything a Page supports on this install, plus Elementor.
	 *
	 * Asked of WordPress rather than copied from it: core's own list for
	 * `page` has changed across versions, and themes/plugins add to it. Reading
	 * it means a destination keeps matching a page without anyone remembering
	 * to update this file.
	 *
	 * Safe at this point in the request: core registers `page` on `init` at
	 * priority 0, and the plugin boots at the default priority 10.
	 *
	 * `custom-fields` matters beyond the meta box — without it a custom post
	 * type's `meta` never appears in REST responses, however well the fields
	 * are registered (the same gotcha already documented for bikes). It is
	 * part of a page's own list, so it comes along on its own.
	 *
	 * @return array
	 */
	private static function page_supports() {
		$supports = array_keys( get_all_post_type_supports( 'page' ) );

		// Pages are Elementor-editable, so destinations are too.
		$supports[] = 'elementor';

		// Belt and braces: if `page` somehow is not registered yet, fall back
		// to the list this type used to declare by hand.
		if ( count( $supports ) < 3 ) {
			$supports = array( 'title', 'editor', 'thumbnail', 'custom-fields', 'revisions', 'page-attributes', 'elementor' );
		}

		return array_values( array_unique( $supports ) );
	}

	/**
	 * Registers the geofence fields so they're readable via the REST API, same
	 * pattern as Citykleta_Bike_Meta.
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
	 * with a map to pick it on.
	 *
	 * @param WP_Post $post The destination being edited.
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( 'citykleta_save_destination_meta', 'citykleta_destination_meta_nonce' );

		$lat    = get_post_meta( $post->ID, self::META_LAT, true );
		$lng    = get_post_meta( $post->ID, self::META_LNG, true );
		$radius = get_post_meta( $post->ID, self::META_RADIUS_KM, true );

		// Bikes as plain points, so the radius can be judged against real
		// coverage while picking it. Only lat/lng travel to the page.
		$bike_points = array();
		foreach ( Citykleta_Bike_Map_Data::query( array( 'count' => -1 ) ) as $bike ) {
			if ( isset( $bike['lat'], $bike['lng'] ) ) {
				$bike_points[] = array( (float) $bike['lat'], (float) $bike['lng'] );
			}
		}

		$picker_options = array_merge(
			Citykleta_Settings::tile_layer(),
			array(
				'bikes' => $bike_points,
			)
		);
		?>
		<table class="form-table">
			<tr>
				<th>Map</th>
				<td>
					<div class="citykleta-destination-picker" data-options="<?php echo esc_attr( wp_json_encode( $picker_options ) ); ?>" style="height:380px;width:100%;max-width:900px;border:1px solid #c3c4c7;border-radius:4px;"></div>
					<p class="description">Click the map (or drag the pin) to set the center; the circle follows the radius below. Yellow dots are bikes inside the geofence, grey ones are outside. <span class="citykleta-destination-picker__count"></span></p>
				</td>
			</tr>
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
	 * The FAQ list a destination page should show: always Citykleta_Settings'
	 * default (Settings → Destinations) since the per-destination override was
	 * removed. Keeps the destination parameter so callers don't change if a
	 * per-destination source comes back.
	 *
	 * @param int $post_id Destination ID (unused).
	 * @return array { 'question' => string, 'answer' => string }[].
	 */
	public static function faq_for( $post_id ) {
		unset( $post_id );

		return Citykleta_Settings::destination_faq_default();
	}
}
