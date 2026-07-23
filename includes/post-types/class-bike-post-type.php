<?php
/**
 * Registers the "Bike" content type.
 *
 * @package Citykleta_Shared_Bikes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Citykleta_Bike_Post_Type.
 *
 * Creates the custom post type used to manage every bike in the system.
 */
class Citykleta_Bike_Post_Type {

	/**
	 * Internal identifier of the content type.
	 *
	 * @var string
	 */
	const POST_TYPE = 'bike';

	/**
	 * Registers the content type in WordPress.
	 */
	public function register() {

		$labels = array(
			'name'          => 'Bikes',
			'singular_name' => 'Bike',
			'add_new'       => 'Add New',
			'add_new_item'  => 'Add New Bike',
			'edit_item'     => 'Edit Bike',
			'new_item'      => 'New Bike',
			'view_item'     => 'View Bike',
			'search_items'  => 'Search Bikes',
			'not_found'     => 'No bikes found',
			'menu_name'     => 'Shared Bikes',
			'all_items'     => 'All Bikes',
		);

		$args = array(
			'labels'       => $labels,
			'public'       => true,
			'has_archive'  => true,
			'menu_icon'    => 'dashicons-superhero-alt',
			// 'custom-fields' is required for WordPress to include the
			// "meta" field in REST responses (even though we don't use the
			// native custom fields box, since we build our own meta box).
			'supports'     => array( 'title', 'editor', 'thumbnail', 'custom-fields' ),
			'show_in_rest' => true, // Enables the block editor and the REST API.
			// The slug is configurable under Shared Bikes → Sync.
			'rewrite'      => array( 'slug' => get_option( 'citykleta_bike_slug', 'bikes' ) ),
		);

		register_post_type( self::POST_TYPE, $args );

		// The slug may have changed on the settings page; the CPT above was
		// just registered with the new value, so this is the right moment
		// for the flush. See Citykleta_Settings.
		if ( get_option( 'citykleta_flush_rewrite_flag' ) ) {
			flush_rewrite_rules();
			delete_option( 'citykleta_flush_rewrite_flag' );
		}

		add_action( 'admin_menu', array( $this, 'remove_add_new_menu' ), 999 );
		add_action( 'admin_head-edit.php', array( $this, 'hide_add_new_button' ) );
	}

	/**
	 * Removes "Add New" from the sidebar menu — bikes are created by
	 * sync, not by hand (see the warning in Citykleta_Bike_Meta). Doesn't
	 * block the URL directly, only the menu link.
	 */
	public function remove_add_new_menu() {
		remove_submenu_page( 'edit.php?post_type=' . self::POST_TYPE, 'post-new.php?post_type=' . self::POST_TYPE );
	}

	/**
	 * Hides the "Add Bike" button next to the title on the bike list.
	 * Visual only (CSS) — doesn't touch user capabilities, so it can't
	 * accidentally lock down anything else on the site.
	 */
	public function hide_add_new_button() {
		$screen = get_current_screen();

		if ( ! $screen || self::POST_TYPE !== $screen->post_type ) {
			return;
		}

		echo '<style>.page-title-action { display: none; }</style>';
	}
}
