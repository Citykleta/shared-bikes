<?php
/**
 * Página de un Destino. Igual que archive-bike.php y single-bike.php, esta
 * plantilla ya no arma el diseño a mano: imprime la **plantilla** asignada a
 * la vista "destination" (Citykleta_Template_Post_Type), que el usuario
 * diseña con Elementor — versión gratis, sin Theme Builder.
 *
 * Lo que hace que una sola plantilla sirva para todos los destinos: las
 * piezas de adentro ([citykleta_destination_header],
 * [citykleta_destination_faq], …, ver Citykleta_Destination_Part_Widgets) no
 * reciben ningún destino por parámetro — cada una resuelve sola cuál es,
 * leyéndolo de la query. Por eso no hacen falta los Dynamic Tags de Pro.
 *
 * Las bicis del destino NO son una pieza propia: son el widget de grilla
 * (Citykleta_Bike_Widget) con su origen "Destination Bikes", para no duplicar
 * el markup de las tarjetas ni su pestaña Style.
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

$citykleta_template = Citykleta_Templates::ensure_destination_template();

if ( $citykleta_template ) {
	Citykleta_Template_Post_Type::render_template( $citykleta_template );
} else {
	// No debería pasar (ensure_destination_template() la crea si falta), pero
	// nunca dejar la página en blanco: se imprime el diseño por defecto.
	echo do_shortcode( Citykleta_Templates::default_destination_content() );
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
