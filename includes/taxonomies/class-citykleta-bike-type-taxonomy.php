<?php
/**
 * "bike_type" taxonomy: the actual type of each bike (Road, MTB XC,
 * Cargo bicycle, etc.). It's the basis for the search widget's "usage type"
 * filter, which translates each usage (Urban, Road...) into a group of
 * these types — see Citykleta_Templates::usage_to_bike_types().
 *
 * @package Citykleta_Shared_Bikes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase Citykleta_Bike_Type_Taxonomy.
 */
class Citykleta_Bike_Type_Taxonomy {

	/**
	 * Internal taxonomy identifier.
	 */
	const TAXONOMY = 'bike_type';

	/**
	 * Connects the class to WordPress hooks.
	 */
	public function register() {
		$this->register_taxonomy();
		add_action( 'rest_api_init', array( $this, 'register_rest_field' ) );
		add_action( 'admin_head-edit-tags.php', array( $this, 'hide_add_new_form' ) );
		add_filter( 'manage_edit-' . self::TAXONOMY . '_columns', array( $this, 'add_active_column' ) );
		add_filter( 'manage_' . self::TAXONOMY . '_custom_column', array( $this, 'render_active_column' ), 10, 3 );
	}

	/**
	 * Hides the "Add New Type" form on the terms screen. Types are
	 * created/updated by Citykleta_Bike_Type_Sync from the Sharing App's
	 * canonical list — creating one by hand won't connect to any bike.
	 * CSS only, doesn't touch user capabilities.
	 */
	public function hide_add_new_form() {
		$screen = get_current_screen();

		if ( ! $screen || self::TAXONOMY !== $screen->taxonomy ) {
			return;
		}

		echo '<style>#col-left { display: none; } #col-right { width: 100%; float: none; }</style>';
	}

	/**
	 * Registers the taxonomy in WordPress.
	 */
	private function register_taxonomy() {
		$labels = array(
			'name'          => 'Bike types',
			'singular_name' => 'Bike type',
			'search_items'  => 'Search types',
			'all_items'     => 'All types',
			'edit_item'     => 'Edit type',
			'update_item'   => 'Update type',
			'add_new_item'  => 'Add new type',
			'new_item_name' => 'New type name',
			'menu_name'     => 'Bike types',
		);

		register_taxonomy(
			self::TAXONOMY,
			Citykleta_Bike_Post_Type::POST_TYPE,
			array(
				'labels'            => $labels,
				'hierarchical'      => false, // Tag-like, not a hierarchical category.
				'show_admin_column' => true,
				'show_in_rest'      => true,
			)
		);
	}

	/**
	 * Exposes the bike type's readable name(s) in the REST API
	 * (`/wp-json/wp/v2/bike`), on top of the term IDs WordPress already
	 * exposes on its own — so an app consuming the API doesn't need a
	 * second request just to know the type's name.
	 */
	public function register_rest_field() {
		register_rest_field(
			Citykleta_Bike_Post_Type::POST_TYPE,
			'bike_type_names',
			array(
				'get_callback' => function ( $post ) {
					$terms = get_the_terms( $post['id'], self::TAXONOMY );

					if ( ! $terms || is_wp_error( $terms ) ) {
						return array();
					}

					return wp_list_pluck( $terms, 'name' );
				},
				'schema'       => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			)
		);
	}

	/**
	 * Adds an "Active" column to the terms list table, right before "Count".
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_active_column( $columns ) {
		$count = isset( $columns['posts'] ) ? $columns['posts'] : null;
		unset( $columns['posts'] );

		$columns['citykleta_active'] = 'Active';

		if ( null !== $count ) {
			$columns['posts'] = $count;
		}

		return $columns;
	}

	/**
	 * Renders the "Active" column: whether Citykleta_Bike_Type_Sync saw
	 * this type in the last successful sync, without deleting it if not.
	 *
	 * @param string $content     Existing column content (empty for custom columns).
	 * @param string $column_name Column being rendered.
	 * @param int    $term_id     Term id.
	 * @return string
	 */
	public function render_active_column( $content, $column_name, $term_id ) {
		if ( 'citykleta_active' !== $column_name ) {
			return $content;
		}

		$active = get_term_meta( $term_id, Citykleta_Bike_Type_Sync::ACTIVE_META_KEY, true );

		if ( '' === $active ) {
			return '—';
		}

		return $active ? '✅ Yes' : '⚠️ No longer offered by the app';
	}
}
