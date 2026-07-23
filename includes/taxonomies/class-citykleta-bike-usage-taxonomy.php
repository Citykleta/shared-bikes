<?php
/**
 * "bike_usage" taxonomy: the usage types declared by the admin (Urban,
 * Road, Cargo...), each with its own description text (WordPress's native
 * "description" field) and a list of bike types (`bike_type` taxonomy)
 * that belong to it, saved as term meta.
 *
 * It isn't assigned directly to each bike — a bike's usage is DERIVED from
 * its `bike_type` through this relationship (see
 * Citykleta_Templates::usage_to_bike_types()). That's why there's no usage
 * selector on the bike editor (`meta_box_cb` => false); only the native
 * terms screen is used to manage the usage types themselves.
 *
 * @package Citykleta_Shared_Bikes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Citykleta_Bike_Usage_Taxonomy.
 */
class Citykleta_Bike_Usage_Taxonomy {

	/**
	 * Internal taxonomy identifier.
	 */
	const TAXONOMY = 'bike_usage';

	/**
	 * Meta key storing the list of `bike_type` term IDs that belong to
	 * this usage.
	 */
	const META_KEY = 'bike_types';

	/**
	 * Meta key storing the position of this usage in the search widget's
	 * dropdown (lower numbers first).
	 */
	const META_KEY_ORDER = 'display_order';

	/**
	 * Connects the class to WordPress hooks.
	 */
	public function register() {
		$this->register_taxonomy();
		$this->register_meta();

		add_action( self::TAXONOMY . '_add_form_fields', array( $this, 'render_add_field' ) );
		add_action( self::TAXONOMY . '_edit_form_fields', array( $this, 'render_edit_field' ) );
		add_action( 'created_' . self::TAXONOMY, array( $this, 'save_field' ) );
		add_action( 'edited_' . self::TAXONOMY, array( $this, 'save_field' ) );

		add_filter( 'manage_edit-' . self::TAXONOMY . '_columns', array( $this, 'columns' ) );
		add_filter( 'manage_' . self::TAXONOMY . '_custom_column', array( $this, 'column_content' ), 10, 3 );
	}

	/**
	 * Replaces the "Count" column with "Display order" — usage terms are
	 * never assigned directly to a bike post (see class docblock), so
	 * "Count" would always show 0 and isn't useful here.
	 *
	 * @param array $columns Columns keyed by slug.
	 * @return array
	 */
	public function columns( $columns ) {
		unset( $columns['posts'] );

		$columns['order'] = __( 'Display order', 'citykleta-shared-bikes' );

		return $columns;
	}

	/**
	 * Renders the "Display order" column content.
	 *
	 * @param string $content     Existing column content.
	 * @param string $column_name Column being rendered.
	 * @param int    $term_id     Term ID for the current row.
	 * @return string
	 */
	public function column_content( $content, $column_name, $term_id ) {
		if ( 'order' !== $column_name ) {
			return $content;
		}

		$order = get_term_meta( $term_id, self::META_KEY_ORDER, true );

		return esc_html( '' !== $order ? $order : '0' );
	}

	/**
	 * Registers the taxonomy in WordPress.
	 */
	private function register_taxonomy() {
		$labels = array(
			'name'          => 'Usage types',
			'singular_name' => 'Usage type',
			'search_items'  => 'Search usage types',
			'all_items'     => 'All usage types',
			'edit_item'     => 'Edit usage type',
			'update_item'   => 'Update usage type',
			'add_new_item'  => 'Add new usage type',
			'new_item_name' => 'New usage type name',
			'menu_name'     => 'Usages',
			'back_to_items' => '← Go to usage types',
		);

		register_taxonomy(
			self::TAXONOMY,
			Citykleta_Bike_Post_Type::POST_TYPE,
			array(
				'labels'            => $labels,
				'hierarchical'      => false,
				'public'            => false,
				'show_ui'           => true,
				'show_admin_column' => false,
				'show_in_rest'      => true,
				// No term is assigned per bike — usage is derived from each
				// bike's bike_type, not picked by hand in the editor.
				'meta_box_cb'       => false,
			)
		);
	}

	/**
	 * Registers the term meta field storing the bike types included in
	 * the usage.
	 */
	private function register_meta() {
		register_term_meta(
			self::TAXONOMY,
			self::META_KEY,
			array(
				'type'          => 'array',
				'single'        => true,
				'show_in_rest'  => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'integer' ),
					),
				),
				'auth_callback' => function () {
					return current_user_can( 'manage_categories' );
				},
			)
		);

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
	 * Renders the bike types field on the "Add New Usage Type" screen.
	 */
	public function render_add_field() {
		?>
		<div class="form-field">
			<label for="citykleta-bike-usage-order"><?php esc_html_e( 'Display order', 'citykleta-shared-bikes' ); ?></label>
			<input type="number" step="1" id="citykleta-bike-usage-order" name="citykleta_bike_usage_order" value="0" />
			<p><?php esc_html_e( 'Lower numbers appear first in the usage dropdown.', 'citykleta-shared-bikes' ); ?></p>
		</div>
		<div class="form-field">
			<label><?php esc_html_e( 'Included bike types', 'citykleta-shared-bikes' ); ?></label>
			<?php $this->render_checkboxes( array() ); ?>
			<p><?php esc_html_e( 'Check which bike types belong to this usage.', 'citykleta-shared-bikes' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Renders the bike types field on the "Edit Usage Type" screen.
	 *
	 * @param WP_Term $term Term being edited.
	 */
	public function render_edit_field( $term ) {
		$selected = get_term_meta( $term->term_id, self::META_KEY, true );
		$selected = is_array( $selected ) ? $selected : array();
		$order    = get_term_meta( $term->term_id, self::META_KEY_ORDER, true );
		?>
		<tr class="form-field">
			<th scope="row"><label for="citykleta-bike-usage-order"><?php esc_html_e( 'Display order', 'citykleta-shared-bikes' ); ?></label></th>
			<td>
				<input type="number" step="1" id="citykleta-bike-usage-order" name="citykleta_bike_usage_order" value="<?php echo esc_attr( $order ); ?>" />
				<p class="description"><?php esc_html_e( 'Lower numbers appear first in the usage dropdown.', 'citykleta-shared-bikes' ); ?></p>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label><?php esc_html_e( 'Included bike types', 'citykleta-shared-bikes' ); ?></label></th>
			<td>
				<?php $this->render_checkboxes( $selected ); ?>
				<p class="description"><?php esc_html_e( 'Check which bike types belong to this usage.', 'citykleta-shared-bikes' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Renders the list of checkboxes for every bike type.
	 *
	 * @param array $selected `bike_type` term IDs already checked.
	 */
	private function render_checkboxes( $selected ) {
		$types = get_terms(
			array(
				'taxonomy'   => Citykleta_Bike_Type_Taxonomy::TAXONOMY,
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $types ) || ! $types ) {
			echo '<p>' . esc_html__( 'No bike types have been created yet.', 'citykleta-shared-bikes' ) . '</p>';
			return;
		}

		wp_nonce_field( 'citykleta_save_bike_usage_types', 'citykleta_bike_usage_types_nonce' );

		foreach ( $types as $type ) {
			printf(
				'<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="citykleta_bike_types[]" value="%1$d" %2$s /> %3$s</label>',
				(int) $type->term_id,
				checked( in_array( $type->term_id, $selected, true ), true, false ),
				esc_html( $type->name )
			);
		}
	}

	/**
	 * Saves the checked bike types and the display order when a usage type
	 * is created or edited, then rebuilds the term's real "description" as
	 * whatever the admin typed plus the selected bike types appended.
	 *
	 * Reads the description from $_POST (what was just submitted), not
	 * from the term after WordPress already saved it, and strips any
	 * "Bike types: …" tail from that submitted text before re-appending —
	 * otherwise re-saving without touching the checkboxes would keep
	 * piling "Bike types: …" fragments onto the end every time, since the
	 * edit screen always shows the previously-combined description back
	 * in the textarea.
	 *
	 * @param int $term_id Saved term ID.
	 */
	public function save_field( $term_id ) {
		if ( ! isset( $_POST['citykleta_bike_usage_types_nonce'] ) ||
			! wp_verify_nonce( sanitize_key( $_POST['citykleta_bike_usage_types_nonce'] ), 'citykleta_save_bike_usage_types' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_categories' ) ) {
			return;
		}

		$types = isset( $_POST['citykleta_bike_types'] ) ? (array) $_POST['citykleta_bike_types'] : array();
		$types = array_map( 'intval', $types );

		update_term_meta( $term_id, self::META_KEY, $types );

		$order = isset( $_POST['citykleta_bike_usage_order'] ) ? intval( wp_unslash( $_POST['citykleta_bike_usage_order'] ) ) : 0;

		update_term_meta( $term_id, self::META_KEY_ORDER, $order );

		$description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';
		$description = trim( preg_replace( '/\s*Bike types:.*$/s', '', $description ) );

		$type_terms = $types ? get_terms(
			array(
				'taxonomy'   => Citykleta_Bike_Type_Taxonomy::TAXONOMY,
				'include'    => $types,
				'hide_empty' => false,
			)
		) : array();

		$names = ( ! is_wp_error( $type_terms ) && $type_terms ) ? wp_list_pluck( $type_terms, 'name' ) : array();

		if ( $names ) {
			$description = ( $description ? $description . ' ' : '' ) . 'Bike types: ' . implode( ', ', $names );
		}

		if ( $description && ! preg_match( '/[.!?]$/', $description ) ) {
			$description .= '.';
		}

		// wp_update_term() fires 'edited_' . self::TAXONOMY again — unhook
		// this method first, or it'd call itself forever.
		remove_action( 'edited_' . self::TAXONOMY, array( $this, 'save_field' ) );
		wp_update_term( $term_id, self::TAXONOMY, array( 'description' => $description ) );
		add_action( 'edited_' . self::TAXONOMY, array( $this, 'save_field' ) );
	}
}
