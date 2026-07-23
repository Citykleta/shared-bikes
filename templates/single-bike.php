<?php
/**
 * Plantilla de detalle de una bicicleta: HTML básico, sin diseño propio —
 * se diseña visualmente con templates de Elementor. Solo separa lo que se
 * muestra siempre de lo que queda detrás del muro de registro (la reserva
 * real la maneja la app). Ver Citykleta_Templates::load_single_template().
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

<?php while ( have_posts() ) : the_post(); ?>

	<?php
	$model        = get_post_meta( get_the_ID(), 'model', true );
	$location     = get_post_meta( get_the_ID(), 'location', true );
	$price        = get_post_meta( get_the_ID(), 'price_per_hour', true );
	$available    = '1' === (string) get_post_meta( get_the_ID(), 'available', true );
	$brand        = get_post_meta( get_the_ID(), 'brand', true );
	$size         = get_post_meta( get_the_ID(), 'size', true );
	$wheel_size   = get_post_meta( get_the_ID(), 'wheel_size', true );
	$is_electric  = '1' === (string) get_post_meta( get_the_ID(), 'is_electric', true );
	$owner_name   = get_post_meta( get_the_ID(), 'owner_name', true );
	$owner_photo  = get_post_meta( get_the_ID(), 'owner_photo', true );
	$bike_types   = get_the_terms( get_the_ID(), Citykleta_Bike_Type_Taxonomy::TAXONOMY );

	if ( ! is_array( $bike_types ) ) {
		$bike_types = array();
	}
	?>

	<main class="citykleta-bike-single">

		<a href="<?php echo esc_url( get_post_type_archive_link( Citykleta_Bike_Post_Type::POST_TYPE ) ); ?>">&larr; Volver a bicicletas</a>

		<?php if ( has_post_thumbnail() ) : ?>
			<?php the_post_thumbnail( 'large' ); ?>
		<?php endif; ?>

		<h1><?php the_title(); ?></h1>

		<?php if ( $model ) : ?>
			<p><?php echo esc_html( $model ); ?></p>
		<?php endif; ?>

		<p><?php echo $available ? 'Disponible' : 'No disponible'; ?></p>

		<?php if ( $bike_types || $is_electric ) : ?>
			<p>
				<?php
				$tags = wp_list_pluck( $bike_types, 'name' );
				if ( $is_electric ) {
					$tags[] = 'Eléctrica';
				}
				echo esc_html( implode( ', ', $tags ) );
				?>
			</p>
		<?php endif; ?>

		<?php if ( $location ) : ?>
			<p><?php echo esc_html( $location ); ?></p>
		<?php endif; ?>

		<ul>
			<?php if ( $brand ) : ?>
				<li>Marca: <?php echo esc_html( $brand ); ?></li>
			<?php endif; ?>
			<?php if ( $size ) : ?>
				<li>Talla: <?php echo esc_html( $size ); ?></li>
			<?php endif; ?>
			<?php if ( $wheel_size ) : ?>
				<li>Tamaño de rueda: <?php echo esc_html( $wheel_size ); ?></li>
			<?php endif; ?>
		</ul>

		<?php if ( get_the_content() ) : ?>
			<div>
				<h2>Especificaciones</h2>
				<?php the_content(); ?>
			</div>
		<?php endif; ?>

		<?php if ( $owner_name ) : ?>
			<p>
				Publicado por <?php echo esc_html( $owner_name ); ?>
				<?php if ( $owner_photo ) : ?>
					<img src="<?php echo esc_url( $owner_photo ); ?>" alt="<?php echo esc_attr( $owner_name ); ?>" width="32" height="32" />
				<?php endif; ?>
			</p>
		<?php endif; ?>

		<?php if ( $price ) : ?>
			<p><?php echo esc_html( $price ); ?> CUP/hora</p>
		<?php endif; ?>

		<div>
			<p>Crea una cuenta o inicia sesión para reservar esta bicicleta desde la app de Citykleta.</p>
			<a href="<?php echo esc_url( wp_registration_url() ); ?>">Crear cuenta</a>
			<a href="<?php echo esc_url( wp_login_url() ); ?>">Iniciar sesión</a>
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
