<?php
/**
 * Plantilla del archivo de bicicletas: "showroom" con dos vistas alternables
 * por un botón — grid de tarjetas (que pagina/scrollea) o mapa a todo el ancho
 * para navegar la ciudad, solo una visible a la vez. Las dos vistas son
 * INDEPENDIENTES y responden a los mismos filtros por su cuenta: el grid usa la
 * query principal (paginada + carga infinita); el mapa corre su propia query
 * con TODAS las bicis con coordenadas que matchean los filtros.
 *
 * La meta es enganchar al usuario para que descargue la app (donde reserva);
 * cada tarjeta muestra solo lo justo para atraer, el detalle va en la página
 * individual. Sin footer: la vista llena la ventana como una app.
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

	<?php
	// Barra superior FIJA (sticky) reservada para los filtros. Todavía no
	// existen: aquí irán más adelante los controles de filtrado (marca, tipo
	// de bici, altura del ciclista, orden, etc.). Se deja el <section> ya
	// montado para que su sitio y estilo estén listos cuando se construyan.
	?>
	<section class="citykleta-filters-bar" aria-label="Filtros">
		<!-- TODO: filtros aquí (marca, tipo, altura, orden…). Placeholder por ahora. -->
		<span class="citykleta-filters-bar__placeholder">Filtros (próximamente)</span>

		<?php
		// El botón que alterna tarjetas/mapa vive aquí, en la barra de filtros,
		// para no gastar una fila (y alto) propia en la vista. El texto indica
		// la vista de destino y lo actualiza el JS (citykleta-view-toggle.js),
		// que lo encuentra por [data-view-toggle] aunque esté fuera de
		// .citykleta-results (donde viven los paneles de las vistas).
		?>
		<button type="button" class="citykleta-view-toggle" data-view-toggle aria-pressed="false">
			<span class="citykleta-view-toggle__label">Ver mapa</span>
		</button>
	</section>

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
		<section class="citykleta-results" data-active-view="grid">
			<div class="citykleta-view citykleta-view--grid" data-view="grid">
				<div class="citykleta-bike-grid">
					<?php
					while ( have_posts() ) :
						the_post();

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
					?>
				</div>

				<?php
				// Carga infinita en vez de paginación clásica: se deja un
				// "sentinel" al final del grid que el JS observa (Intersection
				// Observer) para traer la página siguiente por fetch y anexar
				// sus tarjetas. Ver assets/js/citykleta-infinite-scroll.js.
				?>
				<div
					class="citykleta-load-more"
					data-paged="1"
					data-max-pages="<?php echo esc_attr( (int) $GLOBALS['wp_query']->max_num_pages ); ?>"
				>
					<span class="citykleta-load-more__status" aria-live="polite"></span>
				</div>
			</div>

			<?php
			// El mapa es INDEPENDIENTE del grid: corre su propia query con TODAS
			// las bicis con coordenadas que matchean los mismos filtros
			// (ubicacion, electricas, tipo de uso), sin paginar. El grid
			// scrollea su tanda; el mapa pinta todo de una vez. Sin centrar en el
			// visitante: interesa encuadrar los resultados (fitBounds). Empieza
			// oculto: la vista por defecto es la de tarjetas.
			$map_filters = Citykleta_Templates::request_filters();
			$map_bikes   = Citykleta_Bike_Map_Data::query(
				array(
					'count'      => -1,
					'extra_meta' => $map_filters['meta_query'],
					'tax_query'  => $map_filters['tax_query'],
				)
			);

			$map_options = array_merge(
				array( 'centerOnUser' => false ),
				Citykleta_Settings::tile_layer(),
				Citykleta_Settings::cluster_options()
			);

			$map_options['iconSize'] = Citykleta_Settings::marker_icon_size();
			?>
			<div class="citykleta-view citykleta-view--map" data-view="map" hidden>
				<div
					class="citykleta-map"
					style="<?php echo esc_attr( Citykleta_Settings::map_color_style_attr() ); ?>"
					data-bikes="<?php echo esc_attr( wp_json_encode( $map_bikes ) ); ?>"
					data-options="<?php echo esc_attr( wp_json_encode( $map_options ) ); ?>"
				></div>
			</div>
		</section>
	<?php else : ?>
		<p>Todavía no hay bicicletas disponibles.</p>
	<?php endif; ?>
</main>

<?php
// En la página de resultados NO se imprime el footer del sitio (patrón tipo
// showroom/app: la vista llena la ventana, sin pie de página). Se omite el
// footer del tema pero se CONSERVA wp_footer() —encola los scripts del footer,
// incluidos Leaflet y citykleta-map.js, más la barra de admin— y el cierre del
// documento, que get_footer()/el footer de bloques harían normalmente. Es
// seguro omitirlo: ni Hello (header.php) ni el header de bloques dejan ningún
// contenedor abierto que el footer tuviera que cerrar.
wp_footer();
?>
</body>
</html>
