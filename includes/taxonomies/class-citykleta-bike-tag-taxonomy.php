<?php
/**
 * "bike_tag" taxonomy: loose labels on a bike (Tandem, Kids, Trailer,
 * Electric…), as opposed to bike_type, which is what the bike IS.
 *
 * Read-only in WordPress, like bike types: the tags live in
 * citykleta-sharing-api (its `tag` / `bike_tag` tables) and arrive on every row
 * of GET /bikesPublic/sync as `tags: ["…"]`. Citykleta_Bike_Sync::sync_tags()
 * mirrors them on each run — adding, and removing what the app removed — so
 * editing a tag here would be overwritten next sync. That's why the editor
 * box and the "Add New" form are hidden.
 *
 * One exception: **Electric is derived, not synced.** The app already says
 * whether a bike is electric through `is_electric`; tagging it a second time
 * in the back office would be two sources for one fact, free to disagree. The
 * sync adds the Electric tag exactly when `is_electric` is true — see
 * self::ELECTRIC.
 *
 * @package Citykleta_Shared_Bikes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Citykleta_Bike_Tag_Taxonomy.
 */
class Citykleta_Bike_Tag_Taxonomy {

	/**
	 * Internal taxonomy identifier.
	 */
	const TAXONOMY = 'bike_tag';

	/**
	 * Name of the tag the sync derives from `is_electric`.
	 */
	const ELECTRIC = 'Electric';

	/**
	 * Connects the class to WordPress hooks.
	 */
	public function register() {
		$this->register_taxonomy();

		add_action( 'admin_head-edit-tags.php', array( $this, 'hide_add_new_form' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_field' ) );
	}

	/**
	 * Registers the taxonomy.
	 */
	private function register_taxonomy() {
		$labels = array(
			'name'          => 'Bike tags',
			'singular_name' => 'Bike tag',
			'search_items'  => 'Search bike tags',
			'all_items'     => 'All bike tags',
			'edit_item'     => 'Edit bike tag',
			'update_item'   => 'Update bike tag',
			'add_new_item'  => 'Add new bike tag',
			'new_item_name' => 'New bike tag name',
			'menu_name'     => 'Bike tags',
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
				// Synced from the app — a tag ticked by hand here would be
				// wiped by the next sync, so no editor box to tick it in.
				'meta_box_cb'       => false,
			)
		);
	}

	/**
	 * Hides the "Add New Tag" form: tags are created by the sync from the
	 * app's catalogue, and one typed in here would never reach a bike. CSS
	 * only — same approach the bike types screen used.
	 */
	public function hide_add_new_form() {
		$screen = get_current_screen();

		if ( ! $screen || self::TAXONOMY !== $screen->taxonomy ) {
			return;
		}

		echo '<style>#col-left { display: none; } #col-right { width: 100%; float: none; }</style>';
	}

	/**
	 * Exposes the bike's tag names in the REST API.
	 */
	public function register_rest_field() {
		register_rest_field(
			Citykleta_Bike_Post_Type::POST_TYPE,
			'bike_tag_names',
			array(
				'get_callback' => function ( $post ) {
					return self::names( $post['id'] );
				},
				'schema'       => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			)
		);
	}

	/**
	 * A bike's tag names.
	 *
	 * @param int $bike_id Bike post ID.
	 * @return string[]
	 */
	public static function names( $bike_id ) {
		$terms = get_the_terms( $bike_id, self::TAXONOMY );

		if ( ! $terms || is_wp_error( $terms ) ) {
			return array();
		}

		return wp_list_pluck( $terms, 'name' );
	}
}
