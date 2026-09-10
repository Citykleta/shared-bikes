<?php
/**
 * The "request this bike" form shown on a bike's page.
 *
 * Rendered by templates/single-bike.php, which is why this is a render helper
 * rather than a shortcode: unlike the app-download wall (global, droppable
 * anywhere), a request is always about one specific bike.
 *
 * Assets are registered on 'wp_enqueue_scripts' and enqueued there for the
 * single-bike screen — enqueuing from inside render() would miss the <head>,
 * the same gotcha the Elementor widget CSS documents in CLAUDE.md.
 *
 * @package Citykleta_Shared_Bikes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Citykleta_Bike_Request_Form.
 */
class Citykleta_Bike_Request_Form {

	/**
	 * Connects the class to WordPress hooks.
	 */
	public function register() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Enqueues the form's CSS/JS on a bike page.
	 */
	public function enqueue_assets() {
		if ( ! is_singular( Citykleta_Bike_Post_Type::POST_TYPE ) ) {
			return;
		}

		wp_enqueue_style(
			'citykleta-bike-request',
			CITYKLETA_URL . 'assets/css/bike-request.css',
			array(),
			self::asset_version( 'assets/css/bike-request.css' )
		);

		wp_enqueue_script(
			'citykleta-bike-request',
			CITYKLETA_URL . 'assets/js/citykleta-bike-request.js',
			array(),
			self::asset_version( 'assets/js/citykleta-bike-request.js' ),
			true
		);

		wp_localize_script(
			'citykleta-bike-request',
			'CitykletaRequest',
			array(
				'restBase' => esc_url_raw( rest_url( Citykleta_Bike_Request_Rest::REST_NAMESPACE ) ),
			)
		);
	}

	/**
	 * Asset version for cache-busting — same filemtime() approach
	 * Citykleta_Templates uses, so an edited stylesheet doesn't need a
	 * CITYKLETA_VERSION bump to reach the browser.
	 *
	 * @param string $relative_path Path inside the plugin.
	 * @return string|int
	 */
	private static function asset_version( $relative_path ) {
		$file = CITYKLETA_PATH . $relative_path;

		return file_exists( $file ) ? filemtime( $file ) : CITYKLETA_VERSION;
	}

	/**
	 * Prints the form for one bike.
	 *
	 * Dates are plain <input type="date"> rather than the Flatpickr range the
	 * search widget uses: those vendor files don't ship with the plugin
	 * (see CLAUDE.md), and a bike request must not silently lose its date
	 * fields on an install that hasn't downloaded them.
	 *
	 * @param int $bike_id Bike post ID.
	 */
	public static function render( $bike_id ) {
		$today = current_time( 'Y-m-d' );
		?>
		<div class="citykleta-bike-request" data-bike="<?php echo (int) $bike_id; ?>">

			<h2 class="citykleta-bike-request__title">Solicitar esta bicicleta</h2>

			<form class="citykleta-bike-request__form">

				<label class="citykleta-bike-request__field">
					<span>Tu nombre</span>
					<input type="text" name="name" autocomplete="name" required />
				</label>

				<label class="citykleta-bike-request__field">
					<span>Correo o teléfono</span>
					<input type="text" name="contact" inputmode="email" required />
					<small>Por aquí te respondemos.</small>
				</label>

				<div class="citykleta-bike-request__dates">
					<label class="citykleta-bike-request__field">
						<span>Desde</span>
						<input type="date" name="from" min="<?php echo esc_attr( $today ); ?>" />
					</label>

					<label class="citykleta-bike-request__field">
						<span>Hasta</span>
						<input type="date" name="to" min="<?php echo esc_attr( $today ); ?>" />
					</label>
				</div>

				<label class="citykleta-bike-request__field">
					<span>Mensaje <small>(opcional)</small></span>
					<textarea name="message" rows="3"></textarea>
				</label>

				<?php // Honeypot: hidden from real users, bots tend to fill it. ?>
				<div class="citykleta-bike-request__hp" aria-hidden="true">
					<label>No llenar este campo
						<input type="text" name="website" tabindex="-1" autocomplete="off" />
					</label>
				</div>

				<?php
				// Optional on purpose: requesting a bike is not consent to be
				// marketed at. Unticked, the address stays on the request and
				// never becomes a marketing lead — see
				// Citykleta_Bike_Request_Rest::maybe_capture_lead().
				?>
				<label class="citykleta-bike-request__consent">
					<input type="checkbox" name="consent" value="1" />
					<span>Quiero recibir novedades de Citykleta.</span>
				</label>

				<button type="submit" class="citykleta-bike-request__submit">Enviar solicitud</button>

				<p class="citykleta-bike-request__error" role="alert" hidden></p>

				<p class="citykleta-bike-request__note">
					Esto es una solicitud, no una reserva confirmada: te escribimos para coordinar con el propietario.
				</p>
			</form>

			<div class="citykleta-bike-request__done" role="status" hidden>
				<p class="citykleta-bike-request__done-title">¡Solicitud enviada!</p>
				<p>Te contactamos pronto para coordinar con el propietario.</p>
			</div>

		</div>
		<?php
	}
}
