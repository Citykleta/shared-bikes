<?php
/**
 * Plantilla de detalle de una bicicleta: muestra todos los datos que tenemos
 * (ficha técnica, tipos, propietario y precio). La reserva real la maneja la
 * app de Citykleta, por eso el bloque final es solo una llamada a la acción,
 * no un muro que esconda contenido.
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
	$price       = get_post_meta( get_the_ID(), 'price_per_hour', true );
	$available   = '1' === (string) get_post_meta( get_the_ID(), 'available', true );
	$brand       = get_post_meta( get_the_ID(), 'brand', true );
	$size        = get_post_meta( get_the_ID(), 'size', true );
	$wheel_size  = get_post_meta( get_the_ID(), 'wheel_size', true );
	$is_electric = '1' === (string) get_post_meta( get_the_ID(), 'is_electric', true );
	$owner_name  = get_post_meta( get_the_ID(), 'owner_name', true );
	$owner_photo = get_post_meta( get_the_ID(), 'owner_photo', true );
	$bike_types  = get_the_terms( get_the_ID(), Citykleta_Bike_Type_Taxonomy::TAXONOMY );

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

			<div class="citykleta-bike-single__main">

				<div class="citykleta-bike-single__media">
					<?php if ( has_post_thumbnail() ) : ?>
						<?php the_post_thumbnail( 'large' ); ?>
					<?php endif; ?>

					<?php if ( $available ) : ?>
						<span class="citykleta-bike-single__badge citykleta-bike-single__badge--available">Disponible</span>
					<?php else : ?>
						<span class="citykleta-bike-single__badge citykleta-bike-single__badge--unavailable">No disponible</span>
					<?php endif; ?>
				</div>

				<h1 class="citykleta-bike-single__title"><?php the_title(); ?></h1>

			</div>

			<div class="citykleta-bike-single__info">

				<?php if ( $location ) : ?>
					<p class="citykleta-bike-single__location"><?php echo esc_html( $location ); ?></p>
				<?php endif; ?>

				<?php if ( $tags ) : ?>
					<ul class="citykleta-bike-single__tags">
						<?php foreach ( $tags as $tag ) : ?>
							<li class="citykleta-bike-single__tag"><?php echo esc_html( $tag ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<?php if ( $price ) : ?>
					<p class="citykleta-bike-single__price">
						<?php echo esc_html( $price ); ?> CUP <span>/ hora</span>
					</p>
				<?php endif; ?>

				<?php if ( $specs ) : ?>
					<dl class="citykleta-bike-single__specs">
						<?php foreach ( $specs as $label => $value ) : ?>
							<div class="citykleta-bike-single__spec">
								<dt><?php echo esc_html( $label ); ?></dt>
								<dd><?php echo esc_html( $value ); ?></dd>
							</div>
						<?php endforeach; ?>
					</dl>
				<?php endif; ?>

				<?php if ( $owner_name ) : ?>
					<div class="citykleta-bike-single__owner">
						<?php if ( $owner_photo ) : ?>
							<img src="<?php echo esc_url( $owner_photo ); ?>" alt="<?php echo esc_attr( $owner_name ); ?>" width="40" height="40" />
						<?php endif; ?>
						<div>
							<span class="citykleta-bike-single__owner-label">Publicado por</span>
							<span class="citykleta-bike-single__owner-name"><?php echo esc_html( $owner_name ); ?></span>
						</div>
					</div>
				<?php endif; ?>

			</div>

		</div>

		<?php if ( trim( get_the_content() ) ) : ?>
			<div class="citykleta-bike-single__description">
				<h2>Sobre esta bicicleta</h2>
				<?php the_content(); ?>
			</div>
		<?php endif; ?>

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
