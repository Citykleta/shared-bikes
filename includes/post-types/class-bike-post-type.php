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
			// Bikes come from the app through the sync, never from WordPress: nobody
			// can create or publish one in wp-admin or through the REST API (the
			// "Add New" menu item and button disappear on their own). What is
			// already published can still be edited and trashed. The sync is not
			// affected: it writes with wp_insert_post() / wp_update_post(), which do
			// not check capabilities. An owner's bike arrives as a proposal
			// (Citykleta_Bike_Proposal) and is approved in the Back Office.
			'capabilities' => array(
				'create_posts'  => 'do_not_allow',
				'publish_posts' => 'do_not_allow',
			),
			// Needed whenever 'capabilities' is set: without it WordPress ignores
			// the mapping above and falls back to the plain "post" capabilities.
			'map_meta_cap' => true,
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
	}
}
