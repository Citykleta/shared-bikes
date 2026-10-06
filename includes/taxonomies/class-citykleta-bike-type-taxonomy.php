<?php
/**
 * "bike_type" taxonomy: what a bike is, as a two-level tree.
 *
 *   Urbano        → Chopper, City bike, E-bike, Fixie, Folding bike
 *   Carretera     → Hybrid, Road, Touring bike
 *   Todo-terreno  → Cruiser, Gravel, Mountain bike (MTB), MTB XC, Trail
 *   …
 *
 * **Top level = the bike types** (Urbano, Carretera…): declared by hand in
 * wp-admin, each with a description and a display order. They used to be the
 * flat `bike_usage` taxonomy.
 * **Second level = the subtypes** (Road, MTB…): created by
 * Citykleta_Bike_Type_Sync from the app's catalogue, one per slug the API
 * sends in `bike_type`. They used to be the flat `bike_type` taxonomy — the
 * API still calls them that. A bike is assigned its SUBTYPE; its type is the
 * subtype's parent.
 *
 * This replaced two flat taxonomies joined by a hand-kept list of IDs in term
 * meta (each usage stored the bike_type IDs it "included"). That join had to
 * be translated by hand everywhere it was read — the search filter, the
 * dropdown's "Inc. …" list, the spec table (which never managed it, so its
 * "Uso" row was always empty) — and it allowed a subtype to sit under two
 * usages at once. A real hierarchy gets all of that from WordPress itself: a
 * `tax_query` on a type includes its children by default, and the term
 * archive of a type lists every bike of every subtype under it. The price is
 * that a subtype has exactly ONE type — when the two-usage ones were folded
 * in, which one each kept was a product decision, recorded in
 * Citykleta_Bike_Type_Migration.
 *
 * Briefly (2026-10) this taxonomy was called `bike_kind`. The name `bike_type`
 * was taken by the old flat terms, which are kept in the database renamed to
 * `bike_type_legacy` — see Citykleta_Bike_Type_Migration.
 *
 * Subtypes the sync creates for the first time land under self::UNCLASSIFIED
 * rather than at the top level, where they'd pass for a type in the search
 * dropdown and the categories widget. Moving one to its real type is changing
 * its parent in wp-admin, like any category; the sync never touches a parent
 * once set, so that choice survives every later run.
 *
 * @package Citykleta_Shared_Bikes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Citykleta_Bike_Type_Taxonomy.
 */
class Citykleta_Bike_Type_Taxonomy {

	/**
	 * Internal taxonomy identifier.
	 */
	const TAXONOMY = 'bike_type';

	/**
	 * URL base: /bike-type/urbano/city-bike/.
	 */
	const REWRITE_SLUG = 'bike-type';

	/**
	 * Term meta: position of a top-level type in lists (lower first, 0 =
	 * unset, sorted last). Same key the old `bike_usage` used, so a value
	 * carried over by the migration means the same thing.
	 */
	const META_KEY_ORDER = 'display_order';

	/**
	 * Slug of the holding type new, not-yet-placed subtypes are filed under.
	 * Never shown to visitors — see self::top_level().
	 */
	const UNCLASSIFIED = 'unclassified';

	/**
	 * Connects the class to WordPress hooks.
	 */
	public function register() {
		$this->register_taxonomy();
		$this->register_meta();

		add_action( 'rest_api_init', array( $this, 'register_rest_fields' ) );

		add_action( self::TAXONOMY . '_add_form_fields', array( $this, 'render_add_field' ) );
		add_action( self::TAXONOMY . '_edit_form_fields', array( $this, 'render_edit_field' ) );
		add_action( 'created_' . self::TAXONOMY, array( $this, 'save_field' ) );
		add_action( 'edited_' . self::TAXONOMY, array( $this, 'save_field' ) );

		add_filter( 'manage_edit-' . self::TAXONOMY . '_columns', array( $this, 'columns' ) );
		add_filter( 'manage_' . self::TAXONOMY . '_custom_column', array( $this, 'column_content' ), 10, 3 );
	}

	/**
	 * Registers the taxonomy. Called straight from register() — the plugin
	 * already runs inside 'init' (see the "don't re-hook the same hook"
	 * gotcha in CLAUDE.md).
	 */
	private function register_taxonomy() {
		$labels = array(
			'name'              => 'Bike types',
			'singular_name'     => 'Bike type',
			'search_items'      => 'Search bike types',
			'all_items'         => 'All bike types',
			'parent_item'       => 'Parent type',
			'parent_item_colon' => 'Parent type:',
			'edit_item'         => 'Edit bike type',
			'update_item'       => 'Update bike type',
			'add_new_item'      => 'Add new bike type',
			'new_item_name'     => 'New bike type name',
			'menu_name'         => 'Bike types',
			'back_to_items'     => '← Go to bike types',
		);

		register_taxonomy(
			self::TAXONOMY,
			Citykleta_Bike_Post_Type::POST_TYPE,
			array(
				'labels'            => $labels,
				// The whole point: categories-style nesting, type → subtype.
				'hierarchical'      => true,
				'public'            => true,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'rewrite'           => array(
					'slug'         => self::REWRITE_SLUG,
					'hierarchical' => true,
				),
			)
		);
	}

	/**
	 * Registers the term meta.
	 */
	private function register_meta() {
		register_term_meta(
			self::TAXONOMY,
			self::META_KEY_ORDER,
			array(
				'type'          => 'integer',
				'single'        => true,
				'default'       => 0,
				'show_in_rest'  => true,
				'auth_callback' => function () {
					return current_user_can( 'manage_categories' );
				},
			)
		);
	}

	/**
	 * Exposes the bike's subtype and type names in the REST API, so an app
	 * reading /wp-json/wp/v2/bike doesn't need a second request per bike.
	 *
	 * `bike_type_names` keeps returning the SUBTYPES (Road, MTB…) — exactly
	 * what it returned back when `bike_type` was the flat list the API calls
	 * bike_type — so whatever already reads it doesn't break. The top level
	 * (Urbano…) goes in its own field.
	 */
	public function register_rest_fields() {
		register_rest_field(
			Citykleta_Bike_Post_Type::POST_TYPE,
			'bike_type_names',
			array(
				'get_callback' => function ( $post ) {
					return wp_list_pluck( self::bike_subtypes( $post['id'] ), 'name' );
				},
				'schema'       => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			)
		);

		register_rest_field(
			Citykleta_Bike_Post_Type::POST_TYPE,
			'bike_type_parent_names',
			array(
				'get_callback' => function ( $post ) {
					return wp_list_pluck( self::bike_types( $post['id'] ), 'name' );
				},
				'schema'       => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			)
		);
	}

	/**
	 * The holding type for unplaced subtypes, created on first need.
	 *
	 * @return int Term ID, or 0 if it couldn't be created.
	 */
	public static function unclassified_id() {
		$term = get_term_by( 'slug', self::UNCLASSIFIED, self::TAXONOMY );

		if ( $term ) {
			return (int) $term->term_id;
		}

		$inserted = wp_insert_term(
			'Unclassified',
			self::TAXONOMY,
			array(
				'slug'        => self::UNCLASSIFIED,
				'description' => 'Subtypes the sync found that haven\'t been placed under a type yet. Hidden from visitors.',
			)
		);

		return is_wp_error( $inserted ) ? 0 : (int) $inserted['term_id'];
	}

	/**
	 * The top-level types, in display order (an unset order goes last,
	 * ties by name).
	 *
	 * Sorted in PHP rather than with 'meta_key' in the query: in
	 * WP_Term_Query that becomes an INNER JOIN, which silently drops every
	 * type that has no order saved yet.
	 *
	 * @param bool $include_unclassified Whether to include the holding type.
	 *                                   Visitors never should see it.
	 * @return WP_Term[]
	 */
	public static function top_level( $include_unclassified = false ) {
		$terms = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'parent'     => 0,
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) || ! $terms ) {
			return array();
		}

		if ( ! $include_unclassified ) {
			$terms = array_values(
				array_filter(
					$terms,
					function ( $term ) {
						return self::UNCLASSIFIED !== $term->slug;
					}
				)
			);
		}

		usort( $terms, array( __CLASS__, 'compare_order' ) );

		return $terms;
	}

	/**
	 * usort() callback: display order first (0 = unset, goes last), then name.
	 *
	 * @param WP_Term $a First term.
	 * @param WP_Term $b Second term.
	 * @return int
	 */
	public static function compare_order( $a, $b ) {
		$order_a = (int) get_term_meta( $a->term_id, self::META_KEY_ORDER, true );
		$order_b = (int) get_term_meta( $b->term_id, self::META_KEY_ORDER, true );

		if ( 0 === $order_a && 0 !== $order_b ) {
			return 1;
		}

		if ( 0 !== $order_a && 0 === $order_b ) {
			return -1;
		}

		if ( $order_a === $order_b ) {
			return strcasecmp( $a->name, $b->name );
		}

		return $order_a <=> $order_b;
	}

	/**
	 * The subtypes filed under a type.
	 *
	 * @param int $type_id Top-level type term ID.
	 * @return WP_Term[]
	 */
	public static function children( $type_id ) {
		$terms = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'parent'     => (int) $type_id,
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);

		return is_wp_error( $terms ) ? array() : $terms;
	}

	/**
	 * Every subtype (any second-level term), regardless of type.
	 *
	 * @return WP_Term[]
	 */
	public static function all_subtypes() {
		$terms = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$terms,
				function ( $term ) {
					return 0 !== (int) $term->parent;
				}
			)
		);
	}

	/**
	 * A bike's subtype(s) — the terms actually assigned to it.
	 *
	 * Only second-level terms count: should someone tick a top-level type
	 * directly in the bike editor, that's not a subtype.
	 *
	 * @param int $bike_id Bike post ID.
	 * @return WP_Term[]
	 */
	public static function bike_subtypes( $bike_id ) {
		$terms = get_the_terms( $bike_id, self::TAXONOMY );

		if ( ! $terms || is_wp_error( $terms ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$terms,
				function ( $term ) {
					return 0 !== (int) $term->parent;
				}
			)
		);
	}

	/**
	 * A bike's type(s): the parents of its subtypes, never the holding type.
	 *
	 * @param int $bike_id Bike post ID.
	 * @return WP_Term[]
	 */
	public static function bike_types( $bike_id ) {
		$types = array();

		foreach ( self::bike_subtypes( $bike_id ) as $subtype ) {
			if ( isset( $types[ $subtype->parent ] ) ) {
				continue;
			}

			$parent = get_term( (int) $subtype->parent, self::TAXONOMY );

			if ( $parent instanceof WP_Term && self::UNCLASSIFIED !== $parent->slug ) {
				$types[ $parent->term_id ] = $parent;
			}
		}

		return array_values( $types );
	}

	/**
	 * Adds "Order" and "Active" before "Count". Count stays — on a subtype
	 * it's the number of bikes; on a top-level type it's 0, since bikes are
	 * assigned their subtype and never the type itself.
	 *
	 * @param array $columns Columns keyed by slug.
	 * @return array
	 */
	public function columns( $columns ) {
		$count = isset( $columns['posts'] ) ? $columns['posts'] : null;
		unset( $columns['posts'] );

		$columns['citykleta_order']  = 'Order';
		$columns['citykleta_active'] = 'Active';

		if ( null !== $count ) {
			$columns['posts'] = $count;
		}

		return $columns;
	}

	/**
	 * Renders the custom columns.
	 *
	 * "Order" only means something on a top-level type; "Active" only on a
	 * subtype (whether the app still offers it, see Citykleta_Bike_Type_Sync).
	 *
	 * @param string $content     Existing column content.
	 * @param string $column_name Column being rendered.
	 * @param int    $term_id     Term ID for the current row.
	 * @return string
	 */
	public function column_content( $content, $column_name, $term_id ) {
		$term = get_term( $term_id, self::TAXONOMY );

		if ( ! $term instanceof WP_Term ) {
			return $content;
		}

		$is_top = 0 === (int) $term->parent;

		if ( 'citykleta_order' === $column_name ) {
			if ( ! $is_top ) {
				return '—';
			}

			$order = get_term_meta( $term_id, self::META_KEY_ORDER, true );

			return esc_html( '' !== $order ? $order : '0' );
		}

		if ( 'citykleta_active' === $column_name ) {
			if ( $is_top ) {
				return '—';
			}

			$active = get_term_meta( $term_id, Citykleta_Bike_Type_Sync::ACTIVE_META_KEY, true );

			if ( '' === $active ) {
				return '—';
			}

			return $active ? '✅ Yes' : '⚠️ No longer offered by the app';
		}

		return $content;
	}

	/**
	 * Display order field on the "Add New Bike Type" screen.
	 */
	public function render_add_field() {
		wp_nonce_field( 'citykleta_save_bike_type', 'citykleta_bike_type_nonce' );
		?>
		<div class="form-field">
			<label for="citykleta-bike-type-order">Display order</label>
			<input type="number" step="1" id="citykleta-bike-type-order" name="citykleta_bike_type_order" value="0" />
			<p>Only for top-level types: lower numbers appear first in the search dropdown and the categories widget. Subtypes (second level) are created by the sync — a term with no parent is a type.</p>
		</div>
		<?php
	}

	/**
	 * Display order field on the "Edit Bike Type" screen.
	 *
	 * @param WP_Term $term Term being edited.
	 */
	public function render_edit_field( $term ) {
		wp_nonce_field( 'citykleta_save_bike_type', 'citykleta_bike_type_nonce' );

		$order = get_term_meta( $term->term_id, self::META_KEY_ORDER, true );
		?>
		<tr class="form-field">
			<th scope="row"><label for="citykleta-bike-type-order">Display order</label></th>
			<td>
				<input type="number" step="1" id="citykleta-bike-type-order" name="citykleta_bike_type_order" value="<?php echo esc_attr( $order ); ?>" />
				<p class="description">Only used for top-level types. Lower numbers appear first.</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Saves the display order.
	 *
	 * @param int $term_id Saved term ID.
	 */
	public function save_field( $term_id ) {
		if ( ! isset( $_POST['citykleta_bike_type_nonce'] ) ||
			! wp_verify_nonce( sanitize_key( $_POST['citykleta_bike_type_nonce'] ), 'citykleta_save_bike_type' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_categories' ) ) {
			return;
		}

		if ( ! isset( $_POST['citykleta_bike_type_order'] ) ) {
			return;
		}

		update_term_meta( $term_id, self::META_KEY_ORDER, intval( wp_unslash( $_POST['citykleta_bike_type_order'] ) ) );
	}
}
