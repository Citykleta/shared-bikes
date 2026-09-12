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
 * (self::TYPES). A draft is a safe place to try a redesign without it going
 * live, since only a published template is ever used.
 *
 * **Several templates may draw the same view, and one of them is active.**
 * The two metas answer different questions on purpose: META_TYPE says what a
 * template is FOR (and it has to stay, because Citykleta_Template_Defaults
 * needs it to know which layout file a capture belongs in), while the
 * self::ACTIVE_OPTION option says which of those is the one actually used.
 * Picking it is one dropdown per view in Bicicletas → Settings → Templates,
 * so "which layout is my site using?" is answered on one screen instead of by
 * opening templates one by one.
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
	 * Option holding the active template per view: view key => template ID.
	 *
	 * Absent (or pointing at a template that was deleted, unpublished, or
	 * reassigned to another view) means "fall back to the first published
	 * template of that view" — see self::get_template(). That fallback is
	 * what every install had before this option existed, so nothing has to be
	 * chosen here for the plugin to work: ensure_template() keeps creating
	 * the default layout on its own.
	 */
	const ACTIVE_OPTION = 'citykleta_active_templates';

	/**
	 * The views a template can be assigned to, and the label shown in
	 * wp-admin. Adding a new front-end view = one entry here plus the
	 * template file that calls self::content_for().
	 */
	const TYPES = array(
		'results'     => 'Bike results (/bikes/)',
		'single_bike' => 'Single bike page',
		'destination' => 'Destination page',
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
				// show_in_rest => the normal WordPress block editor works
				// here, like on any other post type. 'elementor' only adds
				// the "Edit with Elementor" button as an OPTION — the
				// plugin never turns a template into an Elementor document
				// on its own.
				'show_in_rest'        => true,
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

		echo '<p class="description">The front-end view this layout is <em>for</em>. Which of that view\'s templates is actually used is picked under Settings → <strong>Templates</strong>; only a <strong>published</strong> one can be, so a draft is a safe place to try a redesign.</p>';

		if ( 'publish' === get_post_status( $post->ID ) && $current && self::is_active( $post->ID ) ) {
			echo '<p class="description" style="color:#00733c;"><strong>This is the active template for that view.</strong></p>';
		}
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

		/*
		 * The same state the Templates tab of Settings shows, repeated here
		 * on purpose: with more than one template per view, "is this the one
		 * the site is using?" is the first thing you ask while looking at
		 * this list, and having to leave it to find out defeats the column.
		 */
		if ( 'publish' !== get_post_status( $post_id ) ) {
			echo ' <em style="color:#996800;">— Inactive (draft)</em>';
			return;
		}

		if ( self::is_active( $post_id ) ) {
			echo ' <strong style="color:#00733c;">— Active</strong>';
			return;
		}

		echo ' <span style="color:#646970;">— Inactive</span>';
	}

	/**
	 * Every published template assigned to a view, oldest first. This is the
	 * list the Settings dropdown offers, and its first entry is the fallback
	 * when no template has been picked.
	 *
	 * @param string $type A key of self::TYPES.
	 * @return WP_Post[]
	 */
	public static function templates_for( $type ) {
		if ( ! array_key_exists( $type, self::TYPES ) ) {
			return array();
		}

		return get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'meta_key'       => self::META_TYPE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $type, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
	}

	/**
	 * The template ID picked for a view in Settings, as stored — not
	 * validated, so callers that need a usable template go through
	 * self::get_template() instead. Only the Settings screen wants the raw
	 * value, to show the current selection even when it no longer resolves.
	 *
	 * @param string $type A key of self::TYPES.
	 * @return int 0 when nothing is picked.
	 */
	public static function active_id( $type ) {
		$active = get_option( self::ACTIVE_OPTION, array() );

		if ( ! is_array( $active ) || empty( $active[ $type ] ) ) {
			return 0;
		}

		return (int) $active[ $type ];
	}

	/**
	 * The template a view is actually drawn with: the one picked in Settings
	 * when that pick still resolves, else the first published template of
	 * that view.
	 *
	 * The pick is re-validated on every call rather than trusted, because
	 * nothing stops the chosen template from being deleted, moved to draft or
	 * reassigned to another view after it was selected — and a stale ID here
	 * would mean a blank page, which is the one outcome worth ruling out.
	 *
	 * @param string $type A key of self::TYPES.
	 * @return WP_Post|null
	 */
	public static function get_template( $type ) {
		$chosen = self::active_id( $type );

		if ( $chosen ) {
			$post = get_post( $chosen );

			if ( $post
				&& self::POST_TYPE === $post->post_type
				&& 'publish' === $post->post_status
				&& $type === get_post_meta( $post->ID, self::META_TYPE, true ) ) {
				return $post;
			}
		}

		$found = self::templates_for( $type );

		return $found ? $found[0] : null;
	}

	/**
	 * Whether this template is the one its view is currently drawn with.
	 *
	 * @param int $post_id Template ID.
	 * @return bool
	 */
	public static function is_active( $post_id ) {
		$type = get_post_meta( $post_id, self::META_TYPE, true );

		if ( ! $type || ! array_key_exists( $type, self::TYPES ) ) {
			return false;
		}

		$active = self::get_template( $type );

		return $active && (int) $active->ID === (int) $post_id;
	}

	/**
	 * Sanitizes the Settings dropdowns before they're stored: only known view
	 * keys survive, and only as positive integers. An empty pick is dropped
	 * rather than stored as 0, so "nothing chosen" has exactly one
	 * representation.
	 *
	 * @param mixed $value Raw option value posted by the form.
	 * @return array
	 */
	public static function sanitize_active( $value ) {
		$clean = array();

		if ( ! is_array( $value ) ) {
			return $clean;
		}

		foreach ( array_keys( self::TYPES ) as $type ) {
			if ( empty( $value[ $type ] ) ) {
				continue;
			}

			$id = absint( $value[ $type ] );

			if ( $id ) {
				$clean[ $type ] = $id;
			}
		}

		return $clean;
	}

	/**
	 * Creates the template for a view if none exists yet, with the default
	 * layout as its content. Idempotent: it only ever fills a gap, and never
	 * touches a template that already exists (so a redesign is never
	 * overwritten).
	 *
	 * @param string $type    A key of self::TYPES.
	 * @param string $title   Title for the template if it has to be created.
	 * @param string $content Default layout, as normal WordPress block markup.
	 * @param array  $legacy  Older default layouts this one replaces. An
	 *                        existing template still holding one of these
	 *                        verbatim was never edited, so it's brought up to
	 *                        date; anything else is somebody's work and is
	 *                        left untouched.
	 * @param array  $layout  Default layout as Elementor elements, so the
	 *                        template opens on a working design instead of an
	 *                        empty canvas. Only ever written to a template
	 *                        that has no Elementor design yet.
	 * @return WP_Post|null
	 */
	public static function ensure_template( $type, $title, $content, array $legacy = array(), array $layout = array() ) {
		$existing = self::get_template( $type );

		if ( $existing ) {
			if ( $layout && ! get_post_meta( $existing->ID, '_elementor_data', true ) ) {
				self::seed_elementor_layout( $existing->ID, $layout );
			}

			if ( $legacy && in_array( trim( $existing->post_content ), array_map( 'trim', $legacy ), true ) ) {
				wp_update_post(
					array(
						'ID'           => $existing->ID,
						'post_content' => $content,
					)
				);

				$existing = get_post( $existing->ID );
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

		if ( $layout ) {
			self::seed_elementor_layout( $post_id, $layout );
		}

		return get_post( $post_id );
	}

	/**
	 * Writes an Elementor design onto a template, the way Elementor itself
	 * stores one (its Core\Base\Document meta keys).
	 *
	 * `_elementor_data` is a JSON *string*, and update_post_meta() unslashes
	 * whatever it's handed — hence wp_slash(), same as Elementor's own
	 * Document::update_json_meta().
	 *
	 * Seeding only ever happens on a template with no design yet (see
	 * ensure_template()): from then on the layout belongs to whoever edited
	 * it. The one other caller is the explicit "Reset to default" action in
	 * Citykleta_Template_Defaults, which is a deliberate throw-away.
	 *
	 * @param int   $post_id  Template ID.
	 * @param array $elements Elementor element tree.
	 */
	public static function seed_elementor_layout( $post_id, array $elements ) {
		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $post_id, '_elementor_template_type', 'wp-post' );
		update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $elements ) ) );

		/*
		 * Everything Elementor derives from the data above has to go with it.
		 * Elementor invalidates these itself when the change comes through its
		 * own editor; a write straight to the meta, like this one, does not.
		 *
		 * _elementor_element_cache is the nastiest of the three because it
		 * caches rendered HTML: leave it behind and the page shows the OLD
		 * layout while the freshly generated CSS describes the new one — the
		 * design looks stale for no visible reason, and the element ids on the
		 * page are the only clue that the two disagree.
		 *
		 * On _elementor_css, note it is the META that gets dropped: never the
		 * generated .css FILE, which would leave the page pointing at a
		 * stylesheet that 404s.
		 */
		delete_post_meta( $post_id, '_elementor_css' );
		delete_post_meta( $post_id, '_elementor_element_cache' );
		delete_post_meta( $post_id, '_elementor_page_assets' );

		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			update_post_meta( $post_id, '_elementor_version', ELEMENTOR_VERSION );
		}
	}

	/**
	 * One Elementor Container.
	 *
	 * Container — not the old Section/Column pair. Elementor 3.16 made
	 * containers the way layouts are built, and section.php only survives so
	 * existing content keeps rendering; seeding sections would hand a brand
	 * new template a deprecated structure on day one.
	 *
	 * The settings that matter (from includes/elements/container.php):
	 * `content_width` is 'boxed' or 'full'; the `width` slider only applies
	 * while it's 'full'; direction comes from the flex group, so the key is
	 * `flex_direction` ('row' / 'column').
	 *
	 * @param array $children Widgets or nested containers.
	 * @param array $settings Container settings.
	 * @param bool  $inner    True for a container nested inside another.
	 * @return array
	 */
	public static function container( array $children, array $settings = array(), $inner = false ) {
		return array(
			'id'       => self::element_id(),
			'elType'   => 'container',
			'settings' => (object) $settings,
			'elements' => $children,
			'isInner'  => (bool) $inner,
		);
	}

	/**
	 * A container acting as a column: fixed share of the row's width.
	 *
	 * @param int   $percent  Share of the row, in per cent.
	 * @param array $widgets  Widgets inside it.
	 * @return array
	 */
	public static function column( $percent, array $widgets ) {
		return self::container(
			$widgets,
			array(
				// 'full' because the width below only applies in that mode —
				// 'boxed' would re-box the column inside the already-boxed row.
				'content_width'  => 'full',
				'width'          => array(
					'unit' => '%',
					'size' => (int) $percent,
				),
				'flex_direction' => 'column',
			),
			true
		);
	}

	/**
	 * One Elementor widget.
	 *
	 * @param string $widget_type The widget's get_name().
	 * @param array  $settings    Any non-default settings.
	 * @return array
	 */
	public static function widget( $widget_type, array $settings = array() ) {
		return array(
			'id'         => self::element_id(),
			'elType'     => 'widget',
			'widgetType' => $widget_type,
			'settings'   => (object) $settings,
			'elements'   => array(),
		);
	}

	/**
	 * Elementor's own Heading widget, used for the static section titles.
	 *
	 * The plugin's pieces deliberately print no headings: that text never
	 * changes from one bike to the next, so there is no reason for it to live
	 * inside a widget of ours where it can only be edited through a control we
	 * had to write. As a plain Heading it is styled, moved, retitled or
	 * deleted like any other element on the canvas.
	 *
	 * @param string $text Title text.
	 * @param string $tag  Heading level.
	 * @return array
	 */
	public static function heading( $text, $tag = 'h2' ) {
		return self::widget(
			'heading',
			array(
				'title'       => $text,
				'header_size' => $tag,
			)
		);
	}

	/**
	 * An Elementor-style element id: 7 hex characters, unique per element.
	 *
	 * @return string
	 */
	private static function element_id() {
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

		$elementor = self::elementor_html( $template );

		if ( null !== $elementor ) {
			echo $elementor; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}

		// Only the fallback needs the global post pointed at the template, so
		// that the_content's filters run against the template's own content.
		$original = $post;
		$post     = $template; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $post );

		echo apply_filters( 'the_content', $template->post_content ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		wp_reset_postdata();
		$post = $original; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/**
	 * The template's markup: its Elementor design when it has one, else its
	 * ordinary WordPress content.
	 *
	 * **Elementor has to be asked directly.** Running the layout through
	 * apply_filters( 'the_content', … ) does NOT produce the Elementor design:
	 * Elementor only swaps content for the builder output of the post the main
	 * query is on, and here that is the *bike*, never the template. The filter
	 * therefore returned the raw post_content — the block/shortcode fallback —
	 * so every piece still appeared and the page looked right, while the design
	 * made in Elementor was silently ignored. It was only visible by comparing
	 * element ids: those on the page were freshly generated by the shortcodes,
	 * not the ones stored in _elementor_data.
	 *
	 * get_builder_content_for_display() is the supported way to render a
	 * document that is not the queried post (it is what Elementor's own theme
	 * locations use), and asking for its CSS matters for the same reason: no
	 * other part of the page will have enqueued the stylesheet of a document
	 * WordPress never knew it was showing.
	 *
	 * **And it must NOT be called with the global $post pointed at the
	 * template.** Elementor treats "the post being rendered is the document I
	 * was asked for" as recursion and returns an empty string — silently, so
	 * the caller just falls through to the fallback and everything keeps
	 * looking almost right. That is why render_template() only switches the
	 * global post for the fallback branch, never for this one; the pieces
	 * don't need it either, since they resolve their bike from the query.
	 *
	 * @param WP_Post $template Template to render.
	 * @return string|null Null when the template has no usable Elementor design.
	 */
	private static function elementor_html( WP_Post $template ) {
		if ( ! class_exists( '\Elementor\Plugin' ) || ! get_post_meta( $template->ID, '_elementor_data', true ) ) {
			return null;
		}

		$html = \Elementor\Plugin::$instance->frontend->get_builder_content_for_display( $template->ID, true );

		return trim( (string) $html ) ? $html : null;
	}
}
