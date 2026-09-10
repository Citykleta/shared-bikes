<?php
/**
 * Plugin Name: Citykleta Shared Bikes
 * Description: Integración de bicicletas compartidas de Citykleta.
 * Version: 0.1.9
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
define( 'CITYKLETA_VERSION', '0.1.9' );
define( 'CITYKLETA_PATH', plugin_dir_path( __FILE__ ) );
define( 'CITYKLETA_URL', plugin_dir_url( __FILE__ ) );

/**
 * Carga los archivos que componen el plugin.
 */
require_once CITYKLETA_PATH . 'includes/post-types/class-bike-post-type.php';
require_once CITYKLETA_PATH . 'includes/post-types/class-citykleta-destination-post-type.php';
require_once CITYKLETA_PATH . 'includes/taxonomies/class-citykleta-bike-type-taxonomy.php';
require_once CITYKLETA_PATH . 'includes/taxonomies/class-citykleta-bike-usage-taxonomy.php';
require_once CITYKLETA_PATH . 'includes/meta/class-bike-meta.php';
require_once CITYKLETA_PATH . 'includes/meta/class-citykleta-owner-contact.php';
require_once CITYKLETA_PATH . 'includes/sync/class-citykleta-bike-type-sync.php';
require_once CITYKLETA_PATH . 'includes/sync/class-citykleta-map-settings-sync.php';
require_once CITYKLETA_PATH . 'includes/sync/class-citykleta-destination-matcher.php';
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
require_once CITYKLETA_PATH . 'includes/requests/class-citykleta-bike-request.php';
require_once CITYKLETA_PATH . 'includes/requests/class-citykleta-bike-request-rest.php';
require_once CITYKLETA_PATH . 'includes/requests/class-citykleta-bike-request-form.php';
require_once CITYKLETA_PATH . 'includes/requests/class-citykleta-bike-request-notifier.php';
require_once CITYKLETA_PATH . 'includes/elementor/class-citykleta-elementor.php';
require_once CITYKLETA_PATH . 'includes/elementor/class-citykleta-widget-shortcodes.php';

/**
 * Arranca el plugin.
 */
function citykleta_init() {
	// Registra el tipo de contenido "Bicicleta".
	( new Citykleta_Bike_Post_Type() )->register();

	// Tipo de contenido "Destination" (Vedado, Playa...), creado a mano en
	// wp-admin. La asignación bici → destino la calcula
	// Citykleta_Destination_Matcher por geocerca, no se elige a mano (ver
	// Citykleta_Bike_Sync::run()).
	( new Citykleta_Destination_Post_Type() )->register();

	// Taxonomía de tipo de bicicleta (Road, MTB XC, Cargo bicycle, etc.).
	( new Citykleta_Bike_Type_Taxonomy() )->register();

	// Taxonomía de tipo de uso (Urbano, Carretera...), administrable desde
	// wp-admin, con su relación a tipos de bicicleta.
	( new Citykleta_Bike_Usage_Taxonomy() )->register();

	// Registra los campos personalizados (modelo, ubicación, precio, etc.).
	( new Citykleta_Bike_Meta() )->register();

	// Contacto del propietario: el único campo de la bici que NO se sincroniza
	// (el catálogo público de la app no trae PII del dueño), se escribe a mano
	// en wp-admin para poder hacerle llegar una solicitud.
	( new Citykleta_Owner_Contact() )->register();

	// Sincroniza las bicicletas desde la API configurada.
	( new Citykleta_Bike_Sync() )->register();

	// Columnas de marca, ubicación e imagen en la lista de bicis de wp-admin.
	( new Citykleta_List_Columns() )->register();

	// Mapa de todas las bicis con coordenadas, mostrado arriba de la lista
	// "All Bikes" (fusionado ahí en vez de ser su propia página aparte).
	( new Citykleta_Admin_Map() )->register();

	// Página de configuración: URL de la API, frecuencia de sincronización
	// y colores del mapa.
	( new Citykleta_Settings() )->register();

	// Plantilla del archivo de bicis: sirve el contenido de una Page
	// editable (ver Citykleta_Templates::ensure_bikes_page()), armada por
	// defecto con los shortcodes de abajo — no una grilla fija.
	( new Citykleta_Templates() )->register();

	// [citykleta_search] / [citykleta_grid] / [citykleta_map]: los mismos
	// widgets de Elementor (Search/Bike/Map), usables como piezas sueltas en
	// cualquier Page — mismo patrón que WooCommerce con su página "Shop",
	// solo que en 3 elementos independientes en vez de uno solo. No-op si
	// Elementor no está activo (ver la propia clase).
	( new Citykleta_Widget_Shortcodes() )->register();

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

	// Solicitudes de alquiler: CPT privado + endpoint REST + formulario en la
	// página de la bici + los correos de aviso. Es una SOLICITUD, no una
	// reserva — la app es la fuente de verdad y su /reservations es de staff.
	( new Citykleta_Bike_Request() )->register();
	( new Citykleta_Bike_Request_Rest() )->register();
	( new Citykleta_Bike_Request_Form() )->register();
	( new Citykleta_Bike_Request_Notifier() )->register();

	// Widget de Elementor (no hace nada si Elementor no está activo).
	( new Citykleta_Elementor() )->register();

	// El slug de Destinations puede haber cambiado en Settings (ver
	// Citykleta_Settings::on_destination_slug_changed()). A diferencia del
	// flush de citykleta_bike_slug (que corre dentro de
	// Citykleta_Bike_Post_Type::register(), antes de que el resto de lo de
	// arriba se registre), este va al final de citykleta_init() a propósito:
	// para esta altura ya se registraron el CPT de Destinations y las
	// taxonomías, así que el flush de acá sí cubre todas sus reglas de una vez.
	if ( get_option( 'citykleta_destination_flush_rewrite_flag' ) ) {
		flush_rewrite_rules();
		delete_option( 'citykleta_destination_flush_rewrite_flag' );
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
