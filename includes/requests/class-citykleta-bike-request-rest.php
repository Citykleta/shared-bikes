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
	 * Shortest string accepted as a phone number, counting digits only. Cuban
	 * mobiles are 8 digits; anything shorter isn't a number someone can call.
	 */
	const MIN_PHONE_DIGITS = 8;

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

		$name = sanitize_text_field( (string) $request->get_param( 'name' ) );

		if ( '' === $name ) {
			return $this->error( 'Hace falta tu nombre.' );
		}

		$contact = $this->parse_contact( (string) $request->get_param( 'contact' ) );

		if ( null === $contact ) {
			return $this->error( 'Deja un correo o un teléfono para poder responderte.' );
		}

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

		$post_id = Citykleta_Bike_Request::create(
			array(
				'bike_id'      => $bike_id,
				'name'         => $name,
				'contact'      => $contact['value'],
				'contact_type' => $contact['type'],
				'from'         => $from,
				'to'           => $to,
				'message'      => sanitize_textarea_field( (string) $request->get_param( 'message' ) ),
				// Stored as typed, never checked: there is no public route on
				// citykleta-sharing-api to verify a person against. See
				// Citykleta_Bike_Request::META_VERIFICATION_CODE.
				'verification_code' => sanitize_text_field( (string) $request->get_param( 'verification_code' ) ),
				'source'       => esc_url_raw( (string) $request->get_param( 'source' ) ),
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

		$this->maybe_capture_lead( $request, $name, $contact );

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

		return new WP_REST_Response( array( 'ok' => true ), 201 );
	}

	/**
	 * Optionally records the requester as a marketing lead.
	 *
	 * Only when they ticked the box AND left an email. Requesting a bike is not
	 * consent to be marketed at — those are different legal bases — and
	 * Citykleta_Subscriber::upsert() stamps consent unconditionally, so a
	 * request must never be turned into a subscriber on its own. Without the
	 * tick the address stays on the request and never reaches HubSpot.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @param string          $name    Sanitized name.
	 * @param array           $contact Parsed contact (value + type).
	 */
	private function maybe_capture_lead( WP_REST_Request $request, $name, array $contact ) {
		if ( Citykleta_Bike_Request::CONTACT_EMAIL !== $contact['type'] ) {
			return;
		}

		if ( ! rest_sanitize_boolean( $request->get_param( 'consent' ) ) ) {
			return;
		}

		if ( ! class_exists( 'Citykleta_Subscriber' ) ) {
			return;
		}

		$source  = esc_url_raw( (string) $request->get_param( 'source' ) );
		$post_id = Citykleta_Subscriber::upsert( $contact['value'], $name, $source );

		if ( ! $post_id ) {
			return;
		}

		/** This is documented in class-citykleta-subscriber-rest.php. */
		do_action( 'citykleta_subscriber_captured', $post_id, strtolower( $contact['value'] ), $name, $source );
	}

	/**
	 * Validates the contact field, which accepts either an email or a phone.
	 *
	 * One field instead of two on purpose: in Havana plenty of people have a
	 * WhatsApp number and no email they check, and asking for both would lose
	 * requests.
	 *
	 * @param string $raw What the visitor typed.
	 * @return array{value:string,type:string}|null Null when it's neither.
	 */
	private function parse_contact( $raw ) {
		$raw = trim( $raw );

		if ( '' === $raw ) {
			return null;
		}

		$email = sanitize_email( $raw );

		if ( is_email( $email ) ) {
			return array(
				'value' => strtolower( $email ),
				'type'  => Citykleta_Bike_Request::CONTACT_EMAIL,
			);
		}

		$digits = preg_replace( '/\D/', '', $raw );

		if ( strlen( (string) $digits ) >= self::MIN_PHONE_DIGITS ) {
			return array(
				'value' => sanitize_text_field( $raw ),
				'type'  => Citykleta_Bike_Request::CONTACT_PHONE,
			);
		}

		return null;
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
	 * A 400 with a message the form shows to the visitor.
	 *
	 * @param string $message Message in Spanish, like the rest of the front-end.
	 * @return WP_REST_Response
	 */
	private function error( $message ) {
		return new WP_REST_Response(
			array(
				'ok'      => false,
				'message' => $message,
			),
			400
		);
	}
}
