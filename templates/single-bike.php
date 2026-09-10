<?php
/**
 * Plantilla de detalle de una bicicleta: muestra todos los datos que tenemos
 * (fotos, ficha técnica, tipos, propietario y precio) y el formulario para
 * solicitarla. Ojo: es una SOLICITUD, no una reserva — no se procesa ningún
 * pago acá (Citykleta no cobra pagos internacionales ni locales): el dueño
 * recibe la solicitud y coordina el resto — incluido el cobro, en efectivo —
 * por su cuenta. Ver Citykleta_Bike_Request.
 *
 * Estructura, patrón ListNRide: dos columnas desde arriba. A la izquierda, la
 * galería (carrusel de una foto a la vez, deslizable — motor Splide, botones
 * y puntos propios, ver assets/js/citykleta-bike-gallery.js) seguida del
 * título, ficha técnica, dónde recogerla y el resto del contenido. A la
 * derecha, la tarjeta de solicitud, pegada al borde superior de la galería y
 * pegajosa al hacer scroll. Colapsa a una columna en pantallas angostas.
 *
 * Diseño en assets/css/single-bike.css, encolado desde
 * Citykleta_Templates::enqueue_styles(). Ver también
 * Citykleta_Templates::load_single_template().
 *
 * @package Citykleta_Shared_Bikes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Mismo patrón que archive-bike.php: get_header()/get_footer() no sirven en
// temas de bloques (FSE), hay que armar el documento a mano en ese caso.
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

<?php
while ( have_posts() ) :
	the_post();

	$model       = get_post_meta( get_the_ID(), 'model', true );
	$location    = get_post_meta( get_the_ID(), 'location', true );
	$price       = Citykleta_Bike_Meta::price_html( get_the_ID() );
	$brand       = get_post_meta( get_the_ID(), 'brand', true );
	$size        = get_post_meta( get_the_ID(), 'size', true );
	$wheel_size  = get_post_meta( get_the_ID(), 'wheel_size', true );
	$is_electric = '1' === (string) get_post_meta( get_the_ID(), 'is_electric', true );
	$owner_name  = get_post_meta( get_the_ID(), 'owner_name', true );
	$owner_photo = get_post_meta( get_the_ID(), 'owner_photo', true );
	$bike_types  = get_the_terms( get_the_ID(), Citykleta_Bike_Type_Taxonomy::TAXONOMY );
	$images      = Citykleta_Bike_Meta::gallery_ids( get_the_ID() );
	$map_bike    = Citykleta_Bike_Map_Data::for_bike( get_the_ID() );

	if ( ! is_array( $bike_types ) ) {
		$bike_types = array();
	}

	// Etiquetas: los tipos de bici de la taxonomía, más "Eléctrica" cuando aplica.
	$tags = wp_list_pluck( $bike_types, 'name' );

	if ( $is_electric ) {
		$tags[] = 'Eléctrica';
	}

	// Ficha técnica: solo se listan los campos que tienen valor.
	$specs = array_filter(
		array(
			'Marca'           => $brand,
			'Modelo'          => $model,
			'Talla'           => $size,
			'Tamaño de rueda' => $wheel_size,
		)
	);
	?>

	<main class="citykleta-bike-single">

		<?php
		// "Volver a bicicletas" conserva la búsqueda: si el visitante vino del
		// archivo de bicis (con su query string ?location=... etc.), volvemos a
		// ESA URL en vez de al archivo pelado, para no perder los filtros. Solo
		// cae al archivo sin filtros en una visita directa (sin referer válido).
		$citykleta_archive_url = get_post_type_archive_link( Citykleta_Bike_Post_Type::POST_TYPE );
		$citykleta_back_url    = wp_get_referer();

		if ( ! $citykleta_back_url || ! $citykleta_archive_url || 0 !== strpos( $citykleta_back_url, $citykleta_archive_url ) ) {
			$citykleta_back_url = $citykleta_archive_url;
		}
		?>
		<a class="citykleta-bike-single__back" href="<?php echo esc_url( $citykleta_back_url ); ?>">&larr; Volver a bicicletas</a>

		<div class="citykleta-bike-single__layout">

			<div class="citykleta-bike-single__content">

				<?php if ( $images ) : ?>
					<?php $citykleta_multi = count( $images ) > 1; ?>
					<?php // Structure Splide requires: .splide > .splide__track > .splide__list > .splide__slide* — see assets/js/citykleta-bike-gallery.js. ?>
					<div class="citykleta-bike-single__gallery splide" aria-label="Fotos de la bici">
						<div class="splide__track">
							<ul class="splide__list">
								<?php
								foreach ( $images as $index => $image_id ) {
									?>
									<li class="splide__slide citykleta-bike-single__shot">
										<?php echo wp_get_attachment_image( $image_id, 'large', false, array( 'loading' => 0 === $index ? 'eager' : 'lazy' ) ); ?>
									</li>
									<?php
								}
								?>
							</ul>
						</div>

						<?php if ( $citykleta_multi ) : ?>
							<button type="button" class="citykleta-bike-single__gallery-nav citykleta-bike-single__gallery-nav--prev" aria-label="Foto anterior">&lsaquo;</button>
							<button type="button" class="citykleta-bike-single__gallery-nav citykleta-bike-single__gallery-nav--next" aria-label="Foto siguiente">&rsaquo;</button>

							<div class="citykleta-bike-single__gallery-dots">
								<?php foreach ( $images as $index => $image_id ) : ?>
									<button type="button" class="citykleta-bike-single__gallery-dot" aria-label="Foto <?php echo (int) $index + 1; ?>" aria-current="<?php echo 0 === $index ? 'true' : 'false'; ?>"></button>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<header class="citykleta-bike-single__header">
					<h1 class="citykleta-bike-single__title"><?php the_title(); ?></h1>

					<div class="citykleta-bike-single__subhead">
						<?php if ( $location ) : ?>
							<span class="citykleta-bike-single__location"><?php echo esc_html( $location ); ?></span>
						<?php endif; ?>

						<?php if ( $tags ) : ?>
							<ul class="citykleta-bike-single__tags">
								<?php foreach ( $tags as $tag ) : ?>
									<li class="citykleta-bike-single__tag"><?php echo esc_html( $tag ); ?></li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</div>
				</header>

				<?php if ( $owner_name ) : ?>
					<section class="citykleta-bike-single__owner">
						<?php if ( $owner_photo ) : ?>
							<img class="citykleta-bike-single__owner-photo" src="<?php echo esc_url( $owner_photo ); ?>" alt="<?php echo esc_attr( $owner_name ); ?>" width="48" height="48" loading="lazy" />
						<?php endif; ?>
						<div>
							<span class="citykleta-bike-single__owner-label">Publicado por</span>
							<span class="citykleta-bike-single__owner-name"><?php echo esc_html( $owner_name ); ?></span>
						</div>
					</section>
				<?php endif; ?>

				<?php if ( trim( get_the_content() ) ) : ?>
					<section class="citykleta-bike-single__section">
						<h2 class="citykleta-bike-single__section-title">Sobre esta bicicleta</h2>
						<div class="citykleta-bike-single__description"><?php the_content(); ?></div>
					</section>
				<?php endif; ?>

				<?php if ( $specs ) : ?>
					<section class="citykleta-bike-single__section">
						<h2 class="citykleta-bike-single__section-title">Ficha técnica</h2>
						<dl class="citykleta-bike-single__specs">
							<?php foreach ( $specs as $label => $value ) : ?>
								<div class="citykleta-bike-single__spec">
									<dt><?php echo esc_html( $label ); ?></dt>
									<dd><?php echo esc_html( $value ); ?></dd>
								</div>
							<?php endforeach; ?>
						</dl>
					</section>
				<?php endif; ?>

				<?php if ( $map_bike ) : ?>
					<section class="citykleta-bike-single__section">
						<h2 class="citykleta-bike-single__section-title">Dónde recogerla</h2>
						<div
							class="citykleta-map citykleta-bike-single__location-map"
							data-bikes="<?php echo esc_attr( wp_json_encode( array( $map_bike ) ) ); ?>"
							data-options="<?php echo esc_attr( wp_json_encode( array( 'centerOnUser' => false ) ) ); ?>"
						></div>
						<?php if ( $location ) : ?>
							<p class="citykleta-bike-single__location-caption"><?php echo esc_html( $location ); ?></p>
						<?php endif; ?>
					</section>
				<?php endif; ?>

			</div>

			<aside class="citykleta-bike-single__aside">
				<div class="citykleta-bike-single__price-card">
					<?php if ( $price ) : ?>
						<p class="citykleta-bike-single__price"><?php echo esc_html( $price ); ?></p>
					<?php endif; ?>

					<?php
					// Solicitud, no reserva: acá no se procesa ningún pago —
					// Citykleta no cobra pagos internacionales ni locales, el
					// dueño coordina el cobro (en efectivo) por su cuenta al
					// recibir la solicitud. Ver
					// includes/requests/class-citykleta-bike-request.php.
					Citykleta_Bike_Request_Form::render( get_the_ID() );
					?>
				</div>
			</aside>

		</div>

	</main>

<?php endwhile; ?>

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
