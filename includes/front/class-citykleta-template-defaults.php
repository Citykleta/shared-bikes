<?php
/**
 * Capturing a finished Elementor design as the layout the plugin ships with.
 *
 * The default layouts (Citykleta_Templates::default_*_layout()) started as
 * element trees written by hand in PHP. That is fine for a plain starting
 * point, but the real design is made in Elementor — so this class lets that
 * design be frozen into the plugin: "Mark as ready" copies a template's
 * _elementor_data into includes/front/layouts/{view}.json, and from then on
 * that file IS the default a fresh install is seeded with.
 *
 * **The write only exists on a development machine.** wp_get_environment_type()
 * has to say 'local' or 'development'; WordPress defaults it to 'production'
 * when nothing declares otherwise, so the gate fails closed. There is no
 * filter on that function (deliberately, in core) and no admin setting for it
 * — changing it takes file access to wp-config.php or the server env.
 *
 * The captured layout is stored as JSON and never as generated PHP: it is read
 * back with json_decode(), so nothing in that file can ever be executed.
 *
 * @package Citykleta_Shared_Bikes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Citykleta_Template_Defaults.
 */
class Citykleta_Template_Defaults {

	/**
	 * Where captured layouts live, relative to the plugin root.
	 */
	const DIR = 'includes/front/layouts/';

	/**
	 * admin-post.php actions behind the two row links.
	 */
	const ACTION_MARK  = 'citykleta_mark_ready';
	const ACTION_RESET = 'citykleta_reset_default';

	/**
	 * Query arg carrying the result of a single action back to the list.
	 */
	const NOTICE_ARG = 'citykleta_default_msg';

	/**
	 * Query args carrying the tally of a bulk run back to the list.
	 */
	const BULK_SAVED   = 'citykleta_saved';
	const BULK_SKIPPED = 'citykleta_skipped';

	/**
	 * Connects the class to WordPress hooks.
	 */
	public function register() {
		// layout() is always available — it is what the front end reads. Only
		// the UI that WRITES into the plugin folder is gated to development.
		if ( ! self::is_dev() ) {
			return;
		}

		add_filter( 'post_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_filter( 'manage_' . Citykleta_Template_Post_Type::POST_TYPE . '_posts_columns', array( $this, 'add_ready_column' ) );
		add_action( 'manage_' . Citykleta_Template_Post_Type::POST_TYPE . '_posts_custom_column', array( $this, 'render_ready_column' ), 10, 2 );
		add_filter( 'bulk_actions-edit-' . Citykleta_Template_Post_Type::POST_TYPE, array( $this, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-' . Citykleta_Template_Post_Type::POST_TYPE, array( $this, 'handle_bulk_action' ), 10, 3 );
		add_action( 'admin_post_' . self::ACTION_MARK, array( $this, 'handle_mark_ready' ) );
		add_action( 'admin_post_' . self::ACTION_RESET, array( $this, 'handle_reset' ) );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
	}

	/**
	 * Whether this install is a development one, and may therefore write a
	 * captured layout into the plugin's own folder.
	 *
	 * 'staging' is deliberately not enough: a staging site has no business
	 * editing what the plugin ships with.
	 *
	 * @return bool
	 */
	public static function is_dev() {
		if ( ! function_exists( 'wp_get_environment_type' ) ) {
			// WordPress < 5.5 cannot tell us, so assume the worst.
			return false;
		}

		return in_array( wp_get_environment_type(), array( 'local', 'development' ), true );
	}

	/**
	 * The captured layout for a view, if one has been marked ready.
	 *
	 * @param string $type A key of Citykleta_Template_Post_Type::TYPES.
	 * @return array Elementor element tree, or an empty array.
	 */
	public static function layout( $type ) {
		$data = self::read( $type );

		if ( ! $data || empty( $data['elements'] ) || ! is_array( $data['elements'] ) ) {
			return array();
		}

		return $data['elements'];
	}

	/**
	 * When the layout for a view was last marked ready.
	 *
	 * @param string $type A key of Citykleta_Template_Post_Type::TYPES.
	 * @return string Date as captured, or '' if there is no captured layout.
	 */
	public static function captured_at( $type ) {
		$data = self::read( $type );

		return $data && ! empty( $data['captured_at'] ) ? (string) $data['captured_at'] : '';
	}

	/**
	 * Reads and decodes a captured layout file.
	 *
	 * @param string $type A key of Citykleta_Template_Post_Type::TYPES.
	 * @return array|null
	 */
	private static function read( $type ) {
		$file = self::file( $type );

		if ( ! $file || ! file_exists( $file ) ) {
			return null;
		}

		$raw = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( ! $raw ) {
			return null;
		}

		$data = json_decode( $raw, true );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Absolute path of the file holding a view's captured layout.
	 *
	 * The view key is validated against TYPES rather than sanitised, so
	 * nothing outside that fixed list can ever name a file.
	 *
	 * @param string $type A key of Citykleta_Template_Post_Type::TYPES.
	 * @return string Empty when the view is unknown.
	 */
	private static function file( $type ) {
		if ( ! $type || ! array_key_exists( $type, Citykleta_Template_Post_Type::TYPES ) ) {
			return '';
		}

		return CITYKLETA_PATH . self::DIR . str_replace( '_', '-', $type ) . '.json';
	}

	/**
	 * Adds the two links to a template's row.
	 *
	 * @param array   $actions Existing row actions.
	 * @param WP_Post $post    The row's post.
	 * @return array
	 */
	public function row_actions( $actions, $post ) {
		if ( Citykleta_Template_Post_Type::POST_TYPE !== $post->post_type || ! current_user_can( 'manage_options' ) ) {
			return $actions;
		}

		$type = get_post_meta( $post->ID, Citykleta_Template_Post_Type::META_TYPE, true );

		if ( ! $type || ! array_key_exists( $type, Citykleta_Template_Post_Type::TYPES ) ) {
			return $actions;
		}

		$captured = self::captured_at( $type );

		// The label is short on purpose; what it actually does is spelled out
		// here, where there is room for it.
		$confirm = $captured
			? sprintf( 'Save this design as the layout the plugin ships with? It replaces the one captured on %s.', $captured )
			: 'Save this design as the layout the plugin ships with?';

		$actions[ self::ACTION_MARK ]  = self::action_link( self::ACTION_MARK, $post->ID, 'Mark as ready', $confirm );
		$actions[ self::ACTION_RESET ] = self::action_link(
			self::ACTION_RESET,
			$post->ID,
			'Reset to default',
			'Discard this design and rebuild the template from the plugin default? This cannot be undone.'
		);

		return $actions;
	}

	/**
	 * One row-action link, with its nonce and its confirmation.
	 *
	 * @param string $action  One of the ACTION_* constants.
	 * @param int    $post_id Template ID.
	 * @param string $label   Link text.
	 * @param string $confirm Text of the confirmation dialog.
	 * @return string
	 */
	private static function action_link( $action, $post_id, $label, $confirm ) {
		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action'   => $action,
					'template' => $post_id,
				),
				admin_url( 'admin-post.php' )
			),
			$action . '_' . $post_id
		);

		return sprintf(
			'<a href="%1$s" onclick="return confirm(%2$s);">%3$s</a>',
			esc_url( $url ),
			esc_attr( wp_json_encode( $confirm ) ),
			esc_html( $label )
		);
	}

	/**
	 * Adds the "Ready" column, right after the one saying what the template
	 * draws.
	 *
	 * Added from here rather than from the post type so the whole feature —
	 * column included — stays inside this class and disappears with it on a
	 * production install.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_ready_column( $columns ) {
		$new = array();

		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;

			if ( 'citykleta_template_type' === $key ) {
				$new['citykleta_template_ready'] = 'Ready';
			}
		}

		// The "Draws" column is added by the post type; if that ever stops
		// being the case, still show this one rather than dropping it.
		if ( ! isset( $new['citykleta_template_ready'] ) ) {
			$new['citykleta_template_ready'] = 'Ready';
		}

		return $new;
	}

	/**
	 * Prints the capture state: never captured, captured, or captured and
	 * since edited — which is the one that says "press the button".
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Template ID.
	 */
	public function render_ready_column( $column, $post_id ) {
		if ( 'citykleta_template_ready' !== $column ) {
			return;
		}

		$type = get_post_meta( $post_id, Citykleta_Template_Post_Type::META_TYPE, true );

		if ( ! $type || ! array_key_exists( $type, Citykleta_Template_Post_Type::TYPES ) ) {
			echo '<span style="color:#646970;">—</span>';
			return;
		}

		$captured = self::captured_at( $type );

		if ( ! $captured ) {
			echo '<span style="color:#646970;">Not captured</span>';
			return;
		}

		echo esc_html( $captured );

		if ( ! self::matches_capture( $post_id, $type ) ) {
			echo '<br><em style="color:#996800;">Edited since</em>';
		}
	}

	/**
	 * Whether a template's current Elementor design is exactly what was
	 * captured for its view.
	 *
	 * Both sides are decoded and re-encoded before comparing, so the answer
	 * is about structure and not about how the JSON happens to be spelled.
	 *
	 * @param int    $post_id Template ID.
	 * @param string $type    A key of Citykleta_Template_Post_Type::TYPES.
	 * @return bool
	 */
	private static function matches_capture( $post_id, $type ) {
		$data = self::read( $type );

		if ( ! $data || ! isset( $data['elements'] ) ) {
			return false;
		}

		$raw     = get_post_meta( $post_id, '_elementor_data', true );
		$current = $raw ? json_decode( $raw, true ) : null;

		if ( ! is_array( $current ) ) {
			return false;
		}

		return wp_json_encode( $current ) === wp_json_encode( $data['elements'] );
	}

	/**
	 * "Mark as ready": copies the template's Elementor design into the plugin.
	 */
	public function handle_mark_ready() {
		$post_id = $this->requested_template( self::ACTION_MARK );

		$this->back( self::capture( $post_id ) );
	}

	/**
	 * Writes one template's Elementor design into the plugin as the default
	 * for its view.
	 *
	 * Shared by the row link and the bulk action so both take exactly the same
	 * path, including which cases are refused.
	 *
	 * @param int $post_id Template ID.
	 * @return string Outcome key understood by render_notice().
	 */
	private static function capture( $post_id ) {
		$type = get_post_meta( $post_id, Citykleta_Template_Post_Type::META_TYPE, true );
		$file = self::file( $type );

		if ( ! $file ) {
			return 'no-type';
		}

		$raw      = get_post_meta( $post_id, '_elementor_data', true );
		$elements = $raw ? json_decode( $raw, true ) : null;

		if ( ! is_array( $elements ) || ! $elements ) {
			return 'no-design';
		}

		$dir = dirname( $file );

		if ( ! wp_mkdir_p( $dir ) || ! wp_is_writable( $dir ) ) {
			return 'not-writable';
		}

		$payload = array(
			'captured_at'    => current_time( 'Y-m-d H:i' ),
			'plugin_version' => CITYKLETA_VERSION,
			'elements'       => $elements,
		);

		// Pretty-printed on purpose: this file is committed, and a single-line
		// blob would make every capture look like "the whole file changed".
		$json = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		$written = file_put_contents( $file, $json . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		return false === $written ? 'not-writable' : 'saved';
	}

	/**
	 * Offers "Mark as ready" in the bulk-actions dropdown.
	 *
	 * "Reset to default" is deliberately NOT offered in bulk: throwing away
	 * several designs is too easy to do by accident from a dropdown, and
	 * unlike a capture it cannot be recovered from git.
	 *
	 * @param array $actions Existing bulk actions.
	 * @return array
	 */
	public function bulk_actions( $actions ) {
		$actions[ self::ACTION_MARK ] = 'Mark as ready';

		return $actions;
	}

	/**
	 * Runs "Mark as ready" over every selected template.
	 *
	 * Templates that cannot be captured (no view assigned, no Elementor design
	 * yet) are counted and skipped rather than aborting the whole run — one
	 * unfinished template shouldn't stop the others being saved.
	 *
	 * @param string $redirect Where the list screen is about to go.
	 * @param string $doaction The chosen bulk action.
	 * @param array  $post_ids Selected IDs.
	 * @return string
	 */
	public function handle_bulk_action( $redirect, $doaction, $post_ids ) {
		if ( self::ACTION_MARK !== $doaction ) {
			return $redirect;
		}

		// The bulk nonce is checked by core before this filter runs; the
		// environment and capability are ours to enforce.
		if ( ! self::is_dev() || ! current_user_can( 'manage_options' ) ) {
			return $redirect;
		}

		$saved   = 0;
		$skipped = 0;

		foreach ( $post_ids as $post_id ) {
			if ( Citykleta_Template_Post_Type::POST_TYPE !== get_post_type( $post_id ) ) {
				continue;
			}

			if ( 'saved' === self::capture( (int) $post_id ) ) {
				++$saved;
			} else {
				++$skipped;
			}
		}

		return add_query_arg(
			array(
				self::BULK_SAVED   => $saved,
				self::BULK_SKIPPED => $skipped,
			),
			$redirect
		);
	}

	/**
	 * "Reset to default": throws the current design away and re-seeds the
	 * template from whatever the plugin currently ships with.
	 */
	public function handle_reset() {
		$post_id = $this->requested_template( self::ACTION_RESET );
		$type    = get_post_meta( $post_id, Citykleta_Template_Post_Type::META_TYPE, true );
		$layout  = Citykleta_Templates::default_layout( $type );

		if ( ! $layout ) {
			$this->back( 'no-type' );
		}

		Citykleta_Template_Post_Type::seed_elementor_layout( $post_id, $layout );

		$this->back( 'reset' );
	}

	/**
	 * Shared entry check for both actions: nonce, capability, environment.
	 *
	 * @param string $action One of the ACTION_* constants.
	 * @return int Template ID.
	 */
	private function requested_template( $action ) {
		$post_id = isset( $_GET['template'] ) ? absint( $_GET['template'] ) : 0;

		check_admin_referer( $action . '_' . $post_id );

		// Re-checked here and not only when drawing the link: the URL can be
		// typed, and the environment gate is the whole point of this feature.
		if ( ! self::is_dev() || ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}

		if ( ! $post_id || Citykleta_Template_Post_Type::POST_TYPE !== get_post_type( $post_id ) ) {
			wp_die( 'Not a template.' );
		}

		return $post_id;
	}

	/**
	 * Back to the templates list, carrying the outcome.
	 *
	 * @param string $message Key understood by render_notice().
	 */
	private function back( $message ) {
		wp_safe_redirect(
			add_query_arg(
				self::NOTICE_ARG,
				$message,
				admin_url( 'edit.php?post_type=' . Citykleta_Template_Post_Type::POST_TYPE )
			)
		);
		exit;
	}

	/**
	 * Prints the outcome of the last action.
	 */
	public function render_notice() {
		if ( isset( $_GET[ self::BULK_SAVED ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->render_bulk_notice();
			return;
		}

		if ( empty( $_GET[ self::NOTICE_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$key = sanitize_key( wp_unslash( $_GET[ self::NOTICE_ARG ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$messages = array(
			'saved'        => array( 'success', 'Design saved as the plugin default, in ' . self::DIR . '. It is a change in your working copy now — review the diff and commit it.' ),
			'reset'        => array( 'success', 'Template rebuilt from the plugin default. If the styling still looks like the old design, regenerate the CSS from Elementor → Tools.' ),
			'no-type'      => array( 'error', 'This template is not assigned to a view yet — pick one in the "Draws" box first.' ),
			'no-design'    => array( 'error', 'This template has no Elementor design to capture. Open it in Elementor, save it, and try again.' ),
			'not-writable' => array( 'error', 'Could not write to ' . self::DIR . ' inside the plugin. Check the folder permissions.' ),
		);

		if ( ! isset( $messages[ $key ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $messages[ $key ][0] ),
			esc_html( $messages[ $key ][1] )
		);
	}

	/**
	 * Prints the tally of a bulk "Mark as ready" run.
	 */
	private function render_bulk_notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$saved   = absint( wp_unslash( $_GET[ self::BULK_SAVED ] ) );
		$skipped = isset( $_GET[ self::BULK_SKIPPED ] ) ? absint( wp_unslash( $_GET[ self::BULK_SKIPPED ] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$message = sprintf(
			'Saved %1$d of %2$d selected templates as the plugin default.',
			$saved,
			$saved + $skipped
		);

		if ( $skipped ) {
			$message .= sprintf(
				' Skipped %d: no view assigned, or no Elementor design to capture yet.',
				$skipped
			);
		}

		if ( $saved ) {
			$message .= ' Review the diff in ' . self::DIR . ' and commit it.';
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			$saved ? 'success' : 'warning',
			esc_html( $message )
		);
	}
}
