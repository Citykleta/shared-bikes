<?php
/**
 * Plugin Name: Citykleta Shared Bikes
 * Description: Integración de bicicletas compartidas de Citykleta.
 * Version: 0.1.1
 * Author: Yasser
 * License: GPL-2.0-or-later
 * Text Domain: citykleta-shared-bikes
 *
 * @package Citykleta_Shared_Bikes
 */

// Evita que se acceda al archivo directamente.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Constantes del plugin.
define( 'CITYKLETA_VERSION', '0.1.1' );
define( 'CITYKLETA_PATH', plugin_dir_path( __FILE__ ) );
define( 'CITYKLETA_URL', plugin_dir_url( __FILE__ ) );

// Mientras la API real de la app Bike Sharing no exista, se usa una API
// falsa local para desarrollar la sincronización. Poner en false (o borrar
// includes/mock/) en cuanto haya un endpoint real.
define( 'CITYKLETA_USE_MOCK_API', true );

/**
 * Carga los archivos que componen el plugin.
 */
require_once CITYKLETA_PATH . 'includes/post-types/class-bike-post-type.php';
require_once CITYKLETA_PATH . 'includes/taxonomies/class-citykleta-bike-type-taxonomy.php';
require_once CITYKLETA_PATH . 'includes/taxonomies/class-citykleta-bike-usage-taxonomy.php';
require_once CITYKLETA_PATH . 'includes/meta/class-bike-meta.php';
require_once CITYKLETA_PATH . 'includes/sync/class-citykleta-bike-type-sync.php';
require_once CITYKLETA_PATH . 'includes/sync/class-citykleta-bike-sync.php';
require_once CITYKLETA_PATH . 'includes/admin/class-citykleta-settings.php';
require_once CITYKLETA_PATH . 'includes/admin/class-citykleta-list-columns.php';
require_once CITYKLETA_PATH . 'includes/map/class-citykleta-bike-map-data.php';
require_once CITYKLETA_PATH . 'includes/admin/class-citykleta-admin-map.php';
require_once CITYKLETA_PATH . 'includes/front/class-citykleta-templates.php';
require_once CITYKLETA_PATH . 'includes/front/class-citykleta-bike-router.php';
require_once CITYKLETA_PATH . 'includes/stats/class-citykleta-view-stats.php';
require_once CITYKLETA_PATH . 'includes/stats/class-citykleta-search-stats.php';
require_once CITYKLETA_PATH . 'includes/subscribers/class-citykleta-subscriber.php';
require_once CITYKLETA_PATH . 'includes/subscribers/class-citykleta-subscriber-rest.php';
require_once CITYKLETA_PATH . 'includes/subscribers/class-citykleta-app-cta.php';
require_once CITYKLETA_PATH . 'includes/elementor/class-citykleta-elementor.php';

if ( CITYKLETA_USE_MOCK_API ) {
	require_once CITYKLETA_PATH . 'includes/mock/class-citykleta-mock-api.php';
}

/**
 * Arranca el plugin.
 */
function citykleta_init() {
	// Registra el tipo de contenido "Bicicleta".
	( new Citykleta_Bike_Post_Type() )->register();

	// Taxonomía de tipo de bicicleta (Road, MTB XC, Cargo bicycle, etc.).
	( new Citykleta_Bike_Type_Taxonomy() )->register();

	// Taxonomía de tipo de uso (Urbano, Carretera...), administrable desde
	// wp-admin, con su relación a tipos de bicicleta.
	( new Citykleta_Bike_Usage_Taxonomy() )->register();

	// Registra los campos personalizados (modelo, ubicación, precio, etc.).
	( new Citykleta_Bike_Meta() )->register();

	// Sincroniza las bicicletas desde la API configurada (mock por ahora).
	( new Citykleta_Bike_Sync() )->register();

	// Columnas de marca, ubicación e imagen en la lista de bicis de wp-admin.
	( new Citykleta_List_Columns() )->register();

	// Página de wp-admin con el mapa de todas las bicis con coordenadas.
	// Queda como segunda opción del menú "Bicicletas" (justo después de
	// "All Bikes", antes de "Bike types"/"Usages"/"Settings") — ver
	// Citykleta_Admin_Map::reposition_menu() para el porqué de cómo se logra.
	( new Citykleta_Admin_Map() )->register();

	// Página de configuración: URL de la API, frecuencia de sincronización
	// y colores del mapa.
	( new Citykleta_Settings() )->register();

	// Plantilla del archivo de bicis (grid de tarjetas de vista previa).
	( new Citykleta_Templates() )->register();

	// Permalinks individuales /bikes/{external_id}/{slug}/.
	( new Citykleta_Bike_Router() )->register();

	// Conteo de vistas por bici (endpoint REST + agregación por día).
	( new Citykleta_View_Stats() )->register();

	// Estadísticas de búsqueda: qué ubicaciones se buscan y cuáles no dan
	// resultados (huecos de oferta). Página "Searches" bajo Shared Bikes.
	( new Citykleta_Search_Stats() )->register();

	// Lista de Subscribers (leads del muro de descarga): CPT privado en
	// wp-admin, staging para sincronizar a HubSpot más adelante. WP no crea
	// cuentas ni verifica correo — solo guarda el lead con su consentimiento.
	( new Citykleta_Subscriber() )->register();

	// Endpoints REST del muro: capturar el sign-up y marcar la descarga.
	( new Citykleta_Subscriber_Rest() )->register();

	// Muro de descarga de la app (shortcode [citykleta_app_download]).
	( new Citykleta_App_Cta() )->register();

	// Widget de Elementor (no hace nada si Elementor no está activo).
	( new Citykleta_Elementor() )->register();

	// API falsa temporal (ver nota de CITYKLETA_USE_MOCK_API arriba).
	if ( CITYKLETA_USE_MOCK_API ) {
		( new Citykleta_Mock_Api() )->register();
	}
}
add_action( 'init', 'citykleta_init' );

/**
 * Al activar el plugin, refresca los enlaces permanentes
 * para que la URL de las bicicletas funcione correctamente.
 */
function citykleta_activate() {
	citykleta_init();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'citykleta_activate' );

/**
 * Al desactivar, limpia los enlaces permanentes y el evento de
 * sincronización programado en WP-Cron.
 */
function citykleta_deactivate() {
	( new Citykleta_Bike_Sync() )->clear_schedule();
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'citykleta_deactivate' );
