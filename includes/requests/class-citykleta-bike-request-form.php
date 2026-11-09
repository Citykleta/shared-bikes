<?php
/**
 * The "contact the owner" card shown on a bike's page.
 *
 * Rendered by templates/single-bike.php, which is why this is a render helper
 * rather than a shortcode: unlike the app-download wall (global, droppable
 * anywhere), the contact is always about one specific bike.
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
	 * The form's CSS/JS.
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
	 * Those vendor files don't ship with the plugin (see CLAUDE.md), and the
	 * card must not silently lose its date fields on an install that
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
	 * card. The Elementor widget overrides these from its own controls.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'show_title'    => 'yes',
			'title'         => 'Contactar al propietario',
			'name_label'    => 'Tu nombre',
			'email_label'   => 'Tu correo',
			'dates_label'   => 'Periodo de renta',
			'dates_hint'    => 'Llegada — Salida',
			'whatsapp_text' => 'Escribir por WhatsApp',
		);
	}

	/**
	 * The owner's WhatsApp number for a bike, or '' when none is on file.
	 *
	 * The manual contact box wins (Citykleta_Owner_Contact — it is what staff
	 * type by hand, and an email there is not a number), then the synced
	 * `owner_phone` meta. Today that meta is always empty: the public catalogue
	 * carries no phones (see CLAUDE.md, "El contacto del dueño"), so until the
	 * API exposes one — or a verification step returns — numbers come from the
	 * manual box. This is the single place to change when that happens.
	 *
	 * @param int $bike_id Bike post ID.
	 * @return string Digits only, '' when there is no usable number.
	 */
	public static function owner_phone( $bike_id ) {
		$contact = Citykleta_Owner_Contact::get( $bike_id );
		$phone   = Citykleta_Owner_Contact::TYPE_PHONE === $contact['type'] ? $contact['value'] : '';

		if ( '' === $phone ) {
			$phone = (string) get_post_meta( $bike_id, 'owner_phone', true );
		}

		return (string) preg_replace( '/\D+/', '', $phone );
	}

	/**
	 * Prints the contact card for one bike: the rental period, an estimated
	 * total, and a WhatsApp button that opens a chat with the owner.
	 *
	 * **The message is never sent from here.** The button is a plain link to
	 * wa.me with the message already written; the visitor sends it from their
	 * own WhatsApp, where they can write whatever else they want — which is why
	 * there is no message field. That is also why there is no "request sent"
	 * state. What does reach the server is the visitor's name and email (plus
	 * bike and dates), recorded when they tap the button
	 * (citykleta-bike-request.js → Citykleta_Bike_Request_Rest), so Citykleta
	 * knows who asked about which bike. The dates also rewrite the link's text as
	 * they are picked; without JS the link still opens the chat, just with no
	 * name check and no dates in the message.
	 *
	 * @param int   $bike_id Bike post ID.
	 * @param array $args    Presentation args; see self::defaults().
	 */
	public static function render( $bike_id, array $args = array() ) {
		$args  = array_merge( self::defaults(), $args );
		$today = current_time( 'Y-m-d' );
		$uid   = 'citykleta-req-' . wp_rand( 1000, 999999 );
		$price = Citykleta_Bike_Meta::price_amount( $bike_id );
		$phone = self::owner_phone( $bike_id );

		$template = '';
		$href     = '';

		if ( '' !== $phone ) {
			// {dates} stays in the template for the JS to fill; the href below is
			// the no-dates version, which is what a no-JS visitor gets.
			$replacements = array(
				'{owner}' => (string) get_post_meta( $bike_id, 'owner_name', true ),
				'{bike}'  => (string) get_the_title( $bike_id ),
				'{price}' => Citykleta_Bike_Meta::price_html( $bike_id ),
				'{url}'   => (string) get_permalink( $bike_id ),
				'{dates}' => '{dates}',
			);

			$template = Citykleta_Settings::whatsapp_message( $replacements );
			$href     = Citykleta_Settings::whatsapp_url( $phone, str_replace( '{dates}', '', $template ) );
		}
		?>
		<div
			class="citykleta-bike-request"
			data-bike="<?php echo (int) $bike_id; ?>"
			<?php echo null !== $price ? 'data-price="' . esc_attr( (string) $price ) . '"' : ''; ?>
		>

			<?php if ( 'yes' === $args['show_title'] && '' !== $args['title'] ) : ?>
				<h2 class="citykleta-bike-request__title"><?php echo esc_html( $args['title'] ); ?></h2>
			<?php endif; ?>

			<form class="citykleta-bike-request__form" onsubmit="return false;" novalidate>

				<label class="citykleta-bike-request__field">
					<span><?php echo esc_html( $args['name_label'] ); ?></span>
					<input type="text" name="name" autocomplete="name" required />
				</label>

				<label class="citykleta-bike-request__field">
					<span><?php echo esc_html( $args['email_label'] ); ?></span>
					<input type="email" name="email" autocomplete="email" inputmode="email" required />
				</label>

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

				<?php if ( '' !== $href ) : ?>
					<a
						class="citykleta-bike-request__whatsapp"
						href="<?php echo esc_url( $href ); ?>"
						target="_blank"
						rel="noopener noreferrer"
						data-phone="<?php echo esc_attr( $phone ); ?>"
						data-message="<?php echo esc_attr( $template ); ?>"
					>
						<svg class="citykleta-bike-request__whatsapp-icon" viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path fill="currentColor" d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/></svg>
						<span><?php echo esc_html( $args['whatsapp_text'] ); ?></span>
					</a>
				<?php else : ?>
					<p class="citykleta-bike-request__unavailable">Este propietario todavía no tiene un WhatsApp disponible en Citykleta.</p>
				<?php endif; ?>

				<?php // Honeypot: hidden from real users, bots tend to fill it. ?>
				<div class="citykleta-bike-request__hp" aria-hidden="true">
					<label>No llenar este campo
						<input type="text" name="website" tabindex="-1" autocomplete="off" />
					</label>
				</div>

				<p class="citykleta-bike-request__error" role="alert" hidden></p>

				<p class="citykleta-bike-request__note">
					Sin pago online: lo coordinas directo con el propietario por WhatsApp. Esto no es una reserva confirmada.
				</p>
			</form>

		</div>
		<?php
	}
}
