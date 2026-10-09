<?php
/**
 * REST route behind the "request this bike" form.
 *
 * Public, with the same reasoning as the sign-up wall and the view counter
 * (see Citykleta_Subscriber_Rest): visitors aren't logged in, so a per-user
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
	 * REST namespace — the same one the subscriber routes use.
	 */
	const REST_NAMESPACE = 'citykleta/v1';

	/**
	 * Connects the class to WordPress hooks.
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the capture route.
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
	}

	/**
	 * Captures a bike request.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function capture( WP_REST_Request $request ) {
		// Honeypot: a hidden field real users leave empty. If it's filled, a bot
		// did it — pretend everything went fine but store nothing.
		if ( '' !== trim( (string) $request->get_param( 'website' ) ) ) {
			return new WP_REST_Response( array( 'ok' => true ), 200 );
		}

		$bike_id = absint( $request->get_param( 'bike_id' ) );

		// The bike has to be a real, published bike: the id comes from the page
		// but nothing stops someone posting an arbitrary number.
		if ( ! $bike_id
			|| Citykleta_Bike_Post_Type::POST_TYPE !== get_post_type( $bike_id )
			|| 'publish' !== get_post_status( $bike_id ) ) {
			return $this->error( 'Esa bicicleta no está disponible.' );
		}

		$code = sanitize_text_field( (string) $request->get_param( 'verification_code' ) );

		$from = $this->parse_date( (string) $request->get_param( 'from' ) );
		$to   = $this->parse_date( (string) $request->get_param( 'to' ) );

		if ( false === $from || false === $to ) {
			return $this->error( 'Las fechas no tienen un formato válido.' );
		}

		// A range that ends before it starts is a mistake worth catching here;
		// nothing else validates the dates, since no availability system exists.
		if ( '' !== $from && '' !== $to && $to < $from ) {
			return $this->error( 'La fecha de fin va antes que la de inicio.' );
		}

		// Verify the code BEFORE saving anything, and after the cheap checks
		// above: every reveal is logged by the API and counts against the
		// code's per-owner allowance, so bad dates must not spend it.
		$reveal = $this->reveal( $bike_id, $code );

		// The API says the person is not who they claim (or has asked too much,
		// or the bike is gone): there is nothing to store, tell the visitor.
		if ( isset( $reveal['reject'] ) ) {
			return $this->error( $reveal['reject']['message'], $reveal['reject']['http'] );
		}

		$post_id = Citykleta_Bike_Request::create(
			array(
				'bike_id'            => $bike_id,
				'name'               => $reveal['requester_name'],
				'from'               => $from,
				'to'                 => $to,
				'message'            => sanitize_textarea_field( (string) $request->get_param( 'message' ) ),
				'verification_code'  => $code,
				'verified'           => $reveal['verified'],
				'verification_note'  => $reveal['note'],
				'source'             => esc_url_raw( (string) $request->get_param( 'source' ) ),
			)
		);

		if ( ! $post_id ) {
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'message' => 'No se pudo guardar la solicitud.',
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
		 * knowing anything about it — the same arrangement as
		 * `citykleta_subscriber_captured` and the HubSpot connector.
		 *
		 * @param int   $post_id Request post ID.
		 * @param int   $bike_id Requested bike post ID.
		 * @param array $data    The stored request (Citykleta_Bike_Request::get_data()).
		 */
		do_action( 'citykleta_bike_request_captured', $post_id, $bike_id, Citykleta_Bike_Request::get_data( $post_id ) );

		$payload = array(
			'ok'           => true,
			'verified'     => $reveal['verified'],
			'owner_name'   => '',
			'whatsapp_url' => '',
		);

		// The owner's number goes to the visitor ONLY as part of a ready-made
		// wa.me link, once, in this response — it is never stored on the request
		// (the API logs and rate-limits each reveal; keeping a copy here would
		// sidestep that). Without a number the request is still saved and the
		// form falls back to "we'll get in touch".
		if ( $reveal['verified'] ) {
			$owner_name = '' !== $reveal['owner_name'] ? $reveal['owner_name'] : (string) get_post_meta( $bike_id, 'owner_name', true );

			$message = Citykleta_Settings::whatsapp_message(
				array(
					'{owner}' => $owner_name,
					'{bike}'  => (string) get_the_title( $bike_id ),
					'{price}' => Citykleta_Bike_Meta::price_html( $bike_id ),
					'{url}'   => (string) get_permalink( $bike_id ),
					'{dates}' => Citykleta_Bike_Request::dates_phrase( $from, $to ),
				)
			);

			$payload['owner_name']   = $owner_name;
			$payload['whatsapp_url'] = Citykleta_Settings::whatsapp_url( $reveal['owner_phone'], $message );
		}

		return new WP_REST_Response( $payload, 201 );
	}

	/**
	 * Checks the verification code with the app and gets the owner's contact in
	 * the same call (`POST /contact/reveal`, see Citykleta_Contact_Reveal).
	 *
	 * Three possible outcomes, decided by the visitor's code and the API's health:
	 * - **Rejected** (`reject` key): the API said the code is not valid, that this
	 *   code asked about too many owners, or that the bike is gone. Nothing is
	 *   stored and the visitor sees why.
	 * - **Verified**: the code checked out; the owner's phone comes back so the
	 *   response can carry a WhatsApp link.
	 * - **Unverified**: no code was typed, or the check could not run (API down,
	 *   key missing, bike not from the sync). The request is saved anyway —
	 *   losing it over our own outage would cost more than a code staff has to
	 *   look at by hand — and flagged so wp-admin says it was NOT verified.
	 *
	 * @param int    $bike_id Requested bike post ID.
	 * @param string $code    Verification code as typed on the form.
	 * @return array{verified:bool,note:string,requester_name:string,owner_name:string,owner_phone:string,reject?:array{message:string,http:int}}
	 */
	private function reveal( $bike_id, $code ) {
		$outcome = array(
			'verified'       => false,
			'note'           => '',
			'requester_name' => '',
			'owner_name'     => '',
			'owner_phone'    => '',
		);

		if ( '' === $code ) {
			$outcome['note'] = 'No code was typed';
			return $outcome;
		}

		$result = Citykleta_Contact_Reveal::request( $code, (string) get_post_meta( $bike_id, 'external_id', true ) );

		switch ( $result['status'] ) {
			case Citykleta_Contact_Reveal::STATUS_OK:
				$outcome['verified']       = true;
				$outcome['requester_name'] = $result['requester_name'];
				$outcome['owner_name']     = $result['owner_name'];
				$outcome['owner_phone']    = $result['owner_phone'];
				return $outcome;

			case Citykleta_Contact_Reveal::STATUS_INVALID_CODE:
				$outcome['reject'] = array(
					'message' => $result['message'],
					'http'    => 403,
				);
				return $outcome;

			case Citykleta_Contact_Reveal::STATUS_LIMIT:
				$outcome['reject'] = array(
					'message' => $result['message'],
					'http'    => 429,
				);
				return $outcome;

			case Citykleta_Contact_Reveal::STATUS_BIKE_GONE:
				$outcome['reject'] = array(
					'message' => $result['message'],
					'http'    => 400,
				);
				return $outcome;

			default:
				$outcome['note'] = $result['note'];
				return $outcome;
		}
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
