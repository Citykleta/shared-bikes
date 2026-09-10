<?php
/**
 * Plantilla del archivo de bicicletas. Ya NO arma el showroom a mano: imprime
 * el contenido de una Page normal de WordPress ("Bicicletas (resultados)",
 * ver Citykleta_Templates::ensure_bikes_page()) que el usuario diseña con
 * Elementor **gratis** — es una Page común, no una plantilla de tipo de
 * contenido, así que no hace falta Elementor Pro para redecorarla. El
 * shortcode [citykleta_bikes] (Citykleta_Bikes_Shortcode) hace la parte
 * dinámica de verdad — bicis reales, filtros, mapa — donde sea que el
 * usuario lo ponga dentro de esa página.
 *
 * Mismo patrón que usa WooCommerce con su página "Shop": la URL del archivo
 * (/bikes/) sigue siendo la del tipo de contenido "bike", pero lo que se ve
 * es el contenido de una Page editable, no una plantilla fija.
 *
 * Antes esta plantilla armaba un "app-shell" a pantalla completa sin pie de
 * página (ver el historial de bike-cards.css) — se abandonó a propósito: el
 * usuario ahora controla el diseño entero de esta página, incluido si lleva
 * pie de página o no, así que ya no tiene sentido forzarlo desde acá.
 *
 * @package Citykleta_Shared_Bikes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// get_header()/get_footer() no saben leer temas de bloques (FSE) como Twenty
// Twenty-Five — mismo gotcha que en single-bike.php.
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

$citykleta_page_id = Citykleta_Templates::ensure_bikes_page();
$citykleta_page    = $citykleta_page_id ? get_post( $citykleta_page_id ) : null;

if ( $citykleta_page ) :
	// Elementor's the_content filter (and anyone else's) only renders the
	// post it thinks is "current" — swap the global $post so it renders
	// THIS page's design, not whatever the archive query resolved to.
	global $post;
	$post = $citykleta_page; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	setup_postdata( $post );
	echo apply_filters( 'the_content', $post->post_content ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	wp_reset_postdata();
else :
	// Shouldn't happen — ensure_bikes_page() always returns an id — but
	// never show a blank page if it somehow does.
	echo do_shortcode( '[citykleta_bikes]' );
endif;

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
