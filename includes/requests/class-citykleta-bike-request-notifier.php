<?php
/**
 * Sends the emails a bike request triggers: one to Citykleta, one to the
 * owner when there's an address on file for them.
 *
 * Kept apart from the REST route and hooked onto
 * `citykleta_bike_request_captured` rather than called directly, so the
 * capture stays the single seam other code hangs off.
 *
 * Mail is best-effort. wp_mail() from a LocalWP install (or from a Cuban host
 * with an intermittent connection) frequently fails, so nothing here treats a
 * send as guaranteed: the request is already stored before any of this runs,
 * and the owner notification is only stamped when wp_mail() actually reports
 * success. The wp-admin screen is the reliable channel; email is the
 * convenience on top.
 *
 * @package Citykleta_Shared_Bikes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Citykleta_Bike_Request_Notifier.
 */
class Citykleta_Bike_Request_Notifier {

	/**
	 * Connects the class to WordPress hooks.
	 */
	public function register() {
		add_action( 'citykleta_bike_request_captured', array( $this, 'notify' ), 10, 3 );
	}

	/**
	 * Sends both notifications.
	 *
	 * @param int   $post_id Request post ID.
	 * @param int   $bike_id Requested bike post ID.
	 * @param array $data    Stored request data.
	 */
	public function notify( $post_id, $bike_id, array $data ) {
		$this->notify_staff( $post_id, $data );
		$this->notify_owner( $post_id, $bike_id, $data );
	}

	/**
	 * Emails Citykleta so someone picks the request up.
	 *
	 * @param int   $post_id Request post ID.
	 * @param array $data    Stored request data.
	 */
	private function notify_staff( $post_id, array $data ) {
		$to = Citykleta_Settings::request_notify_email();

		if ( '' === $to ) {
			return;
		}

		$lines = array(
			'Nueva solicitud de bicicleta.',
			'',
			'Bicicleta: ' . $data['bike_title'],
			'Solicitante: ' . $this->requester_label( $data ),
			'Contacto: ' . ( '' !== $data['contact'] ? $data['contact'] : 'sin resolver' ),
			'Fechas: ' . Citykleta_Bike_Request::format_dates( $post_id ),
		);

		if ( '' !== $data['message'] ) {
			$lines[] = '';
			$lines[] = 'Mensaje:';
			$lines[] = $data['message'];
		}

		$lines[] = '';
		$lines[] = 'Verla en wp-admin: ' . get_edit_post_link( $post_id, 'raw' );

		wp_mail(
			$to,
			sprintf( '[Citykleta] Solicitud: %s', $data['bike_title'] ),
			implode( "\n", $lines )
		);
	}

	/**
	 * Emails the bike's owner, when a contact is on file and it's an address.
	 *
	 * A phone number can't be messaged from here — WhatsApp has no send-on-
	 * behalf API available to this plugin — so those are left for a human to
	 * press the pre-filled link on the request screen. See
	 * Citykleta_Owner_Contact for why the owner's contact is typed by hand at
	 * all (the app's public catalogue deliberately carries no owner PII).
	 *
	 * @param int   $post_id Request post ID.
	 * @param int   $bike_id Requested bike post ID.
	 * @param array $data    Stored request data.
	 */
	private function notify_owner( $post_id, $bike_id, array $data ) {
		$owner = Citykleta_Owner_Contact::get( $bike_id );

		if ( '' === $owner['value'] || Citykleta_Owner_Contact::TYPE_EMAIL !== $owner['type'] ) {
			return;
		}

		$owner_name = (string) get_post_meta( $bike_id, 'owner_name', true );

		$lines = array(
			'' !== $owner_name ? sprintf( 'Hola %s,', $owner_name ) : 'Hola,',
			'',
			sprintf( 'Alguien quiere alquilar tu bicicleta "%s" a través de Citykleta.', $data['bike_title'] ),
			'',
			'Solicitante: ' . $this->requester_label( $data ),
			'Fechas: ' . Citykleta_Bike_Request::format_dates( $post_id ),
		);

		if ( '' !== $data['message'] ) {
			$lines[] = '';
			$lines[] = 'Mensaje:';
			$lines[] = $data['message'];
		}

		$lines[] = '';
		$lines[] = 'El equipo de Citykleta se pondrá en contacto contigo para coordinar.';

		$sent = wp_mail(
			$owner['value'],
			sprintf( 'Solicitud para tu bicicleta "%s"', $data['bike_title'] ),
			implode( "\n", $lines )
		);

		// Only stamp it when the mailer actually accepted the message —
		// otherwise the request screen would claim the owner was told when
		// nothing left the server.
		if ( $sent ) {
			Citykleta_Bike_Request::mark_owner_notified( $post_id );
		}
	}

	/**
	 * Who to call the requester in an email — falls back to the verification
	 * code since the form no longer asks for a name (see
	 * Citykleta_Bike_Request_Rest::resolve_identity()).
	 *
	 * @param array $data Stored request data.
	 * @return string
	 */
	private function requester_label( array $data ) {
		if ( '' !== $data['name'] ) {
			return $data['name'];
		}

		return '' !== $data['code'] ? 'código ' . $data['code'] : 'sin identificar';
	}
}
