<?php
/**
 * Map of every bike that has coordinates, shown at the top of the "All Bikes"
 * list screen in wp-admin, so the team sees at a glance where bikes are
 * without leaving the list they already use daily.
 *
 * Used to be its own "Bikes Map" submenu page; fused into the list screen
 * instead (2026) so there's one bikes view, not two half-used ones.
 *
 * @package Citykleta_Shared_Bikes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Citykleta_Admin_Map.
 */
class Citykleta_Admin_Map {

	/**
	 * User meta: whether this user wants the map shown ('1') or hidden ('0')
	 * on the bike list. Missing meta means "show it" (see is_visible_for_user()).
	 */
	const META_VISIBLE = 'citykleta_admin_map_visible';

	/**
	 * Name of the hidden marker field in the Screen Options form — its
	 * presence in $_POST is how save_visibility_preference() knows the
	 * submitted form was this screen's Screen Options panel, not some other
	 * page's (see that method's docblock).
	 */
	const SCREEN_MARKER_FIELD = 'citykleta_admin_map_screen';

	/**
	 * Connects the class to WordPress hooks.
	 */
	public function register() {
		add_action( 'admin_notices', array( $this, 'render_map' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'screen_settings', array( $this, 'render_visibility_toggle' ), 10, 2 );
		// Try to save as early as possible — wp_footer in admin is late in the process
		// but earlier hooks may not have valid state. Catch it at the very start.
		add_action( 'wp_loaded', array( $this, 'save_visibility_preference' ), 1 );
	}

	/**
	 * Whether the current admin screen is the bike list ("All Bikes").
	 *
	 * @return bool
	 */
	private function is_bike_list_screen() {
		$screen = get_current_screen();

		return $screen && 'edit' === $screen->base && Citykleta_Bike_Post_Type::POST_TYPE === $screen->post_type;
	}

	/**
	 * Whether the current user has the map shown. Defaults to shown (missing
	 * meta = '1') so nobody loses the map just because they never touched the
	 * new toggle.
	 *
	 * @return bool
	 */
	private function is_visible_for_user() {
		$value = get_user_meta( get_current_user_id(), self::META_VISIBLE, true );

		return '0' !== $value;
	}

	/**
	 * Adds the "Show bikes map" checkbox to the Screen Options panel (the
	 * "Columns" / "Pagination" / "View mode" panel opened from the
	 * "Screen Options" tab), so hiding the map is a per-user preference next
	 * to the list's other display settings, not a separate control.
	 *
	 * @param string    $settings Existing screen_settings HTML from other
	 *                            plugins/core (empty on this screen so far).
	 * @param WP_Screen $screen   Screen the panel is being rendered for.
	 * @return string
	 */
	public function render_visibility_toggle( $settings, $screen ) {
		if ( 'edit' !== $screen->base || Citykleta_Bike_Post_Type::POST_TYPE !== $screen->post_type ) {
			return $settings;
		}

		$checked = $this->is_visible_for_user() ? ' checked="checked"' : '';

		$settings .= '<h5>Bikes map</h5>';
		$settings .= '<input type="hidden" name="' . esc_attr( self::SCREEN_MARKER_FIELD ) . '" value="1" />';
		$settings .= '<label><input type="checkbox" name="citykleta_show_map" value="1"' . $checked . ' /> Show bikes map above the list</label>';

		return $settings;
	}

	/**
	 * Saves the map-visibility checkbox when the Screen Options form is
	 * submitted (the "Apply" button there does a normal POST back to the same
	 * page, reusing the same 'screenoptionnonce' nonce WordPress uses for its
	 * own per-page/view-mode fields — see wp_screen_options() in
	 * wp-admin/includes/misc.php for the core equivalent this mirrors).
	 *
	 * Hooked to 'admin_init' rather than checked inside render_map() itself
	 * because get_current_screen() isn't reliable yet at 'admin_init' (core
	 * calls set_current_screen() *after* firing 'admin_init', see admin.php) —
	 * so instead of trusting the screen, this checks for
	 * self::SCREEN_MARKER_FIELD, a hidden input only present in $_POST when
	 * the submitted form was this screen's own Screen Options panel (it's
	 * only ever rendered there, see render_visibility_toggle()).
	 */
	public function save_visibility_preference() {
		if ( ! isset( $_POST[ self::SCREEN_MARKER_FIELD ] ) ) {
			return;
		}

		// Verify nonce safely without wp_die if it fails — just return
		// and let the meta remain unchanged (the toggle doesn't change).
		if ( ! isset( $_POST['screenoptionnonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['screenoptionnonce'] ) ), 'screen-options-nonce' ) ) {
			return;
		}

		$visible = isset( $_POST['citykleta_show_map'] ) ? '1' : '0';

		update_user_meta( get_current_user_id(), self::META_VISIBLE, $visible );
	}

	/**
	 * Loads Leaflet + citykleta-map.js/css, only on the bike list screen —
	 * same file-existence guards as the rest of the plugin (vendor files
	 * aren't shipped, see CLAUDE.md).
	 */
	public function enqueue_assets() {
		if ( ! $this->is_bike_list_screen() || ! $this->is_visible_for_user() ) {
			return;
		}

		if ( ! file_exists( CITYKLETA_PATH . 'assets/css/leaflet.css' ) ) {
			return;
		}

		wp_enqueue_style( 'leaflet', CITYKLETA_URL . 'assets/css/leaflet.css', array(), CITYKLETA_VERSION );
		wp_enqueue_style( 'leaflet-markercluster', CITYKLETA_URL . 'assets/css/MarkerCluster.css', array( 'leaflet' ), CITYKLETA_VERSION );
		wp_enqueue_style( 'leaflet-markercluster-default', CITYKLETA_URL . 'assets/css/MarkerCluster.Default.css', array( 'leaflet-markercluster' ), CITYKLETA_VERSION );
		wp_enqueue_style( 'citykleta-map', CITYKLETA_URL . 'assets/css/citykleta-map.css', array( 'leaflet-markercluster-default' ), CITYKLETA_VERSION );

		if ( ! file_exists( CITYKLETA_PATH . 'assets/js/leaflet.js' ) ) {
			return;
		}

		wp_enqueue_script( 'leaflet', CITYKLETA_URL . 'assets/js/leaflet.js', array(), CITYKLETA_VERSION, true );
		wp_enqueue_script( 'leaflet-markercluster', CITYKLETA_URL . 'assets/js/leaflet.markercluster.js', array( 'leaflet' ), CITYKLETA_VERSION, true );
		wp_enqueue_script( 'citykleta-map', CITYKLETA_URL . 'assets/js/citykleta-map.js', array( 'leaflet-markercluster' ), CITYKLETA_VERSION, true );
	}

	/**
	 * Renders the map above the list table. Hooked to 'admin_notices' (same
	 * pattern as Citykleta_Bike_Meta::render_sync_warning()) — that's just the
	 * standard low-risk hook for injecting screen-specific content on a
	 * wp-admin page without touching WP_List_Table internals.
	 *
	 * Content printed via 'admin_notices' lands in the HTML *before* the
	 * page's own <h1> (edit.php requires admin-header.php, which fires
	 * 'admin_notices', before it prints its own "<div class='wrap'><h1>").
	 * Core's own admin.js/common.js works around exactly this by relocating
	 * any `div.notice` (that isn't `.inline`) to right after `.wp-header-end`
	 * — which sits right below the title — via JS on page load. So the
	 * wrapper keeps the "notice" class purely to opt into that repositioning,
	 * with its default box styling (border/background/padding) neutralized
	 * inline so it doesn't read as a dismissible admin notice.
	 */
	public function render_map() {
		if ( ! $this->is_bike_list_screen() || ! $this->is_visible_for_user() ) {
			return;
		}

		$bikes   = Citykleta_Bike_Map_Data::query( array( 'count' => -1 ) );
		$total   = Citykleta_Bike_Map_Data::count_all();
		$missing = $total - count( $bikes );

		if ( ! $bikes ) {
			return; // Nothing to plot — don't show an empty map above the list.
		}

		echo '<div class="notice" style="margin:16px 0;padding:0;border:none;background:transparent;box-shadow:none;">';

		if ( $missing > 0 ) {
			echo '<div class="notice notice-warning inline"><p>';
			echo (int) $missing . ' bike' . ( 1 === $missing ? '' : 's' );
			echo ' ' . ( 1 === $missing ? 'has' : 'have' ) . ' no coordinates yet and ';
			echo ( 1 === $missing ? "isn't" : "aren't" ) . ' shown on the map — run a sync';
			echo ' (Bicicletas &rarr; Settings &rarr; API) to pull ' . ( 1 === $missing ? 'it' : 'them' ) . ' in.';
			echo '</p></div>';
		}

		$options                   = array_merge( array( 'centerOnUser' => false ), Citykleta_Settings::tile_layer(), Citykleta_Settings::cluster_options() );
		$options['iconSize']       = Citykleta_Settings::marker_icon_size();
		// Only here, not in the public widget: this map sits inside a long
		// wp-admin list, where scrolling past it would otherwise zoom it by
		// accident.
		$options['zoomLockButton'] = true;

		printf(
			'<div id="citykleta-admin-map" class="citykleta-map" style="height:400px;width:100%%;%3$s" data-bikes="%1$s" data-options="%2$s"></div>',
			esc_attr( wp_json_encode( $bikes ) ),
			esc_attr( wp_json_encode( $options ) ),
			esc_attr( Citykleta_Settings::map_color_style_attr() )
		);

		echo '</div>';
	}
}
