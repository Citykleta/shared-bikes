<?php
/**
 * Connects the plugin with Elementor: registers the bike widgets if
 * Elementor is active.
 *
 * @package Citykleta_Shared_Bikes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Citykleta_Elementor.
 */
class Citykleta_Elementor {

	/**
	 * Connects the class to Elementor's widget registration hook. Does
	 * nothing if Elementor isn't installed/active.
	 */
	public function register() {
		add_action( 'elementor/elements/categories_registered', array( $this, 'register_category' ) );
		add_action( 'elementor/widgets/register', array( $this, 'register_widgets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Loads the bike card CSS early (wp_enqueue_scripts), not inside the
	 * widget's render().
	 *
	 * Enqueuing a style inside render() works on a normal page load, but
	 * not inside the Elementor editor: there the widget is redrawn via
	 * AJAX after <head> was already printed, so the <link> never makes it
	 * out and the widget looks unstyled.
	 */
	public function enqueue_styles() {
		wp_enqueue_style(
			'citykleta-bike-cards',
			CITYKLETA_URL . 'assets/css/bike-cards.css',
			array(),
			CITYKLETA_VERSION
		);

		wp_enqueue_style(
			'citykleta-search-bar',
			CITYKLETA_URL . 'assets/css/search-bar.css',
			array(),
			CITYKLETA_VERSION
		);

		// Pieces of a single-bike layout (Citykleta_Bike_Part_Widgets) can be
		// dropped on any page now, not just a bike's own — including the
		// Elementor canvas while a template is being designed. Same
		// "enqueue early, not inside render()" reason as everything else
		// here; templates/single-bike.php no longer has to load these
		// itself. Splide is vendor and may be missing (see CLAUDE.md).
		if ( file_exists( CITYKLETA_PATH . 'assets/js/splide.min.js' ) ) {
			wp_enqueue_style(
				'splide',
				CITYKLETA_URL . 'assets/css/splide.min.css',
				array(),
				CITYKLETA_VERSION
			);
		}

		wp_enqueue_style(
			'citykleta-single-bike',
			CITYKLETA_URL . 'assets/css/single-bike.css',
			array(),
			CITYKLETA_VERSION
		);

		wp_enqueue_style(
			'citykleta-bike-request',
			CITYKLETA_URL . 'assets/css/bike-request.css',
			array(),
			CITYKLETA_VERSION
		);

		// Flatpickr (date-range calendar) — see enqueue_scripts() for why
		// it's loaded early instead of inside render().
		if ( file_exists( CITYKLETA_PATH . 'assets/css/flatpickr.min.css' ) ) {
			wp_enqueue_style(
				'flatpickr',
				CITYKLETA_URL . 'assets/css/flatpickr.min.css',
				array(),
				CITYKLETA_VERSION
			);

			wp_enqueue_style(
				'citykleta-search-bar-datepicker',
				CITYKLETA_URL . 'assets/css/search-bar-datepicker.css',
				array( 'flatpickr' ),
				CITYKLETA_VERSION
			);
		}

		// Tom Select ("usage type" selector with descriptions) — see
		// enqueue_scripts() for why it's loaded early.
		if ( file_exists( CITYKLETA_PATH . 'assets/css/tom-select.min.css' ) ) {
			wp_enqueue_style(
				'tom-select',
				CITYKLETA_URL . 'assets/css/tom-select.min.css',
				array(),
				CITYKLETA_VERSION
			);

			wp_enqueue_style(
				'citykleta-search-bar-usage',
				CITYKLETA_URL . 'assets/css/search-bar-usage.css',
				array( 'tom-select' ),
				CITYKLETA_VERSION
			);
		}

		// Leaflet + Leaflet.markercluster (bikes map widget) — see
		// enqueue_scripts() for why it's loaded early.
		if ( file_exists( CITYKLETA_PATH . 'assets/css/leaflet.css' ) ) {
			wp_enqueue_style(
				'leaflet',
				CITYKLETA_URL . 'assets/css/leaflet.css',
				array(),
				CITYKLETA_VERSION
			);

			wp_enqueue_style(
				'leaflet-markercluster',
				CITYKLETA_URL . 'assets/css/MarkerCluster.css',
				array( 'leaflet' ),
				CITYKLETA_VERSION
			);

			wp_enqueue_style(
				'leaflet-markercluster-default',
				CITYKLETA_URL . 'assets/css/MarkerCluster.Default.css',
				array( 'leaflet-markercluster' ),
				CITYKLETA_VERSION
			);

			wp_enqueue_style(
				'citykleta-map',
				CITYKLETA_URL . 'assets/css/citykleta-map.css',
				array( 'leaflet-markercluster-default' ),
				CITYKLETA_VERSION
			);
		}
	}

	/**
	 * Loads Flatpickr (library + Spanish locale) and the JS that
	 * initializes it on the search widget's date field.
	 *
	 * Same as the styles, this goes in wp_enqueue_scripts (not inside
	 * render()) so it also works inside the Elementor editor.
	 */
	public function enqueue_scripts() {
		if ( ! file_exists( CITYKLETA_PATH . 'assets/js/flatpickr.min.js' ) ) {
			return;
		}

		wp_enqueue_script(
			'flatpickr',
			CITYKLETA_URL . 'assets/js/flatpickr.min.js',
			array(),
			CITYKLETA_VERSION,
			true
		);

		wp_enqueue_script(
			'flatpickr-es',
			CITYKLETA_URL . 'assets/js/flatpickr-es.js',
			array( 'flatpickr' ),
			CITYKLETA_VERSION,
			true
		);

		$search_widget_deps = array( 'flatpickr-es' );

		// Tom Select ("usage type" selector with descriptions on open).
		if ( file_exists( CITYKLETA_PATH . 'assets/js/tom-select.complete.min.js' ) ) {
			wp_enqueue_script(
				'tom-select',
				CITYKLETA_URL . 'assets/js/tom-select.complete.min.js',
				array(),
				CITYKLETA_VERSION,
				true
			);

			$search_widget_deps[] = 'tom-select';
		}

		wp_enqueue_script(
			'citykleta-search-widget',
			CITYKLETA_URL . 'assets/js/citykleta-search-widget.js',
			$search_widget_deps,
			CITYKLETA_VERSION,
			true
		);

		// Splide + the gallery glue, for the Bike Gallery widget. Same
		// reasoning as the styles above: these widgets are no longer tied to
		// templates/single-bike.php. Vendor file may be missing (CLAUDE.md).
		if ( file_exists( CITYKLETA_PATH . 'assets/js/splide.min.js' ) ) {
			wp_enqueue_script(
				'splide',
				CITYKLETA_URL . 'assets/js/splide.min.js',
				array(),
				CITYKLETA_VERSION,
				true
			);
		}

		wp_enqueue_script(
			'citykleta-bike-gallery',
			CITYKLETA_URL . 'assets/js/citykleta-bike-gallery.js',
			array( 'splide' ),
			CITYKLETA_VERSION,
			true
		);

		// The request form's own JS needs its REST base, so it goes through
		// the form class rather than being enqueued straight from here.
		Citykleta_Bike_Request_Form::enqueue_form_assets();

		// Leaflet + Leaflet.markercluster (bikes map widget).
		if ( file_exists( CITYKLETA_PATH . 'assets/js/leaflet.js' ) ) {
			wp_enqueue_script(
				'leaflet',
				CITYKLETA_URL . 'assets/js/leaflet.js',
				array(),
				CITYKLETA_VERSION,
				true
			);

			wp_enqueue_script(
				'leaflet-markercluster',
				CITYKLETA_URL . 'assets/js/leaflet.markercluster.js',
				array( 'leaflet' ),
				CITYKLETA_VERSION,
				true
			);

			wp_enqueue_script(
				'citykleta-map',
				CITYKLETA_URL . 'assets/js/citykleta-map.js',
				array( 'leaflet-markercluster' ),
				CITYKLETA_VERSION,
				true
			);
		}
	}

	/**
	 * Creates the "Shared Bikes" category to group the plugin's widgets in
	 * the Elementor panel, instead of mixing them into "General".
	 *
	 * @param \Elementor\Elements_Manager $elements_manager Elementor's category manager.
	 */
	public function register_category( $elements_manager ) {
		$elements_manager->add_category(
			'citykleta',
			array(
				'title' => 'Shared Bikes',
				'icon'  => 'eicon-posts-grid',
			)
		);
	}

	/**
	 * Registers the plugin's widgets.
	 *
	 * @param \Elementor\Widgets_Manager $widgets_manager Elementor's widget manager.
	 */
	public function register_widgets( $widgets_manager ) {
		require_once CITYKLETA_PATH . 'includes/elementor/class-citykleta-bike-widget.php';
		require_once CITYKLETA_PATH . 'includes/elementor/class-citykleta-search-widget.php';
		require_once CITYKLETA_PATH . 'includes/elementor/class-citykleta-map-widget.php';
		$widgets_manager->register( new Citykleta_Bike_Widget() );
		$widgets_manager->register( new Citykleta_Search_Widget() );
		$widgets_manager->register( new Citykleta_Map_Widget() );

		// The pieces a single-bike layout is built from. They resolve the
		// bike from the current query themselves, which is what lets one
		// layout serve every bike without Elementor Pro's Dynamic Tags —
		// see Citykleta_Bike_Part_Widget.
		require_once CITYKLETA_PATH . 'includes/elementor/class-citykleta-bike-part-widgets.php';
		$widgets_manager->register( new Citykleta_Bike_Gallery_Widget() );
		$widgets_manager->register( new Citykleta_Bike_Header_Widget() );
		$widgets_manager->register( new Citykleta_Bike_Owner_Widget() );
		$widgets_manager->register( new Citykleta_Bike_Description_Widget() );
		$widgets_manager->register( new Citykleta_Bike_Specs_Widget() );
		$widgets_manager->register( new Citykleta_Bike_Request_Widget() );
		$widgets_manager->register( new Citykleta_Bike_Directions_Widget() );

		// Same idea for a Destination's page: each piece resolves the
		// destination from the query, so one template serves every
		// destination. The bikes of a destination are NOT here — that's the
		// grid widget's "Destination Bikes" source, so the card markup and
		// its Style tab stay in one place.
		require_once CITYKLETA_PATH . 'includes/elementor/class-citykleta-destination-part-widgets.php';
		$widgets_manager->register( new Citykleta_Destination_Header_Widget() );
		$widgets_manager->register( new Citykleta_Destination_Description_Widget() );
		$widgets_manager->register( new Citykleta_Destination_Price_Widget() );
		$widgets_manager->register( new Citykleta_Destination_Articles_Widget() );
		$widgets_manager->register( new Citykleta_Destination_Faq_Widget() );
	}
}
