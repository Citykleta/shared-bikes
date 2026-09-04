<?php
/**
 * The bike owner's contact details — the one field on a bike that WordPress
 * owns instead of syncing.
 *
 * Why this exists: /bikesPublic/sync is public and unauthenticated, so it is a
 * deliberately PII-free catalogue. Every bike's owner arrives as
 * { name, verified } — no phone, no email, no photo (verified against all 68
 * bikes in the real catalogue). Publishing owner phone numbers on an open
 * endpoint would make them scrapeable in bulk, so the backend is right not to
 * send them, and WordPress has no way to reach an owner on its own.
 *
 * Until the app grows a route that notifies owners itself (see the phase 2 note
 * in Citykleta_Bike_Request), staff type a contact here by hand so a bike
 * request can be forwarded.
 *
 * IMPORTANT — this is deliberately NOT the synced `owner_phone` meta.
 * Citykleta_Bike_Sync::upsert_bike() writes owner_phone from
 * $item['owner']['phone']; that key never arrives today, so the write never
 * happens, but the day the API starts sending it a hand-typed value there would
 * be silently overwritten. Keeping this in its own meta key means the sync can
 * never touch it, whatever the payload grows into.
 *
 * @package Citykleta_Shared_Bikes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Citykleta_Owner_Contact.
 */
class Citykleta_Owner_Contact {

	/**
	 * Meta: the contact itself (an email address or a phone number).
	 */
	const META_VALUE = '_citykleta_owner_contact';

	/**
	 * Meta: which kind it is (self::TYPE_*), derived from the value on save so
	 * there's only one field to fill in.
	 */
	const META_TYPE = '_citykleta_owner_contact_type';

	/**
	 * Contact kind: an email address.
	 */
	const TYPE_EMAIL = 'email';

	/**
	 * Contact kind: a phone number (WhatsApp).
	 */
	const TYPE_PHONE = 'phone';

	/**
	 * Nonce action for the meta box.
	 */
	const NONCE = 'citykleta_owner_contact';

	/**
	 * Connects the class to WordPress hooks.
	 */
	public function register() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post_' . Citykleta_Bike_Post_Type::POST_TYPE, array( $this, 'save' ) );
	}

	/**
	 * Reads a bike's owner contact.
	 *
	 * @param int $bike_id Bike post ID.
	 * @return array{value:string,type:string} Empty value when none is on file.
	 */
	public static function get( $bike_id ) {
		$value = (string) get_post_meta( $bike_id, self::META_VALUE, true );

		return array(
			'value' => $value,
			'type'  => '' === $value ? '' : self::detect_type( $value ),
		);
	}

	/**
	 * Works out whether a contact string is an email or a phone number.
	 *
	 * @param string $value The contact as typed.
	 * @return string One of self::TYPE_*.
	 */
	public static function detect_type( $value ) {
		return is_email( $value ) ? self::TYPE_EMAIL : self::TYPE_PHONE;
	}

	/**
	 * Adds the box to the bike edit screen.
	 */
	public function add_meta_box() {
		add_meta_box(
			'citykleta_owner_contact',
			'Owner contact (WordPress only)',
			array( $this, 'render' ),
			Citykleta_Bike_Post_Type::POST_TYPE,
			'normal',
			'default'
		);
	}

	/**
	 * Renders the field.
	 *
	 * The notice here says the opposite of the one on the "Bike data" box right
	 * above it (which warns that everything in it is overwritten on the next
	 * sync), so it has to be explicit rather than rely on the reader noticing
	 * the difference.
	 *
	 * @param WP_Post $post The bike being edited.
	 */
	public function render( $post ) {
		wp_nonce_field( self::NONCE, 'citykleta_owner_contact_nonce' );

		$contact    = self::get( $post->ID );
		$owner_name = (string) get_post_meta( $post->ID, 'owner_name', true );

		echo '<p style="color:#0a4b78;background:#e5f5fa;border-left:4px solid #2271b1;padding:8px 12px;margin:0 0 12px;">';
		echo 'Unlike the fields above, this one is <strong>kept by WordPress and never overwritten by a sync</strong>. ';
		echo 'The app\'s public catalogue carries no owner contact on purpose (it would expose personal data), ';
		echo 'so it has to be filled in here for bike requests to reach the owner.';
		echo '</p>';

		echo '<table class="form-table"><tr>';
		echo '<th><label for="citykleta_owner_contact">Email or phone</label></th>';
		echo '<td>';
		echo '<input type="text" id="citykleta_owner_contact" name="citykleta_owner_contact" value="' . esc_attr( $contact['value'] ) . '" class="regular-text" />';
		echo '<p class="description">';

		if ( '' !== $owner_name ) {
			echo 'How to reach <strong>' . esc_html( $owner_name ) . '</strong> about a request. ';
		}

		echo 'An email address gets notified automatically; a phone number is offered as a WhatsApp link on the request, for a person to send.';
		echo '</p>';
		echo '</td></tr></table>';
	}

	/**
	 * Saves the field.
	 *
	 * @param int $post_id Bike ID.
	 */
	public function save( $post_id ) {
		if ( ! isset( $_POST['citykleta_owner_contact_nonce'] ) ||
			! wp_verify_nonce( sanitize_key( $_POST['citykleta_owner_contact_nonce'] ), self::NONCE ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( ! isset( $_POST['citykleta_owner_contact'] ) ) {
			return;
		}

		$value = sanitize_text_field( wp_unslash( $_POST['citykleta_owner_contact'] ) );

		if ( '' === $value ) {
			delete_post_meta( $post_id, self::META_VALUE );
			delete_post_meta( $post_id, self::META_TYPE );
			return;
		}

		update_post_meta( $post_id, self::META_VALUE, $value );
		update_post_meta( $post_id, self::META_TYPE, self::detect_type( $value ) );
	}
}
