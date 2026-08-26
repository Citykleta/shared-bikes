<?php
/**
 * Syncs map display settings (base map tile style + access token, marker
 * icon size, cluster grouping behavior, cluster colors) from Back Office,
 * which now owns and administers this configuration — see the map-config
 * hand-off note in CLAUDE.md.
 *
 * Writes into the exact same wp_options the old editable "Maps" tab used
 * to save (citykleta_map_tile_style, citykleta_map_cluster_radius, etc.),
 * so every consumer — Citykleta_Settings::tile_layer() / map_colors() /
 * cluster_options() / marker_icon_size(), and everything built on top of
 * them (Citykleta_Map_Widget, Citykleta_Admin_Map) — keeps working
 * unchanged. Only *where* these options get written changed, not how
 * they're read.
 *
 * @package Citykleta_Shared_Bikes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Citykleta_Map_Settings_Sync class.
 */
class Citykleta_Map_Settings_Sync {

	/**
	 * Fetches the map settings endpoint and stores its values as the same
	 * options the old editable "Maps" tab used to save. Silently does
	 * nothing on any failure (no endpoint configured, network error,
	 * non-200, malformed body) — the plugin just keeps whatever was synced
	 * last, same as Citykleta_Bike_Type_Sync.
	 */
	public function run() {
		$url = Citykleta_Settings::get_endpoint_url( 'map_settings' );

		if ( empty( $url ) ) {
			return;
		}

		$response = wp_remote_get( $url, array( 'timeout' => 15 ) );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return;
		}

		// Bare object, no { data: … } envelope — unlike /bikes and /bike-types,
		// this matches citykleta-sharing-api's own convention for a single-object
		// resource (e.g. GET /general_settings/pricing), confirmed against its
		// actual GET /map-settings implementation while building the Back
		// Office side of this. See the map-config hand-off note in CLAUDE.md.
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) ) {
			return;
		}

		update_option( 'citykleta_map_tile_style', Citykleta_Settings::sanitize_tile_style( isset( $data['tile_style'] ) ? $data['tile_style'] : 'osm' ) );
		update_option( 'citykleta_map_tile_api_key', sanitize_text_field( isset( $data['tile_api_key'] ) ? $data['tile_api_key'] : '' ) );
		update_option( 'citykleta_map_marker_icon_size', Citykleta_Settings::sanitize_icon_size( isset( $data['marker_icon_size'] ) ? $data['marker_icon_size'] : 30 ) );
		update_option( 'citykleta_map_cluster_radius', Citykleta_Settings::sanitize_cluster_radius( isset( $data['cluster_radius'] ) ? $data['cluster_radius'] : 80 ) );
		update_option( 'citykleta_map_cluster_disable_zoom', Citykleta_Settings::sanitize_cluster_disable_zoom( isset( $data['cluster_disable_zoom'] ) ? $data['cluster_disable_zoom'] : 0 ) );

		$this->update_cluster_colors( isset( $data['cluster_colors'] ) && is_array( $data['cluster_colors'] ) ? $data['cluster_colors'] : array() );

		update_option( 'citykleta_map_settings_last_sync', current_time( 'mysql' ) );
	}

	/**
	 * Updates the three cluster color options from the payload's
	 * 'cluster_colors' object ({ small, medium, large }), matched against
	 * Citykleta_Settings::MAP_COLORS by stripping that constant's
	 * 'cluster-' key prefix.
	 *
	 * @param array $colors Payload's 'cluster_colors' value, e.g. { 'small' => '#fed94c', ... }.
	 */
	private function update_cluster_colors( array $colors ) {
		foreach ( Citykleta_Settings::MAP_COLORS as $key => $config ) {
			$payload_key = str_replace( 'cluster-', '', $key );
			$value       = isset( $colors[ $payload_key ] ) ? $colors[ $payload_key ] : '';

			update_option( $config['option'], Citykleta_Settings::sanitize_map_color( $value, $config['default'] ) );
		}
	}
}
