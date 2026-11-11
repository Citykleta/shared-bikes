<?php
/**
 * "Bike proposal" content type: a bike an owner sent through the site's form.
 *
 * This is an INBOX, not the bike. The Citykleta API owns the bike: it pulls
 * the pending proposals from here (Citykleta_Bike_Proposal_Rest), creates the
 * owner's account and a bike in review, and staff publishes or rejects it in
 * the Back Office. When the API confirms it has the proposal, it is marked
 * "imported" here and its photos are deleted from this server. Published bikes
 * come back to the site through the normal sync. Nothing here is approved or
 * rejected in wp-admin on purpose: there is exactly one place to decide.
 *
 * Private CPT, like the bike requests: the owner's contact details are personal
 * data, so no front-end, no archive and no REST exposure; only wp-admin.
 *
 * @package Citykleta_Shared_Bikes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Citykleta_Bike_Proposal.
 */
class Citykleta_Bike_Proposal {

	/**
	 * Internal identifier of the content type.
	 */
	const POST_TYPE = 'citykleta_bike_sub';

	/**
	 * The only uploads folder proposal photos may come from, and the only one this
	 * class ever deletes from. It is where Elementor puts the files of its forms.
	 */
	const PHOTO_DIR = 'elementor/forms/';

	const META_FIELDS         = '_citykleta_sub_fields';
	const META_TAGS           = '_citykleta_sub_tags';
	const META_EXTRA          = '_citykleta_sub_extra';
	const META_PHOTOS         = '_citykleta_sub_photos';
	const META_MISSING        = '_citykleta_sub_missing';
	const META_STATUS         = '_citykleta_sub_status';
	const META_FINGERPRINT    = '_citykleta_sub_fingerprint';
	const META_FORM_NAME      = '_citykleta_sub_form_name';
	const META_RECEIVED       = '_citykleta_sub_received_at';
	const META_API_BIKE_ID    = '_citykleta_sub_api_bike_id';
	const META_IMPORTED_AT    = '_citykleta_sub_imported_at';
	const META_PHOTOS_DELETED = '_citykleta_sub_photos_deleted';

	/**
	 * Meta on the BIKE post (not on the proposal): the proposal its owner contact
	 * was copied from. Set once, so a contact that staff clear on purpose is not
	 * filled in again by the next sync.
	 */
	const META_CONTACT_COPIED_FROM = '_citykleta_owner_contact_copied_from';

	/**
	 * Status: waiting for the API to pick it up.
	 */
	const STATUS_PENDING = 'pending';

	/**
	 * Status: the API confirmed it has it.
	 */
	const STATUS_IMPORTED = 'imported';

	/**
	 * Connects the class to WordPress hooks.
	 */
	public function register() {
		$this->register_post_type();

		add_filter( 'manage_edit-' . self::POST_TYPE . '_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );

		// A bike approved in the Back Office comes back through the sync; give it
		// the owner's WhatsApp (see fill_owner_contact()).
		add_action( 'citykleta_bike_synced', array( __CLASS__, 'fill_owner_contact' ), 10, 2 );
	}

	/**
	 * Registers the private CPT. Called straight from register() because the
	 * plugin already runs it during 'init'.
	 */
	private function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => 'Proposals',
					'singular_name' => 'Proposal',
					'menu_name'     => 'Proposals',
					'all_items'     => 'Proposals',
					'edit_item'     => 'Proposal',
					'view_item'     => 'Proposal',
					'search_items'  => 'Search Proposals',
					'not_found'     => 'No proposals yet',
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_in_rest'        => false,
				'show_ui'             => true,
				'show_in_menu'        => 'edit.php?post_type=' . Citykleta_Bike_Post_Type::POST_TYPE,
				'capability_type'     => 'post',
				// Proposals only arrive from the form: nobody adds one by hand.
				'capabilities'        => array( 'create_posts' => 'do_not_allow' ),
				'map_meta_cap'        => true,
				'hierarchical'        => false,
				'supports'            => array( 'title' ),
			)
		);
	}

	// --- Storing ---------------------------------------------------------------

	/**
	 * Stores a proposal.
	 *
	 * @param array  $normalized  The result of Citykleta_Proposal_Fields::normalize().
	 * @param string $form_name   The Elementor form's name.
	 * @param string $fingerprint Identifies the submission, to ignore a repeat.
	 * @return int|WP_Error The post ID.
	 */
	public static function create( array $normalized, $form_name, $fingerprint ) {
		$fields = $normalized['fields'];
		$name   = trim( ( isset( $fields['first_name'] ) ? $fields['first_name'] : '' ) . ' ' . ( isset( $fields['last_name'] ) ? $fields['last_name'] : '' ) );
		$title  = '' !== $name ? $name : ( isset( $fields['email'] ) ? $fields['email'] : 'Proposal' );

		$post_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => wp_slash( $title ),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		self::set_meta( $post_id, self::META_FIELDS, $fields );
		self::set_meta( $post_id, self::META_TAGS, $normalized['tags'] );
		self::set_meta( $post_id, self::META_EXTRA, $normalized['extra'] );
		self::set_meta( $post_id, self::META_PHOTOS, $normalized['photos'] );
		self::set_meta( $post_id, self::META_MISSING, Citykleta_Proposal_Fields::missing( $normalized ) );
		self::set_meta( $post_id, self::META_STATUS, self::STATUS_PENDING );
		self::set_meta( $post_id, self::META_FINGERPRINT, $fingerprint );
		self::set_meta( $post_id, self::META_FORM_NAME, $form_name );
		self::set_meta( $post_id, self::META_RECEIVED, gmdate( 'c' ) );

		return $post_id;
	}

	/**
	 * update_post_meta() unslashes what it gets, which would eat the backslashes
	 * of any value (arrays included), so everything goes in slashed.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @param mixed  $value   Meta value.
	 */
	private static function set_meta( $post_id, $key, $value ) {
		update_post_meta( $post_id, $key, wp_slash( $value ) );
	}

	/**
	 * A meta value that is always an array.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @return array
	 */
	private static function meta_array( $post_id, $key ) {
		$value = get_post_meta( $post_id, $key, true );

		return is_array( $value ) ? $value : array();
	}

	/**
	 * The proposal a submission already produced, so a repeat (Elementor
	 * re-sending, a double click) does not become two bikes.
	 *
	 * @param string $fingerprint Fingerprint of the submission.
	 * @return int Post ID, or 0.
	 */
	public static function find_by_fingerprint( $fingerprint ) {
		$ids = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => self::META_FINGERPRINT, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $fingerprint, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		return $ids ? (int) $ids[0] : 0;
	}

	// --- What the API reads ----------------------------------------------------

	/**
	 * IDs of the pending proposals after a given one, oldest first.
	 *
	 * @param int $after Only proposals with a higher ID.
	 * @param int $limit How many at most.
	 * @return array<int,int>
	 */
	public static function pending_ids_after( $after, $limit ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- an ID cursor WP_Query cannot express.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value = %s
				WHERE p.post_type = %s AND p.post_status = 'publish' AND p.ID > %d
				ORDER BY p.ID ASC LIMIT %d",
				self::META_STATUS,
				self::STATUS_PENDING,
				self::POST_TYPE,
				max( 0, (int) $after ),
				max( 1, (int) $limit )
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * What the API gets for one proposal. Photos are public URLs: the API runs on
	 * another server and downloads them.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,mixed>
	 */
	public static function to_api_array( $post_id ) {
		$photos = array();

		foreach ( self::meta_array( $post_id, self::META_PHOTOS ) as $relative ) {
			$absolute = self::absolute_path( (string) $relative );

			if ( '' === $absolute ) {
				continue; // Already deleted, or no longer where it was.
			}

			$type     = wp_check_filetype( $absolute );
			$photos[] = array(
				'url'       => self::photo_url( (string) $relative ),
				'size'      => (int) filesize( $absolute ),
				'mime_type' => (string) $type['type'],
			);
		}

		return array(
			'id'          => (int) $post_id,
			'received_at' => (string) get_post_meta( $post_id, self::META_RECEIVED, true ),
			'form_name'   => (string) get_post_meta( $post_id, self::META_FORM_NAME, true ),
			'fields'      => self::meta_array( $post_id, self::META_FIELDS ),
			'tags'        => self::meta_array( $post_id, self::META_TAGS ),
			'extra'       => self::meta_array( $post_id, self::META_EXTRA ),
			'photos'      => $photos,
			'missing'     => self::meta_array( $post_id, self::META_MISSING ),
		);
	}

	/**
	 * The API has the proposal: remember its bike and delete the photos here.
	 *
	 * @param int $post_id     Post ID.
	 * @param int $api_bike_id The bike's ID in the API.
	 * @return int How many photo files were deleted.
	 */
	public static function mark_imported( $post_id, $api_bike_id ) {
		self::set_meta( $post_id, self::META_STATUS, self::STATUS_IMPORTED );
		self::set_meta( $post_id, self::META_API_BIKE_ID, (int) $api_bike_id );
		self::set_meta( $post_id, self::META_IMPORTED_AT, gmdate( 'c' ) );

		$deleted = self::delete_photos( $post_id );

		self::set_meta( $post_id, self::META_PHOTOS_DELETED, $deleted );

		return $deleted;
	}

	/**
	 * Deletes the proposal's photo files. Only paths recorded when the proposal
	 * arrived, and only inside PHOTO_DIR (absolute_path() enforces it).
	 *
	 * @param int $post_id Post ID.
	 * @return int How many files were deleted.
	 */
	public static function delete_photos( $post_id ) {
		$deleted = 0;

		foreach ( self::meta_array( $post_id, self::META_PHOTOS ) as $relative ) {
			$absolute = self::absolute_path( (string) $relative );

			if ( '' === $absolute ) {
				continue;
			}

			wp_delete_file( $absolute );

			if ( ! file_exists( $absolute ) ) {
				++$deleted;
			}
		}

		return $deleted;
	}

	// --- Back onto the bike ----------------------------------------------------

	/**
	 * Gives a bike that just arrived from the API the WhatsApp number its owner
	 * typed in the form. The catalogue the sync reads carries no personal data,
	 * and a bike with no contact is hidden from the public site
	 * (Citykleta_Owner_Contact::hide_uncontactable()), so without this an approved
	 * bike would not appear at all.
	 *
	 * It only fills an EMPTY contact, and only once per bike: what staff typed by
	 * hand wins, and a contact they clear on purpose stays cleared.
	 *
	 * @param int $bike_id     The bike's post ID.
	 * @param int $external_id The bike's ID in the API.
	 */
	public static function fill_owner_contact( $bike_id, $external_id ) {
		if ( get_post_meta( $bike_id, self::META_CONTACT_COPIED_FROM, true ) ) {
			return;
		}

		$current = Citykleta_Owner_Contact::get( $bike_id );

		if ( '' !== $current['value'] ) {
			return;
		}

		$proposal_id = self::find_imported_by_api_bike_id( $external_id );

		if ( ! $proposal_id ) {
			return;
		}

		$fields   = self::meta_array( $proposal_id, self::META_FIELDS );
		$whatsapp = isset( $fields['whatsapp'] ) ? trim( (string) $fields['whatsapp'] ) : '';

		if ( '' === $whatsapp ) {
			return;
		}

		update_post_meta( $bike_id, Citykleta_Owner_Contact::META_VALUE, $whatsapp );
		update_post_meta( $bike_id, Citykleta_Owner_Contact::META_TYPE, Citykleta_Owner_Contact::detect_type( $whatsapp ) );
		update_post_meta( $bike_id, self::META_CONTACT_COPIED_FROM, $proposal_id );
	}

	/**
	 * The imported proposal whose bike has this ID in the API, or 0.
	 *
	 * @param int $external_id The bike's ID in the API.
	 * @return int Proposal post ID.
	 */
	public static function find_imported_by_api_bike_id( $external_id ) {
		$ids = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					array(
						'key'   => self::META_API_BIKE_ID,
						'value' => (int) $external_id,
						'type'  => 'NUMERIC',
					),
					array(
						'key'   => self::META_STATUS,
						'value' => self::STATUS_IMPORTED,
					),
				),
			)
		);

		return $ids ? (int) $ids[0] : 0;
	}

	// --- Photo files -----------------------------------------------------------

	/**
	 * The path, relative to uploads, of a photo URL: only when the URL is this
	 * site's own, points inside PHOTO_DIR, exists and is an image. Anything else
	 * (another folder, another site, "..") is not a proposal photo.
	 *
	 * @param string $url A URL found in a submission.
	 * @return string The relative path, or '' when it is not a photo.
	 */
	public static function relative_upload_path( $url ) {
		$upload   = wp_upload_dir( null, false );
		$base_url = preg_replace( '#^https?:#i', '', trailingslashit( (string) $upload['baseurl'] ) );
		$clean    = preg_replace( '#^https?:#i', '', (string) strtok( (string) $url, '?#' ) );

		if ( 0 !== strpos( $clean, $base_url ) ) {
			return '';
		}

		$relative = ltrim( rawurldecode( substr( $clean, strlen( $base_url ) ) ), '/' );

		if ( 0 !== strpos( $relative, self::PHOTO_DIR ) ) {
			return '';
		}

		$absolute = self::absolute_path( $relative );

		if ( '' === $absolute ) {
			return '';
		}

		$type = wp_check_filetype( $absolute );

		return 0 === strpos( (string) $type['type'], 'image/' ) ? $relative : '';
	}

	/**
	 * The file behind a relative path, or '' when it does not exist or is not
	 * inside PHOTO_DIR once ".." and symlinks are resolved. It is the one check
	 * both reading and deleting go through.
	 *
	 * @param string $relative Path relative to uploads.
	 * @return string
	 */
	public static function absolute_path( $relative ) {
		$upload = wp_upload_dir( null, false );
		$base   = realpath( trailingslashit( (string) $upload['basedir'] ) . self::PHOTO_DIR );
		$real   = realpath( trailingslashit( (string) $upload['basedir'] ) . $relative );

		if ( false === $base || false === $real || ! is_file( $real ) ) {
			return '';
		}

		$inside = 0 === strpos( wp_normalize_path( $real ), trailingslashit( wp_normalize_path( $base ) ) );

		return $inside ? $real : '';
	}

	/**
	 * The public URL of a stored relative path.
	 *
	 * @param string $relative Path relative to uploads.
	 * @return string
	 */
	public static function photo_url( $relative ) {
		$upload = wp_upload_dir( null, false );

		return trailingslashit( (string) $upload['baseurl'] ) . implode( '/', array_map( 'rawurlencode', explode( '/', $relative ) ) );
	}

	// --- wp-admin --------------------------------------------------------------

	/**
	 * Columns of the proposals list.
	 *
	 * @return array<string,string>
	 */
	public function columns() {
		return array(
			'cb'         => '<input type="checkbox" />',
			'title'      => 'Owner',
			'contact'    => 'Contact',
			'bike'       => 'Bike',
			'photos'     => 'Photos',
			'sub_status' => 'Status',
			'missing'    => 'Missing',
			'date'       => 'Received',
		);
	}

	/**
	 * Draws one cell of the list.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public function render_column( $column, $post_id ) {
		$fields = self::meta_array( $post_id, self::META_FIELDS );
		$get    = static function ( $name ) use ( $fields ) {
			return isset( $fields[ $name ] ) ? (string) $fields[ $name ] : '';
		};

		if ( 'contact' === $column ) {
			echo esc_html( implode( ' / ', array_filter( array( $get( 'email' ), $get( 'whatsapp' ) ) ) ) );
		} elseif ( 'bike' === $column ) {
			echo esc_html( implode( ' ', array_filter( array( $get( 'brand' ), $get( 'model' ), $get( 'bike_type' ) ) ) ) );
		} elseif ( 'photos' === $column ) {
			echo esc_html( (string) count( self::meta_array( $post_id, self::META_PHOTOS ) ) );
		} elseif ( 'sub_status' === $column ) {
			$status = (string) get_post_meta( $post_id, self::META_STATUS, true );
			$bike   = (int) get_post_meta( $post_id, self::META_API_BIKE_ID, true );

			echo esc_html( self::STATUS_IMPORTED === $status ? 'Imported' . ( $bike ? ' (bike #' . $bike . ')' : '' ) : 'Pending' );
		} elseif ( 'missing' === $column ) {
			echo esc_html( implode( ', ', self::meta_array( $post_id, self::META_MISSING ) ) );
		}
	}

	/**
	 * Adds the read-only details box.
	 */
	public function add_meta_boxes() {
		add_meta_box( 'citykleta_sub_details', 'Proposal', array( $this, 'render_details' ), self::POST_TYPE, 'normal', 'high' );
	}

	/**
	 * The details box: everything the form sent, named, plus the photos.
	 *
	 * @param WP_Post $post The proposal.
	 */
	public function render_details( $post ) {
		$rows = array(
			'Status'       => (string) get_post_meta( $post->ID, self::META_STATUS, true ),
			'API bike'     => (string) get_post_meta( $post->ID, self::META_API_BIKE_ID, true ),
			'Imported at'  => (string) get_post_meta( $post->ID, self::META_IMPORTED_AT, true ),
			'Received at'  => (string) get_post_meta( $post->ID, self::META_RECEIVED, true ),
			'Form'         => (string) get_post_meta( $post->ID, self::META_FORM_NAME, true ),
			'Missing'      => implode( ', ', self::meta_array( $post->ID, self::META_MISSING ) ),
			'Tags'         => implode( ', ', self::meta_array( $post->ID, self::META_TAGS ) ),
		);

		echo '<table class="widefat striped"><tbody>';

		foreach ( $rows as $label => $value ) {
			echo '<tr><th style="width:160px">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}

		foreach ( self::meta_array( $post->ID, self::META_FIELDS ) as $name => $value ) {
			echo '<tr><th>' . esc_html( (string) $name ) . '</th><td>' . nl2br( esc_html( (string) $value ) ) . '</td></tr>';
		}

		foreach ( self::meta_array( $post->ID, self::META_EXTRA ) as $name => $value ) {
			echo '<tr><th>Other: ' . esc_html( (string) $name ) . '</th><td>' . esc_html( is_array( $value ) ? implode( ', ', $value ) : (string) $value ) . '</td></tr>';
		}

		echo '</tbody></table><h3>Photos</h3>';

		$photos = self::meta_array( $post->ID, self::META_PHOTOS );

		foreach ( $photos as $relative ) {
			if ( '' === self::absolute_path( (string) $relative ) ) {
				echo '<p><em>' . esc_html( basename( (string) $relative ) ) . ': deleted from this server after the import.</em></p>';
				continue;
			}

			$url = self::photo_url( (string) $relative );

			echo '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener"><img src="' . esc_url( $url ) . '" alt="" style="max-width:160px;max-height:160px;margin:0 8px 8px 0" /></a>';
		}

		if ( empty( $photos ) ) {
			echo '<p><em>No photos.</em></p>';
		}
	}
}
