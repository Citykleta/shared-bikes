<?php
/**
 * Bikes -> Settings -> "Proposals" tab: where the two secrets of the owner-bike
 * inbox are generated, and its two options set (see Citykleta_Bike_Proposal_Rest).
 * It has no page of its own: Citykleta_Settings lists the tab and calls
 * render_tab(); this class only draws it and handles its forms (they post to the
 * tab's own URL, so admin_init sees them).
 *
 * A key is shown ONCE, right after it is generated, and only its hash is kept:
 * to see it again you generate another, which stops the old one working.
 *
 * @package Citykleta_Shared_Bikes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Citykleta_Proposal_Intake_Page.
 */
class Citykleta_Proposal_Intake_Page {

	/**
	 * The tab's key in Citykleta_Settings::TABS.
	 */
	const TAB = 'proposals';

	/**
	 * Nonce action of the tab's forms.
	 */
	const NONCE = 'citykleta_proposal_intake';

	/**
	 * Connects the class to WordPress hooks.
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'handle_post' ) );
	}

	/**
	 * Where the tab lives.
	 *
	 * @return string
	 */
	public static function tab_url() {
		return admin_url( 'edit.php?post_type=' . Citykleta_Bike_Post_Type::POST_TYPE . '&page=' . Citykleta_Settings::PAGE_SLUG . '&tab=' . self::TAB );
	}

	/**
	 * The transient that carries a freshly made secret to the next page load.
	 *
	 * @return string
	 */
	private function secrets_key() {
		return 'citykleta_proposal_secrets_' . get_current_user_id();
	}

	/**
	 * Handles the three forms of the page.
	 */
	public function handle_post() {
		if ( ! isset( $_POST['citykleta_proposal_action'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( self::NONCE );

		$action = sanitize_key( wp_unslash( $_POST['citykleta_proposal_action'] ) );

		if ( 'webhook_key' === $action ) {
			$key = Citykleta_Bike_Proposal_Rest::generate_key( Citykleta_Bike_Proposal_Rest::OPTION_WEBHOOK_HASH );

			set_transient(
				$this->secrets_key(),
				array( 'webhook_url' => add_query_arg( 'key', $key, rest_url( Citykleta_Bike_Proposal_Rest::REST_NAMESPACE . '/proposals/webhook' ) ) ),
				5 * MINUTE_IN_SECONDS
			);
		} elseif ( 'api_key' === $action ) {
			set_transient(
				$this->secrets_key(),
				array( 'api_key' => Citykleta_Bike_Proposal_Rest::generate_key( Citykleta_Bike_Proposal_Rest::OPTION_API_HASH ) ),
				5 * MINUTE_IN_SECONDS
			);
		} elseif ( 'options' === $action ) {
			update_option( Citykleta_Bike_Proposal_Rest::OPTION_FORM_NAME, isset( $_POST['form_name'] ) ? sanitize_text_field( wp_unslash( $_POST['form_name'] ) ) : '', false );
			update_option( Citykleta_Bike_Proposal_Rest::OPTION_REJECT_INCOMPLETE, empty( $_POST['reject_incomplete'] ) ? '0' : '1', false );
		}

		wp_safe_redirect( self::tab_url() );
		exit;
	}

	/**
	 * Renders the tab's content (the Settings page already draws the wrap, the
	 * title and the tab bar).
	 */
	public function render_tab() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$secrets = get_transient( $this->secrets_key() );

		delete_transient( $this->secrets_key() );

		$secrets = is_array( $secrets ) ? $secrets : array();
		$webhook = '' !== (string) get_option( Citykleta_Bike_Proposal_Rest::OPTION_WEBHOOK_HASH, '' );
		$api     = '' !== (string) get_option( Citykleta_Bike_Proposal_Rest::OPTION_API_HASH, '' );

		echo '<p>Bikes that owners send through the form arrive in <a href="' . esc_url( admin_url( 'edit.php?post_type=' . Citykleta_Bike_Proposal::POST_TYPE ) ) . '">Bikes &rarr; Proposals</a>. The Citykleta API picks them up; they are approved or rejected in the Back Office, not here. This tab only connects the form and the API to that inbox.</p>';

		echo '<h2>1. Webhook URL (for the Elementor form)</h2>';
		echo '<p>Status: <strong>' . ( $webhook ? 'a URL exists' : 'none yet' ) . '</strong>. Paste it in the form: <em>Actions after submit</em> &rarr; Webhook &rarr; Webhook URL. It can only add proposals to this inbox.</p>';

		if ( ! empty( $secrets['webhook_url'] ) ) {
			echo '<p><strong>Copy it now: it is not shown again.</strong></p><input type="text" readonly class="large-text code" value="' . esc_attr( $secrets['webhook_url'] ) . '" onclick="this.select()" />';
		}

		$this->button( 'webhook_key', $webhook ? 'Generate a new URL (the old one stops working)' : 'Generate the URL' );

		echo '<h2>2. API key (for the Citykleta API)</h2>';
		echo '<p>Status: <strong>' . ( $api ? 'a key exists' : 'none yet' ) . '</strong>. The API sends it in the <code>x-citykleta-proposals-key</code> header to <code>' . esc_html( rest_url( Citykleta_Bike_Proposal_Rest::REST_NAMESPACE . '/proposals' ) ) . '</code>. Only its hash is kept here.</p>';

		if ( ! empty( $secrets['api_key'] ) ) {
			echo '<p><strong>Copy it now: it is not shown again.</strong></p><input type="text" readonly class="large-text code" value="' . esc_attr( $secrets['api_key'] ) . '" onclick="this.select()" />';
		}

		$this->button( 'api_key', $api ? 'Generate a new key (the old one stops working)' : 'Generate the key' );

		echo '<h2>3. Options</h2><form method="post">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="citykleta_proposal_action" value="options" />';
		echo '<p><label>Only keep submissions of the form named: <input type="text" name="form_name" value="' . esc_attr( (string) get_option( Citykleta_Bike_Proposal_Rest::OPTION_FORM_NAME, '' ) ) . '" class="regular-text" /></label><br /><span class="description">Elementor sends the form\'s name with every submission. Empty keeps any form.</span></p>';
		echo '<p><label><input type="checkbox" name="reject_incomplete" value="1"' . checked( '1', (string) get_option( Citykleta_Bike_Proposal_Rest::OPTION_REJECT_INCOMPLETE, '0' ), false ) . ' /> Refuse submissions that lack required fields</label><br /><span class="description">The person then sees the form\'s error and can retry. Turn it on once the real form is in place.</span></p>';
		submit_button( 'Save options', 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * A one-button form.
	 *
	 * @param string $action Action name.
	 * @param string $label  Button label.
	 */
	private function button( $action, $label ) {
		echo '<form method="post">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="citykleta_proposal_action" value="' . esc_attr( $action ) . '" />';
		submit_button( $label, 'secondary', 'submit', false );
		echo '</form>';
	}
}
