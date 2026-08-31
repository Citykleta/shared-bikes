<?php
/**
 * Plantilla de una página de Destino (ej. /destination/vedado/): grid de las
 * bicis que Citykleta_Destination_Matcher asignó a este destino por
 * geocerca, precio promedio, artículos relacionados (posts con una tag cuyo
 * slug coincide con el del destino) y FAQ (propia si el destino la activó,
 * si no la genérica de Settings → Destinations).
 *
 * El rating agregado de las bicis del destino queda fuera a propósito por
 * ahora — el mecanismo de captura todavía no está definido, ver la memoria
 * de sesión `project_destinos_feature` (fuera del repo) para el detalle de
 * la decisión pendiente.
 *
 * @package Citykleta_Shared_Bikes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Mismo patrón que archive-bike.php/single-bike.php: get_header()/get_footer()
// no sirven en temas de bloques (FSE), hay que armar el documento a mano.
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

while ( have_posts() ) :
	the_post();

	$destination_id = get_the_ID();

	$bikes_query = new WP_Query(
		array(
			'post_type'      => Citykleta_Bike_Post_Type::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => Citykleta_Destination_Matcher::META_KEY,
					'value' => $destination_id,
				),
			),
		)
	);

	// Precio promedio: se calcula acá, no con un AVG() en SQL — la lista de
	// bicis de un destino es chica (geocerca local), no vale la pena una
	// consulta aparte por un promedio que ya podemos sacar de la misma query.
	$prices = array();

	while ( $bikes_query->have_posts() ) {
		$bikes_query->the_post();
		$price = Citykleta_Bike_Meta::price_amount( get_the_ID() );

		if ( null !== $price ) {
			$prices[] = $price;
		}
	}

	$avg_price = $prices ? array_sum( $prices ) / count( $prices ) : null;

	$bikes_query->rewind_posts();

	// Artículos relacionados: posts con una tag cuyo slug coincide con el del
	// destino — ver la nota de Citykleta_Destination_Post_Type sobre esta
	// convención.
	$articles = get_posts(
		array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 6,
			'tag'            => get_post_field( 'post_name', $destination_id ),
		)
	);

	$faq = Citykleta_Destination_Post_Type::faq_for( $destination_id );

	// Reads by ID from here on, not the bare the_title()/the_content()/
	// has_post_thumbnail() template tags: those read the global $post, which
	// $bikes_query->the_post() above already moved on to the last bike in
	// the list — rewind_posts() resets that query's own pointer for the grid
	// loop below, but doesn't restore the global $post to this destination.
	$destination_content = apply_filters( 'the_content', get_post_field( 'post_content', $destination_id, 'raw' ) );
	?>

	<main class="citykleta-destination">
		<header class="citykleta-destination__header">
			<h1 class="citykleta-destination__title"><?php echo esc_html( get_the_title( $destination_id ) ); ?></h1>

			<?php if ( has_post_thumbnail( $destination_id ) ) : ?>
				<div class="citykleta-destination__hero"><?php echo get_the_post_thumbnail( $destination_id, 'large' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core-escaped markup. ?></div>
			<?php endif; ?>

			<?php if ( $destination_content ) : ?>
				<div class="citykleta-destination__description"><?php echo $destination_content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already ran through the_content filters. ?></div>
			<?php endif; ?>

			<?php if ( null !== $avg_price ) : ?>
				<p class="citykleta-destination__avg-price">
					Precio promedio: <strong><?php echo esc_html( Citykleta_Bike_Meta::format_price( $avg_price ) ); ?></strong>
					(<?php echo esc_html( count( $prices ) ); ?> bicis)
				</p>
			<?php endif; ?>
		</header>

		<section class="citykleta-destination__bikes">
			<?php if ( $bikes_query->have_posts() ) : ?>
				<div class="citykleta-bike-grid">
					<?php
					while ( $bikes_query->have_posts() ) :
						$bikes_query->the_post();

						$bike_id     = get_the_ID();
						$price       = Citykleta_Bike_Meta::price_html( $bike_id );
						$location    = get_post_meta( $bike_id, 'location', true );
						$available   = get_post_meta( $bike_id, 'available', true );
						$owner_name  = get_post_meta( $bike_id, 'owner_name', true );
						$owner_photo = get_post_meta( $bike_id, 'owner_photo', true );
						?>
						<a class="citykleta-bike-card" href="<?php the_permalink(); ?>" data-bike-id="<?php echo esc_attr( $bike_id ); ?>">
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
									<p class="citykleta-bike-card__price"><?php echo esc_html( $price ); ?></p>
								<?php endif; ?>
							</div>
						</a>
						<?php
					endwhile;
					wp_reset_postdata();
					?>
				</div>
			<?php else : ?>
				<p>Todavía no hay bicicletas registradas acá.</p>
			<?php endif; ?>
		</section>

		<?php if ( $articles ) : ?>
			<section class="citykleta-destination__articles">
				<h2>Artículos relacionados</h2>
				<ul class="citykleta-destination__article-list">
					<?php foreach ( $articles as $article ) : ?>
						<li>
							<a href="<?php echo esc_url( get_permalink( $article ) ); ?>"><?php echo esc_html( get_the_title( $article ) ); ?></a>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endif; ?>

		<?php if ( $faq ) : ?>
			<section class="citykleta-destination__faq">
				<h2>Preguntas frecuentes</h2>
				<?php foreach ( $faq as $item ) : ?>
					<details class="citykleta-destination__faq-item">
						<summary><?php echo esc_html( $item['question'] ); ?></summary>
						<p><?php echo esc_html( $item['answer'] ); ?></p>
					</details>
				<?php endforeach; ?>
			</section>
		<?php endif; ?>
	</main>
	<?php
endwhile;
?>

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
