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

		add_filter( "views_edit-{$post_type}", array( $this, 'phone_views' ) );
		add_action( 'pre_get_posts', array( $this, 'filter_by_phone' ) );
		add_filter( "bulk_actions-edit-{$post_type}", array( $this, 'bulk_actions' ) );
		add_filter( "handle_bulk_actions-edit-{$post_type}", array( $this, 'handle_bulk_actions' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'bulk_notice' ) );
	}

	/**
	 * Meta: bike flagged by staff as "needs an owner phone" (value '1').
	 */
	const META_FOLLOWUP = '_citykleta_phone_followup';

	/**
	 * Counts bikes for one phone view.
	 *
	 * @param string $view 'missing' (no phone) or 'marked' (flagged for follow-up).
	 * @return int
	 */
	private static function count_phone_view( $view ) {
		$query = new WP_Query(
			array(
				'post_type'      => Citykleta_Bike_Post_Type::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => self::phone_view_meta_query( $view ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);

		return (int) $query->found_posts;
	}

	/**
	 * Meta query for a phone view.
	 *
	 * @param string $view 'missing' or 'marked'.
	 * @return array
	 */
	private static function phone_view_meta_query( $view ) {
		if ( 'marked' === $view ) {
			return array(
				array(
					'key'   => self::META_FOLLOWUP,
					'value' => '1',
				),
			);
		}

		return array( Citykleta_Owner_Contact::missing_phone_meta_query() );
	}

	/**
	 * Adds "No phone" and "Flagged" links above the list, with counts. Bikes with
	 * no phone are hidden from the public site, so staff need a quick way to find
	 * them here.
	 *
	 * @param array $views Existing views.
	 * @return array
	 */
	public function phone_views( $views ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
		$current = isset( $_GET['citykleta_phone'] ) ? sanitize_key( wp_unslash( $_GET['citykleta_phone'] ) ) : '';
		$base    = admin_url( 'edit.php?post_type=' . Citykleta_Bike_Post_Type::POST_TYPE );

		$labels = array(
			'missing' => 'No phone',
			'marked'  => 'Flagged to follow up',
		);

		foreach ( $labels as $view => $label ) {
			$views[ 'citykleta_phone_' . $view ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( add_query_arg( 'citykleta_phone', $view, $base ) ),
				$current === $view ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				self::count_phone_view( $view )
			);
		}

		return $views;
	}

	/**
	 * Applies ?citykleta_phone=missing|marked to the bike list.
	 *
	 * @param WP_Query $query Current admin query.
	 */
	public function filter_by_phone( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || Citykleta_Bike_Post_Type::POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
		$view = isset( $_GET['citykleta_phone'] ) ? sanitize_key( wp_unslash( $_GET['citykleta_phone'] ) ) : '';

		if ( ! in_array( $view, array( 'missing', 'marked' ), true ) ) {
			return;
		}

		$meta_query = array( 'relation' => 'AND' );
		$existing   = $query->get( 'meta_query' );

		if ( ! empty( $existing ) ) {
			$meta_query[] = $existing;
		}

		$meta_query[] = self::phone_view_meta_query( $view )[0];

		$query->set( 'meta_query', $meta_query );
	}

	/**
	 * Adds the flag / unflag bulk actions.
	 *
	 * @param array $actions Existing bulk actions.
	 * @return array
	 */
	public function bulk_actions( $actions ) {
		$actions['citykleta_flag_phone']   = 'Flag: needs owner phone';
		$actions['citykleta_unflag_phone'] = 'Clear follow-up flag';

		return $actions;
	}

	/**
	 * Handles the bulk actions above. WordPress already verified the bulk-action
	 * nonce before calling this filter.
	 *
	 * @param string $redirect_to Redirect URL.
	 * @param string $action      Chosen bulk action.
	 * @param array  $post_ids    Selected bike IDs.
	 * @return string
	 */
	public function handle_bulk_actions( $redirect_to, $action, $post_ids ) {
		if ( ! in_array( $action, array( 'citykleta_flag_phone', 'citykleta_unflag_phone' ), true ) ) {
			return $redirect_to;
		}

		$done = 0;

		foreach ( $post_ids as $post_id ) {
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				continue;
			}

			if ( 'citykleta_flag_phone' === $action ) {
				update_post_meta( $post_id, self::META_FOLLOWUP, '1' );
			} else {
				delete_post_meta( $post_id, self::META_FOLLOWUP );
			}

			++$done;
		}

		return add_query_arg(
			array(
				'citykleta_flagged' => $done,
				'citykleta_cleared' => 'citykleta_unflag_phone' === $action ? 1 : 0,
			),
			$redirect_to
		);
	}

	/**
	 * Confirms the bulk action on the list screen.
	 */
	public function bulk_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only confirmation message.
		if ( ! isset( $_GET['citykleta_flagged'] ) ) {
			return;
		}

		$count   = (int) $_GET['citykleta_flagged']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$cleared = ! empty( $_GET['citykleta_cleared'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html( sprintf( $cleared ? 'Follow-up flag cleared on %d bike(s).' : 'Flagged %d bike(s) as needing an owner phone.', $count ) )
		);
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
		$new_columns['citykleta_phone']    = 'Owner phone';

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

			case 'citykleta_phone':
				$phone = Citykleta_Bike_Request_Form::owner_phone( $post_id );

				if ( '' !== $phone ) {
					echo esc_html( '+' . $phone );
					break;
				}

				echo '<strong style="color:#b32d2e;">Missing</strong> <span style="color:#646970;">(hidden from site)</span>';

				if ( '1' === get_post_meta( $post_id, self::META_FOLLOWUP, true ) ) {
					echo '<br><span style="background:#fcf0c3;color:#6e4c00;padding:1px 6px;border-radius:3px;font-size:11px;">Flagged to follow up</span>';
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
		$taxonomy  = Citykleta_Bike_Type_Taxonomy::TAXONOMY;

		if ( $typenow !== $post_type ) {
			return;
		}

		$selected = isset( $_GET[$taxonomy] ) ? $_GET[$taxonomy] : '';

		wp_dropdown_categories( array(
			'show_option_all' => 'All types',
			'taxonomy'        => $taxonomy,
			'name'            => $taxonomy,
			'orderby'         => 'name',
			'selected'        => $selected,
			'value_field'     => 'slug', // Important: WP filters by slug.
			'hierarchical'    => true,
			'hide_empty'      => false, // A top-level type owns no bikes directly, only through its subtypes.
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
		$taxonomy  = Citykleta_Bike_Type_Taxonomy::TAXONOMY;

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
