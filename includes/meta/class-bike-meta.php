<?php
/**
 * Custom fields (meta) for the bike.
 *
 * @package Citykleta_Shared_Bikes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Citykleta_Bike_Meta.
 *
 * Registers each bike's structured data and adds a meta box to the
 * admin edit screen.
 */
class Citykleta_Bike_Meta {

	/**
	 * Definition of the bike's fields.
	 *
	 * Every field is exposed via the REST API (show_in_rest) so the
	 * mobile prototype can read them.
	 *
	 * @var array
	 */
	private $fields = array(
		'model'          => array(
			'label' => 'Model',
			'type'  => 'string',
		),
		'location'       => array(
			'label' => 'Location',
			'type'  => 'string',
		),
		'lat'            => array(
			'label' => 'Latitude',
			'type'  => 'number',
		),
		'lng'            => array(
			'label' => 'Longitude',
			'type'  => 'number',
		),
		'price_per_hour' => array(
			'label' => 'Price per hour (CUP)',
			'type'  => 'number',
		),
		'available'      => array(
			'label' => 'Available?',
			'type'  => 'boolean',
		),
		'brand'          => array(
			'label' => 'Brand',
			'type'  => 'string',
		),
		'size'           => array(
			'label' => 'Size',
			'type'  => 'string',
		),
		'wheel_size'     => array(
			'label' => 'Wheel size',
			'type'  => 'string',
		),
		'is_electric'    => array(
			'label' => 'Electric?',
			'type'  => 'boolean',
		),
		'owner_name'     => array(
			'label' => 'Owner name',
			'type'  => 'string',
		),
		'owner_photo'    => array(
			'label' => 'Owner photo (URL)',
			'type'  => 'string',
		),
		'owner_phone'    => array(
			'label' => 'Owner phone (WhatsApp)',
			'type'  => 'string',
		),
	);

	/**
	 * Connects the class to WordPress hooks.
	 */
	public function register() {
		// Called directly (not via add_action('init', ...)) because register()
		// is already invoked from within the 'init' hook (see citykleta_init()).
		// Hooking 'init' again at the same priority wouldn't run in that
		// same pass.
		$this->register_meta();
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post_bike', array( $this, 'save_meta' ) );
		add_action( 'admin_notices', array( $this, 'render_sync_warning' ) );
	}

	/**
	 * Warning on the bike edit screen: this data comes from the app and
	 * syncs on its own, so editing here by hand doesn't help much — the
	 * next sync overwrites any manual change.
	 */
	public function render_sync_warning() {
		$screen = get_current_screen();

		if ( ! $screen || 'post' !== $screen->base || Citykleta_Bike_Post_Type::POST_TYPE !== $screen->post_type ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>';
		echo '<strong>Warning:</strong> this bike\'s data comes from the Bike Sharing app and syncs automatically. ';
		echo 'Any change you make here by hand <strong>will be lost</strong> on the next sync.';
		echo '</p></div>';
	}

	/**
	 * Registers each field in the database and the REST API.
	 */
	public function register_meta() {
		foreach ( $this->fields as $key => $config ) {
			register_post_meta(
				Citykleta_Bike_Post_Type::POST_TYPE,
				$key,
				array(
					'type'          => $config['type'],
					'single'        => true,
					'show_in_rest'  => true,
					'auth_callback' => function () {
						return current_user_can( 'edit_posts' );
					},
				)
			);
		}
	}

	/**
	 * Adds the fields box to the bike edit screen.
	 */
	public function add_meta_box() {
		add_meta_box(
			'citykleta_bike_data',
			'Bike data',
			array( $this, 'render_meta_box' ),
			Citykleta_Bike_Post_Type::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Renders the fields box form.
	 *
	 * @param WP_Post $post The bike being edited.
	 */
	public function render_meta_box( $post ) {
		// Security field (nonce) to validate the save.
		wp_nonce_field( 'citykleta_save_meta', 'citykleta_meta_nonce' );

		echo '<p style="color:#996800;background:#fff8e5;border-left:4px solid #dba617;padding:8px 12px;margin:0 0 12px;">';
		echo 'These fields sync automatically from the app. Editing here is not saved back to the data source.';
		echo '</p>';

		echo '<table class="form-table">';
		foreach ( $this->fields as $key => $config ) {
			$value = get_post_meta( $post->ID, $key, true );
			echo '<tr>';
			echo '<th><label for="' . esc_attr( $key ) . '">' . esc_html( $config['label'] ) . '</label></th>';
			echo '<td>';

			if ( 'boolean' === $config['type'] ) {
				echo '<input type="checkbox" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="1" ' . checked( $value, '1', false ) . ' />';
			} elseif ( 'number' === $config['type'] ) {
				echo '<input type="number" step="any" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" class="regular-text" />';
			} else {
				echo '<input type="text" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" class="regular-text" />';
			}

			echo '</td>';
			echo '</tr>';
		}
		echo '</table>';
	}

	/**
	 * Saves the field values when the bike is saved.
	 *
	 * @param int $post_id Bike ID.
	 */
	public function save_meta( $post_id ) {
		// Security checks before saving.
		if ( ! isset( $_POST['citykleta_meta_nonce'] ) ||
			! wp_verify_nonce( sanitize_key( $_POST['citykleta_meta_nonce'] ), 'citykleta_save_meta' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Saves each field, sanitizing the value according to its type.
		foreach ( $this->fields as $key => $config ) {
			if ( 'boolean' === $config['type'] ) {
				$value = isset( $_POST[ $key ] ) ? '1' : '0';
			} elseif ( 'number' === $config['type'] ) {
				$value = isset( $_POST[ $key ] ) ? floatval( wp_unslash( $_POST[ $key ] ) ) : '';
			} else {
				$value = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
			}

			update_post_meta( $post_id, $key, $value );
		}
	}
}
