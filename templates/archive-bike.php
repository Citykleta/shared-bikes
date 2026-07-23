<?php
/**
 * Plantilla del archivo de bicicletas: grid de tarjetas de vista previa.
 *
 * Cada tarjeta muestra solo lo justo para atraer al cliente potencial; los
 * detalles completos están en la página individual de cada bici.
 *
 * @package Citykleta_Shared_Bikes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// get_header() no sabe leer temas de bloques (FSE) como Twenty Twenty-Five
// (no tienen header.php) y cae en un header de compatibilidad genérico de
// WordPress, sin relación con el diseño real del tema. Para esos temas hay
// que armar el documento a mano y pedir el header con block_template_part().
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
?>

<main class="citykleta-bike-archive">
	<h1 class="citykleta-bike-archive__title">Bicicletas disponibles</h1>

	<?php
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- solo lectura para mostrar un aviso, no cambia estado.
	$search_location = isset( $_GET['location'] ) ? sanitize_text_field( wp_unslash( $_GET['location'] ) ) : '';
	$search_dates    = ! empty( $_GET['from'] ) || ! empty( $_GET['to'] );
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
	?>

	<?php if ( $search_location || $search_dates ) : ?>
		<p class="citykleta-bike-archive__search-note">
			<?php if ( $search_location ) : ?>
				Mostrando resultados para: <strong><?php echo esc_html( $search_location ); ?></strong>.
			<?php endif; ?>
			<?php if ( $search_dates ) : ?>
				Las fechas todavía no filtran resultados (próximamente).
			<?php endif; ?>
		</p>
	<?php endif; ?>

	<?php if ( have_posts() ) : ?>
		<div class="citykleta-bike-grid">
			<?php
			while ( have_posts() ) :
				the_post();

				$price        = get_post_meta( get_the_ID(), 'price_per_hour', true );
				$location     = get_post_meta( get_the_ID(), 'location', true );
				$available    = get_post_meta( get_the_ID(), 'available', true );
				$owner_name   = get_post_meta( get_the_ID(), 'owner_name', true );
				$owner_photo  = get_post_meta( get_the_ID(), 'owner_photo', true );
				?>
				<a class="citykleta-bike-card" href="<?php the_permalink(); ?>">
					<div class="citykleta-bike-card__image">
						<?php if ( has_post_thumbnail() ) : ?>
							<?php the_post_thumbnail( 'medium' ); ?>
						<?php else : ?>
							<div class="citykleta-bike-card__image-placeholder"></div>
						<?php endif; ?>

						<?php if ( '1' === (string) $available ) : ?>
							<span class="citykleta-bike-card__badge citykleta-bike-card__badge--available">Disponible</span>
						<?php else : ?>
							<span class="citykleta-bike-card__badge citykleta-bike-card__badge--unavailable">No disponible</span>
						<?php endif; ?>

						<?php if ( $owner_photo ) : ?>
							<img class="citykleta-bike-card__owner" src="<?php echo esc_url( $owner_photo ); ?>" alt="<?php echo esc_attr( $owner_name ); ?>" width="20" height="20" />
						<?php endif; ?>
					</div>

					<div class="citykleta-bike-card__body">
						<h2 class="citykleta-bike-card__title"><?php the_title(); ?></h2>

						<?php if ( $location ) : ?>
							<p class="citykleta-bike-card__location"><?php echo esc_html( $location ); ?></p>
						<?php endif; ?>

						<?php if ( $price ) : ?>
							<p class="citykleta-bike-card__price"><?php echo esc_html( $price ); ?> CUP/hora</p>
						<?php endif; ?>
					</div>
				</a>
				<?php
			endwhile;
			?>
		</div>

		<div class="citykleta-bike-archive__pagination">
			<?php the_posts_pagination(); ?>
		</div>
	<?php else : ?>
		<p>Todavía no hay bicicletas disponibles.</p>
	<?php endif; ?>
</main>

<?php
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
