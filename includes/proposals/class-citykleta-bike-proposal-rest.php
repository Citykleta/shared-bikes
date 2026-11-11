<?php
/**
 * REST routes of the owner-bike inbox (see Citykleta_Bike_Proposal).
 *
 * Three routes, two secrets, and the secrets are kept apart on purpose:
 *
 * - POST /citykleta/v1/proposals/webhook?key=...  is what the Elementor form's
 *   Webhook calls. Elementor can only send a URL, so the secret travels in it.
 *   It can do one thing: add a proposal to this inbox. If the URL leaks, the
 *   worst case is junk proposals, which is what anyone can already do with the
 *   public form; the hourly cap below bounds it.
 * - GET  /citykleta/v1/proposals and
 *   POST /citykleta/v1/proposals/{id}/imported  are for the Citykleta API, which
 *   pulls from here. They need the API key in the x-citykleta-proposals-key
 *   header. The key is kept here only as a SHA-256 hash: a copy of this site's
 *   database cannot pull anyone's contact details. That is the reason the API
 *   asks WordPress and not the other way round (ARCHITECTURE.md).
 *
 * @package Citykleta_Shared_Bikes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Citykleta_Bike_Proposal_Rest.
 */
class Citykleta_Bike_Proposal_Rest {

	/**
	 * REST namespace shared with the rest of the plugin.
	 */
	const REST_NAMESPACE = 'citykleta/v1';

	/**
	 * Option: SHA-256 of the key in the Webhook URL.
	 */
	const OPTION_WEBHOOK_HASH = 'citykleta_proposal_webhook_key_hash';

	/**
	 * Option: SHA-256 of the key the API sends.
	 */
	const OPTION_API_HASH = 'citykleta_proposal_api_key_hash';

	/**
	 * Option: when set, only submissions of the form with this name are kept
	 * (Elementor sends it as form_name). Empty accepts any form.
	 */
	const OPTION_FORM_NAME = 'citykleta_proposal_form_name';

	/**
	 * Option: '1' answers 400 to a submission that lacks required fields, so the
	 * person sees the form's error and can retry. Off by default: until the real
	 * form exists, a test form would be refused for lacking fields it never had.
	 */
	const OPTION_REJECT_INCOMPLETE = 'citykleta_proposal_reject_incomplete';

	/**
	 * Header the API sends its key in (canonical form, as WP_REST_Request reads it).
	 */
	const API_KEY_HEADER = 'x_citykleta_proposals_key';

	/**
	 * A submission bigger than this is refused (photos are links, not bytes).
	 */
	const MAX_BODY = 262144;

	/**
	 * Most proposals one listing returns.
	 */
	const MAX_PAGE = 50;

	/**
	 * Connects the class to WordPress hooks.
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the three routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/proposals/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'receive' ),
				'permission_callback' => array( $this, 'check_webhook_key' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/proposals',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_pending' ),
				'permission_callback' => array( $this, 'check_api_key' ),
				'args'                => array(
					'after' => array(
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'limit' => array(
						'default'           => 20,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/proposals/(?P<id>\d+)/imported',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'mark_imported' ),
				'permission_callback' => array( $this, 'check_api_key' ),
			)
		);
	}

	// --- Keys ------------------------------------------------------------------

	/**
	 * Makes a new key, stores only its hash and returns the key, which is shown
	 * once. The previous key stops working at once.
	 *
	 * @param string $option One of the OPTION_*_HASH constants.
	 * @return string The key.
	 */
	public static function generate_key( $option ) {
		$key = wp_generate_password( 48, false, false );

		update_option( $option, hash( 'sha256', $key ), false );

		return $key;
	}

	/**
	 * Whether a key matches the hash stored in an option.
	 *
	 * @param string $sent   The key received.
	 * @param string $option One of the OPTION_*_HASH constants.
	 * @return bool
	 */
	private static function key_matches( $sent, $option ) {
		$hash = (string) get_option( $option, '' );

		return '' !== $hash && '' !== $sent && hash_equals( $hash, hash( 'sha256', $sent ) );
	}

	/**
	 * Permission check of the Webhook: the key in the URL.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return true|WP_Error
	 */
	public function check_webhook_key( $request ) {
		$query = $request->get_query_params();
		$sent  = isset( $query['key'] ) && is_string( $query['key'] ) ? $query['key'] : '';

		return self::key_matches( $sent, self::OPTION_WEBHOOK_HASH ) ? true : $this->error( 'forbidden', 'Forbidden', 403 );
	}

	/**
	 * Permission check of the API routes: the key in the header.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return true|WP_Error
	 */
	public function check_api_key( $request ) {
		$sent = (string) $request->get_header( self::API_KEY_HEADER );

		return self::key_matches( $sent, self::OPTION_API_HASH ) ? true : $this->error( 'forbidden', 'Forbidden', 403 );
	}

	// --- Routes ----------------------------------------------------------------

	/**
	 * The Webhook: stores one submission. It does nothing slow (no call to the
	 * API, no mail), because Elementor gives up on a slow answer and shows the
	 * person an error.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function receive( $request ) {
		$raw = (string) $request->get_body();

		if ( strlen( $raw ) > self::MAX_BODY ) {
			return $this->error( 'too_large', 'Submission too large.', 413 );
		}

		$parsed = Citykleta_Proposal_Fields::parse_raw_body( $raw );

		if ( empty( $parsed ) ) {
			$parsed = (array) $request->get_body_params();
		}

		if ( empty( $parsed ) ) {
			return $this->error( 'empty', 'Empty submission.', 400 );
		}

		$form_name = isset( $parsed['form_name'] ) && is_string( $parsed['form_name'] ) ? sanitize_text_field( $parsed['form_name'] ) : '';
		$only_form = (string) get_option( self::OPTION_FORM_NAME, '' );

		if ( '' !== $only_form && $only_form !== $form_name ) {
			return new WP_REST_Response(
				array(
					'ok'      => true,
					'ignored' => true,
				),
				200
			);
		}

		if ( $this->over_cap() ) {
			return $this->error( 'rate_limited', 'Too many submissions. Try again later.', 429 );
		}

		$normalized = Citykleta_Proposal_Fields::normalize( $parsed );

		if ( '1' === (string) get_option( self::OPTION_REJECT_INCOMPLETE, '0' ) && Citykleta_Proposal_Fields::missing( $normalized ) ) {
			return $this->error( 'incomplete', 'Incomplete submission.', 400 );
		}

		$fingerprint = $this->fingerprint( $normalized, $raw );
		$existing    = Citykleta_Bike_Proposal::find_by_fingerprint( $fingerprint );

		if ( $existing ) {
			return new WP_REST_Response(
				array(
					'ok'        => true,
					'id'        => $existing,
					'duplicate' => true,
				),
				200
			);
		}

		$post_id = Citykleta_Bike_Proposal::create( $normalized, $form_name, $fingerprint );

		if ( is_wp_error( $post_id ) ) {
			return $this->error( 'save_failed', 'Could not save the submission.', 500 );
		}

		/**
		 * Fires after a proposal is stored, for anything that wants to react (a
		 * staff notification, for instance).
		 *
		 * @param int   $post_id    The proposal's post ID.
		 * @param array $normalized What was stored.
		 */
		do_action( 'citykleta_bike_proposal_received', $post_id, $normalized );

		return new WP_REST_Response(
			array(
				'ok' => true,
				'id' => $post_id,
			),
			200
		);
	}

	/**
	 * For the API: the pending proposals after a cursor, oldest first.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function list_pending( $request ) {
		$after = (int) $request->get_param( 'after' );
		$limit = min( self::MAX_PAGE, max( 1, (int) $request->get_param( 'limit' ) ) );
		$ids   = Citykleta_Bike_Proposal::pending_ids_after( $after, $limit );
		$list  = array();

		foreach ( $ids as $id ) {
			$list[] = Citykleta_Bike_Proposal::to_api_array( $id );
		}

		return new WP_REST_Response(
			array(
				'proposals'  => $list,
				'next_after' => $ids ? (int) end( $ids ) : $after,
			),
			200
		);
	}

	/**
	 * For the API: "I have this one". Records the bike's ID and deletes the
	 * photos from this server. Repeating it is harmless.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function mark_imported( $request ) {
		$post = get_post( (int) $request['id'] );

		if ( ! $post || Citykleta_Bike_Proposal::POST_TYPE !== $post->post_type ) {
			return $this->error( 'not_found', 'No such proposal.', 404 );
		}

		$body    = $request->get_json_params();
		$bike_id = is_array( $body ) && isset( $body['api_bike_id'] ) ? absint( $body['api_bike_id'] ) : 0;

		if ( $bike_id < 1 ) {
			return $this->error( 'bad_bike_id', 'api_bike_id is required.', 400 );
		}

		if ( Citykleta_Bike_Proposal::STATUS_IMPORTED === get_post_meta( $post->ID, Citykleta_Bike_Proposal::META_STATUS, true ) ) {
			return new WP_REST_Response(
				array(
					'ok'      => true,
					'already' => true,
				),
				200
			);
		}

		return new WP_REST_Response(
			array(
				'ok'             => true,
				'deleted_photos' => Citykleta_Bike_Proposal::mark_imported( $post->ID, $bike_id ),
			),
			200
		);
	}

	// --- Helpers ---------------------------------------------------------------

	/**
	 * Identifies a submission so Elementor sending it twice does not make two
	 * proposals: the form, the moment Elementor stamped it and the email. Without
	 * those, the whole body.
	 *
	 * @param array  $normalized The result of normalize().
	 * @param string $raw        The raw body.
	 * @return string
	 */
	private function fingerprint( array $normalized, $raw ) {
		$form_id   = isset( $normalized['system']['form_id'] ) ? $normalized['system']['form_id'] : '';
		$timestamp = isset( $normalized['system']['timestamp'] ) ? $normalized['system']['timestamp'] : '';

		if ( '' === $form_id && '' === $timestamp ) {
			return sha1( $raw );
		}

		return sha1( $form_id . '|' . $timestamp . '|' . ( isset( $normalized['fields']['email'] ) ? $normalized['fields']['email'] : '' ) );
	}

	/**
	 * A ceiling on proposals per hour, in case the Webhook URL leaks. The sender's
	 * IP cannot be used: Elementor calls this from the site's own server.
	 *
	 * @return bool True when this one would go over the cap.
	 */
	private function over_cap() {
		$cap   = (int) apply_filters( 'citykleta_proposal_hourly_cap', 30 );
		$key   = 'citykleta_proposal_cap_' . gmdate( 'YmdH' );
		$count = (int) get_transient( $key );

		if ( $count >= $cap ) {
			return true;
		}

		set_transient( $key, $count + 1, 2 * HOUR_IN_SECONDS );

		return false;
	}

	/**
	 * A REST error.
	 *
	 * @param string $code    Short code.
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 * @return WP_Error
	 */
	private function error( $code, $message, $status ) {
		return new WP_Error( 'citykleta_proposal_' . $code, $message, array( 'status' => $status ) );
	}
}
