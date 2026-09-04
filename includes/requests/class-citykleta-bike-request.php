<?php
/**
 * "Bike request" content type: a visitor asking to rent a specific bike.
 *
 * This is a REQUEST, not a reservation. The app (citykleta-sharing-api) owns
 * real reservations, and its /reservations route is staff-only (401 without a
 * session), so WordPress cannot book anything. What WP can do is capture the
 * intent, keep the record, and let someone confirm it by hand — the same
 * staging role it already plays for leads (see Citykleta_Subscriber, which this
 * class is modelled on).
 *
 * There is deliberately NO availability check: no date-level availability
 * exists anywhere in the data (the `available` meta is a single boolean synced
 * from status === 'active'). Two people can request the same bike for the same
 * day; both requests land and staff resolves it. Pretending otherwise would
 * mean inventing a booking system on top of data that can't support one.
 *
 * Private CPT — the requester's contact details are personal data, so no
 * front-end, no archive, no REST exposure. Only visible inside wp-admin.
 *
 * Phase 2, once the backend grows a public request route, is to push these the
 * way leads are pushed to HubSpot: the status meta and the
 * `citykleta_bike_request_captured` action are the seams for that.
 *
 * @package Citykleta_Shared_Bikes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Citykleta_Bike_Request.
 */
class Citykleta_Bike_Request {

	/**
	 * Internal identifier of the content type.
	 */
	const POST_TYPE = 'citykleta_bike_req';

	/**
	 * Meta: the requested bike's WP post ID.
	 */
	const META_BIKE_ID = '_citykleta_request_bike_id';

	/**
	 * Meta: the requested bike's id in the app (external_id). Kept alongside
	 * the post ID so a request still identifies its bike if the post is later
	 * retired or rebuilt by a sync.
	 */
	const META_BIKE_EXTERNAL_ID = '_citykleta_request_bike_external_id';

	/**
	 * Meta: the bike's title at the time of the request. A snapshot on purpose
	 * — the bike's title can change on the next sync, and the request should
	 * keep saying what the visitor actually asked for.
	 */
	const META_BIKE_TITLE = '_citykleta_request_bike_title';

	/**
	 * Meta: how to reach the person (an email address or a phone number).
	 */
	const META_CONTACT = '_citykleta_request_contact';

	/**
	 * Meta: which kind of contact was given (self::CONTACT_*), so the admin
	 * screen knows whether to offer a mailto: or a wa.me link.
	 */
	const META_CONTACT_TYPE = '_citykleta_request_contact_type';

	/**
	 * Meta: first day the visitor wants the bike ('Y-m-d'), may be empty.
	 */
	const META_FROM = '_citykleta_request_from';

	/**
	 * Meta: last day the visitor wants the bike ('Y-m-d'), may be empty.
	 */
	const META_TO = '_citykleta_request_to';

	/**
	 * Meta: the visitor's free-text note, may be empty.
	 */
	const META_MESSAGE = '_citykleta_request_message';

	/**
	 * Meta: where the request came from (the bike page URL).
	 */
	const META_SOURCE = '_citykleta_request_source';

	/**
	 * Meta: workflow state (self::STATUS_*).
	 */
	const META_STATUS = '_citykleta_request_status';

	/**
	 * Meta: when the owner notification was sent ('Y-m-d H:i:s'), empty when it
	 * was never sent — either no owner contact is on file, or wp_mail() failed.
	 * Kept because mail from a local/Cuban host is unreliable and "we told the
	 * owner" must not be assumed.
	 */
	const META_OWNER_NOTIFIED_AT = '_citykleta_request_owner_notified_at';

	/**
	 * Status: just arrived, nobody has acted on it.
	 */
	const STATUS_NEW = 'new';

	/**
	 * Status: someone reached out to the visitor and/or the owner.
	 */
	const STATUS_CONTACTED = 'contacted';

	/**
	 * Status: the rental was agreed.
	 */
	const STATUS_CONFIRMED = 'confirmed';

	/**
	 * Status: it didn't go ahead.
	 */
	const STATUS_CLOSED = 'closed';

	/**
	 * Contact kind: an email address.
	 */
	const CONTACT_EMAIL = 'email';

	/**
	 * Contact kind: a phone number (WhatsApp).
	 */
	const CONTACT_PHONE = 'phone';

	/**
	 * Nonce action for the status box on the request edit screen.
	 */
	const STATUS_NONCE = 'citykleta_request_status';

	/**
	 * Human labels for each status, in workflow order.
	 *
	 * @return array<string,string>
	 */
	public static function statuses() {
		return array(
			self::STATUS_NEW       => 'New',
			self::STATUS_CONTACTED => 'Contacted',
			self::STATUS_CONFIRMED => 'Confirmed',
			self::STATUS_CLOSED    => 'Closed',
		);
	}

	/**
	 * Connects the class to WordPress hooks.
	 */
	public function register() {
		$this->register_post_type();

		add_filter( 'manage_edit-' . self::POST_TYPE . '_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save_status' ) );
	}

	/**
	 * Registers the private CPT. Called straight from register() (not hooked to
	 * 'init' again) because the plugin already runs this during 'init' — see the
	 * "don't re-hook the same hook" gotcha in CLAUDE.md.
	 */
	private function register_post_type() {
		$labels = array(
			'name'          => 'Requests',
			'singular_name' => 'Request',
			'menu_name'     => 'Requests',
			'all_items'     => 'Requests',
			'edit_item'     => 'Request',
			'view_item'     => 'Request',
			'search_items'  => 'Search Requests',
			'not_found'     => 'No requests yet',
		);

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => $labels,
				// Private: the requester's contact details are personal data and
				// must stay inside wp-admin — same reasoning as Subscribers.
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_in_rest'        => false,
				'show_ui'             => true,
				// Lives as a submenu under "Shared Bikes", next to the bikes.
				'show_in_menu'        => 'edit.php?post_type=' . Citykleta_Bike_Post_Type::POST_TYPE,
				'capability_type'     => 'post',
				'hierarchical'        => false,
				// No 'editor': the visitor's note is a meta field shown
				// read-only, not something staff should rewrite.
				'supports'            => array( 'title' ),
				'map_meta_cap'        => true,
			)
		);
	}

	/**
	 * Stores a request. Called from the REST route after validation.
	 *
	 * Never deduplicates: the same person asking twice for the same bike is two
	 * real requests (different dates, or a follow-up), unlike a repeat sign-up
	 * which is the same person and gets upserted.
	 *
	 * @param array $data {
	 *     Validated request data.
	 *
	 *     @type int    $bike_id      Bike post ID.
	 *     @type string $name         Requester's name.
	 *     @type string $contact      Email address or phone number.
	 *     @type string $contact_type One of self::CONTACT_*.
	 *     @type string $from         Start date 'Y-m-d', or ''.
	 *     @type string $to           End date 'Y-m-d', or ''.
	 *     @type string $message      Free-text note, or ''.
	 *     @type string $source       URL the request came from, or ''.
	 * }
	 * @return int Request post ID, or 0 on failure.
	 */
	public static function create( array $data ) {
		$bike_id    = isset( $data['bike_id'] ) ? (int) $data['bike_id'] : 0;
		$bike_title = $bike_id ? (string) get_the_title( $bike_id ) : '';
		$name       = isset( $data['name'] ) ? (string) $data['name'] : '';

		// The list table shows the title, so make it say who wants what.
		$title = sprintf(
			'%s — %s',
			'' !== $name ? $name : 'Anónimo',
			'' !== $bike_title ? $bike_title : 'bicicleta'
		);

		$post_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $title,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return 0;
		}

		update_post_meta( $post_id, self::META_BIKE_ID, $bike_id );
		update_post_meta( $post_id, self::META_BIKE_TITLE, $bike_title );
		update_post_meta( $post_id, self::META_BIKE_EXTERNAL_ID, $bike_id ? (string) get_post_meta( $bike_id, 'external_id', true ) : '' );
		update_post_meta( $post_id, self::META_CONTACT, isset( $data['contact'] ) ? (string) $data['contact'] : '' );
		update_post_meta( $post_id, self::META_CONTACT_TYPE, isset( $data['contact_type'] ) ? (string) $data['contact_type'] : '' );
		update_post_meta( $post_id, self::META_FROM, isset( $data['from'] ) ? (string) $data['from'] : '' );
		update_post_meta( $post_id, self::META_TO, isset( $data['to'] ) ? (string) $data['to'] : '' );
		update_post_meta( $post_id, self::META_MESSAGE, isset( $data['message'] ) ? (string) $data['message'] : '' );
		update_post_meta( $post_id, self::META_SOURCE, isset( $data['source'] ) ? (string) $data['source'] : '' );
		update_post_meta( $post_id, self::META_STATUS, self::STATUS_NEW );

		return (int) $post_id;
	}

	/**
	 * Records that the owner notification went out.
	 *
	 * @param int $post_id Request ID.
	 */
	public static function mark_owner_notified( $post_id ) {
		if ( self::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}

		update_post_meta( $post_id, self::META_OWNER_NOTIFIED_AT, current_time( 'mysql' ) );
	}

	/**
	 * Returns everything about a request, so other code (a future connector,
	 * the notifier) never has to know the meta keys. Same rule as
	 * Citykleta_Subscriber::get_data(): this class owns its meta.
	 *
	 * @param int $post_id Request ID.
	 * @return array<string,mixed>
	 */
	public static function get_data( $post_id ) {
		return array(
			'id'           => (int) $post_id,
			'name'         => (string) get_the_title( $post_id ),
			'bike_id'      => (int) get_post_meta( $post_id, self::META_BIKE_ID, true ),
			'bike_title'   => (string) get_post_meta( $post_id, self::META_BIKE_TITLE, true ),
			'external_id'  => (string) get_post_meta( $post_id, self::META_BIKE_EXTERNAL_ID, true ),
			'contact'      => (string) get_post_meta( $post_id, self::META_CONTACT, true ),
			'contact_type' => (string) get_post_meta( $post_id, self::META_CONTACT_TYPE, true ),
			'from'         => (string) get_post_meta( $post_id, self::META_FROM, true ),
			'to'           => (string) get_post_meta( $post_id, self::META_TO, true ),
			'message'      => (string) get_post_meta( $post_id, self::META_MESSAGE, true ),
			'source'       => (string) get_post_meta( $post_id, self::META_SOURCE, true ),
			'status'       => (string) get_post_meta( $post_id, self::META_STATUS, true ),
		);
	}

	/**
	 * How many requests came in, optionally only those in a given status.
	 *
	 * @param string $status One of self::STATUS_*, or '' for all.
	 * @return int
	 */
	public static function count( $status = '' ) {
		if ( '' === $status ) {
			$counts = wp_count_posts( self::POST_TYPE );

			return isset( $counts->publish ) ? (int) $counts->publish : 0;
		}

		$query = new WP_Query(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => self::META_STATUS,
						'value' => $status,
					),
				),
			)
		);

		return (int) $query->found_posts;
	}

	/**
	 * Requests grouped by day ('Y-m-d' => count) over the last $days days.
	 * Feeds the Stats funnel next to views / sign-ups / downloads.
	 *
	 * @param int $days How many days back to include.
	 * @return array<string,int>
	 */
	public static function requests_by_day( $days = 14 ) {
		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'date_query'     => array(
					array(
						'after'     => ( (int) $days - 1 ) . ' days ago',
						'inclusive' => true,
					),
				),
			)
		);

		$by_day = array();

		foreach ( $posts as $post ) {
			$day            = substr( $post->post_date, 0, 10 );
			$by_day[ $day ] = isset( $by_day[ $day ] ) ? $by_day[ $day ] + 1 : 1;
		}

		return $by_day;
	}

	/**
	 * Adds the list-table columns.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function columns( $columns ) {
		$date = isset( $columns['date'] ) ? $columns['date'] : '';
		unset( $columns['date'] );

		$columns['citykleta_bike']    = 'Bike';
		$columns['citykleta_dates']   = 'Dates';
		$columns['citykleta_contact'] = 'Contact';
		$columns['citykleta_status']  = 'Status';

		if ( $date ) {
			$columns['date'] = $date;
		}

		return $columns;
	}

	/**
	 * Renders a custom column.
	 *
	 * @param string $column  Column id.
	 * @param int    $post_id Request ID.
	 */
	public function render_column( $column, $post_id ) {
		switch ( $column ) {
			case 'citykleta_bike':
				$bike_id = (int) get_post_meta( $post_id, self::META_BIKE_ID, true );
				$title   = (string) get_post_meta( $post_id, self::META_BIKE_TITLE, true );

				if ( $bike_id && get_post( $bike_id ) ) {
					printf(
						'<a href="%s">%s</a>',
						esc_url( (string) get_edit_post_link( $bike_id ) ),
						esc_html( '' !== $title ? $title : (string) get_the_title( $bike_id ) )
					);
				} else {
					echo $title ? esc_html( $title ) : '—';
				}
				break;

			case 'citykleta_dates':
				echo esc_html( self::format_dates( $post_id ) );
				break;

			case 'citykleta_contact':
				echo esc_html( (string) get_post_meta( $post_id, self::META_CONTACT, true ) );
				break;

			case 'citykleta_status':
				$statuses = self::statuses();
				$status   = (string) get_post_meta( $post_id, self::META_STATUS, true );

				echo esc_html( isset( $statuses[ $status ] ) ? $statuses[ $status ] : $statuses[ self::STATUS_NEW ] );
				break;
		}
	}

	/**
	 * The requested date range as one readable string.
	 *
	 * @param int $post_id Request ID.
	 * @return string
	 */
	public static function format_dates( $post_id ) {
		$from = (string) get_post_meta( $post_id, self::META_FROM, true );
		$to   = (string) get_post_meta( $post_id, self::META_TO, true );

		if ( '' === $from && '' === $to ) {
			return '—';
		}

		if ( '' !== $from && '' !== $to ) {
			return $from === $to ? $from : $from . ' → ' . $to;
		}

		return '' !== $from ? 'desde ' . $from : 'hasta ' . $to;
	}

	/**
	 * Adds the read-only detail box and the (editable) status box.
	 */
	public function add_meta_boxes() {
		add_meta_box(
			'citykleta_request_details',
			'Request',
			array( $this, 'render_details_box' ),
			self::POST_TYPE,
			'normal',
			'high'
		);

		add_meta_box(
			'citykleta_request_status',
			'Status',
			array( $this, 'render_status_box' ),
			self::POST_TYPE,
			'side',
			'high'
		);
	}

	/**
	 * Renders the read-only detail box, plus the shortcuts staff needs to
	 * actually act on the request (reply to the visitor, reach the owner).
	 *
	 * @param WP_Post $post The request.
	 */
	public function render_details_box( $post ) {
		$data    = self::get_data( $post->ID );
		$bike_id = $data['bike_id'];

		$rows = array(
			'Bike'         => ( $bike_id && get_post( $bike_id ) )
				? '<a href="' . esc_url( (string) get_edit_post_link( $bike_id ) ) . '">' . esc_html( $data['bike_title'] ) . '</a>'
				: esc_html( $data['bike_title'] ),
			'App bike id'  => esc_html( $data['external_id'] ),
			'Dates'        => esc_html( self::format_dates( $post->ID ) ),
			'Contact'      => $this->contact_link( $data ),
			'Message'      => '' !== $data['message'] ? nl2br( esc_html( $data['message'] ) ) : '',
			'Came from'    => '' !== $data['source'] ? '<a href="' . esc_url( $data['source'] ) . '">' . esc_html( $data['source'] ) . '</a>' : '',
			'Owner told'   => esc_html( (string) get_post_meta( $post->ID, self::META_OWNER_NOTIFIED_AT, true ) ),
			'Received'     => esc_html( (string) get_post_time( 'Y-m-d H:i:s', false, $post ) ),
		);

		echo '<table class="widefat striped"><tbody>';
		foreach ( $rows as $label => $value ) {
			echo '<tr><th style="width:160px;">' . esc_html( $label ) . '</th><td>' . ( '' !== $value ? wp_kses_post( $value ) : '—' ) . '</td></tr>';
		}
		echo '</tbody></table>';

		$this->render_owner_actions( $bike_id, $data );
	}

	/**
	 * The requester's contact as a clickable link when we can build one.
	 *
	 * @param array $data Request data from get_data().
	 * @return string HTML.
	 */
	private function contact_link( array $data ) {
		if ( '' === $data['contact'] ) {
			return '';
		}

		if ( self::CONTACT_EMAIL === $data['contact_type'] ) {
			return '<a href="mailto:' . esc_attr( $data['contact'] ) . '">' . esc_html( $data['contact'] ) . '</a>';
		}

		$url = Citykleta_Settings::whatsapp_url( $data['contact'], '' );

		return '' !== $url
			? '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $data['contact'] ) . '</a>'
			: esc_html( $data['contact'] );
	}

	/**
	 * Shows how to reach the bike's owner about this request.
	 *
	 * The owner's contact never comes from the app: /bikesPublic/sync is a
	 * deliberately PII-free catalogue ({ name, verified } and nothing else), so
	 * it's typed by hand in WP — see Citykleta_Owner_Contact. When it's a phone
	 * number there is nothing to send automatically, so what's offered here is a
	 * pre-filled WhatsApp link for a human to press.
	 *
	 * @param int   $bike_id Bike post ID.
	 * @param array $data    Request data from get_data().
	 */
	private function render_owner_actions( $bike_id, array $data ) {
		if ( ! $bike_id ) {
			return;
		}

		$owner   = Citykleta_Owner_Contact::get( $bike_id );
		$name    = (string) get_post_meta( $bike_id, 'owner_name', true );
		$heading = '<h4 style="margin:16px 0 6px;">Owner</h4>';

		if ( '' === $owner['value'] ) {
			echo wp_kses_post(
				$heading .
				'<p class="description">No contact on file for ' . esc_html( '' !== $name ? $name : 'this owner' ) .
				'. Add one on the <a href="' . esc_url( (string) get_edit_post_link( $bike_id ) ) . '">bike\'s edit screen</a> ' .
				'(the app\'s public catalogue carries no owner contact, so it has to be typed in here).</p>'
			);
			return;
		}

		$message = Citykleta_Settings::whatsapp_message(
			array(
				'{owner}' => '' !== $name ? $name : '',
				'{bike}'  => $data['bike_title'],
				'{price}' => Citykleta_Bike_Meta::price_html( $bike_id ),
				'{url}'   => (string) get_permalink( $bike_id ),
			)
		);

		if ( Citykleta_Owner_Contact::TYPE_EMAIL === $owner['type'] ) {
			$link  = 'mailto:' . rawurlencode( $owner['value'] ) . '?subject=' . rawurlencode( 'Solicitud: ' . $data['bike_title'] ) . '&body=' . rawurlencode( $message );
			$label = 'Email ' . $owner['value'];
		} else {
			$link  = Citykleta_Settings::whatsapp_url( $owner['value'], $message );
			$label = 'WhatsApp ' . $owner['value'];
		}

		if ( '' === $link ) {
			return;
		}

		echo wp_kses_post(
			$heading .
			'<p><a class="button" href="' . esc_url( $link ) . '" target="_blank" rel="noopener">' . esc_html( $label ) . '</a></p>'
		);
	}

	/**
	 * Renders the status dropdown.
	 *
	 * @param WP_Post $post The request.
	 */
	public function render_status_box( $post ) {
		wp_nonce_field( self::STATUS_NONCE, 'citykleta_request_status_nonce' );

		$current  = (string) get_post_meta( $post->ID, self::META_STATUS, true );
		$statuses = self::statuses();

		if ( ! isset( $statuses[ $current ] ) ) {
			$current = self::STATUS_NEW;
		}

		echo '<select name="citykleta_request_status" style="width:100%;">';
		foreach ( $statuses as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $current, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';

		echo '<p class="description" style="margin-top:8px;">Requests are confirmed by hand — the app owns real reservations and WordPress can\'t book anything.</p>';
	}

	/**
	 * Saves the status dropdown.
	 *
	 * @param int $post_id Request ID.
	 */
	public function save_status( $post_id ) {
		if ( ! isset( $_POST['citykleta_request_status_nonce'] ) ||
			! wp_verify_nonce( sanitize_key( $_POST['citykleta_request_status_nonce'] ), self::STATUS_NONCE ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( ! isset( $_POST['citykleta_request_status'] ) ) {
			return;
		}

		$status = sanitize_key( wp_unslash( $_POST['citykleta_request_status'] ) );

		if ( isset( self::statuses()[ $status ] ) ) {
			update_post_meta( $post_id, self::META_STATUS, $status );
		}
	}
}
