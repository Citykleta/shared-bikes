<?php
/**
 * Settings page: "Sync" tab (bike API URL, sync frequency, URL slug),
 * "Maps" tab (read-only display of the base map style/token, marker size,
 * and cluster behavior/colors used by the bikes map everywhere it appears —
 * Elementor widget and the wp-admin overview page; administered from Back
 * Office and pulled in by Citykleta_Map_Settings_Sync, see the map-config
 * hand-off note in CLAUDE.md), and "Communication" tab (the WhatsApp message
 * template pre-filled on each bike's contact box).
 *
 * @package Citykleta_Shared_Bikes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Citykleta_Settings.
 */
class Citykleta_Settings {

	/**
	 * Option groups used by the Settings API — one per tab with a savable
	 * form, not shared. The "Maps" tab isn't in this list: it's read-only
	 * (see Citykleta_Map_Settings_Sync), so it has nothing to register with
	 * the Settings API.
	 *
	 * wp-admin/options.php processes every option registered under the
	 * submitted group, not just the ones present in that request's form: any
	 * option in the group that the form didn't include gets blanked out. A
	 * single group shared across tabs would mean saving "Sync" wipes
	 * "Communication" and vice versa, since each tab's <form> only contains
	 * its own fields. Separate groups keep each tab's save from touching the
	 * other's data.
	 */
	const OPTION_GROUP_SYNC = 'citykleta_settings_sync';
	const OPTION_GROUP_COMM = 'citykleta_settings_comm';
	const OPTION_GROUP_DEST = 'citykleta_settings_dest';
	const OPTION_GROUP_TPL  = 'citykleta_settings_tpl';

	/**
	 * Settings page slug.
	 */
	const PAGE_SLUG = 'citykleta-settings';

	/**
	 * Tabs shown on the settings page, in order.
	 */
	const TABS = array(
		'sync'     => 'Sync',
		'maps'     => 'Maps',
		'comm'     => 'Communication',
		'dest'     => 'Destinations',
		'tpl'      => 'Templates',
		'frontend' => 'Frontend',
	);

	/**
	 * Placeholders the WhatsApp message template understands, mapped to a
	 * short human description shown next to the field. Filled per bike by
	 * self::whatsapp_message() from the values the single-bike template
	 * passes in.
	 */
	const MESSAGE_PLACEHOLDERS = array(
		'{owner}' => 'Owner name',
		'{bike}'  => 'Bike title',
		'{price}' => 'Price per day',
		'{url}'   => 'Link to the bike page',
		'{dates}' => 'Requested dates, with its own leading space (" del 20/09/2026 al 23/09/2026"), or nothing',
	);

	/**
	 * Default WhatsApp message when the admin hasn't set one. Front-end
	 * copy is Spanish (like the templates), even though labels/identifiers
	 * stay English.
	 */
	const DEFAULT_MESSAGE = 'Hola {owner}, vi tu bicicleta "{bike}" en Citykleta y me interesa alquilarla{dates}. ¿Está disponible? {url}';

	/**
	 * Fixed path of every API resource the plugin syncs, keyed by the slug the
	 * sync classes pass to self::get_endpoint_url().
	 *
	 * These are NOT editable in wp-admin on purpose: each one is confirmed
	 * against citykleta-sharing-api's real routes, and a typo in a text field
	 * would silently break a sync. They used to be editable (self::ENDPOINTS,
	 * option citykleta_path_{key}) while the plugin was pointed at a mock and
	 * the real routes weren't settled — that reconciliation is done, so the
	 * fields are gone and the paths live here.
	 *
	 * - bikes: GET /bikesPublic/sync, the PII-free catalogue built for this
	 *   plugin. Public on purpose (mounted before the API's global verifyAuth),
	 *   so no session or shared key is involved. NOT /bikes — that one is the
	 *   staff router and sits behind auth.
	 * - map_settings: GET /map-settings, see the map-config hand-off note in
	 *   CLAUDE.md.
	 * - contact_reveal: POST /contact/reveal, the one route here that is NOT
	 *   anonymous: it needs the shared key (CITYKLETA_PLUGIN_KEY in wp-config.php,
	 *   see Citykleta_Contact_Reveal). Not synced — called live per request.
	 *
	 * There is deliberately no 'bike_types' entry: the API's GET /bike-types is
	 * mounted after verifyAuth and answers 401 without a staff session, so this
	 * plugin can't read it. It doesn't need to — every row of /bikesPublic/sync
	 * carries bike_type (slug) and bike_type_name, so the taxonomy is built
	 * from the bike payload instead. See Citykleta_Bike_Type_Sync::run().
	 */
	const PATHS = array(
		'bikes'          => '/bikesPublic/sync',
		'map_settings'   => '/map-settings',
		'contact_reveal' => '/contact/reveal',
	);

	/**
	 * Settings section IDs the "Maps" tab groups its fields into.
	 */
	const SECTION_TILE_STYLE       = 'citykleta_tile_style_section';
	const SECTION_MARKER_DESIGN    = 'citykleta_marker_design_section';
	const SECTION_CLUSTER_BEHAVIOR = 'citykleta_cluster_behavior_section';
	const SECTION_CLUSTER_COLORS   = 'citykleta_cluster_colors_section';

	/**
	 * Base map tile providers the "Base Map" field can resolve
	 * (citykleta_map_tile_style option, now set by Citykleta_Map_Settings_Sync
	 * instead of picked by hand — see the Maps tab note in CLAUDE.md).
	 * assets/js/citykleta-map.js already accepts a tileUrl/attribution pair
	 * per map instance (falls back to the 'osm' entry below if none is
	 * passed) — self::tile_layer() resolves the synced style key against
	 * this list, same "one place controls every map" reasoning as
	 * self::MAP_COLORS.
	 *
	 * Reduced (2026-08) to just the two providers actually used: OpenStreetMap
	 * as a free/keyless fallback, and Mapbox as the real base map — Citykleta
	 * already has a Mapbox style set up for its own access token. This list
	 * stays plugin-side code even though Back Office now owns *which* key +
	 * the access token value: Back Office only ever sends a style key from
	 * this same enum, never a raw tile_url, so both sides only have to agree
	 * on the key names, not on tile URL templates. See the map-config
	 * hand-off note in CLAUDE.md for the full reasoning.
	 */
	const TILE_STYLES = array(
		'osm'    => array(
			'label'       => 'OpenStreetMap (default)',
			'tile_url'    => 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
			'attribution' => '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
		),
		// Mapbox's ToS technically also requires their logo to stay visible
		// on the map, not just this text credit — worth double-checking
		// their attribution guidelines before a real commercial launch.
		// tile_url points at Citykleta's own Mapbox Studio style
		// (mapbox://styles/citykleta-dev/cmh7t96hm00o401r50epi7e7s), not the
		// generic 'mapbox/streets-v12' — swap the "citykleta-dev/…" segment
		// if that style is ever replaced.
		'mapbox' => array(
			'label'       => 'Mapbox (Citykleta style, needs Access Token)',
			'tile_url'    => 'https://api.mapbox.com/styles/v1/citykleta-dev/cmh7t96hm00o401r50epi7e7s/tiles/{z}/{x}/{y}{r}',
			'attribution' => '&copy; <a href="https://www.mapbox.com/about/maps/">Mapbox</a> &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
			'key_param'   => 'access_token',
		),
	);

	/**
	 * Map colors this plugin exposes, keyed by the suffix used in both the
	 * option name and the CSS custom property citykleta-map.css reads
	 * (--citykleta-map-{key}-color). Shared by the "Maps" tab fields and
	 * Citykleta_Settings::map_colors().
	 *
	 * The three "cluster-*" entries match Leaflet.markercluster's own
	 * small/medium/large size tiers (see citykleta-map.css), so each tier
	 * of the "how many bikes are grouped here" gradient is configurable
	 * on its own instead of forcing all three to the same color.
	 */
	const MAP_COLORS = array(
		'cluster-small'  => array(
			'option'  => 'citykleta_map_cluster_small_color',
			'label'   => 'Small Cluster',
			'default' => '#fed94c',
			'section' => self::SECTION_CLUSTER_COLORS,
		),
		'cluster-medium' => array(
			'option'  => 'citykleta_map_cluster_medium_color',
			'label'   => 'Medium Cluster',
			'default' => '#fac114',
			'section' => self::SECTION_CLUSTER_COLORS,
		),
		'cluster-large'  => array(
			'option'  => 'citykleta_map_cluster_large_color',
			'label'   => 'Large Cluster',
			'default' => '#e0960a',
			'section' => self::SECTION_CLUSTER_COLORS,
		),
	);

	/**
	 * Hook suffix returned by add_submenu_page(), used so the color picker
	 * assets only load on this specific admin page.
	 *
	 * @var string
	 */
	private $page_hook = '';

	/**
	 * Connects the class to WordPress hooks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'add_option_citykleta_sync_frequency', array( $this, 'on_frequency_added' ), 10, 2 );
		add_action( 'update_option_citykleta_sync_frequency', array( $this, 'on_frequency_updated' ), 10, 2 );
		add_action( 'add_option_citykleta_bike_slug', array( $this, 'on_slug_changed' ) );
		add_action( 'update_option_citykleta_bike_slug', array( $this, 'on_slug_changed' ) );
		add_action( 'add_option_citykleta_destination_slug', array( $this, 'on_destination_slug_changed' ) );
		add_action( 'update_option_citykleta_destination_slug', array( $this, 'on_destination_slug_changed' ) );
	}

	/**
	 * Builds the full URL for a synced resource — the configured base URL
	 * plus that resource's fixed path (self::PATHS). Used by sync classes
	 * instead of reading a full URL option directly.
	 *
	 * @param string $key Endpoint key, e.g. 'bikes' or 'map_settings'.
	 * @return string Empty string if $key isn't a known endpoint.
	 */
	public static function get_endpoint_url( $key ) {
		if ( ! isset( self::PATHS[ $key ] ) ) {
			return '';
		}

		$base = trim( (string) get_option( 'citykleta_api_base_url', '' ) );

		// No default any more: it used to fall back to the bundled mock API,
		// which is gone. Without a base URL there is nowhere to sync from, and
		// callers report that rather than build a nonsense URL.
		if ( '' === $base ) {
			return '';
		}

		return rtrim( $base, '/' ) . '/' . ltrim( self::PATHS[ $key ], '/' );
	}

	/**
	 * The map colors currently configured (Maps tab), falling back to the
	 * plugin's default palette for any color that was never set.
	 *
	 * @return array Keyed the same as self::MAP_COLORS.
	 */
	public static function map_colors() {
		$colors = array();

		foreach ( self::MAP_COLORS as $key => $config ) {
			$value          = get_option( $config['option'], $config['default'] );
			$colors[ $key ] = $value ? $value : $config['default'];
		}

		return $colors;
	}

	/**
	 * The configured map colors as inline CSS custom properties, ready to
	 * drop into a .citykleta-map container's style="" attribute — that's
	 * what assets/css/citykleta-map.css's var(--citykleta-map-{key}-color, …)
	 * rules read. Used by both Citykleta_Map_Widget and Citykleta_Admin_Map
	 * so there's a single place that turns settings into actual colors.
	 *
	 * Builds one "--citykleta-map-{key}-color:{value};" per self::MAP_COLORS
	 * entry, so adding a new configurable color there doesn't need a change
	 * here too.
	 *
	 * @return string
	 */
	public static function map_color_style_attr() {
		$style = '';

		foreach ( self::map_colors() as $key => $value ) {
			$style .= '--citykleta-map-' . $key . '-color:' . esc_attr( $value ) . ';';
		}

		return $style;
	}

	/**
	 * The base map tile URL + attribution currently configured ("Base Map"
	 * field, Maps tab), falling back to OpenStreetMap if never set. Used by
	 * Citykleta_Map_Widget, Citykleta_Admin_Map, and this class's own
	 * preview map — same single-source-of-truth reasoning as
	 * self::map_colors().
	 *
	 * For a style with a 'key_param' (MapTiler, Stadia Maps), appends
	 * citykleta_map_tile_api_key as that provider's own query parameter
	 * name. If that option is empty, the URL goes out without a key at all
	 * — MapTiler will fail to load (it requires one from the first
	 * request), Stadia Maps still works keyless for light/dev traffic.
	 *
	 * @return array { 'tileUrl' => string, 'attribution' => string }.
	 */
	public static function tile_layer() {
		$style = get_option( 'citykleta_map_tile_style', 'osm' );

		if ( ! isset( self::TILE_STYLES[ $style ] ) ) {
			$style = 'osm';
		}

		$config   = self::TILE_STYLES[ $style ];
		$tile_url = $config['tile_url'];

		if ( ! empty( $config['key_param'] ) ) {
			$api_key = get_option( 'citykleta_map_tile_api_key', '' );

			if ( $api_key ) {
				$separator = false === strpos( $tile_url, '?' ) ? '?' : '&';
				$tile_url .= $separator . $config['key_param'] . '=' . rawurlencode( $api_key );
			}
		}

		return array(
			'tileUrl'     => $tile_url,
			'attribution' => $config['attribution'],
		);
	}

	/**
	 * Cluster grouping behavior currently configured ("Cluster Behavior",
	 * Maps tab): how close bikes need to be, in screen pixels, to group into
	 * one cluster (maxClusterRadius — Leaflet.markercluster's own default is
	 * 80), and, optionally, a zoom level past which clustering turns off
	 * entirely so every bike always shows on its own
	 * (disableClusteringAtZoom — 0 stored means "never", which this returns
	 * as null since that's what tells Leaflet.markercluster to keep its own
	 * default behavior). Used by Citykleta_Map_Widget, Citykleta_Admin_Map,
	 * Citykleta_Templates (archive-bike.php's map view), and this class's
	 * own preview map — same single-source-of-truth reasoning as
	 * self::tile_layer().
	 *
	 * @return array { 'maxClusterRadius' => int, 'disableClusteringAtZoom' => int|null }.
	 */
	public static function cluster_options() {
		$disable_at_zoom = (int) get_option( 'citykleta_map_cluster_disable_zoom', 0 );

		return array(
			'maxClusterRadius'        => (int) get_option( 'citykleta_map_cluster_radius', 80 ),
			'disableClusteringAtZoom' => $disable_at_zoom > 0 ? $disable_at_zoom : null,
		);
	}

	/**
	 * The individual bike marker's size in pixels (Settings → Maps →
	 * "Icon Size").
	 *
	 * @return int
	 */
	public static function marker_icon_size() {
		return (int) get_option( 'citykleta_map_marker_icon_size', 30 );
	}

	/**
	 * The WhatsApp message template configured on the Communication tab,
	 * falling back to self::DEFAULT_MESSAGE.
	 *
	 * @return string
	 */
	public static function whatsapp_message_template() {
		$template = get_option( 'citykleta_whatsapp_message', self::DEFAULT_MESSAGE );
		return '' !== trim( $template ) ? $template : self::DEFAULT_MESSAGE;
	}

	/**
	 * The default FAQ (Destinations tab), parsed into
	 * { 'question' => string, 'answer' => string }[]. Read by
	 * Citykleta_Destination_Post_Type::faq_for() — every destination shows
	 * this list; there is no per-destination override anymore.
	 *
	 * @return array
	 */
	public static function destination_faq_default() {
		return Citykleta_Destination_Post_Type::parse_faq_text( get_option( 'citykleta_destination_faq_default', '' ) );
	}

	/**
	 * Fills the message template's placeholders (see
	 * self::MESSAGE_PLACEHOLDERS) with a single bike's data.
	 *
	 * @param array $replacements Placeholder => value, e.g. { '{owner}' => 'Laura' }.
	 * @return string
	 */
	public static function whatsapp_message( array $replacements ) {
		return strtr( self::whatsapp_message_template(), $replacements );
	}

	/**
	 * Builds a click-to-chat WhatsApp URL (wa.me) for a phone number and a
	 * ready message. Returns '' if there's no usable phone number, so the
	 * caller can hide the button entirely.
	 *
	 * wa.me needs the number in international format with digits only (no
	 * "+", spaces, or dashes), so everything else is stripped.
	 *
	 * @param string $phone   Owner phone, however the API formatted it.
	 * @param string $message Message already run through self::whatsapp_message().
	 * @return string
	 */
	public static function whatsapp_url( $phone, $message ) {
		$digits = preg_replace( '/\D+/', '', (string) $phone );

		if ( '' === $digits ) {
			return '';
		}

		return 'https://wa.me/' . $digits . '?text=' . rawurlencode( $message );
	}

	/**
	 * Adds the settings page as a submenu of "Shared Bikes".
	 */
	public function add_menu() {
		$this->page_hook = add_submenu_page(
			'edit.php?post_type=' . Citykleta_Bike_Post_Type::POST_TYPE,
			'Settings',
			'Settings',
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Loads the Maps tab's live preview map assets, only on this page. The
	 * Maps tab is read-only (see the map-config hand-off note in CLAUDE.md),
	 * so this no longer needs the color picker or the tile-style-dependent
	 * API key row toggle it used to load.
	 *
	 * @param string $hook_suffix Current admin page's hook suffix.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( $hook_suffix !== $this->page_hook ) {
			return;
		}

		// Live preview map (right column of the Maps tab) — same assets as
		// the front-end map, loaded the same optional/graceful-degradation
		// way (file_exists() guard) as Citykleta_Admin_Map does. It renders
		// with whatever Citykleta_Map_Settings_Sync last synced.
		if ( file_exists( CITYKLETA_PATH . 'assets/css/leaflet.css' ) ) {
			wp_enqueue_style( 'leaflet', CITYKLETA_URL . 'assets/css/leaflet.css', array(), CITYKLETA_VERSION );
			wp_enqueue_style( 'leaflet-markercluster', CITYKLETA_URL . 'assets/css/MarkerCluster.css', array( 'leaflet' ), CITYKLETA_VERSION );
			wp_enqueue_style( 'leaflet-markercluster-default', CITYKLETA_URL . 'assets/css/MarkerCluster.Default.css', array( 'leaflet-markercluster' ), CITYKLETA_VERSION );
			wp_enqueue_style( 'citykleta-map', CITYKLETA_URL . 'assets/css/citykleta-map.css', array( 'leaflet-markercluster-default' ), CITYKLETA_VERSION );

			wp_add_inline_style(
				'citykleta-map',
				'.citykleta-settings-maps-layout { display: flex; gap: 40px; align-items: flex-start; }
				.citykleta-settings-maps-summary { flex: 1 1 50%; max-width: 600px; }
				.citykleta-settings-maps-preview { flex: 1 1 50%; position: sticky; top: 40px; }
				.citykleta-settings-maps-preview .citykleta-map { width: 100%; height: auto; aspect-ratio: 1 / 1; }
				@media ( max-width: 900px ) { .citykleta-settings-maps-layout { flex-direction: column; } }'
			);
		}

		if ( file_exists( CITYKLETA_PATH . 'assets/js/leaflet.js' ) ) {
			wp_enqueue_script( 'leaflet', CITYKLETA_URL . 'assets/js/leaflet.js', array(), CITYKLETA_VERSION, true );
			wp_enqueue_script( 'leaflet-markercluster', CITYKLETA_URL . 'assets/js/leaflet.markercluster.js', array( 'leaflet' ), CITYKLETA_VERSION, true );
			wp_enqueue_script( 'citykleta-map', CITYKLETA_URL . 'assets/js/citykleta-map.js', array( 'leaflet-markercluster' ), CITYKLETA_VERSION, true );
		}
	}

	/**
	 * A handful of made-up bikes for the Maps tab's live preview — three
	 * close together (to preview cluster colors) plus two standalone ones
	 * (one of each availability state, to preview marker colors). Not real
	 * data, so it doesn't touch Citykleta_Bike_Map_Data.
	 *
	 * @return array
	 */
	private function preview_bikes() {
		return array(
			array(
				'id'        => 'preview-1',
				'lat'       => 23.1450,
				'lng'       => -82.3550,
				'title'     => 'Preview bike',
				'available' => true,
			),
			array(
				'id'        => 'preview-2',
				'lat'       => 23.1300,
				'lng'       => -82.3700,
				'title'     => 'Preview bike',
				'available' => false,
			),
			array(
				'id'        => 'preview-3',
				'lat'       => 23.1150,
				'lng'       => -82.3600,
				'title'     => 'Preview bike',
				'available' => true,
			),
			array(
				'id'        => 'preview-4',
				'lat'       => 23.1154,
				'lng'       => -82.3596,
				'title'     => 'Preview bike',
				'available' => true,
			),
			array(
				'id'        => 'preview-5',
				'lat'       => 23.1158,
				'lng'       => -82.3604,
				'title'     => 'Preview bike',
				'available' => true,
			),
		);
	}

	/**
	 * Renders the live preview map for the Maps tab's right column.
	 */
	public function render_preview_map() {
		if ( ! file_exists( CITYKLETA_PATH . 'assets/js/leaflet.js' ) ) {
			echo '<p class="description">Preview needs Leaflet — see the "Mapa de bicis" section in CLAUDE.md for the download links.</p>';
			return;
		}

		$options = array_merge( array( 'centerOnUser' => false ), self::tile_layer(), self::cluster_options() );
		$options['iconSize'] = self::marker_icon_size();

		printf(
			'<div class="citykleta-map" style="%3$s" data-bikes="%1$s" data-options="%2$s"></div>',
			esc_attr( wp_json_encode( $this->preview_bikes() ) ),
			esc_attr( wp_json_encode( $options ) ),
			esc_attr( self::map_color_style_attr() )
		);
	}

	/**
	 * Available options for the sync frequency.
	 *
	 * @return array Frequency slug => label.
	 */
	public function get_frequency_options() {
		return array(
			'manual'           => 'Manual only',
			'every_15_minutes' => 'Every 15 minutes',
			'every_30_minutes' => 'Every 30 minutes',
			'hourly'           => 'Every hour',
			'twicedaily'       => 'Twice a day',
			'daily'            => 'Once a day',
		);
	}

	/**
	 * Registers the options, sections, and fields for both tabs.
	 */
	public function register_settings() {
		register_setting(
			self::OPTION_GROUP_SYNC,
			'citykleta_api_base_url',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'esc_url_raw',
				// No default: this used to point at the bundled mock API, which
				// no longer exists. It has to be set to the real API.
				'default'           => '',
			)
		);

		register_setting(
			self::OPTION_GROUP_SYNC,
			'citykleta_sync_frequency',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_frequency' ),
				'default'           => 'hourly',
			)
		);

		register_setting(
			self::OPTION_GROUP_SYNC,
			'citykleta_bike_slug',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_slug' ),
				'default'           => 'bikes',
			)
		);

		add_settings_section(
			'citykleta_sync_section',
			'Bike sync',
			// Description + "Sync now" button are rendered at the top of the tab
			// instead (see render_page()), so the button can sit right after the
			// description — it can't live inside this settings <form> (its own
			// form posts elsewhere, and nested forms are invalid HTML).
			'__return_null',
			self::PAGE_SLUG . '-sync'
		);

		add_settings_field(
			'citykleta_api_base_url',
			'API URL',
			array( $this, 'render_api_base_url_field' ),
			self::PAGE_SLUG . '-sync',
			'citykleta_sync_section'
		);

		add_settings_field(
			'citykleta_sync_frequency',
			'Update frequency',
			array( $this, 'render_frequency_field' ),
			self::PAGE_SLUG . '-sync',
			'citykleta_sync_section'
		);

		add_settings_field(
			'citykleta_bike_slug',
			'URL slug',
			array( $this, 'render_slug_field' ),
			self::PAGE_SLUG . '-sync',
			'citykleta_sync_section'
		);

		// The "Maps" tab's fields below are read-only — no register_setting()
		// calls for them, since Citykleta_Map_Settings_Sync writes these
		// options directly (update_option()) from Back Office instead of a
		// wp-admin form posting to options.php. add_settings_section()/
		// add_settings_field() are still used, just to keep the same
		// grouped-table layout do_settings_sections() renders; the field
		// callbacks below print plain text instead of <input>s. See the
		// map-config hand-off note in CLAUDE.md.
		add_settings_section(
			self::SECTION_TILE_STYLE,
			'Base Map',
			function () {
				echo '<p>Tile style used as the base map, everywhere a bike map appears (Elementor widget, wp-admin overview page, and this preview).</p>';
			},
			self::PAGE_SLUG . '-maps'
		);

		add_settings_field(
			'citykleta_map_tile_style',
			'Tile Style',
			array( $this, 'render_tile_style_field' ),
			self::PAGE_SLUG . '-maps',
			self::SECTION_TILE_STYLE
		);

		add_settings_field(
			'citykleta_map_tile_api_key',
			'Access Token',
			array( $this, 'render_tile_api_key_field' ),
			self::PAGE_SLUG . '-maps',
			self::SECTION_TILE_STYLE
		);

		add_settings_section(
			self::SECTION_MARKER_DESIGN,
			'Marker design',
			function () {
				echo '<p>Size for individual bike markers — a fixed circle showing the built-in bike glyph, colored by availability (not configurable, see assets/css/citykleta-map.css). Used by the Elementor "Bikes Map" widget and the map shown above the bike list (Bicicletas → All Bikes), so there\'s one place to keep them on-brand.</p>';
			},
			self::PAGE_SLUG . '-maps'
		);

		add_settings_field(
			'citykleta_map_marker_icon_size',
			'Icon Size',
			array( $this, 'render_icon_size_field' ),
			self::PAGE_SLUG . '-maps',
			self::SECTION_MARKER_DESIGN
		);

		add_settings_section(
			self::SECTION_CLUSTER_BEHAVIOR,
			'Cluster Behavior',
			function () {
				echo '<p>Controls when nearby bikes group into a cluster instead of showing as individual markers.</p>';
			},
			self::PAGE_SLUG . '-maps'
		);

		add_settings_field(
			'citykleta_map_cluster_radius',
			'Cluster Radius',
			array( $this, 'render_cluster_radius_field' ),
			self::PAGE_SLUG . '-maps',
			self::SECTION_CLUSTER_BEHAVIOR
		);

		add_settings_field(
			'citykleta_map_cluster_disable_zoom',
			'Unfold At Zoom',
			array( $this, 'render_cluster_disable_zoom_field' ),
			self::PAGE_SLUG . '-maps',
			self::SECTION_CLUSTER_BEHAVIOR
		);

		add_settings_section(
			self::SECTION_CLUSTER_COLORS,
			'Cluster Colors',
			function () {
				echo '<p>Color for each cluster size tier — how many bikes are grouped together at the current zoom level.</p>';
			},
			self::PAGE_SLUG . '-maps'
		);

		foreach ( self::MAP_COLORS as $config ) {
			add_settings_field(
				$config['option'],
				$config['label'],
				array( $this, 'render_color_field' ),
				self::PAGE_SLUG . '-maps',
				$config['section'],
				$config
			);
		}

		register_setting(
			self::OPTION_GROUP_COMM,
			'citykleta_whatsapp_message',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_message' ),
				'default'           => self::DEFAULT_MESSAGE,
			)
		);

		add_settings_section(
			'citykleta_comm_section',
			'WhatsApp contact',
			function () {
				echo '<p>Default message pre-filled when a visitor taps "Contact via WhatsApp" on a bike page. The visitor can still edit it before sending.</p>';
			},
			self::PAGE_SLUG . '-comm'
		);

		add_settings_field(
			'citykleta_whatsapp_message',
			'Message template',
			array( $this, 'render_message_field' ),
			self::PAGE_SLUG . '-comm',
			'citykleta_comm_section'
		);

		register_setting(
			self::OPTION_GROUP_COMM,
			'citykleta_request_notify_email',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_notify_email' ),
				'default'           => '',
			)
		);

		add_settings_section(
			'citykleta_requests_section',
			'Bike requests',
			function () {
				echo '<p>Where to send an email when a visitor asks to rent a bike. Requests are always stored under ';
				echo '<strong>Bicicletas → Requests</strong> — this is the heads-up on top, not the record itself, so a ';
				echo 'failed send never loses a request.</p>';
			},
			self::PAGE_SLUG . '-comm'
		);

		add_settings_field(
			'citykleta_request_notify_email',
			'Notification email',
			array( $this, 'render_notify_email_field' ),
			self::PAGE_SLUG . '-comm',
			'citykleta_requests_section'
		);

		register_setting(
			self::OPTION_GROUP_DEST,
			'citykleta_destination_slug',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_destination_slug' ),
				'default'           => 'destination',
			)
		);

		add_settings_section(
			'citykleta_dest_url_section',
			'URL',
			function () {
				echo '<p>Where destination pages live.</p>';
			},
			self::PAGE_SLUG . '-dest'
		);

		add_settings_field(
			'citykleta_destination_slug',
			'URL slug',
			array( $this, 'render_destination_slug_field' ),
			self::PAGE_SLUG . '-dest',
			'citykleta_dest_url_section'
		);

		register_setting(
			self::OPTION_GROUP_DEST,
			'citykleta_destination_faq_default',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'default'           => '',
			)
		);

		add_settings_section(
			'citykleta_dest_section',
			'Default FAQ',
			function () {
				echo '<p>Shown on every destination page (Bicicletas → Destinations → a destination, e.g. "Vedado"), unless that destination has its own FAQ switched on — see the "Custom FAQ" field on its edit screen.</p>';
			},
			self::PAGE_SLUG . '-dest'
		);

		add_settings_field(
			'citykleta_destination_faq_default',
			'FAQ items',
			array( $this, 'render_destination_faq_field' ),
			self::PAGE_SLUG . '-dest',
			'citykleta_dest_section'
		);

		// Templates: which layout each of the plugin's own front-end views is
		// drawn with. One option holding view => template ID, rather than one
		// option per view, so adding a view to
		// Citykleta_Template_Post_Type::TYPES needs nothing here.
		register_setting(
			self::OPTION_GROUP_TPL,
			Citykleta_Template_Post_Type::ACTIVE_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'Citykleta_Template_Post_Type', 'sanitize_active' ),
				'default'           => array(),
			)
		);

		add_settings_section(
			'citykleta_tpl_section',
			'Active template',
			function () {
				echo '<p>Each view is drawn with one template. A view only lists the templates assigned to it in their own "Draws" box (Bicicletas → Templates), so a destination layout can never end up drawing a bike page.</p>';
			},
			self::PAGE_SLUG . '-tpl'
		);

		foreach ( Citykleta_Template_Post_Type::TYPES as $tpl_type => $tpl_label ) {
			add_settings_field(
				'citykleta_active_template_' . $tpl_type,
				$tpl_label,
				array( $this, 'render_active_template_field' ),
				self::PAGE_SLUG . '-tpl',
				'citykleta_tpl_section',
				array( 'type' => $tpl_type )
			);
		}
	}

	/**
	 * Renders one view's "which template draws this" dropdown.
	 *
	 * The blank option is not "none": it is the behaviour every install had
	 * before this screen existed — the first published template of that view.
	 * Keeping that as the default is what lets the plugin keep creating and
	 * using its own default layouts without anyone coming here first.
	 *
	 * @param array $args Field args; 'type' is a key of
	 *                    Citykleta_Template_Post_Type::TYPES.
	 */
	public function render_active_template_field( $args ) {
		$type      = isset( $args['type'] ) ? $args['type'] : '';
		$templates = Citykleta_Template_Post_Type::templates_for( $type );

		if ( ! $templates ) {
			echo '<p class="description">No published template draws this view yet. The plugin creates one with its default layout the first time the page is visited.</p>';
			return;
		}

		$current = Citykleta_Template_Post_Type::active_id( $type );
		$in_use  = Citykleta_Template_Post_Type::get_template( $type );

		printf(
			'<select name="%1$s[%2$s]" style="min-width:320px;">',
			esc_attr( Citykleta_Template_Post_Type::ACTIVE_OPTION ),
			esc_attr( $type )
		);

		printf(
			'<option value="" %1$s>— First published (%2$s) —</option>',
			selected( $current, 0, false ),
			esc_html( get_the_title( $templates[0] ) )
		);

		foreach ( $templates as $template ) {
			printf(
				'<option value="%1$d" %2$s>%3$s</option>',
				(int) $template->ID,
				selected( $current, $template->ID, false ),
				esc_html( get_the_title( $template ) )
			);
		}

		echo '</select>';

		if ( $in_use ) {
			printf(
				' <a href="%1$s">Edit “%2$s”</a>',
				esc_url( (string) get_edit_post_link( $in_use->ID ) ),
				esc_html( get_the_title( $in_use ) )
			);
		}

		// The stored pick can stop resolving at any time (deleted, moved to
		// draft, reassigned to another view) — say so instead of quietly
		// drawing something else.
		if ( $current && ( ! $in_use || (int) $in_use->ID !== $current ) ) {
			echo '<p class="description" style="color:#996800;">The template picked here is no longer published for this view, so it falls back to the first published one. Pick another to clear this notice.</p>';
		}
	}

	/**
	 * Sanitizes the WhatsApp message template, keeping it as multi-line
	 * plain text and falling back to the default if left blank.
	 *
	 * @param string $value Value submitted by the form.
	 * @return string
	 */
	public function sanitize_message( $value ) {
		$value = sanitize_textarea_field( $value );
		return '' !== trim( $value ) ? $value : self::DEFAULT_MESSAGE;
	}

	/**
	 * Clamps the marker icon's size to a sane range. static (not just
	 * public) because Citykleta_Map_Settings_Sync also calls this to clamp
	 * whatever Back Office sends, the same way this used to clamp form input.
	 *
	 * @param mixed $value Value submitted by the form, or synced from Back Office.
	 * @return int
	 */
	public static function sanitize_icon_size( $value ) {
		$value = is_numeric( $value ) ? (int) $value : 30;
		return max( 12, min( 80, $value ) );
	}

	/**
	 * Validates that the saved tile style is one of self::TILE_STYLES. static
	 * for the same reason as self::sanitize_icon_size().
	 *
	 * @param string $value Value submitted by the form, or synced from Back Office.
	 * @return string
	 */
	public static function sanitize_tile_style( $value ) {
		return isset( self::TILE_STYLES[ $value ] ) ? $value : 'osm';
	}

	/**
	 * Clamps the cluster grouping radius to a sane pixel range. static for
	 * the same reason as self::sanitize_icon_size().
	 *
	 * @param mixed $value Value submitted by the form, or synced from Back Office.
	 * @return int
	 */
	public static function sanitize_cluster_radius( $value ) {
		$value = is_numeric( $value ) ? (int) $value : 80;
		return max( 10, min( 200, $value ) );
	}

	/**
	 * Clamps the "Unfold At Zoom" level. 0 means never disable clustering.
	 * static for the same reason as self::sanitize_icon_size().
	 *
	 * @param mixed $value Value submitted by the form, or synced from Back Office.
	 * @return int
	 */
	public static function sanitize_cluster_disable_zoom( $value ) {
		$value = is_numeric( $value ) ? (int) $value : 0;
		return max( 0, min( 19, $value ) );
	}

	/**
	 * Validates one map color, falling back to that color's own default
	 * instead of ever saving blank. Used by Citykleta_Map_Settings_Sync for
	 * every self::MAP_COLORS entry — sanitize_hex_color() alone returns ''
	 * for a blank/invalid value, and that '' would get saved: after that,
	 * get_option( $option, $default ) stops falling back to $default,
	 * because the row now exists (just empty).
	 *
	 * @param mixed  $value   Value synced from Back Office.
	 * @param string $default That color's self::MAP_COLORS default.
	 * @return string
	 */
	public static function sanitize_map_color( $value, $default ) {
		$color = sanitize_hex_color( $value );
		return $color ? $color : $default;
	}

	/**
	 * Validates that the saved frequency is one of the allowed options.
	 *
	 * @param string $value Value submitted by the form.
	 * @return string
	 */
	public function sanitize_frequency( $value ) {
		$valid = array_keys( $this->get_frequency_options() );
		return in_array( $value, $valid, true ) ? $value : 'hourly';
	}

	/**
	 * Converts whatever the user typed into a valid URL slug.
	 *
	 * @param string $value Value submitted by the form.
	 * @return string
	 */
	public function sanitize_slug( $value ) {
		$slug = sanitize_title( $value );
		return $slug ? $slug : 'bikes';
	}

	/**
	 * Same as self::sanitize_slug(), just a different fallback name.
	 *
	 * A blank value ("remove the prefix entirely") was tried and reverted:
	 * WordPress's own 'rewrite' => array('slug' => '') / '/' don't produce a
	 * working flat %postname%/ rule for a plain register_post_type() call —
	 * every destination page 404s the moment the prefix is cleared, verified
	 * empirically (0 rewrite rules generated for the CPT). Getting a real
	 * flat URL would need a hand-written top-priority rewrite rule plus
	 * collision handling against Pages/Posts with the same slug — a bigger
	 * change than this field, so for now the slug always falls back to
	 * 'destination' instead of ever being blank, same safety net as bikes.
	 *
	 * @param string $value Value submitted by the form.
	 * @return string
	 */
	public function sanitize_destination_slug( $value ) {
		$slug = sanitize_title( $value );
		return $slug ? $slug : 'destination';
	}

	/**
	 * Renders the API base URL field.
	 */
	public function render_api_base_url_field() {
		$value = get_option( 'citykleta_api_base_url' );
		echo '<input type="url" name="citykleta_api_base_url" value="' . esc_attr( $value ) . '" class="regular-text" placeholder="http://localhost:8001" />';
		echo '<p class="description">Base URL of the Sharing App API, e.g. <code>http://localhost:8001</code>. Resource paths are fixed in code &mdash; bikes sync from <code>' . esc_html( self::PATHS['bikes'] ) . '</code>.</p>';
	}

	/**
	 * Renders the sync frequency field.
	 */
	public function render_frequency_field() {
		$value = get_option( 'citykleta_sync_frequency', 'hourly' );
		echo '<select name="citykleta_sync_frequency">';
		foreach ( $this->get_frequency_options() as $slug => $label ) {
			echo '<option value="' . esc_attr( $slug ) . '" ' . selected( $value, $slug, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	/**
	 * Renders the bike URL slug field.
	 */
	public function render_slug_field() {
		$value = get_option( 'citykleta_bike_slug', 'bikes' );
		echo '<input type="text" name="citykleta_bike_slug" value="' . esc_attr( $value ) . '" class="regular-text" style="width:220px" placeholder="bikes" />';
		echo '<p class="description">→ <code>' . esc_html( home_url( '/' . $value . '/' ) ) . '</code></p>';
		echo '<p class="description">Changing this moves every bike\'s URL. Letters, numbers, and hyphens only.</p>';
	}

	/**
	 * Renders the destination URL slug field. Same shape as self::render_slug_field()
	 * for bikes — see self::sanitize_destination_slug() for why it can't be blank.
	 */
	public function render_destination_slug_field() {
		$value = get_option( 'citykleta_destination_slug', 'destination' );
		echo '<input type="text" name="citykleta_destination_slug" value="' . esc_attr( $value ) . '" class="regular-text" style="width:220px" placeholder="destination" />';
		echo '<p class="description">→ <code>' . esc_html( home_url( '/' . $value . '/vedado/' ) ) . '</code></p>';
		echo '<p class="description">Changing this moves every destination\'s URL. Letters, numbers, and hyphens only.</p>';
	}

	/**
	 * Renders the "Base Map" tile style value — read-only, synced from Back
	 * Office (Citykleta_Map_Settings_Sync) rather than picked from a
	 * dropdown. See the map-config hand-off note in CLAUDE.md.
	 */
	public function render_tile_style_field() {
		$style  = get_option( 'citykleta_map_tile_style', 'osm' );
		$config = isset( self::TILE_STYLES[ $style ] ) ? self::TILE_STYLES[ $style ] : self::TILE_STYLES['osm'];
		echo '<p>' . esc_html( $config['label'] ) . '</p>';
	}

	/**
	 * Renders the tile provider's access token/API key — read-only, masked
	 * (never prints the full secret into the page source), and only shown
	 * when the current style actually uses one (self::TILE_STYLES'
	 * 'key_param'). Synced from Back Office, see self::render_tile_style_field().
	 */
	public function render_tile_api_key_field() {
		$style  = get_option( 'citykleta_map_tile_style', 'osm' );
		$config = isset( self::TILE_STYLES[ $style ] ) ? self::TILE_STYLES[ $style ] : self::TILE_STYLES['osm'];

		if ( empty( $config['key_param'] ) ) {
			echo '<p class="description">Not used by the current base map style.</p>';
			return;
		}

		$value = get_option( 'citykleta_map_tile_api_key', '' );

		if ( '' === $value ) {
			echo '<p><em>Not set.</em></p>';
			return;
		}

		$masked = strlen( $value ) > 8
			? substr( $value, 0, 4 ) . str_repeat( '•', 8 ) . substr( $value, -4 )
			: str_repeat( '•', strlen( $value ) );

		echo '<p><code>' . esc_html( $masked ) . '</code></p>';
	}

	/**
	 * Renders the cluster grouping radius value — read-only, see
	 * self::render_tile_style_field().
	 */
	public function render_cluster_radius_field() {
		$value = (int) get_option( 'citykleta_map_cluster_radius', 80 );
		echo '<p>' . esc_html( $value ) . ' px</p>';
		echo '<p class="description">How close bikes need to be, in screen pixels, to group into one cluster. Smaller means bikes need to be closer together to cluster, so more of them show individually. Leaflet.markercluster\'s own default is 80.</p>';
	}

	/**
	 * Renders the "Unfold At Zoom" value — read-only, see
	 * self::render_tile_style_field().
	 */
	public function render_cluster_disable_zoom_field() {
		$value = (int) get_option( 'citykleta_map_cluster_disable_zoom', 0 );
		echo '<p>' . ( $value > 0 ? esc_html( $value ) : 'Never (0)' ) . '</p>';
		echo '<p class="description">Zoom level from which bikes always show individually, never clustered, no matter how close together they are.</p>';
	}

	/**
	 * Renders one map color as a swatch + hex code — read-only, see
	 * self::render_tile_style_field(). Shared by every entry in self::MAP_COLORS.
	 *
	 * @param array $args One of self::MAP_COLORS' entries: { 'option' => string, 'label' => string, 'default' => string }.
	 */
	public function render_color_field( $args ) {
		$value = get_option( $args['option'], $args['default'] );
		printf(
			'<p><span style="display:inline-block;width:20px;height:20px;vertical-align:middle;border:1px solid #ccc;border-radius:3px;background:%1$s;margin-right:8px;"></span><code>%1$s</code></p>',
			esc_attr( $value )
		);
	}

	/**
	 * Renders the WhatsApp message template field: a textarea plus the list
	 * of placeholders it understands (self::MESSAGE_PLACEHOLDERS).
	 */
	public function render_message_field() {
		$value = get_option( 'citykleta_whatsapp_message', self::DEFAULT_MESSAGE );
		echo '<textarea name="citykleta_whatsapp_message" rows="4" class="large-text">' . esc_textarea( $value ) . '</textarea>';
		echo '<p class="description">Available placeholders:</p>';
		echo '<ul class="description" style="list-style:disc;margin-left:1.5em;">';
		foreach ( self::MESSAGE_PLACEHOLDERS as $token => $label ) {
			echo '<li><code>' . esc_html( $token ) . '</code> — ' . esc_html( $label ) . '</li>';
		}
		echo '</ul>';
	}

	/**
	 * Renders the bike-request notification email field.
	 */
	public function render_notify_email_field() {
		$value = (string) get_option( 'citykleta_request_notify_email', '' );

		echo '<input type="email" name="citykleta_request_notify_email" value="' . esc_attr( $value ) . '" class="regular-text" placeholder="' . esc_attr( (string) get_option( 'admin_email' ) ) . '" />';
		echo '<p class="description">Leave empty to use the site admin address (' . esc_html( (string) get_option( 'admin_email' ) ) . ').</p>';
	}

	/**
	 * Keeps only a valid address, so a typo silently falls back to the admin
	 * email (see self::request_notify_email()) instead of sending nowhere.
	 *
	 * @param string $value Raw submitted value.
	 * @return string
	 */
	public function sanitize_notify_email( $value ) {
		$value = sanitize_email( (string) $value );

		return is_email( $value ) ? $value : '';
	}

	/**
	 * Where bike-request notifications go: the configured address, falling
	 * back to the site admin's.
	 *
	 * @return string Address, or '' when even the admin email is unusable.
	 */
	public static function request_notify_email() {
		$value = (string) get_option( 'citykleta_request_notify_email', '' );

		if ( is_email( $value ) ) {
			return $value;
		}

		$admin = (string) get_option( 'admin_email' );

		return is_email( $admin ) ? $admin : '';
	}

	/**
	 * Renders the default destination FAQ field: a plain textarea, one
	 * "Question :: Answer" pair per line — same format (and same reasoning:
	 * a JS repeater is overkill here) as Citykleta_Destination_Post_Type's
	 * per-destination override field, which parses this with the exact same
	 * Citykleta_Destination_Post_Type::parse_faq_text().
	 */
	public function render_destination_faq_field() {
		$value = get_option( 'citykleta_destination_faq_default', '' );
		echo '<textarea name="citykleta_destination_faq_default" rows="6" class="large-text">' . esc_textarea( $value ) . '</textarea>';
		echo '<p class="description">One question per line: "Question :: Answer".</p>';
	}

	/**
	 * Renders the marker icon's size value — read-only, see
	 * self::render_tile_style_field().
	 */
	public function render_icon_size_field() {
		echo '<p>' . (int) get_option( 'citykleta_map_marker_icon_size', 30 ) . ' px</p>';
	}

	/**
	 * Reschedules the sync when the frequency is saved for the first time
	 * (the option didn't exist yet).
	 *
	 * @param string $option Option name.
	 * @param string $value  New value.
	 */
	public function on_frequency_added( $option, $value ) {
		( new Citykleta_Bike_Sync() )->reschedule( $value );
	}

	/**
	 * Reschedules the sync when an existing frequency changes.
	 *
	 * @param string $old_value Previous value.
	 * @param string $value     New value.
	 */
	public function on_frequency_updated( $old_value, $value ) {
		( new Citykleta_Bike_Sync() )->reschedule( $value );
	}

	/**
	 * Flags that a rewrite rules flush is needed once the slug changes.
	 * Can't flush right here: the CPT for this same request was already
	 * registered (on 'init') with the old slug. It flushes on the next
	 * load, after the CPT registers with the new slug — see
	 * Citykleta_Bike_Post_Type::register().
	 */
	public function on_slug_changed() {
		update_option( 'citykleta_flush_rewrite_flag', '1' );
	}

	/**
	 * Same idea as self::on_slug_changed(), separate flag: by the time
	 * Citykleta_Bike_Post_Type::register() checks its own flag, the
	 * Destinations CPT (registered right after it in citykleta_init()) and
	 * the taxonomies (registered after that) haven't run yet on this
	 * request, so a flush there wouldn't pick up this slug's fresh rewrite
	 * rules. This flag is checked at the very end of citykleta_init()
	 * instead — see citykleta-shared-bikes.php — once everything has
	 * registered.
	 */
	public function on_destination_slug_changed() {
		update_option( 'citykleta_destination_flush_rewrite_flag', '1' );
	}

	/**
	 * The settings tabs, slug => label. Filterable so a separate plugin (e.g.
	 * the HubSpot connector) can add its own tab to this page without its code
	 * living here: it adds a slug/label via the 'citykleta_settings_tabs'
	 * filter and renders that tab's body on the matching
	 * 'citykleta_settings_render_tab_{slug}' action (see render_page()). The
	 * external tab owns its own <form>, option group, and settings_fields() —
	 * same "one option group per form" rule the built-in tabs follow.
	 *
	 * @return array<string,string>
	 */
	public static function tabs() {
		return apply_filters( 'citykleta_settings_tabs', self::TABS );
	}

	/**
	 * Renders the full settings page: tab nav + the active tab's form.
	 */
	public function render_page() {
		$tabs       = self::tabs();
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'sync';

		if ( ! array_key_exists( $active_tab, $tabs ) ) {
			$active_tab = 'sync';
		}

		$base_url = admin_url( 'edit.php?post_type=' . Citykleta_Bike_Post_Type::POST_TYPE . '&page=' . self::PAGE_SLUG );
		?>
		<div class="wrap">
			<h1>Settings</h1>

			<h2 class="nav-tab-wrapper">
				<?php foreach ( $tabs as $tab_slug => $tab_label ) : ?>
					<a href="<?php echo esc_url( $base_url . '&tab=' . $tab_slug ); ?>" class="nav-tab <?php echo $active_tab === $tab_slug ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $tab_label ); ?>
					</a>
				<?php endforeach; ?>
			</h2>

			<?php if ( 'maps' === $active_tab ) : ?>
				<?php $map_settings_last_sync = get_option( 'citykleta_map_settings_last_sync' ); ?>
				<div class="notice notice-info inline">
					<p>
						This tab is read-only: map settings are administered from Back Office and synced
						automatically on each "Sync now" / scheduled sync (see the "Map settings" path on the
						<a href="<?php echo esc_url( $base_url . '&tab=sync' ); ?>">Sync</a> tab).
						<?php if ( $map_settings_last_sync ) : ?>
							Last synced: <?php echo esc_html( $map_settings_last_sync ); ?>.
						<?php else : ?>
							Not synced yet.
						<?php endif; ?>
					</p>
				</div>
				<div class="citykleta-settings-maps-layout">
					<div class="citykleta-settings-maps-summary">
						<?php do_settings_sections( self::PAGE_SLUG . '-maps' ); ?>
					</div>
					<div class="citykleta-settings-maps-preview">
						<h2>Preview</h2>
						<?php $this->render_preview_map(); ?>
					</div>
				</div>
			<?php elseif ( 'comm' === $active_tab ) : ?>
				<form method="post" action="options.php">
					<?php
					settings_fields( self::OPTION_GROUP_COMM );
					do_settings_sections( self::PAGE_SLUG . '-comm' );
					submit_button( 'Save changes' );
					?>
				</form>
			<?php elseif ( 'dest' === $active_tab ) : ?>
				<p>Destination pages are managed under Bicicletas → Destinations — this tab only
				holds the FAQ every destination falls back to.</p>
				<form method="post" action="options.php">
					<?php
					settings_fields( self::OPTION_GROUP_DEST );
					do_settings_sections( self::PAGE_SLUG . '-dest' );
					submit_button( 'Save changes' );
					?>
				</form>
			<?php elseif ( 'tpl' === $active_tab ) : ?>
				<p>Layouts live under Bicicletas → <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . Citykleta_Template_Post_Type::POST_TYPE ) ); ?>">Templates</a>
				and are edited with Elementor. This tab only decides which one each view uses, so a
				redesign can be built and published alongside the current layout and switched to
				(or away from) in one step.</p>
				<form method="post" action="options.php">
					<?php
					settings_fields( self::OPTION_GROUP_TPL );
					do_settings_sections( self::PAGE_SLUG . '-tpl' );
					submit_button( 'Save changes' );
					?>
				</form>
			<?php elseif ( 'frontend' === $active_tab ) : ?>
				<?php $this->render_frontend_tab(); ?>
			<?php elseif ( 'sync' === $active_tab ) : ?>
				<?php if ( isset( $_GET['citykleta_synced'] ) ) : ?>
					<div class="notice notice-success"><p>Sync completed.</p></div>
				<?php endif; ?>

				<?php $this->render_status(); ?>

				<p>These settings control where bikes are fetched from and how often they're updated.</p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="citykleta_manual_sync" />
					<?php wp_nonce_field( 'citykleta_manual_sync', 'citykleta_sync_nonce' ); ?>
					<?php submit_button( 'Sync now', 'secondary' ); ?>
				</form>

				<form method="post" action="options.php">
					<?php
					settings_fields( self::OPTION_GROUP_SYNC );
					do_settings_sections( self::PAGE_SLUG . '-sync' );
					submit_button( 'Save changes' );
					?>
				</form>
			<?php else : ?>
				<?php
				/**
				 * Body of a tab registered by another plugin through the
				 * 'citykleta_settings_tabs' filter. That plugin hooks this
				 * action and prints its own <form> (its own option group +
				 * settings_fields()). See self::tabs().
				 */
				do_action( 'citykleta_settings_render_tab_' . $active_tab );
				?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Reference list of the shortcodes the plugin registers — read-only, no
	 * form to save. Exists purely so these aren't only written down in a
	 * chat transcript: each one is also an Elementor widget (Citykleta_Search_Widget,
	 * Citykleta_Bike_Widget, Citykleta_Map_Widget, grouped under the "Shared
	 * Bikes" category in the widgets panel), registered as a shortcode too
	 * by Citykleta_Widget_Shortcodes so they also work outside Elementor, or
	 * loose in a page's content.
	 */
	private function render_frontend_tab() {
		// Row data kept separate from markup so each row is one clean line
		// of intent — one shortcode, one plain-English sentence — instead of
		// packing every variant/attribute into a single dense paragraph.
		$rows = array(
			array(
				'code'    => '[citykleta_search]',
				'summary' => 'Search bar — location, dates, electric-only.',
				'detail'  => 'Submits a GET request to the bikes archive, or wherever <code>results_url</code> points.',
			),
			array(
				'code'    => '[citykleta_grid]',
				'summary' => 'Fixed bike grid — a curated set, not tied to any search.',
				'detail'  => 'Attributes match the widget\'s own Content/Query controls: <code>columns</code>, <code>count</code>, <code>masonry</code>, <code>orderby</code>, <code>order</code>, <code>location_filter</code>…',
			),
			array(
				'code'    => '[citykleta_grid filters="url"]',
				'summary' => 'Same grid, but follows the page\'s search instead.',
				'detail'  => 'Reads location/electric from whatever <code>[citykleta_search]</code> put in the URL, and paginates for real (<code>posts_per_page</code>, default 10) instead of stopping at a fixed count.',
			),
			array(
				'code'    => '[citykleta_map]',
				'summary' => 'Bikes map, fixed height.',
				'detail'  => '<code>map_height</code> sets the height in pixels (default 400) — the one setting that always takes effect even from a shortcode, since Leaflet draws nothing without an explicit height.',
			),
			array(
				'code'    => '[citykleta_map filters="url"]',
				'summary' => 'Same map, but follows the page\'s search too.',
				'detail'  => 'Same <code>filters="url"</code> as the grid — put both on the same page and they stay in sync.',
			),
			array(
				'code'    => '[citykleta_bike_categories]',
				'summary' => 'Bike categories as a horizontal list.',
				'detail'  => '<code>taxonomy="bike_type"</code> (the default: bike subtypes, each linking to its own term archive), <code>taxonomy="bike_usage"</code> (the top-level bike types, linking to the archive with <code>?usage=</code> applied) or <code>taxonomy="bike_tag"</code> (tags). Also <code>orderby</code>, <code>order</code>, <code>limit</code>, <code>show_count</code> (a type counts the bikes of all its subtypes) and <code>link</code>.',
			),
		);

		// The single-bike pieces. Listed apart because they behave
		// differently: they take no query settings at all, they just draw
		// whichever bike the visitor is looking at.
		$bike_parts = array(
			array( '[citykleta_bike_gallery]', 'Photo carousel.' ),
			array( '[citykleta_bike_title]', 'The bike\'s title.' ),
			array( '[citykleta_bike_location_tags]', 'Location + bike type tags.' ),
			array( '[citykleta_bike_owner]', '"Publicado por" + owner photo.' ),
			array( '[citykleta_bike_description]', "The bike's own description." ),
			array( '[citykleta_bike_specs]', 'Brand, model, size, wheel size, type, usage, electric.' ),
			array( '[citykleta_bike_directions]', 'The address, as a line of text.' ),
			array( '[citykleta_bike_request]', 'The two-step request form: period + verification code, then an optional message.' ),
			array( '[citykleta_bike_data]', 'One single value. field="price|description|address|owner|availability|destinations|views|brand|model|size|wheel_size|electric|bike_type|bike_usage|bike_tags" (bike_type = the subtype, bike_usage = the type).' ),
			array( '[citykleta_bike_location]', 'Small pickup map (the map widget, "Bike Data" mode).' ),
		);
		?>
		<style>
			.citykleta-frontend-ref { max-width: 820px; }
			.citykleta-frontend-ref > p { font-size: 14px; line-height: 1.7; color: #3c434a; }
			.citykleta-frontend-ref table { border-collapse: collapse; width: 100%; margin: 1.5em 0; background: #fff; }
			.citykleta-frontend-ref th { text-align: left; font-size: 12px; text-transform: uppercase; letter-spacing: .04em; color: #646970; padding: 0 0 10px; }
			.citykleta-frontend-ref td { padding: 18px 24px 18px 0; border-top: 1px solid #e5e0d3; vertical-align: top; }
			.citykleta-frontend-ref tr:first-child td { border-top: 1px solid #dcdcde; }
			.citykleta-frontend-ref td.citykleta-frontend-ref__code { width: 300px; padding-right: 20px; }
			.citykleta-frontend-ref code { display: inline-block; background: #f0ece0; border: 1px solid #e5e0d3; border-radius: 4px; padding: 4px 8px; font-size: 13px; line-height: 1.4; }
			.citykleta-frontend-ref__summary { display: block; font-size: 14px; font-weight: 600; color: #1d2327; margin-bottom: 6px; }
			.citykleta-frontend-ref__detail { display: block; font-size: 13px; line-height: 1.6; color: #646970; }
			.citykleta-frontend-ref__detail code { padding: 2px 5px; font-size: 12px; }
		</style>

		<div class="citykleta-frontend-ref">
			<p>Every shortcode below is also an Elementor widget under the "Shared Bikes" category.
			One thing doesn't carry over: Style-tab customization set on a saved widget instance —
			a shortcode always renders with the plugin's own default look
			(<code>bike-cards.css</code> / <code>search-bar.css</code> / <code>citykleta-map.css</code>).</p>

			<table>
				<thead>
					<tr>
						<th>Shortcode</th>
						<th>What it does</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td class="citykleta-frontend-ref__code"><code><?php echo esc_html( $row['code'] ); ?></code></td>
							<td>
								<span class="citykleta-frontend-ref__summary"><?php echo esc_html( $row['summary'] ); ?></span>
								<span class="citykleta-frontend-ref__detail"><?php echo wp_kses( $row['detail'], array( 'code' => array() ) ); ?></span>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2 style="margin-top:2em;">Single bike pieces</h2>
			<p>These draw whichever bike the visitor is looking at, so they take no query
			settings — drop them on the "Single bike" template and arrange them freely.
			On any other page they render nothing (in the Elementor editor they show the
			most recent bike, so you can see what you're designing).</p>

			<table>
				<tbody>
					<?php foreach ( $bike_parts as $part ) : ?>
						<tr>
							<td class="citykleta-frontend-ref__code"><code><?php echo esc_html( $part[0] ); ?></code></td>
							<td><span class="citykleta-frontend-ref__summary"><?php echo esc_html( $part[1] ); ?></span></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<p>Both the results page (<code>/bikes/</code>) and each bike's page are drawn from
			layouts you can edit: <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . Citykleta_Template_Post_Type::POST_TYPE ) ); ?>">Bicicletas&nbsp;→&nbsp;Templates</a>.
			Open one and hit "Edit with Elementor" — no Elementor Pro needed.</p>
		</div>
		<?php
	}

	/**
	 * Displays the status of the last sync.
	 */
	private function render_status() {
		$last_sync   = get_option( 'citykleta_last_sync' );
		$count       = get_option( 'citykleta_last_sync_count' );
		$retired     = (int) get_option( 'citykleta_last_sync_retired' );
		$error       = get_option( 'citykleta_last_sync_error' );
		$warning     = get_option( 'citykleta_last_sync_warning' );
		$image_error = get_option( 'citykleta_last_image_error' );

		echo '<p>';
		if ( $error ) {
			echo '<strong>Error on last sync:</strong> ' . esc_html( $error );
		} elseif ( $last_sync ) {
			echo 'Last sync: ' . esc_html( $last_sync ) . ' — ' . intval( $count ) . ' bikes processed';

			// Bikes that stopped coming from the API are moved to draft, not
			// deleted (see Citykleta_Bike_Sync::retire_missing()), so this is a
			// reversible action worth reporting rather than applying silently.
			if ( $retired > 0 ) {
				echo ', ' . $retired . ' no longer in the API moved to draft';
			}

			echo '.';
		} else {
			echo 'No sync has run yet.';
		}
		echo '</p>';

		// Not a failure: the sync ran fine but deliberately held back from
		// retiring anything because the response looked partial. Kept apart
		// from $error so a cautious run doesn't read as a broken one.
		if ( $warning ) {
			echo '<p><strong>Heads up:</strong> ' . esc_html( $warning ) . '</p>';
		}

		// Only genuine fetch failures reach this now. An unsupported file type
		// (SVG, say) is reported as a "Heads up" above instead: the bikes sync
		// fine and only their pictures are missing, so it isn't an error.
		// Both carry a count — Citykleta_Bike_Sync summarises the whole run
		// rather than reporting whatever happened to the last bike.
		if ( $image_error ) {
			echo '<p><strong>Error fetching images:</strong> ' . esc_html( $image_error ) . '</p>';
		}
	}
}
