<?php
/**
 * Front-end "download the app" call to action, with the sign-up wall in front
 * of it.
 *
 * Rendered by the [citykleta_app_download] shortcode so it can be dropped
 * anywhere (a page, a widget, the single-bike template) — the wall is global,
 * not tied to a specific bike (which bike a download came from is intentionally
 * not tracked; see the stats discussion).
 *
 * Flow, driven by citykleta-app-cta.js:
 *   1. A "Descargar la app" button.
 *   2. Clicking it reveals a short form (name, email, consent).
 *   3. Submitting captures the lead (REST) and reveals the store links.
 *   4. Clicking a store link reports the download for that lead.
 * A returning visitor who already signed up (remembered in localStorage) skips
 * straight from the button to the store links.
 *
 * Store URLs are passed as shortcode attributes so no Settings section is
 * needed yet: [citykleta_app_download ios="…" android="…" button="…"].
 *
 * @package Citykleta_Shared_Bikes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Citykleta_App_Cta.
 */
class Citykleta_App_Cta {

	/**
	 * Shortcode tag.
	 */
	const SHORTCODE = 'citykleta_app_download';

	/**
	 * Connects the class to WordPress hooks.
	 */
	public function register() {
		add_shortcode( self::SHORTCODE, array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	/**
	 * Registers the assets and, when the current singular post actually holds
	 * the shortcode, enqueues them early (in the <head>) to avoid a flash of
	 * unstyled content — enqueuing late inside render() would miss the head (the
	 * same gotcha the Elementor widget CSS documents). render() still enqueues
	 * on demand for other contexts (widgets, Elementor) where the shortcode
	 * isn't part of post_content; the enqueue calls are idempotent.
	 */
	public function register_assets() {
		wp_register_style(
			'citykleta-app-cta',
			CITYKLETA_URL . 'assets/css/app-cta.css',
			array(),
			CITYKLETA_VERSION
		);

		wp_register_script(
			'citykleta-app-cta',
			CITYKLETA_URL . 'assets/js/citykleta-app-cta.js',
			array(),
			CITYKLETA_VERSION,
			true
		);

		wp_localize_script(
			'citykleta-app-cta',
			'CitykletaApp',
			array(
				'restBase' => esc_url_raw( rest_url( Citykleta_Subscriber_Rest::REST_NAMESPACE ) ),
			)
		);

		if ( is_singular() ) {
			$post = get_post();

			if ( $post instanceof WP_Post && has_shortcode( $post->post_content, self::SHORTCODE ) ) {
				wp_enqueue_style( 'citykleta-app-cta' );
				wp_enqueue_script( 'citykleta-app-cta' );
			}
		}
	}

	/**
	 * Renders the CTA.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render( $atts ) {
		$atts = shortcode_atts(
			array(
				'ios'     => '',
				'android' => '',
				'button'  => 'Descargar la app',
			),
			$atts,
			self::SHORTCODE
		);

		$ios     = esc_url( $atts['ios'] );
		$android = esc_url( $atts['android'] );

		// Nothing to link to — render nothing rather than a dead button.
		if ( '' === $ios && '' === $android ) {
			return '';
		}

		wp_enqueue_style( 'citykleta-app-cta' );
		wp_enqueue_script( 'citykleta-app-cta' );

		ob_start();
		?>
		<div class="citykleta-app-cta">

			<button type="button" class="citykleta-app-cta__open">
				<?php echo esc_html( $atts['button'] ); ?>
			</button>

			<form class="citykleta-app-cta__form" hidden>
				<p class="citykleta-app-cta__lead">Déjanos tu correo para acceder a la app.</p>

				<label class="citykleta-app-cta__field">
					<span>Nombre</span>
					<input type="text" name="name" autocomplete="name" />
				</label>

				<label class="citykleta-app-cta__field">
					<span>Correo electrónico</span>
					<input type="email" name="email" autocomplete="email" required />
				</label>

				<?php // Honeypot: hidden from real users, bots tend to fill it. ?>
				<div class="citykleta-app-cta__hp" aria-hidden="true">
					<label>No llenar este campo
						<input type="text" name="website" tabindex="-1" autocomplete="off" />
					</label>
				</div>

				<label class="citykleta-app-cta__consent">
					<input type="checkbox" name="consent" value="1" required />
					<span>Acepto recibir comunicación de Citykleta sobre la app y su lanzamiento.</span>
				</label>

				<button type="submit" class="citykleta-app-cta__submit">Continuar</button>

				<p class="citykleta-app-cta__error" role="alert" hidden></p>
			</form>

			<div class="citykleta-app-cta__stores" hidden>
				<p class="citykleta-app-cta__stores-lead">¡Listo! Descarga la app:</p>
				<?php if ( '' !== $ios ) : ?>
					<a class="citykleta-app-cta__store" data-store="ios" href="<?php echo $ios; // Already escaped above. ?>">App Store</a>
				<?php endif; ?>
				<?php if ( '' !== $android ) : ?>
					<a class="citykleta-app-cta__store" data-store="android" href="<?php echo $android; // Already escaped above. ?>">Google Play</a>
				<?php endif; ?>
			</div>

		</div>
		<?php
		return ob_get_clean();
	}
}
