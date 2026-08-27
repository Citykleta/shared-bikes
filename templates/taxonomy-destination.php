<?php
/**
 * Plantilla de una página de Destino (ej. /destination/vedado/): grid de las
 * bicis que Citykleta_Destination_Matcher asignó a este destino por
 * geocerca, precio promedio, artículos relacionados (posts con una tag cuyo
 * slug coincide con el del destino) y FAQ (propia si el destino la activó,
 * si no la genérica de Settings → Destinations).
 *
 * El rating agregado de las bicis del destino queda fuera a propósito por
 * ahora — el mecanismo de captura todavía no está definido, ver
 * HANDOFF-map-config-backoffice.md/CLAUDE.md no lo mencionan aún porque es
 * la siguiente pieza pendiente del feature de Destinos.
 *
 * @package Citykleta_Shared_Bikes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$term = get_queried_object();

if ( ! ( $term instanceof WP_Term ) ) {
	return;
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

$bikes_query = new WP_Query(
	array(
		'post_type'      => Citykleta_Bike_Post_Type::POST_TYPE,
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			array(
				'taxonomy' => Citykleta_Destination_Taxonomy::TAXONOMY,
				'field'    => 'term_id',
				'terms'    => $term->term_id,
			),
		),
	)
);

// Precio promedio: se calcula acá, no con un AVG() en SQL — la lista de bicis
// de un destino es chica (geocerca local), no vale la pena una consulta
// aparte por un promedio que ya podemos sacar de la misma query.
$prices = array();

while ( $bikes_query->have_posts() ) {
	$bikes_query->the_post();
	$price = get_post_meta( get_the_ID(), 'price_per_hour', true );

	if ( '' !== $price ) {
		$prices[] = (float) $price;
	}
}

$avg_price = $prices ? array_sum( $prices ) / count( $prices ) : null;

$bikes_query->rewind_posts();

// Artículos relacionados: posts con una tag cuyo slug coincide con el del
// destino — ver la nota de Citykleta_Destination_Taxonomy sobre esta
// convención.
$articles = get_posts(
	array(
		'post_type'      => 'post',
		'post_status'    => 'publish',
		'posts_per_page' => 6,
		'tag'            => $term->slug,
	)
);

$faq = Citykleta_Destination_Taxonomy::faq_for( $term->term_id );
?>

<main class="citykleta-destination">
	<header class="citykleta-destination__header">
		<h1 class="citykleta-destination__title"><?php echo esc_html( $term->name ); ?></h1>
		<?php if ( $term->description ) : ?>
			<p class="citykleta-destination__description"><?php echo esc_html( $term->description ); ?></p>
		<?php endif; ?>
		<?php if ( null !== $avg_price ) : ?>
			<p class="citykleta-destination__avg-price">
				Precio promedio: <strong><?php echo esc_html( number_format_i18n( $avg_price, 0 ) ); ?> CUP/hora</strong>
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
					$price       = get_post_meta( $bike_id, 'price_per_hour', true );
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
								<p class="citykleta-bike-card__price"><?php echo esc_html( $price ); ?> CUP/hora</p>
							<?php endif; ?>
						</div>
					</a>
					<?php
				endwhile;
				wp_reset_postdata();
				?>
			</div>
		<?php else : ?>
			<p>Todavía no hay bicicletas registradas en <?php echo esc_html( $term->name ); ?>.</p>
		<?php endif; ?>
	</section>

	<?php if ( $articles ) : ?>
		<section class="citykleta-destination__articles">
			<h2>Artículos sobre <?php echo esc_html( $term->name ); ?></h2>
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
