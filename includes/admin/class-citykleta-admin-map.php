<?php
/**
 * Admin page: map of every bike that has coordinates, so the team can see
 * at a glance where bikes actually are without leaving wp-admin.
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
	 * Page slug.
	 */
	const PAGE_SLUG = 'citykleta-bikes-map';

	/**
	 * Hook suffix returned by add_submenu_page(), used so the map assets
	 * only load on this specific admin page, not on every wp-admin screen.
	 *
	 * @var string
	 */
	private $page_hook = '';

	/**
	 * Connects the class to WordPress hooks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		// Runs after everything else has added its submenu — see
		// reposition_menu() for why an earlier priority on add_menu()
		// itself can't achieve this.
		add_action( 'admin_menu', array( $this, 'reposition_menu' ), 999 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Adds the map page as a submenu of the bike CPT menu.
	 */
	public function add_menu() {
		$this->page_hook = add_submenu_page(
			'edit.php?post_type=' . Citykleta_Bike_Post_Type::POST_TYPE,
			'Bikes Map',
			'Bikes Map',
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Moves "Bikes Map" to right after "All Bikes" in the sidebar, before
	 * the taxonomy submenus (Bike types, Usages).
	 *
	 * A hook priority on add_menu() itself can't do this: WordPress core
	 * builds $submenu['edit.php?post_type=bike'][5] ("All Bikes") and the
	 * taxonomy entries (keys 15, 20) directly in wp-admin/menu.php, as plain
	 * procedural code that runs *before* the 'admin_menu' action fires at
	 * all — so no matter how early our own add_submenu_page() call runs
	 * relative to other 'admin_menu' callbacks, core's entries already
	 * exist first. The only way to land between them is to move our entry
	 * after the fact, once every submenu (core's and ours) has been added —
	 * hence priority 999 here. add_submenu_page() re-sorts $submenu by key
	 * (ksort()) after every call, which is also why re-keying our entry to
	 * 6 and sorting again is enough to make it "stick" in that position.
	 */
	public function reposition_menu() {
		global $submenu;

		$parent = 'edit.php?post_type=' . Citykleta_Bike_Post_Type::POST_TYPE;

		if ( empty( $submenu[ $parent ] ) ) {
			return;
		}

		foreach ( $submenu[ $parent ] as $key => $item ) {
			if ( isset( $item[2] ) && self::PAGE_SLUG === $item[2] ) {
				$entry = $item;
				unset( $submenu[ $parent ][ $key ] );
				$submenu[ $parent ][6] = $entry;
				break;
			}
		}

		ksort( $submenu[ $parent ] );
	}

	/**
	 * Loads Leaflet + citykleta-map.js/css, only on this page.
	 *
	 * @param string $hook_suffix Current admin page's hook suffix.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( $hook_suffix !== $this->page_hook ) {
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
	 * Renders the page.
	 */
	public function render_page() {
		$bikes   = Citykleta_Bike_Map_Data::query( array( 'count' => -1 ) );
		$total   = Citykleta_Bike_Map_Data::count_all();
		$missing = $total - count( $bikes );
		?>
		<div class="wrap">
			<h1>Bikes Map</h1>

			<?php if ( $missing > 0 ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php echo (int) $missing; ?> bike<?php echo 1 === $missing ? '' : 's'; ?>
						<?php echo 1 === $missing ? 'has' : 'have'; ?> no coordinates yet and
						<?php echo 1 === $missing ? "isn't" : "aren't"; ?> shown on the map — run a sync
						(Bicicletas → Settings → Sync) to pull them in.
					</p>
				</div>
			<?php endif; ?>

			<?php if ( ! $bikes ) : ?>
				<p>No bikes to show yet.</p>
			<?php else : ?>
				<p><?php echo count( $bikes ); ?> bike<?php echo 1 === count( $bikes ) ? '' : 's'; ?> shown.</p>
				<?php
				$options              = array_merge( array( 'centerOnUser' => false ), Citykleta_Settings::tile_layer() );
				$options['iconSize'] = Citykleta_Settings::marker_icon_size();

				printf(
					'<div id="citykleta-admin-map" class="citykleta-map" style="height:500px;max-width:1200px;%3$s" data-bikes="%1$s" data-options="%2$s"></div>',
					esc_attr( wp_json_encode( $bikes ) ),
					esc_attr( wp_json_encode( $options ) ),
					esc_attr( Citykleta_Settings::map_color_style_attr() )
				);
				?>
			<?php endif; ?>
		</div>
		<?php
	}
}
