<?php
/**
 * Custom columns on the bike list in wp-admin.
 *
 * @package Citykleta_Shared_Bikes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Citykleta_List_Columns.
 */
class Citykleta_List_Columns {

	/**
	 * Connects the class to WordPress hooks.
	 */
	public function register() {
		$post_type = Citykleta_Bike_Post_Type::POST_TYPE;

		add_filter( "manage_{$post_type}_posts_columns", array( $this, 'add_columns' ) );
		add_action( "manage_{$post_type}_posts_custom_column", array( $this, 'render_column' ), 10, 2 );
		add_filter( "manage_edit-{$post_type}_sortable_columns", array( $this, 'sortable_columns' ) );
		add_action( 'pre_get_posts', array( $this, 'sort_by_meta' ) );

		add_action( 'restrict_manage_posts', array( $this, 'add_category_filter' ) );
		add_filter( 'parse_query', array( $this, 'filter_by_category' ) );
	}

	/**
	 * Adds the thumbnail, brand, and location columns.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_columns( $columns ) {
		$new_columns = array();

		foreach ( $columns as $key => $label ) {
			$new_columns[ $key ] = $label;

			if ( 'title' === $key ) {
				$new_columns['citykleta_thumbnail'] = 'Picture';
			}
		}

		$new_columns['citykleta_brand']    = 'Brand';
		$new_columns['citykleta_location'] = 'Location';

		return $new_columns;
	}

	/**
	 * Renders the content of each custom column.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Bike ID.
	 */
	public function render_column( $column, $post_id ) {
		switch ( $column ) {
			case 'citykleta_thumbnail':
				if ( has_post_thumbnail( $post_id ) ) {
					echo get_the_post_thumbnail( $post_id, array( 60, 60 ) );
				} else {
					echo '—';
				}
				break;

			case 'citykleta_brand':
				echo esc_html( get_post_meta( $post_id, 'brand', true ) ?: '—' );
				break;

			case 'citykleta_location':
				echo esc_html( get_post_meta( $post_id, 'location', true ) ?: '—' );
				break;
		}
	}

	/**
	 * Marks the brand and location columns as sortable.
	 *
	 * @param array $columns Existing sortable columns.
	 * @return array
	 */
	public function sortable_columns( $columns ) {
		$columns['citykleta_brand']    = 'citykleta_brand';
		$columns['citykleta_location'] = 'citykleta_location';
		return $columns;
	}

	/**
	 * Translates the "orderby" from the columns above into an actual meta_key.
	 *
	 * @param WP_Query $query Current admin query.
	 */
	public function sort_by_meta( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$orderby = $query->get( 'orderby' );

		if ( 'citykleta_brand' === $orderby ) {
			$query->set( 'meta_key', 'brand' );
			$query->set( 'orderby', 'meta_value' );
		} elseif ( 'citykleta_location' === $orderby ) {
			$query->set( 'meta_key', 'location' );
			$query->set( 'orderby', 'meta_value' );
		}
	}


	/**
	 * Adds the category dropdown above the list.
	 */
	public function add_category_filter() {
		global $typenow;
		$post_type = Citykleta_Bike_Post_Type::POST_TYPE;
		$taxonomy  = 'category'; // Change if your taxonomy has a different name.

		if ( $typenow !== $post_type ) {
			return;
		}

		$selected = isset( $_GET[$taxonomy] ) ? $_GET[$taxonomy] : '';

		wp_dropdown_categories( array(
			'show_option_all' => 'All Categories',
			'taxonomy'        => $taxonomy,
			'name'            => $taxonomy,
			'orderby'         => 'name',
			'selected'        => $selected,
			'value_field'     => 'slug', // Important: WP filters by slug.
			'hierarchical'    => true,
			'hide_empty'      => true,
		) );
	}

	/**
	 * Modifies the main query to filter by category.
	 *
	 * @param WP_Query $query
	 */
	public function filter_by_category( $query ) {
		global $pagenow;
		$post_type = Citykleta_Bike_Post_Type::POST_TYPE;
		$taxonomy  = 'category';

		if ( 'edit.php' !== $pagenow ) {
			return;
		}

		if ( ! isset( $_GET['post_type'] ) || $_GET['post_type'] !== $post_type ) {
			return;
		}

		if ( ! isset( $_GET[$taxonomy] ) || $_GET[$taxonomy] === '' ) {
			return;
		}

		$query->query_vars[$taxonomy] = $_GET[$taxonomy];
	}
}
