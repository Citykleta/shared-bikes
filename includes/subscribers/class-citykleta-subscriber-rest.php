<?php
/**
 * REST routes behind the app-download wall: capture the sign-up, then record
 * the download click.
 *
 * Both are public (visitors aren't logged in) with the same reasoning as the
 * view counter (see Citykleta_View_Stats): a per-user nonce can't be verified
 * for anonymous visitors, so the callbacks validate the payload instead. Abuse
 * is limited to creating junk leads / inflating the download stat; add
 * IP rate-limiting here if that ever matters.
 *
 * WP only stores the lead (with a documented consent + timestamp) and never
 * sends confirmation email — deliverability is poor from WP and verification
 * belongs to HubSpot once the lead is synced. See Citykleta_Subscriber.
 *
 * @package Citykleta_Shared_Bikes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Citykleta_Subscriber_Rest.
 */
class Citykleta_Subscriber_Rest {

	/**
	 * REST namespace.
	 */
	const REST_NAMESPACE = 'citykleta/v1';

	/**
	 * Connects the class to WordPress hooks.
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the capture and download routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/subscribers',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'capture' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/subscribers/(?P<id>\d+)/download',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'record_download' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id' => array(
						'validate_callback' => function ( $value ) {
							return is_numeric( $value );
						},
					),
				),
			)
		);
	}

	/**
	 * Captures a sign-up: validates email + consent, then upserts the lead.
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

		$email = sanitize_email( (string) $request->get_param( 'email' ) );

		if ( ! is_email( $email ) ) {
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'message' => 'A valid email is required.',
				),
				400
			);
		}

		// Consent is mandatory: it's the legal basis for contacting the lead
		// later from HubSpot, so no consent means no capture.
		if ( ! rest_sanitize_boolean( $request->get_param( 'consent' ) ) ) {
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'message' => 'Consent is required.',
				),
				400
			);
		}

		$name   = sanitize_text_field( (string) $request->get_param( 'name' ) );
		$source = esc_url_raw( (string) $request->get_param( 'source' ) );

		$post_id = Citykleta_Subscriber::upsert( $email, $name, $source );

		if ( ! $post_id ) {
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'message' => 'Could not save the sign-up.',
				),
				500
			);
		}

		/**
		 * Fires right after a sign-up is captured and stored. The HubSpot
		 * connector (a separate, optional plugin) hooks this to push the lead
		 * to HubSpot in real time; if no connector is active nothing happens,
		 * and this plugin stays unaware HubSpot exists. Also fires on repeat
		 * sign-ups (the upsert refreshed an existing lead) — the push is
		 * idempotent on the HubSpot side (create-or-update by email).
		 *
		 * @param int    $post_id Subscriber post ID.
		 * @param string $email   Validated, lower-cased email.
		 * @param string $name    Sanitized name (may be empty).
		 * @param string $source  Sign-up source URL (may be empty).
		 */
		do_action( 'citykleta_subscriber_captured', $post_id, strtolower( $email ), $name, $source );

		// The id lets the front-end report the download click for this lead. It
		// isn't PII on its own, and the download route never returns any.
		return new WP_REST_Response(
			array(
				'ok' => true,
				'id' => $post_id,
			),
			201
		);
	}

	/**
	 * Records that a subscriber clicked download.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function record_download( WP_REST_Request $request ) {
		$post_id = absint( $request['id'] );

		if ( Citykleta_Subscriber::POST_TYPE !== get_post_type( $post_id ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 404 );
		}

		Citykleta_Subscriber::mark_downloaded( $post_id );

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}
}
