<?php
/**
 * Página de una bicicleta. Igual que archive-bike.php, esta plantilla ya no
 * arma el diseño a mano: imprime la **plantilla** asignada a la vista
 * "single_bike" (Citykleta_Template_Post_Type), que el usuario diseña con
 * Elementor — versión gratis, sin Theme Builder.
 *
 * Lo que hace que una sola plantilla sirva para las 68 bicis: las piezas de
 * adentro ([citykleta_bike_gallery], [citykleta_bike_header], …, ver
 * Citykleta_Bike_Part_Widgets) no reciben ninguna bici por parámetro —
 * cada una resuelve sola cuál es, leyéndola de la query
 * (`get_queried_object_id()`). Por eso no hacen falta los Dynamic Tags de
 * Elementor Pro.
 *
 * Ojo al leer las piezas: acá abajo se cambia el $post global a la
 * PLANTILLA mientras se imprime, así que dentro de un widget `get_the_ID()`
 * devolvería la plantilla, no la bici.
 *
 * Sigue siendo una SOLICITUD, no una reserva — no se procesa ningún pago
 * (ver Citykleta_Bike_Request).
 *
 * @package Citykleta_Shared_Bikes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// get_header()/get_footer() no saben leer temas de bloques (FSE) — mismo
// gotcha de siempre, ver archive-bike.php.
$is_block_theme = wp_is_block_theme();

if ( $is_block_theme ) :
	?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php
	wp_body_open();
	block_template_part( 'header' );
else :
	get_header();
endif;

$citykleta_template = Citykleta_Templates::ensure_single_bike_template();

if ( $citykleta_template ) {
	Citykleta_Template_Post_Type::render_template( $citykleta_template );
} else {
	// No debería pasar (ensure_single_bike_template() la crea si falta),
	// pero nunca dejar la página en blanco: se imprime el diseño por
	// defecto directo.
	echo do_shortcode( Citykleta_Templates::default_single_bike_content() );
}

if ( $is_block_theme ) :
	block_template_part( 'footer' );
	wp_footer();
	?>
</body>
</html>
	<?php
else :
	get_footer();
endif;
