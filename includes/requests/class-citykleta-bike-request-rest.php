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

		$identity = $this->resolve_identity( $bike_id, $code );

		$post_id = Citykleta_Bike_Request::create(
			array(
				'bike_id'            => $bike_id,
				'name'               => $identity['name'],
				'contact'            => $identity['contact'],
				'contact_type'       => $identity['contact_type'],
				'from'               => $from,
				'to'                 => $to,
				'message'            => sanitize_textarea_field( (string) $request->get_param( 'message' ) ),
				'verification_code'  => $code,
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

		return new WP_REST_Response( array( 'ok' => true ), 201 );
	}

	/**
	 * Resolves who is behind a verification code, so the form doesn't have to
	 * ask for a name or a contact it already has on file elsewhere.
	 *
	 * **Not connected yet.** citykleta-sharing-api has exactly the route this
	 * needs — `POST /contact/reveal` (`{ contact_code, bike_id }` →
	 * `{ requester: { name, verified_at }, owner: { name, phone } }`, see
	 * CLAUDE.md's "El contacto del dueño" section) — but calling it needs the
	 * plugin's shared key (`x-citykleta-plugin-key`) stored somewhere in
	 * Settings, and there is no field for it today. Until that's wired up,
	 * this returns an unresolved identity and the code is the only thing
	 * staff has to go on — the same "captured, never validated" situation
	 * Citykleta_Bike_Request::META_VERIFICATION_CODE already documents.
	 *
	 * A `citykleta_resolve_request_identity` filter is exposed instead of a
	 * hard call to that endpoint, so the actual HTTP request (and where its
	 * key lives) can be added without touching this REST route again — same
	 * seam pattern as `citykleta_bike_request_captured` for the notifier.
	 * Note that `/contact/reveal` only returns the requester's name (plus a
	 * phone for the OWNER, not the requester) — there is still no way to get
	 * an email/phone to reply to the requester on, unless the app grows one.
	 *
	 * @param int    $bike_id Requested bike post ID.
	 * @param string $code    Verification code as typed on the form.
	 * @return array{name:string,contact:string,contact_type:string}
	 */
	private function resolve_identity( $bike_id, $code ) {
		$identity = array(
			'name'         => '',
			'contact'      => '',
			'contact_type' => '',
		);

		if ( '' === $code ) {
			return $identity;
		}

		/**
		 * Filters the identity resolved from a verification code.
		 *
		 * @param array  $identity {name, contact, contact_type}, empty until
		 *                         something hooks this.
		 * @param string $code     Verification code as typed.
		 * @param int    $bike_id  Requested bike post ID.
		 */
		return apply_filters( 'citykleta_resolve_request_identity', $identity, $code, $bike_id );
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
