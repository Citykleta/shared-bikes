<?php
/**
 * Client for citykleta-sharing-api's `POST /contact/reveal`.
 *
 * The route trades a verification (contact) code for the owner's phone number
 * and the requester's name. It is the only call in this plugin that is NOT
 * anonymous: it sends a shared key in the `x-citykleta-plugin-key` header, which
 * the API compares against its WORDPRESS_PLUGIN_KEY. The route is mounted before
 * the API's global verifyAuth precisely so this plugin, which has no user
 * session, can call it.
 *
 * The key is entered in Settings → Sync → API access (option
 * Citykleta_Settings::PLUGIN_KEY_OPTION, never printed back into the page). A
 * CITYKLETA_PLUGIN_KEY constant in wp-config.php overrides it, for installs that
 * prefer to keep the credential out of the database.
 *
 * The API's error bodies carry only `{ message }` (no machine-readable code), so
 * the outcome is told apart by HTTP status — see self::request().
 *
 * @package Citykleta_Shared_Bikes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Citykleta_Contact_Reveal.
 */
class Citykleta_Contact_Reveal {

	/**
	 * wp-config.php constant holding the shared key.
	 */
	const KEY_CONSTANT = 'CITYKLETA_PLUGIN_KEY';

	/**
	 * Seconds to wait for the API. Short on purpose: the visitor is looking at
	 * a spinner, and the request is saved without verification if this runs out.
	 */
	const TIMEOUT = 8;

	/**
	 * Outcome: the code is valid and the owner's contact came back.
	 */
	const STATUS_OK = 'ok';

	/**
	 * Outcome: the API says the code is not valid (403). Covers a code that does
	 * not exist, one that was never approved, and one from a closed account — the
	 * API deliberately answers all three the same.
	 */
	const STATUS_INVALID_CODE = 'invalid_code';

	/**
	 * Outcome: this code already looked up too many different owners (429).
	 */
	const STATUS_LIMIT = 'limit';

	/**
	 * Outcome: the API does not know the bike, or has retired it (400).
	 */
	const STATUS_BIKE_GONE = 'bike_gone';

	/**
	 * Outcome: nothing could be verified — key missing or wrong, API down or
	 * slow, unexpected answer. Not the visitor's fault, so the request is kept.
	 */
	const STATUS_UNAVAILABLE = 'unavailable';

	/**
	 * Trades a code for the owner's contact.
	 *
	 * @param string $code        Verification code as typed on the form.
	 * @param string $external_id The bike's id in the app (the `external_id` meta).
	 * @return array{status:string,message:string,note:string,requester_name:string,owner_name:string,owner_phone:string}
	 *         `message` is what the visitor may be shown (Spanish, from the API
	 *         when it sent one); `note` is for staff and for the log.
	 */
	public static function request( $code, $external_id ) {
		$result = array(
			'status'         => self::STATUS_UNAVAILABLE,
			'message'        => '',
			'note'           => '',
			'requester_name' => '',
			'owner_name'     => '',
			'owner_phone'    => '',
		);

		$key = Citykleta_Settings::plugin_key();
		$url = Citykleta_Settings::get_endpoint_url( 'contact_reveal' );

		if ( '' === $key ) {
			$result['note'] = 'No plugin key (Settings → Sync → API access)';
			return self::logged( $result );
		}

		if ( '' === $url ) {
			$result['note'] = 'No API base URL configured (Settings → Sync)';
			return self::logged( $result );
		}

		// The API reads bike_id with Number() + Number.isInteger(), so anything
		// that is not a whole number would be an invalid payload on its side.
		if ( ! ctype_digit( (string) $external_id ) ) {
			$result['note'] = 'This bike has no app id (not created by the sync)';
			return self::logged( $result );
		}

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Content-Type'           => 'application/json',
					'x-citykleta-plugin-key' => $key,
				),
				'body'    => wp_json_encode(
					array(
						'contact_code' => $code,
						'bike_id'      => (int) $external_id,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$result['note'] = 'API unreachable: ' . $response->get_error_message();
			return self::logged( $result );
		}

		$http = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$body = is_array( $body ) ? $body : array();

		// The API's own message is written to be shown (Spanish, no internals).
		$api_message = isset( $body['message'] ) && is_string( $body['message'] ) ? $body['message'] : '';

		switch ( $http ) {
			case 200:
				if ( empty( $body['ok'] ) ) {
					$result['note'] = 'HTTP 200 without ok:true';
					return self::logged( $result );
				}

				$result['status']         = self::STATUS_OK;
				$result['requester_name'] = isset( $body['requester']['name'] ) ? sanitize_text_field( (string) $body['requester']['name'] ) : '';
				$result['owner_name']     = isset( $body['owner']['name'] ) ? sanitize_text_field( (string) $body['owner']['name'] ) : '';
				$result['owner_phone']    = isset( $body['owner']['phone'] ) ? sanitize_text_field( (string) $body['owner']['phone'] ) : '';
				return $result;

			case 403:
				$result['status']  = self::STATUS_INVALID_CODE;
				$result['message'] = '' !== $api_message ? $api_message : 'El código no es válido.';
				return $result;

			case 429:
				$result['status']  = self::STATUS_LIMIT;
				$result['message'] = '' !== $api_message ? $api_message : 'Has consultado demasiados contactos hoy. Inténtalo de nuevo mañana.';
				return $result;

			case 400:
				$result['status']  = self::STATUS_BIKE_GONE;
				$result['message'] = 'Esa bicicleta ya no está disponible.';
				return $result;

			case 401:
				// A wrong key falls through the API's plugin check into its
				// ordinary session check, which answers 401. That is OUR
				// misconfiguration, not a bad code, so it must not be reported
				// to the visitor as one.
				$result['note'] = 'API rejected the plugin key (401) — check Settings → Sync → API access';
				return self::logged( $result );

			default:
				$result['note'] = 'Unexpected HTTP ' . $http;
				return self::logged( $result );
		}
	}

	/**
	 * Writes an "unavailable" outcome to the PHP error log and returns it.
	 * Without this a missing or wrong key would only show up as a quiet
	 * "not verified" note on each request.
	 *
	 * @param array $result Outcome from self::request().
	 * @return array Same array.
	 */
	private static function logged( array $result ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( '[citykleta] contact reveal unavailable: ' . $result['note'] );

		return $result;
	}
}
