<?php
/**
 * "Subscriber" content type: the people who hand over their email + name to
 * unlock the app download.
 *
 * WordPress is only a STAGING area for these leads — the source of truth is
 * HubSpot (where the marketing lists and double opt-in live), the same way the
 * app is the source of truth for bikes and WP just holds a synced copy. So WP
 * never creates real user accounts (no WP_Users) and never sends confirmation
 * email itself; it captures the lead with a documented consent + timestamp and
 * marks it `pending` until a later sync pushes it to HubSpot, which owns
 * verification and communication from there.
 *
 * The record is a private CPT (not public, no front-end, not in REST) so the
 * email — personal data — never leaks: it's only visible inside wp-admin.
 * Identity is the email, kept unique (a repeat sign-up updates the existing
 * record instead of duplicating the person).
 *
 * @package Citykleta_Shared_Bikes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Citykleta_Subscriber.
 */
class Citykleta_Subscriber {

	/**
	 * Internal identifier of the content type.
	 */
	const POST_TYPE = 'citykleta_subscriber';

	/**
	 * Meta: the subscriber's email (the unique identity).
	 */
	const META_EMAIL = '_citykleta_subscriber_email';

	/**
	 * Meta: where the sign-up happened (the page URL it came from).
	 */
	const META_SOURCE = '_citykleta_subscriber_source';

	/**
	 * Meta: '1' once the visitor ticked the consent box (they always must, so
	 * this is really documentation that they did, alongside the timestamp).
	 */
	const META_CONSENT = '_citykleta_subscriber_consent';

	/**
	 * Meta: when consent was given ('Y-m-d H:i:s', site time). This — not a
	 * confirmed email — is the legal basis HubSpot cares about.
	 */
	const META_CONSENT_AT = '_citykleta_subscriber_consent_at';

	/**
	 * Meta: sync state toward HubSpot (self::STATUS_*).
	 */
	const META_STATUS = '_citykleta_subscriber_status';

	/**
	 * Meta: when the download button was clicked ('Y-m-d H:i:s'), empty until
	 * then. Set once — measures the sign-up -> download step per person.
	 */
	const META_DOWNLOADED_AT = '_citykleta_subscriber_downloaded_at';

	/**
	 * Meta: when the lead was confirmed pushed to HubSpot ('Y-m-d H:i:s'),
	 * empty until then. Written by the HubSpot connector (a separate plugin)
	 * via mark_synced(); the connector's retention sweep counts the purge
	 * window from this moment, not from capture.
	 */
	const META_SYNCED_AT = '_citykleta_subscriber_synced_at';

	/**
	 * Status: captured in WP, not yet pushed to HubSpot.
	 */
	const STATUS_PENDING = 'pending';

	/**
	 * Status: already synced to HubSpot (set by the future sync job).
	 */
	const STATUS_SYNCED = 'synced_to_hubspot';

	/**
	 * Connects the class to WordPress hooks.
	 */
	public function register() {
		$this->register_post_type();

		add_filter( "manage_{$this->list_screen()}_columns", array( $this, 'columns' ) );
		add_action( "manage_" . self::POST_TYPE . "_posts_custom_column", array( $this, 'render_column' ), 10, 2 );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
	}

	/**
	 * Registers the private CPT. Called straight from register() (not hooked to
	 * 'init' again) because the plugin already runs this during 'init' — see the
	 * "don't re-hook the same hook" gotcha in CLAUDE.md.
	 */
	private function register_post_type() {
		$labels = array(
			'name'          => 'Subscribers',
			'singular_name' => 'Subscriber',
			'menu_name'     => 'Subscribers',
			'all_items'     => 'Subscribers',
			'edit_item'     => 'Subscriber',
			'view_item'     => 'Subscriber',
			'search_items'  => 'Search Subscribers',
			'not_found'     => 'No subscribers yet',
		);

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => $labels,
				// Private: no front-end, no archive, no REST — the email is
				// personal data and must stay inside wp-admin only.
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_in_rest'        => false,
				'show_ui'             => true,
				// Lives as a submenu under "Shared Bikes", next to the bikes.
				'show_in_menu'        => 'edit.php?post_type=' . Citykleta_Bike_Post_Type::POST_TYPE,
				'capability_type'     => 'post',
				'hierarchical'        => false,
				'supports'            => array( 'title' ),
			)
		);
	}

	/**
	 * The list-table screen id, used to hook the columns filter.
	 *
	 * @return string
	 */
	private function list_screen() {
		return 'edit-' . self::POST_TYPE;
	}

	/**
	 * Creates (or updates, if the email already signed up) a subscriber. Called
	 * from the REST capture route after validation.
	 *
	 * @param string $email  Validated email (already run through is_email()).
	 * @param string $name   Sanitized display name (may be empty).
	 * @param string $source URL the sign-up came from (may be empty).
	 * @return int Subscriber post ID.
	 */
	public static function upsert( $email, $name, $source ) {
		$email    = strtolower( $email );
		$existing = self::find_by_email( $email );
		$title    = '' !== $name ? $name : $email;

		if ( $existing ) {
			// A repeat sign-up: refresh the name/source but keep the original
			// consent and download history — don't reset the person's record.
			wp_update_post(
				array(
					'ID'         => $existing,
					'post_title' => $title,
				)
			);

			if ( '' !== $source ) {
				update_post_meta( $existing, self::META_SOURCE, $source );
			}

			return $existing;
		}

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

		update_post_meta( $post_id, self::META_EMAIL, $email );
		update_post_meta( $post_id, self::META_SOURCE, $source );
		update_post_meta( $post_id, self::META_CONSENT, '1' );
		update_post_meta( $post_id, self::META_CONSENT_AT, current_time( 'mysql' ) );
		update_post_meta( $post_id, self::META_STATUS, self::STATUS_PENDING );

		return $post_id;
	}

	/**
	 * Records that a subscriber clicked the download button (once — a repeat
	 * click keeps the first timestamp).
	 *
	 * @param int $post_id Subscriber ID.
	 */
	public static function mark_downloaded( $post_id ) {
		if ( self::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}

		if ( '' === (string) get_post_meta( $post_id, self::META_DOWNLOADED_AT, true ) ) {
			update_post_meta( $post_id, self::META_DOWNLOADED_AT, current_time( 'mysql' ) );
		}
	}

	/**
	 * Marks a subscriber as pushed to HubSpot: sets the synced status and
	 * stamps when it happened. Called by the HubSpot connector (a separate
	 * plugin) once its API call succeeds — this class stays the single owner
	 * of subscriber meta, so the connector never touches the keys directly.
	 *
	 * @param int $post_id Subscriber ID.
	 */
	public static function mark_synced( $post_id ) {
		if ( self::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}

		update_post_meta( $post_id, self::META_STATUS, self::STATUS_SYNCED );
		update_post_meta( $post_id, self::META_SYNCED_AT, current_time( 'mysql' ) );
	}

	/**
	 * Returns the lead data the HubSpot connector needs to build a contact,
	 * so it can re-push a pending lead without knowing the meta keys. Name is
	 * the post title unless that fell back to the email (upsert() uses the
	 * email as title when no name was given), in which case it's empty.
	 *
	 * @param int $post_id Subscriber ID.
	 * @return array{email:string,name:string,source:string,status:string}
	 */
	public static function get_data( $post_id ) {
		$email = (string) get_post_meta( $post_id, self::META_EMAIL, true );
		$title = (string) get_the_title( $post_id );

		return array(
			'email'  => $email,
			'name'   => ( '' !== $email && strtolower( $title ) === $email ) ? '' : $title,
			'source' => (string) get_post_meta( $post_id, self::META_SOURCE, true ),
			'status' => (string) get_post_meta( $post_id, self::META_STATUS, true ),
		);
	}

	/**
	 * Returns the IDs of subscribers not yet pushed to HubSpot. The connector's
	 * cron uses this to retry leads whose real-time push failed (e.g. the
	 * network was down when they signed up) and to back-fill leads captured
	 * before the connector existed.
	 *
	 * @param int $limit How many to return at most.
	 * @return int[] Subscriber IDs.
	 */
	public static function get_pending_ids( $limit = 50 ) {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => self::POST_TYPE,
					'post_status'    => 'any',
					'fields'         => 'ids',
					'posts_per_page' => (int) $limit,
					'meta_query'     => array(
						array(
							'key'   => self::META_STATUS,
							'value' => self::STATUS_PENDING,
						),
					),
				)
			)
		);
	}

	/**
	 * Returns the IDs of subscribers synced to HubSpot on or before the given
	 * time. The connector's optional retention sweep uses this to purge local
	 * copies once HubSpot (the source of truth for leads) has held them long
	 * enough — never touches pending leads, which aren't safe yet.
	 *
	 * @param string $before 'Y-m-d H:i:s' cutoff (site time); synced_at <= this.
	 * @param int    $limit  How many to return at most.
	 * @return int[] Subscriber IDs.
	 */
	public static function get_synced_before( $before, $limit = 50 ) {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => self::POST_TYPE,
					'post_status'    => 'any',
					'fields'         => 'ids',
					'posts_per_page' => (int) $limit,
					'meta_query'     => array(
						'relation' => 'AND',
						array(
							'key'   => self::META_STATUS,
							'value' => self::STATUS_SYNCED,
						),
						array(
							'key'     => self::META_SYNCED_AT,
							'value'   => $before,
							'compare' => '<=',
							'type'    => 'DATETIME',
						),
					),
				)
			)
		);
	}

	/**
	 * Permanently deletes a subscriber (skips the trash — the point is to
	 * remove the personal data, not keep a copy). Only ever acts on this CPT.
	 *
	 * @param int $post_id Subscriber ID.
	 * @return bool Whether it was deleted.
	 */
	public static function delete( $post_id ) {
		if ( self::POST_TYPE !== get_post_type( $post_id ) ) {
			return false;
		}

		return (bool) wp_delete_post( $post_id, true );
	}

	/**
	 * Total sign-ups (every subscriber record). Feeds the Stats funnel.
	 *
	 * @return int
	 */
	public static function count_signups() {
		$counts = wp_count_posts( self::POST_TYPE );

		return isset( $counts->publish ) ? (int) $counts->publish : 0;
	}

	/**
	 * How many subscribers clicked download (have a downloaded_at stamp).
	 *
	 * @return int
	 */
	public static function count_downloads() {
		$query = new WP_Query(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => self::META_DOWNLOADED_AT,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		return (int) $query->found_posts;
	}

	/**
	 * How many subscribers are still pending sync to HubSpot.
	 *
	 * @return int
	 */
	public static function count_pending() {
		$query = new WP_Query(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => self::META_STATUS,
						'value' => self::STATUS_PENDING,
					),
				),
			)
		);

		return (int) $query->found_posts;
	}

	/**
	 * Sign-ups grouped by day ('Y-m-d' => count) over the last $days days,
	 * from each subscriber's creation date.
	 *
	 * @param int $days How many days back to include.
	 * @return array<string,int>
	 */
	public static function signups_by_day( $days = 14 ) {
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
	 * Downloads grouped by day ('Y-m-d' => count) over the last $days days,
	 * from each subscriber's downloaded_at stamp. A download can land on a
	 * different (later) day than the sign-up, so it's counted by its own date.
	 *
	 * @param int $days How many days back to include.
	 * @return array<string,int>
	 */
	public static function downloads_by_day( $days = 14 ) {
		$ids = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => self::META_DOWNLOADED_AT, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			)
		);

		$cutoff = gmdate( 'Y-m-d', current_time( 'timestamp' ) - ( (int) $days - 1 ) * DAY_IN_SECONDS );
		$by_day = array();

		foreach ( $ids as $id ) {
			$at = (string) get_post_meta( $id, self::META_DOWNLOADED_AT, true );

			if ( '' === $at ) {
				continue;
			}

			$day = substr( $at, 0, 10 );

			if ( $day < $cutoff ) {
				continue;
			}

			$by_day[ $day ] = isset( $by_day[ $day ] ) ? $by_day[ $day ] + 1 : 1;
		}

		return $by_day;
	}

	/**
	 * Finds a subscriber by email.
	 *
	 * @param string $email Email to look up.
	 * @return int Subscriber ID, or 0 if none.
	 */
	public static function find_by_email( $email ) {
		$found = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'meta_key'       => self::META_EMAIL,
				'meta_value'     => strtolower( $email ),
				'fields'         => 'ids',
				'posts_per_page' => 1,
			)
		);

		return $found ? (int) $found[0] : 0;
	}

	/**
	 * Adds the list-table columns (email, consent, download, sync status).
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function columns( $columns ) {
		$date = isset( $columns['date'] ) ? $columns['date'] : '';
		unset( $columns['date'] );

		$columns['citykleta_email']      = 'Email';
		$columns['citykleta_consent']    = 'Consent';
		$columns['citykleta_downloaded'] = 'Downloaded';
		$columns['citykleta_status']     = 'Sync';

		if ( $date ) {
			$columns['date'] = $date;
		}

		return $columns;
	}

	/**
	 * Renders a custom column.
	 *
	 * @param string $column  Column id.
	 * @param int    $post_id Subscriber ID.
	 */
	public function render_column( $column, $post_id ) {
		switch ( $column ) {
			case 'citykleta_email':
				echo esc_html( (string) get_post_meta( $post_id, self::META_EMAIL, true ) );
				break;

			case 'citykleta_consent':
				$at = (string) get_post_meta( $post_id, self::META_CONSENT_AT, true );
				echo $at ? esc_html( $at ) : '—';
				break;

			case 'citykleta_downloaded':
				$at = (string) get_post_meta( $post_id, self::META_DOWNLOADED_AT, true );
				echo $at ? esc_html( $at ) : '—';
				break;

			case 'citykleta_status':
				$status = (string) get_post_meta( $post_id, self::META_STATUS, true );
				echo esc_html( self::STATUS_SYNCED === $status ? 'Synced to HubSpot' : 'Pending' );
				break;
		}
	}

	/**
	 * Adds a read-only detail box on the subscriber edit screen.
	 */
	public function add_meta_box() {
		add_meta_box(
			'citykleta_subscriber_details',
			'Subscriber',
			array( $this, 'render_meta_box' ),
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Renders the read-only detail box.
	 *
	 * @param WP_Post $post The subscriber.
	 */
	public function render_meta_box( $post ) {
		$rows = array(
			'Email'         => get_post_meta( $post->ID, self::META_EMAIL, true ),
			'Consent given' => get_post_meta( $post->ID, self::META_CONSENT_AT, true ),
			'Downloaded'    => get_post_meta( $post->ID, self::META_DOWNLOADED_AT, true ),
			'Source'        => get_post_meta( $post->ID, self::META_SOURCE, true ),
			'Sync status'   => self::STATUS_SYNCED === get_post_meta( $post->ID, self::META_STATUS, true ) ? 'Synced to HubSpot' : 'Pending',
			'Synced at'     => get_post_meta( $post->ID, self::META_SYNCED_AT, true ),
		);

		echo '<table class="widefat striped"><tbody>';
		foreach ( $rows as $label => $value ) {
			echo '<tr><th style="width:160px;">' . esc_html( $label ) . '</th><td>' . ( $value ? esc_html( $value ) : '—' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
}
