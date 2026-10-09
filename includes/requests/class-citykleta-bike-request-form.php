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

		self::enqueue_form_assets();
	}

	/**
	 * The form's CSS/JS plus the REST base its script needs.
	 *
	 * Public and static because the form is no longer only printed by
	 * templates/single-bike.php: it's also the "Bike Request"
	 * Elementor widget/shortcode (Citykleta_Bike_Part_Widgets), which can
	 * land on any page — so Citykleta_Elementor calls this too. Safe to
	 * call more than once: wp_enqueue_* dedupes by handle.
	 */
	public static function enqueue_form_assets() {
		/*
		 * The rental-period field is the search bar's range picker, so it
		 * needs the search bar's vendor + glue scripts. They're enqueued from
		 * here rather than relied on from Citykleta_Elementor: the form can be
		 * dropped on a page that has no search widget at all, and
		 * wp_enqueue_* dedupes by handle anyway. When the vendor files aren't
		 * there, the form prints native date inputs instead — see
		 * self::has_range_picker().
		 */
		if ( file_exists( CITYKLETA_PATH . 'assets/js/flatpickr.min.js' ) ) {
			wp_enqueue_style( 'flatpickr', CITYKLETA_URL . 'assets/css/flatpickr.min.css', array(), CITYKLETA_VERSION );
			wp_enqueue_style( 'citykleta-search-bar-datepicker', CITYKLETA_URL . 'assets/css/search-bar-datepicker.css', array( 'flatpickr' ), CITYKLETA_VERSION );

			wp_enqueue_script( 'flatpickr', CITYKLETA_URL . 'assets/js/flatpickr.min.js', array(), CITYKLETA_VERSION, true );
			wp_enqueue_script( 'flatpickr-es', CITYKLETA_URL . 'assets/js/flatpickr-es.js', array( 'flatpickr' ), CITYKLETA_VERSION, true );
			wp_enqueue_script( 'citykleta-search-widget', CITYKLETA_URL . 'assets/js/citykleta-search-widget.js', array( 'flatpickr-es' ), CITYKLETA_VERSION, true );
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
	 * Whether the Flatpickr range picker the search bar uses is available.
	 *
	 * Those vendor files don't ship with the plugin (see CLAUDE.md), and a
	 * bike request must not silently lose its date fields on an install that
	 * hasn't downloaded them — so the date field falls back to two native
	 * <input type="date"> when they're missing, rather than printing a
	 * read-only text box that nothing would ever open.
	 *
	 * @return bool
	 */
	private static function has_range_picker() {
		return file_exists( CITYKLETA_PATH . 'assets/js/flatpickr.min.js' );
	}

	/**
	 * Default presentation args for self::render(), so a caller that passes
	 * nothing (the shortcode, or any older call site) still gets a complete
	 * form. The Elementor widget overrides these from its own controls.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'show_title'    => 'yes',
			'title'         => 'Solicitar esta bicicleta',
			'dates_label'   => 'Periodo de renta',
			'dates_hint'    => 'Llegada — Salida',
			'show_code'     => 'yes',
			'code_label'    => 'Código de verificación',
			'require_code'  => 'yes',
			'verify_url'    => '',
			'verify_text'   => 'Verificarme ahora',
			'next_text'     => 'Pedir esta bici',
			'submit_text'   => 'Enviar solicitud',
			'back_text'     => 'Atrás',
			'whatsapp_text' => 'Escribir al propietario por WhatsApp',
		);
	}

	/**
	 * Prints the form for one bike, in two steps.
	 *
	 * **Step 1 is about the bike** (rental period + verification code) and
	 * **step 2 is the optional message**. Splitting them is not cosmetic:
	 * step 1 is what sits inside the price card next to the bike, where a
	 * long form buries the one thing the visitor came to do. Nothing is
	 * submitted until step 2, so the whole thing is still a single POST —
	 * the steps are panels, not requests.
	 *
	 * **No name/contact fields here.** The verification code is the
	 * requester's identifier — whoever verified it already has a name and a
	 * way to reach them on file wherever that verification happened, so
	 * asking for them again on this form would be asking twice for the same
	 * thing. See Citykleta_Bike_Request_Rest::resolve_identity() for how (and
	 * how much of) that gets resolved today.
	 *
	 * @param int   $bike_id Bike post ID.
	 * @param array $args    Presentation args; see self::defaults().
	 */
	public static function render( $bike_id, array $args = array() ) {
		$args  = array_merge( self::defaults(), $args );
		$today = current_time( 'Y-m-d' );
		$uid   = 'citykleta-req-' . wp_rand( 1000, 999999 );
		$price = Citykleta_Bike_Meta::price_amount( $bike_id );
		?>
		<div
			class="citykleta-bike-request"
			data-bike="<?php echo (int) $bike_id; ?>"
			<?php echo null !== $price ? 'data-price="' . esc_attr( (string) $price ) . '"' : ''; ?>
		>

			<?php if ( 'yes' === $args['show_title'] && '' !== $args['title'] ) : ?>
				<h2 class="citykleta-bike-request__title"><?php echo esc_html( $args['title'] ); ?></h2>
			<?php endif; ?>

			<form class="citykleta-bike-request__form">

				<?php // Step 1 — the booking itself. ?>
				<div class="citykleta-bike-request__step" data-step="1">

					<?php if ( self::has_range_picker() ) : ?>
						<?php
						/*
						 * The exact same range picker as the search bar: the
						 * read-only visible input plus two hidden Y-m-d fields,
						 * marked with the data attributes citykleta-search-widget.js
						 * looks for. One implementation, two places.
						 */
						?>
						<div class="citykleta-bike-request__field citykleta-bike-request__field--range" data-citykleta-range>
							<label class="citykleta-bike-request__label" for="<?php echo esc_attr( $uid ); ?>-range"><?php echo esc_html( $args['dates_label'] ); ?></label>
							<input
								type="text"
								id="<?php echo esc_attr( $uid ); ?>-range"
								class="citykleta-bike-request__input citykleta-daterange"
								placeholder="<?php echo esc_attr( $args['dates_hint'] ); ?>"
								autocomplete="off"
								readonly="readonly"
							/>
							<input type="hidden" name="from" data-citykleta-date="from" />
							<input type="hidden" name="to" data-citykleta-date="to" />
						</div>
					<?php else : ?>
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
					<?php endif; ?>

					<?php
						/*
						 * Live estimate, filled in by citykleta-bike-request.js once
						 * both dates are picked. "Estimado" on purpose: the app
						 * charges hotel-style (calendar days, plus one when the
						 * bike comes back after a cutoff hour — see
						 * getRentalDays() in citykleta-sharing-api), and this form
						 * has dates but no hours, so it can only quote the minimum.
						 * Not printed at all for a bike with no price.
						 */
						?>
						<?php if ( null !== $price ) : ?>
							<div class="citykleta-bike-request__total" aria-live="polite" hidden>
								<span class="citykleta-bike-request__total-calc"></span>
								<span class="citykleta-bike-request__total-sum">
									<span>Total estimado</span>
									<strong class="citykleta-bike-request__total-amount"></strong>
								</span>
								<small class="citykleta-bike-request__total-note">El propietario confirma el total final.</small>
							</div>
						<?php endif; ?>

						<?php if ( 'yes' === $args['show_code'] ) : ?>
						<label class="citykleta-bike-request__field citykleta-bike-request__field--code">
							<span><?php echo esc_html( $args['code_label'] ); ?></span>
							<input
								type="text"
								name="verification_code"
								autocomplete="one-time-code"
								inputmode="text"
								<?php echo 'yes' === $args['require_code'] ? 'data-required="1"' : ''; ?>
							/>
						</label>

						<?php if ( '' !== $args['verify_url'] ) : ?>
							<?php
							/*
							 * target="_blank" is what the flow needs — the visitor
							 * goes off to verify and comes back to a form that
							 * still holds what they had typed. rel="noopener
							 * noreferrer" goes with it always: without it the
							 * opened page gets a handle on this one through
							 * window.opener.
							 */
							?>
							<a
								class="citykleta-bike-request__verify"
								href="<?php echo esc_url( $args['verify_url'] ); ?>"
								target="_blank"
								rel="noopener noreferrer"
							><?php echo esc_html( $args['verify_text'] ); ?></a>
						<?php endif; ?>
					<?php endif; ?>

					<button type="button" class="citykleta-bike-request__next"><?php echo esc_html( $args['next_text'] ); ?></button>
				</div>

				<?php // Step 2 — an optional note, nothing identifying. ?>
				<div class="citykleta-bike-request__step" data-step="2" hidden>

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

					<div class="citykleta-bike-request__actions">
						<button type="button" class="citykleta-bike-request__back"><?php echo esc_html( $args['back_text'] ); ?></button>
						<button type="submit" class="citykleta-bike-request__submit"><?php echo esc_html( $args['submit_text'] ); ?></button>
					</div>
				</div>

				<p class="citykleta-bike-request__error" role="alert" hidden></p>

				<p class="citykleta-bike-request__note">
					Sin pago online: lo coordinas directo con el propietario. Esto es una solicitud, no una reserva confirmada.
				</p>
			</form>

			<div class="citykleta-bike-request__done" role="status" hidden>
				<p class="citykleta-bike-request__done-title">¡Solicitud enviada!</p>
				<p class="citykleta-bike-request__done-text">Te contactamos pronto para coordinar con el propietario.</p>
				<?php
				/*
				 * Filled in and un-hidden by citykleta-bike-request.js only when
				 * the server returned a wa.me link (code verified AND the owner
				 * has a phone). Without it the paragraph above is all there is.
				 */
				?>
				<a
					class="citykleta-bike-request__whatsapp"
					href="#"
					target="_blank"
					rel="noopener noreferrer"
					hidden
				><?php echo esc_html( $args['whatsapp_text'] ); ?></a>
			</div>

		</div>
		<?php
	}
}
