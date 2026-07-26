<?php
/**
 * Settings page: "Sync" tab (bike API URL, sync frequency, URL slug),
 * "Maps" tab (marker/cluster colors used by the bikes map everywhere it
 * appears — Elementor widget and the wp-admin overview page), and
 * "Communication" tab (the WhatsApp message template pre-filled on each
 * bike's contact box).
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
	 * Option groups used by the Settings API — one per tab, not shared.
	 *
	 * wp-admin/options.php processes every option registered under the
	 * submitted group, not just the ones present in that request's form: any
	 * option in the group that the form didn't include gets blanked out. A
	 * single group shared across tabs would mean saving "Sync" wipes "Maps"
	 * and vice versa, since each tab's <form> only contains its own fields.
	 * Separate groups keep each tab's save from touching the other's data.
	 */
	const OPTION_GROUP_SYNC = 'citykleta_settings_sync';
	const OPTION_GROUP_MAPS = 'citykleta_settings_maps';
	const OPTION_GROUP_COMM = 'citykleta_settings_comm';

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
	 * API resources this plugin syncs, keyed by the slug used in option
	 * names (citykleta_path_{key}). To add a new synced resource later,
	 * add an entry here and read it with self::get_endpoint_url( $key )
	 * from the new sync class — no other change to this settings page
	 * is needed, the base URL + path fields are generated from this list.
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
	 * Settings section IDs the "Maps" tab groups its fields into.
	 */
	const SECTION_TILE_STYLE     = 'citykleta_tile_style_section';
	const SECTION_MARKER_DESIGN  = 'citykleta_marker_design_section';
	const SECTION_CLUSTER_COLORS = 'citykleta_cluster_colors_section';

	/**
	 * Base map tile providers offered by the "Base Map" field (Maps tab).
	 * assets/js/citykleta-map.js already accepts a tileUrl/attribution pair
	 * per map instance (falls back to the 'osm' entry below if none is
	 * passed) — this just lets that be picked in wp-admin instead of edited
	 * in code, same "one place controls every map" reasoning as
	 * self::MAP_COLORS.
	 *
	 * The first six are each provider's free/keyless tile endpoint — fine
	 * for this LocalWP prototype, but not really meant for a live commercial
	 * app: OpenStreetMap's usage policy only allows light interactive
	 * traffic (operations.osmfoundation.org/policies/tiles/), CARTO's terms
	 * require an Enterprise license for commercial use
	 * (docs.carto.com/faqs/carto-basemaps), and Esri's World_Imagery/
	 * World_Topo don't publish a hard free-tier limit either. MapTiler,
	 * Stadia Maps, and Mapbox are the ones with a documented free tier that
	 * explicitly covers small commercial/production use — hence
	 * 'key_param': all three need an API key (see self::tile_layer(),
	 * citykleta_map_tile_api_key option). MapTiler's and Mapbox's requests
	 * fail without one; Stadia's tiles work keyless for light/dev use but a
	 * key is what makes production traffic count against a registered
	 * free-tier quota instead of being rate-limited as anonymous use.
	 */
	const TILE_STYLES = array(
		'osm'                   => array(
			'label'       => 'OpenStreetMap (default)',
			'tile_url'    => 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
			'attribution' => '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
		),
		'positron'              => array(
			'label'       => 'CARTO Positron (light, minimal)',
			'tile_url'    => 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png',
			'attribution' => '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors &copy; <a href="https://carto.com/attributions">CARTO</a>',
		),
		'voyager'               => array(
			'label'       => 'CARTO Voyager (light, more color)',
			'tile_url'    => 'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png',
			'attribution' => '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors &copy; <a href="https://carto.com/attributions">CARTO</a>',
		),
		'dark_matter'           => array(
			'label'       => 'CARTO Dark Matter (dark)',
			'tile_url'    => 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png',
			'attribution' => '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors &copy; <a href="https://carto.com/attributions">CARTO</a>',
		),
		'esri_imagery'          => array(
			'label'       => 'Esri World Imagery (satellite)',
			'tile_url'    => 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
			'attribution' => 'Tiles &copy; Esri',
		),
		'esri_topo'             => array(
			'label'       => 'Esri World Topo (topographic)',
			'tile_url'    => 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Topo_Map/MapServer/tile/{z}/{y}/{x}',
			'attribution' => 'Tiles &copy; Esri',
		),
		'maptiler_streets'      => array(
			'label'       => 'MapTiler Streets (needs API Key)',
			'tile_url'    => 'https://api.maptiler.com/maps/streets-v2/{z}/{x}/{y}.png',
			'attribution' => '&copy; <a href="https://www.maptiler.com/copyright/">MapTiler</a> &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
			'key_param'   => 'key',
		),
		'stadia_alidade_smooth' => array(
			'label'       => 'Stadia Maps — Alidade Smooth, light (API Key recommended)',
			'tile_url'    => 'https://tiles.stadiamaps.com/tiles/alidade_smooth/{z}/{x}/{y}{r}.png',
			'attribution' => '&copy; <a href="https://stadiamaps.com/">Stadia Maps</a> &copy; <a href="https://openmaptiles.org/">OpenMapTiles</a> &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
			'key_param'   => 'api_key',
		),
		'stadia_alidade_dark'   => array(
			'label'       => 'Stadia Maps — Alidade Smooth Dark (API Key recommended)',
			'tile_url'    => 'https://tiles.stadiamaps.com/tiles/alidade_smooth_dark/{z}/{x}/{y}{r}.png',
			'attribution' => '&copy; <a href="https://stadiamaps.com/">Stadia Maps</a> &copy; <a href="https://openmaptiles.org/">OpenMapTiles</a> &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
			'key_param'   => 'api_key',
		),
		// Mapbox's ToS technically also requires their logo to stay visible
		// on the map, not just this text credit — fine for a prototype,
		// worth double-checking their attribution guidelines again before a
		// real commercial launch.
		'mapbox'                => array(
			'label'       => 'Mapbox Streets (needs API Key)',
			'tile_url'    => 'https://api.mapbox.com/styles/v1/mapbox/streets-v12/tiles/{z}/{x}/{y}{r}',
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
	 * Builds the full URL for one of the resources listed in self::ENDPOINTS
	 * — the configured base URL plus that resource's path. Used by sync
	 * classes instead of reading a full URL option directly.
	 *
	 * @param string $key Endpoint key, e.g. 'bikes' or 'bike_types'.
	 * @return string Empty string if $key isn't a known endpoint.
	 */
	public static function get_endpoint_url( $key ) {
		if ( ! isset( self::ENDPOINTS[ $key ] ) ) {
			return '';
		}

		$base = get_option( 'citykleta_api_base_url', home_url( '/wp-json/citykleta-mock/v1' ) );
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
	 * Loads the color picker, only on this page and only when the Maps tab
	 * can actually be reached (it's always registered, so always enqueue
	 * on this screen — the JS only touches elements that exist).
	 *
	 * @param string $hook_suffix Current admin page's hook suffix.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( $hook_suffix !== $this->page_hook ) {
			return;
		}

		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );

		wp_add_inline_script(
			'wp-color-picker',
			'jQuery( function ( $ ) { $( ".citykleta-color-field" ).wpColorPicker(); } );'
		);

		// "API Key" field only makes sense for a self::TILE_STYLES entry
		// that declares a 'key_param' (MapTiler, Stadia Maps) — hides its
		// whole settings row for the other styles instead of leaving a
		// field there that self::tile_layer() would just ignore.
		$tile_styles_needing_key = array();

		foreach ( self::TILE_STYLES as $key => $config ) {
			if ( ! empty( $config['key_param'] ) ) {
				$tile_styles_needing_key[] = $key;
			}
		}

		wp_add_inline_script(
			'wp-color-picker',
			'jQuery( function ( $ ) {
				var citykletaTileKeysNeeded = ' . wp_json_encode( $tile_styles_needing_key ) . ';

				function citykletaToggleApiKeyRow() {
					var selected = $( "#citykleta_map_tile_style" ).val();
					$( "#citykleta_map_tile_api_key" ).closest( "tr" ).toggle( -1 !== $.inArray( selected, citykletaTileKeysNeeded ) );
				}

				citykletaToggleApiKeyRow();
				$( "#citykleta_map_tile_style" ).on( "change", citykletaToggleApiKeyRow );
			} );'
		);

		// Live preview map (right column of the Maps tab) — same assets as
		// the front-end map, loaded the same optional/graceful-degradation
		// way (file_exists() guard) as Citykleta_Admin_Map does. It renders
		// with whatever is currently *saved*, so it updates on each page
		// load after "Save changes" — not as you type/pick a color.
		if ( file_exists( CITYKLETA_PATH . 'assets/css/leaflet.css' ) ) {
			wp_enqueue_style( 'leaflet', CITYKLETA_URL . 'assets/css/leaflet.css', array(), CITYKLETA_VERSION );
			wp_enqueue_style( 'leaflet-markercluster', CITYKLETA_URL . 'assets/css/MarkerCluster.css', array( 'leaflet' ), CITYKLETA_VERSION );
			wp_enqueue_style( 'leaflet-markercluster-default', CITYKLETA_URL . 'assets/css/MarkerCluster.Default.css', array( 'leaflet-markercluster' ), CITYKLETA_VERSION );
			wp_enqueue_style( 'citykleta-map', CITYKLETA_URL . 'assets/css/citykleta-map.css', array( 'leaflet-markercluster-default' ), CITYKLETA_VERSION );

			wp_add_inline_style(
				'citykleta-map',
				'.citykleta-settings-maps-layout { display: flex; gap: 40px; align-items: flex-start; }
				.citykleta-settings-maps-form { flex: 1 1 50%; max-width: 600px; }
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

		$options = array_merge( array( 'centerOnUser' => false ), self::tile_layer() );
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

		register_setting(
			self::OPTION_GROUP_MAPS,
			'citykleta_map_tile_style',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_tile_style' ),
				'default'           => 'osm',
			)
		);

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

		register_setting(
			self::OPTION_GROUP_MAPS,
			'citykleta_map_tile_api_key',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		add_settings_field(
			'citykleta_map_tile_api_key',
			'API Key',
			array( $this, 'render_tile_api_key_field' ),
			self::PAGE_SLUG . '-maps',
			self::SECTION_TILE_STYLE
		);

		register_setting(
			self::OPTION_GROUP_MAPS,
			'citykleta_map_marker_icon_size',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_icon_size' ),
				'default'           => 30,
			)
		);

		foreach ( self::MAP_COLORS as $config ) {
			register_setting(
				self::OPTION_GROUP_MAPS,
				$config['option'],
				array(
					'type'              => 'string',
					// sanitize_hex_color() alone returns '' for a blank/invalid
					// submission, and that '' gets saved — after that,
					// get_option( $option, $default ) stops falling back to
					// $default, because the row now exists (just empty). Falling
					// back to the field's own default here keeps the option from
					// ever being saved blank in the first place.
					'sanitize_callback' => function ( $value ) use ( $config ) {
						$color = sanitize_hex_color( $value );
						return $color ? $color : $config['default'];
					},
					'default'           => $config['default'],
				)
			);
		}

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
	 * Clamps the marker icon's size to a sane range.
	 *
	 * @param mixed $value Value submitted by the form.
	 * @return int
	 */
	public function sanitize_icon_size( $value ) {
		$value = is_numeric( $value ) ? (int) $value : 30;
		return max( 12, min( 80, $value ) );
	}

	/**
	 * Validates that the saved tile style is one of self::TILE_STYLES.
	 *
	 * @param string $value Value submitted by the form.
	 * @return string
	 */
	public function sanitize_tile_style( $value ) {
		return isset( self::TILE_STYLES[ $value ] ) ? $value : 'osm';
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
	 * Renders the "Base Map" tile style field: a dropdown over self::TILE_STYLES.
	 */
	public function render_tile_style_field() {
		$value = get_option( 'citykleta_map_tile_style', 'osm' );
		echo '<select name="citykleta_map_tile_style" id="citykleta_map_tile_style">';
		foreach ( self::TILE_STYLES as $key => $config ) {
			echo '<option value="' . esc_attr( $key ) . '" ' . selected( $value, $key, false ) . '>' . esc_html( $config['label'] ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">Free/keyless tiles from each provider — fine for development, but check usage terms before relying on this for real production traffic (see CLAUDE.md). MapTiler, Stadia Maps, and Mapbox below are the ones with a free tier meant for actual production use.</p>';
	}

	/**
	 * Renders the API Key field used by any self::TILE_STYLES entry that
	 * declares a 'key_param' (currently MapTiler and Stadia Maps). Left
	 * empty when the selected style doesn't need one.
	 */
	public function render_tile_api_key_field() {
		$value = get_option( 'citykleta_map_tile_api_key', '' );
		echo '<input type="text" name="citykleta_map_tile_api_key" id="citykleta_map_tile_api_key" value="' . esc_attr( $value ) . '" class="regular-text" placeholder="Only needed for MapTiler / Stadia Maps / Mapbox styles" />';
		echo '<p class="description">Free account: <a href="https://www.maptiler.com/" target="_blank" rel="noopener noreferrer">maptiler.com</a> or <a href="https://www.mapbox.com/" target="_blank" rel="noopener noreferrer">mapbox.com</a> (both required — those tiles won\'t load without a key) or <a href="https://stadiamaps.com/" target="_blank" rel="noopener noreferrer">stadiamaps.com</a> (optional for light use, needed once you register a production domain). Ignored by the other styles above.</p>';
	}

	/**
	 * Renders one map color field. Shared by every entry in self::MAP_COLORS.
	 *
	 * @param array $args One of self::MAP_COLORS' entries: { 'option' => string, 'label' => string, 'default' => string }.
	 */
	public function render_color_field( $args ) {
		$value = get_option( $args['option'], $args['default'] );
		echo '<input type="text" class="citykleta-color-field" name="' . esc_attr( $args['option'] ) . '" value="' . esc_attr( $value ) . '" data-default-color="' . esc_attr( $args['default'] ) . '" />';
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
	 * Renders the marker icon's size field.
	 */
	public function render_icon_size_field() {
		$value = get_option( 'citykleta_map_marker_icon_size', 30 );
		echo '<input type="number" min="12" max="80" name="citykleta_map_marker_icon_size" value="' . esc_attr( $value ) . '" class="small-text" /> px';
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
	 * Renders the full settings page: tab nav + the active tab's form.
	 */
	public function render_page() {
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'sync';

		if ( ! array_key_exists( $active_tab, self::TABS ) ) {
			$active_tab = 'sync';
		}

		$base_url = admin_url( 'edit.php?post_type=' . Citykleta_Bike_Post_Type::POST_TYPE . '&page=' . self::PAGE_SLUG );
		?>
		<div class="wrap">
			<h1>Settings</h1>

			<h2 class="nav-tab-wrapper">
				<?php foreach ( self::TABS as $tab_slug => $tab_label ) : ?>
					<a href="<?php echo esc_url( $base_url . '&tab=' . $tab_slug ); ?>" class="nav-tab <?php echo $active_tab === $tab_slug ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $tab_label ); ?>
					</a>
				<?php endforeach; ?>
			</h2>

			<?php if ( 'maps' === $active_tab ) : ?>
				<div class="citykleta-settings-maps-layout">
					<div class="citykleta-settings-maps-form">
						<form method="post" action="options.php">
							<?php
							settings_fields( self::OPTION_GROUP_MAPS );
							do_settings_sections( self::PAGE_SLUG . '-maps' );
							submit_button( 'Save changes' );
							?>
						</form>
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
			<?php else : ?>
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
