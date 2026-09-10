<?php
/**
 * "Templates": the layouts the plugin's own front-end pages are drawn with.
 *
 * A template is NOT a page. It has no URL of its own, never shows up in
 * menus/search/sitemaps, and is never visited directly — it only holds a
 * layout (built with Elementor, or plain shortcodes) that a plugin template
 * file prints for whatever bike/query the visitor is actually looking at.
 * That's why this is a private post type under "Bicicletas → Templates"
 * instead of a regular Page: the results layout used to live as a normal
 * Page, which meant it sat in the Pages list next to real content and could
 * be reached at its own URL.
 *
 * **Elementor works here without Pro.** Elementor decides whether a post
 * type is editable purely through `post_type_supports( $type, 'elementor' )`
 * (see Elementor's Utils::is_post_type_support()) — declaring that support
 * below is enough, no Theme Builder and no checkbox in Elementor → Settings.
 * What Pro would add is Dynamic Tags: binding a *generic* Elementor widget
 * to a bike field. That isn't needed here because the plugin ships its own
 * widgets (see Citykleta_Bike_Part_Widgets), and each one resolves the bike
 * on its own from the current query.
 *
 * Each template declares WHAT it draws through the META_TYPE meta
 * (self::TYPES). The renderer asks for "the published template of type X"
 * — so a draft is a safe place to try a redesign without it going live.
 *
 * @package Citykleta_Shared_Bikes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Citykleta_Template_Post_Type.
 */
class Citykleta_Template_Post_Type {

	/**
	 * Internal identifier of the content type.
	 */
	const POST_TYPE = 'citykleta_template';

	/**
	 * Meta holding which front-end view this template draws (a key of
	 * self::TYPES).
	 */
	const META_TYPE = '_citykleta_template_type';

	/**
	 * The views a template can be assigned to, and the label shown in
	 * wp-admin. Adding a new front-end view = one entry here plus the
	 * template file that calls self::content_for().
	 */
	const TYPES = array(
		'results'     => 'Bike results (/bikes/)',
		'single_bike' => 'Single bike page',
	);

	/**
	 * Connects the class to WordPress hooks.
	 */
	public function register() {
		// Called from within 'init' (citykleta_init()), so registration runs
		// directly instead of hooking 'init' again — see the note in
		// Citykleta_Bike_Meta::register().
		$this->register_post_type();

		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save_meta' ) );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'add_type_column' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'render_type_column' ), 10, 2 );
	}

	/**
	 * Registers the content type: private (no URL, no archive, nothing
	 * public-facing) but with a full wp-admin UI, nested under the same
	 * "Bicicletas" menu as bikes and destinations.
	 */
	private function register_post_type() {
		$labels = array(
			'name'          => 'Templates',
			'singular_name' => 'Template',
			'add_new'       => 'Add New',
			'add_new_item'  => 'Add New Template',
			'edit_item'     => 'Edit Template',
			'new_item'      => 'New Template',
			'search_items'  => 'Search Templates',
			'not_found'     => 'No templates found',
			'menu_name'     => 'Templates',
			'all_items'     => 'Templates',
		);

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => $labels,
				/*
				 * Exactamente los mismos flags con los que Elementor registra
				 * su propio `elementor_library` (ver
				 * elementor/includes/template-library/sources/local.php).
				 * `public => true` NO es un descuido: es lo que hace que el
				 * post sea consultable, y sin eso la vista previa — y el
				 * lienzo del editor de Elementor, que carga el permalink con
				 * ?elementor-preview= — dan 404. Lo que mantiene la plantilla
				 * fuera de todo lo demás es el resto: sin permalink bonito
				 * (`rewrite => false`), fuera de búsquedas y fuera de los
				 * menús.
				 */
				'public'              => true,
				'rewrite'             => false,
				'exclude_from_search' => true,
				'show_in_nav_menus'   => false,
				'has_archive'         => false,
				'show_ui'             => true,
				'show_in_menu'        => 'edit.php?post_type=' . Citykleta_Bike_Post_Type::POST_TYPE,
				// 'elementor' is what makes the "Edit with Elementor" button
				// appear — see the class docblock.
				'supports'            => array( 'title', 'editor', 'elementor' ),
			)
		);
	}

	/**
	 * Adds the "Draws" box to the template edit screen.
	 */
	public function add_meta_box() {
		add_meta_box(
			'citykleta_template_type',
			'Draws',
			array( $this, 'render_meta_box' ),
			self::POST_TYPE,
			'side',
			'high'
		);
	}

	/**
	 * Renders the "which view does this template draw" selector.
	 *
	 * @param WP_Post $post Template being edited.
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( 'citykleta_save_template_type', 'citykleta_template_type_nonce' );

		$current = get_post_meta( $post->ID, self::META_TYPE, true );

		echo '<select name="' . esc_attr( self::META_TYPE ) . '" style="width:100%;">';
		echo '<option value="">— Not assigned —</option>';

		foreach ( self::TYPES as $type => $label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $type ),
				selected( $current, $type, false ),
				esc_html( $label )
			);
		}

		echo '</select>';

		echo '<p class="description">The front-end view this layout is used for. Only a <strong>published</strong> template is used, so a draft is a safe place to try a redesign.</p>';
	}

	/**
	 * Saves the selected type.
	 *
	 * @param int $post_id Template ID.
	 */
	public function save_meta( $post_id ) {
		if ( ! isset( $_POST['citykleta_template_type_nonce'] ) ||
			! wp_verify_nonce( sanitize_key( $_POST['citykleta_template_type_nonce'] ), 'citykleta_save_template_type' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$type = isset( $_POST[ self::META_TYPE ] ) ? sanitize_key( wp_unslash( $_POST[ self::META_TYPE ] ) ) : '';

		if ( $type && array_key_exists( $type, self::TYPES ) ) {
			update_post_meta( $post_id, self::META_TYPE, $type );
		} else {
			delete_post_meta( $post_id, self::META_TYPE );
		}
	}

	/**
	 * Adds a "Draws" column to the templates list, so the assignment is
	 * visible without opening each one.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_type_column( $columns ) {
		$new = array();

		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;

			if ( 'title' === $key ) {
				$new['citykleta_template_type'] = 'Draws';
			}
		}

		return $new;
	}

	/**
	 * Prints the "Draws" column value.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Template ID.
	 */
	public function render_type_column( $column, $post_id ) {
		if ( 'citykleta_template_type' !== $column ) {
			return;
		}

		$type = get_post_meta( $post_id, self::META_TYPE, true );

		if ( ! $type || ! isset( self::TYPES[ $type ] ) ) {
			echo '<span style="color:#b32d2e;">— Not assigned —</span>';
			return;
		}

		echo esc_html( self::TYPES[ $type ] );

		if ( 'publish' !== get_post_status( $post_id ) ) {
			echo ' <em style="color:#996800;">(draft — not in use)</em>';
		}
	}

	/**
	 * The published template assigned to a view, if any.
	 *
	 * @param string $type A key of self::TYPES.
	 * @return WP_Post|null
	 */
	public static function get_template( $type ) {
		$found = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'meta_key'       => self::META_TYPE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $type, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		return $found ? $found[0] : null;
	}

	/**
	 * Creates the template for a view if none exists yet, with the default
	 * layout as its content. Idempotent: it only ever fills a gap, and never
	 * touches a template that already exists (so a redesign is never
	 * overwritten).
	 *
	 * @param string     $type      A key of self::TYPES.
	 * @param string     $title     Title for the template if it has to be created.
	 * @param string     $content   Default layout as shortcodes — what renders
	 *                              if Elementor is ever deactivated.
	 * @param array|null $elementor Default layout as real Elementor elements,
	 *                              so the editor opens on a working design
	 *                              instead of a blank canvas.
	 * @return WP_Post|null
	 */
	public static function ensure_template( $type, $title, $content, $elementor = null ) {
		$existing = self::get_template( $type );

		if ( $existing ) {
			// Templates created before the Elementor layout existed came out
			// holding only shortcode text, so Elementor opened on an empty
			// canvas. Seed them — but ONLY while untouched: the moment
			// _elementor_data exists, that's the user's design and nothing
			// here may overwrite it.
			if ( $elementor && ! get_post_meta( $existing->ID, '_elementor_data', true ) ) {
				self::seed_elementor_layout( $existing->ID, $elementor );
			}

			return $existing;
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => self::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => $content,
			),
			true
		);

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return null;
		}

		update_post_meta( $post_id, self::META_TYPE, $type );

		if ( $elementor ) {
			self::seed_elementor_layout( $post_id, $elementor );
		}

		return get_post( $post_id );
	}

	/**
	 * Writes an Elementor design onto a template, the same way Elementor
	 * itself stores one (see its Core\Base\Document meta keys).
	 *
	 * `_elementor_data` is a JSON *string*, and update_post_meta() unslashes
	 * whatever it's given — hence wp_slash(), same as Elementor's own
	 * Document::update_json_meta().
	 *
	 * @param int   $post_id  Template ID.
	 * @param array $elements Elementor element tree.
	 */
	private static function seed_elementor_layout( $post_id, array $elements ) {
		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $post_id, '_elementor_template_type', 'wp-post' );
		update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $elements ) ) );

		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			update_post_meta( $post_id, '_elementor_version', ELEMENTOR_VERSION );
		}
	}

	/**
	 * One Elementor section wrapping the given columns.
	 *
	 * @param array $columns  Column elements.
	 * @param array $settings Section settings.
	 * @return array
	 */
	public static function el_section( array $columns, array $settings = array() ) {
		return array(
			'id'       => self::el_id(),
			'elType'   => 'section',
			'settings' => (object) $settings,
			'elements' => $columns,
			'isInner'  => false,
		);
	}

	/**
	 * One Elementor column.
	 *
	 * @param int   $width    Percentage of the row this column takes.
	 * @param array $widgets  Widget elements inside it.
	 * @return array
	 */
	public static function el_column( $width, array $widgets ) {
		return array(
			'id'       => self::el_id(),
			'elType'   => 'column',
			'settings' => (object) array(
				'_column_size' => (int) $width,
				'_inline_size' => (int) $width,
			),
			'elements' => $widgets,
			'isInner'  => false,
		);
	}

	/**
	 * One Elementor widget.
	 *
	 * @param string $widget_type The widget's get_name().
	 * @param array  $settings    Any non-default settings.
	 * @return array
	 */
	public static function el_widget( $widget_type, array $settings = array() ) {
		return array(
			'id'         => self::el_id(),
			'elType'     => 'widget',
			'widgetType' => $widget_type,
			'settings'   => (object) $settings,
			'elements'   => array(),
		);
	}

	/**
	 * An Elementor-style element id: 7 hex characters, unique per element.
	 *
	 * @return string
	 */
	private static function el_id() {
		return substr( md5( uniqid( '', true ) ), 0, 7 );
	}

	/**
	 * Prints a template's layout, with the given post as the "current" one
	 * so Elementor (and any other the_content filter) renders THIS layout.
	 *
	 * The bike/query the layout is about is NOT passed here on purpose: the
	 * widgets inside resolve it themselves from the main query
	 * (Citykleta_Bike_Part_Widget::bike_id()), which is what lets one layout
	 * serve every bike.
	 *
	 * @param WP_Post $template Template to print.
	 */
	public static function render_template( WP_Post $template ) {
		global $post;

		$original = $post;
		$post     = $template; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $post );

		echo apply_filters( 'the_content', $template->post_content ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		wp_reset_postdata();
		$post = $original; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}
}
