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

	/**
	 * Settings page slug.
	 */
	const PAGE_SLUG = 'citykleta-settings';

	/**
	 * Tabs shown on the settings page, in order.
	 */
	const TABS = array(
		'sync' => 'Sync',
		'maps' => 'Maps',
		'comm' => 'Communication',
		'dest' => 'Destinations',
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
		'{price}' => 'Price per hour',
		'{url}'   => 'Link to the bike page',
	);

	/**
	 * Default WhatsApp message when the admin hasn't set one. Front-end
	 * copy is Spanish (like the templates), even though labels/identifiers
	 * stay English.
	 */
	const DEFAULT_MESSAGE = 'Hola {owner}, vi tu bicicleta "{bike}" en Citykleta y me interesa alquilarla. ¿Está disponible? {url}';

	/**
	 * API resources whose PATH is still user-configurable, keyed by the slug
	 * used in option names (citykleta_path_{key}). To add a new synced
	 * resource whose path may need to change by hand, add an entry here and
	 * read it with self::get_endpoint_url( $key ) from the new sync class —
	 * no other change to this settings page is needed, the path field is
	 * generated from this list.
	 *
	 * A resource whose real path on citykleta-sharing-api is already
	 * confirmed and stable does NOT belong here — see self::MAP_SETTINGS_PATH
	 * for why 'map_settings' was pulled out. bikes/bike-types stay here
	 * because their defaults below don't actually match sharing-api's real
	 * routes yet (bikes is really GET /bikesPublic/sync; bike-types has no
	 * public route at all today, only the staff-only GET /bike-types) — see
	 * HANDOFF-map-config-backoffice.md. Once those are reconciled, they
	 * should probably move to a fixed path too, the same way map_settings did.
	 */
	const ENDPOINTS = array(
		'bikes'      => array(
			'label'        => 'Bikes',
			'default_path' => '/bikes',
		),
		'bike_types' => array(
			'label'        => 'Bike types',
			'default_path' => '/bike-types',
		),
	);

	/**
	 * Fixed path for the map settings resource — confirmed against
	 * citykleta-sharing-api's actual GET /map-settings while implementing the
	 * Back Office side of the map-config hand-off (see CLAUDE.md and
	 * HANDOFF-map-config-backoffice.md). Not in self::ENDPOINTS: unlike
	 * bikes/bike-types, this one real contract is settled, so there's no
	 * reason to leave it editable in wp-admin where a typo could silently
	 * break map sync.
	 */
	const MAP_SETTINGS_PATH = '/map-settings';

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
	}

	/**
	 * Builds the full URL for a synced resource — the configured base URL
	 * plus that resource's path. Used by sync classes instead of reading a
	 * full URL option directly. 'map_settings' resolves against the fixed
	 * self::MAP_SETTINGS_PATH; every other key resolves against
	 * self::ENDPOINTS' user-configurable path option.
	 *
	 * @param string $key Endpoint key, e.g. 'bikes', 'bike_types', or 'map_settings'.
	 * @return string Empty string if $key isn't a known endpoint.
	 */
	public static function get_endpoint_url( $key ) {
		$base = get_option( 'citykleta_api_base_url', home_url( '/wp-json/citykleta-mock/v1' ) );

		// Fixed path, not read from an option — see self::MAP_SETTINGS_PATH.
		if ( 'map_settings' === $key ) {
			return rtrim( $base, '/' ) . '/' . ltrim( self::MAP_SETTINGS_PATH, '/' );
		}

		if ( ! isset( self::ENDPOINTS[ $key ] ) ) {
			return '';
		}

		$path = get_option( 'citykleta_path_' . $key, self::ENDPOINTS[ $key ]['default_path'] );

		return rtrim( $base, '/' ) . '/' . ltrim( $path, '/' );
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
	 * Citykleta_Destination_Taxonomy::faq_for() for any destination that
	 * hasn't switched on its own override.
	 *
	 * @return array
	 */
	public static function destination_faq_default() {
		return Citykleta_Destination_Taxonomy::parse_faq_text( get_option( 'citykleta_destination_faq_default', '' ) );
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
				'default'           => home_url( '/wp-json/citykleta-mock/v1' ),
			)
		);

		foreach ( self::ENDPOINTS as $key => $endpoint ) {
			register_setting(
				self::OPTION_GROUP_SYNC,
				'citykleta_path_' . $key,
				array(
					'type'              => 'string',
					'sanitize_callback' => array( $this, 'sanitize_path' ),
					'default'           => $endpoint['default_path'],
				)
			);
		}

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

		foreach ( self::ENDPOINTS as $key => $endpoint ) {
			add_settings_field(
				'citykleta_path_' . $key,
				$endpoint['label'],
				array( $this, 'render_path_field' ),
				self::PAGE_SLUG . '-sync',
				'citykleta_sync_section',
				array(
					'key'          => $key,
					'default_path' => $endpoint['default_path'],
				)
			);
		}

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
				echo '<p>Size for individual bike markers — a fixed circle showing the built-in bike glyph, colored by availability (not configurable, see assets/css/citykleta-map.css). Used by the Elementor "Bikes Map" widget and the wp-admin overview page (Bicicletas → Map), so there\'s one place to keep them on-brand.</p>';
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
				echo '<p>Shown on every destination page (Destinations → a term, e.g. "Vedado"), unless that destination has its own FAQ switched on — see the "Custom FAQ" field on the term\'s edit screen.</p>';
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
	 * Normalizes an endpoint path so it always starts with a single slash.
	 *
	 * @param string $value Value submitted by the form.
	 * @return string
	 */
	public function sanitize_path( $value ) {
		$value = trim( $value );
		return '/' . ltrim( $value, '/' );
	}

	/**
	 * Renders the API base URL field.
	 */
	public function render_api_base_url_field() {
		$value = get_option( 'citykleta_api_base_url' );
		echo '<input type="url" name="citykleta_api_base_url" value="' . esc_attr( $value ) . '" class="regular-text" placeholder="https://server.domain:6453" />';
		echo '<p class="description">Base URL of the Sharing App API. Each resource below is appended to it.</p>';
	}

	/**
	 * Renders one resource's path field, plus a preview of the full URL
	 * it resolves to. Shared by every entry in self::ENDPOINTS.
	 *
	 * @param array $args { 'key' => string, 'default_path' => string }.
	 */
	public function render_path_field( $args ) {
		$key   = $args['key'];
		$value = get_option( 'citykleta_path_' . $key, $args['default_path'] );

		echo '<input type="text" name="citykleta_path_' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" class="regular-text" style="width:220px" placeholder="' . esc_attr( $args['default_path'] ) . '" />';
		echo '<p class="description">→ <code>' . esc_html( self::get_endpoint_url( $key ) ) . '</code></p>';
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
	 * Renders the default destination FAQ field: a plain textarea, one
	 * "Question :: Answer" pair per line — same format (and same reasoning:
	 * a JS repeater is overkill here) as Citykleta_Destination_Taxonomy's
	 * per-destination override field, which parses this with the exact same
	 * Citykleta_Destination_Taxonomy::parse_faq_text().
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
				<p>Destination pages are managed as terms under Bicicletas → Destinations — this tab only
				holds the FAQ every destination falls back to.</p>
				<form method="post" action="options.php">
					<?php
					settings_fields( self::OPTION_GROUP_DEST );
					do_settings_sections( self::PAGE_SLUG . '-dest' );
					submit_button( 'Save changes' );
					?>
				</form>
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
	 * Displays the status of the last sync.
	 */
	private function render_status() {
		$last_sync   = get_option( 'citykleta_last_sync' );
		$count       = get_option( 'citykleta_last_sync_count' );
		$error       = get_option( 'citykleta_last_sync_error' );
		$image_error = get_option( 'citykleta_last_image_error' );

		echo '<p>';
		if ( $error ) {
			echo '<strong>Error on last sync:</strong> ' . esc_html( $error );
		} elseif ( $last_sync ) {
			echo 'Last sync: ' . esc_html( $last_sync ) . ' — ' . intval( $count ) . ' bikes processed.';
		} else {
			echo 'No sync has run yet.';
		}
		echo '</p>';

		if ( $image_error ) {
			echo '<p><strong>Error fetching an image:</strong> ' . esc_html( $image_error ) . '</p>';
		}
	}
}
