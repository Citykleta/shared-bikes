<?php
/**
 * REST route behind the "contact the owner" card.
 *
 * The card's WhatsApp button is a plain link, so nothing here gates it: the
 * visitor's name and email are recorded when they tap it, so Citykleta knows who
 * asked about which bike (the owner is reached through WhatsApp, not through
 * this route). The record is an unverified lead — there is no code check any
 * more; see Citykleta_Contact_Reveal for the API call that would come back if a
 * verification step is ever restored.
 *
 * Public, with the same reasoning as the view counter (see
 * Citykleta_View_Stats): visitors aren't logged in, so a per-user
 * nonce can't be verified and the callback validates the payload instead.
 * Abuse is limited to junk requests landing in wp-admin; add IP rate-limiting
 * here if that ever matters.
 *
 * @package Citykleta_Shared_Bikes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Citykleta_Bike_Request_Rest.
 */
class Citykleta_Bike_Request_Rest {

	/**
	 * REST namespace — the same one the view counter uses.
	 */
	const REST_NAMESPACE = 'citykleta/v1';

	/**
	 * Connects the class to WordPress hooks.
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Outcomes the browser may report: the two checks the form makes before
	 * sending anything. Everything else is decided here, server-side.
	 */
	const CLIENT_OUTCOMES = array( 'missing_name', 'invalid_email' );

	/**
	 * Registers the capture route and the client-attempt route.
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/bike-requests',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'capture' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/bike-requests/attempt',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'record_client_attempt' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Counts a request that failed in the browser before reaching capture()
	 * (no name, or an email the form rejects), so the Stats widget can tell
	 * failed attempts from successful ones. Only self::CLIENT_OUTCOMES are
	 * accepted; abuse is limited to inflating a counter, like the view beacon.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function record_client_attempt( WP_REST_Request $request ) {
		$outcome = sanitize_key( (string) $request->get_param( 'outcome' ) );

		if ( ! in_array( $outcome, self::CLIENT_OUTCOMES, true ) ) {
			return $this->error( 'Unknown outcome.' );
		}

		Citykleta_Bike_Request::record_attempt( $outcome );

		return new WP_REST_Response( array( 'ok' => true ), 201 );
	}

	/**
	 * Records who asked about a bike.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function capture( WP_REST_Request $request ) {
		// Honeypot: a hidden field real users leave empty. If it's filled, a bot
		// did it — pretend everything went fine but store nothing.
		if ( '' !== trim( (string) $request->get_param( 'website' ) ) ) {
			Citykleta_Bike_Request::record_attempt( Citykleta_Bike_Request::OUTCOME_SPAM );
			return new WP_REST_Response( array( 'ok' => true ), 200 );
		}

		$bike_id = absint( $request->get_param( 'bike_id' ) );

		// The bike has to be a real, published bike: the id comes from the page
		// but nothing stops someone posting an arbitrary number.
		if ( ! $bike_id
			|| Citykleta_Bike_Post_Type::POST_TYPE !== get_post_type( $bike_id )
			|| 'publish' !== get_post_status( $bike_id ) ) {
			return $this->fail( 'bike_unavailable', 'Esa bicicleta no está disponible.' );
		}

		$name  = sanitize_text_field( (string) $request->get_param( 'name' ) );
		$email = sanitize_email( (string) $request->get_param( 'email' ) );

		if ( '' === $name ) {
			return $this->fail( 'missing_name', 'Escribe tu nombre.' );
		}

		if ( ! is_email( $email ) ) {
			return $this->fail( 'invalid_email', 'Escribe un correo válido.' );
		}

		$from = $this->parse_date( (string) $request->get_param( 'from' ) );
		$to   = $this->parse_date( (string) $request->get_param( 'to' ) );

		if ( false === $from || false === $to ) {
			return $this->fail( 'invalid_dates', 'Las fechas no tienen un formato válido.' );
		}

		// A range that ends before it starts is a mistake worth catching here;
		// nothing else validates the dates, since no availability system exists.
		if ( '' !== $from && '' !== $to && $to < $from ) {
			return $this->fail( 'reversed_dates', 'La fecha de fin va antes que la de inicio.' );
		}

		$post_id = Citykleta_Bike_Request::create(
			array(
				'bike_id'           => $bike_id,
				'name'              => $name,
				'contact'           => $email,
				'contact_type'      => Citykleta_Bike_Request::CONTACT_EMAIL,
				'from'              => $from,
				'to'                => $to,
				'verified'          => false,
				'verification_note' => 'No verification step: name and email as typed',
				'source'            => esc_url_raw( (string) $request->get_param( 'source' ) ),
			)
		);

		if ( ! $post_id ) {
			Citykleta_Bike_Request::record_attempt( 'save_failed' );

			return new WP_REST_Response(
				array(
					'ok'      => false,
					'message' => 'No se pudo guardar.',
				),
				500
			);
		}

		/**
		 * Fires right after a bike request is captured and stored.
		 *
		 * Citykleta_Bike_Request_Notifier hooks this to send the emails. It is
		 * also the seam for phase 2: once citykleta-sharing-api grows a public
		 * route for requests, a connector can push from here without this class
		 * knowing anything about it.
		 *
		 * @param int   $post_id Request post ID.
		 * @param int   $bike_id Requested bike post ID.
		 * @param array $data    The stored request (Citykleta_Bike_Request::get_data()).
		 */
		do_action( 'citykleta_bike_request_captured', $post_id, $bike_id, Citykleta_Bike_Request::get_data( $post_id ) );

		Citykleta_Bike_Request::record_attempt( Citykleta_Bike_Request::OUTCOME_SAVED );

		return new WP_REST_Response( array( 'ok' => true ), 201 );
	}

	/**
	 * Validates a 'Y-m-d' date.
	 *
	 * Past dates are accepted: the visitor's clock or timezone may disagree
	 * with the server's, and a request is confirmed by a human anyway.
	 *
	 * @param string $raw What arrived in the payload.
	 * @return string|false The date, '' when empty, or false when malformed.
	 */
	private function parse_date( $raw ) {
		$raw = trim( $raw );

		if ( '' === $raw ) {
			return '';
		}

		$parts = array_map( 'intval', explode( '-', $raw ) );

		if ( 3 !== count( $parts ) || ! wp_checkdate( $parts[1], $parts[2], $parts[0], $raw ) ) {
			return false;
		}

		return sprintf( '%04d-%02d-%02d', $parts[0], $parts[1], $parts[2] );
	}

	/**
	 * Counts a rejected attempt under its outcome, then answers with the error.
	 *
	 * @param string $outcome A key of Citykleta_Bike_Request::failure_outcomes().
	 * @param string $message Message in Spanish, like the rest of the front-end.
	 * @return WP_REST_Response
	 */
	private function fail( $outcome, $message ) {
		Citykleta_Bike_Request::record_attempt( $outcome );

		return $this->error( $message );
	}

	/**
	 * An error response with a message the form shows to the visitor.
	 *
	 * @param string $message Message in Spanish, like the rest of the front-end.
	 * @param int    $status  HTTP status, 400 unless a more specific one applies.
	 * @return WP_REST_Response
	 */
	private function error( $message, $status = 400 ) {
		return new WP_REST_Response(
			array(
				'ok'      => false,
				'message' => $message,
			),
			$status
		);
	}
}
