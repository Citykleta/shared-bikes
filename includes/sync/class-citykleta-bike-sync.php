<?php
/**
 * Syncs bikes from an external API (the app's, or the mock while it
 * doesn't exist yet) into the "bike" custom post type.
 *
 * @package Citykleta_Shared_Bikes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Citykleta_Bike_Sync.
 */
class Citykleta_Bike_Sync {

	/**
	 * WP-Cron hook name used for the periodic sync.
	 */
	const CRON_HOOK = 'citykleta_sync_bikes';

	/**
	 * Hard cap on how many pages one sync will request, so a backend that
	 * always answers has_more = true can't spin forever.
	 */
	const MAX_PAGES = 50;

	/**
	 * Fraction of the currently published API-managed bikes the response has
	 * to at least cover before any bike is retired. A response carrying less
	 * than this is read as a partial answer from a struggling backend, not as
	 * "all the rest were removed" -- see retire_missing().
	 */
	const RETIRE_SAFETY_RATIO = 0.5;

	/**
	 * Status a bike is moved to once it stops coming from the API. Draft
	 * rather than trash/delete so its accumulated view stats stay attached to
	 * the post, and the next sync republishes it untouched if it comes back.
	 */
	const RETIRED_STATUS = 'draft';

	/**
	 * Meta key recording which source URL an image came from. Written on both
	 * sides: on the bike, to skip re-downloading an unchanged image, and on the
	 * attachment, so a URL already fetched is reused instead of downloaded
	 * again for the next bike (see sync_image()).
	 */
	const IMAGE_SOURCE_META = '_citykleta_image_source_url';

	/**
	 * Warnings collected during the current run (a truncated fetch, a retire
	 * pass skipped by the safety ratio...). Unlike citykleta_last_sync_error
	 * these don't mean the sync failed, only that it deliberately did less
	 * than a normal run -- so they're reported separately on the Sync tab.
	 *
	 * @var string[]
	 */
	private $warnings = array();

	/**
	 * Connects the class to WordPress hooks.
	 */
	public function register() {
		add_filter( 'cron_schedules', array( $this, 'add_cron_schedules' ) );

		// Called directly (not via add_action('init', ...)) because register()
		// is already invoked from within the 'init' hook (see citykleta_init()).
		// Hooking 'init' again at the same priority wouldn't run in that
		// same pass.
		$this->register_external_id_meta();
		$this->maybe_schedule();

		add_action( self::CRON_HOOK, array( $this, 'run' ) );
		add_action( 'admin_post_citykleta_manual_sync', array( $this, 'handle_manual_sync' ) );
	}

	/**
	 * Adds cron intervals WordPress doesn't ship with by default.
	 *
	 * @param array $schedules Existing intervals.
	 * @return array
	 */
	public function add_cron_schedules( $schedules ) {
		$schedules['every_15_minutes'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => 'Every 15 minutes',
		);
		$schedules['every_30_minutes'] = array(
			'interval' => 30 * MINUTE_IN_SECONDS,
			'display'  => 'Every 30 minutes',
		);
		return $schedules;
	}

	/**
	 * Registers the "external_id" meta linking each "bike" post to its id
	 * in the external API. Not manually editable, which is why it isn't
	 * in Citykleta_Bike_Meta.
	 */
	public function register_external_id_meta() {
		register_post_meta(
			Citykleta_Bike_Post_Type::POST_TYPE,
			'external_id',
			array(
				'type'          => 'integer',
				'single'        => true,
				'show_in_rest'  => true,
				'auth_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
	}

	/**
	 * Schedules the cron event if none is scheduled yet. Doesn't change
	 * the frequency of an existing one (that's what reschedule() is for).
	 */
	public function maybe_schedule() {
		$frequency = get_option( 'citykleta_sync_frequency', 'hourly' );

		if ( 'manual' === $frequency ) {
			return;
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), $frequency, self::CRON_HOOK );
		}
	}

	/**
	 * Clears the scheduled event and reschedules it with a new frequency.
	 * Called when the citykleta_sync_frequency option changes.
	 *
	 * @param string $frequency New frequency slug.
	 */
	public function reschedule( $frequency ) {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}

		if ( 'manual' !== $frequency ) {
			wp_schedule_event( time(), $frequency, self::CRON_HOOK );
		}
	}

	/**
	 * Clears any scheduled event. Used when the plugin is deactivated.
	 */
	public function clear_schedule() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Runs the sync: fetches bikes from the configured API and creates or
	 * updates the corresponding "bike" posts.
	 */
	public function run() {
		// Independent of bikes/bike types — just refreshes the read-only Maps
		// tab options from Back Office (see the map-config hand-off note in
		// CLAUDE.md). Bundled into the same sync run/frequency instead of a
		// separate schedule, since it's cheap and there's no reason for it to
		// lag behind.
		( new Citykleta_Map_Settings_Sync() )->run();

		$this->warnings = array();

		$url = Citykleta_Settings::get_endpoint_url( 'bikes' );

		if ( empty( $url ) ) {
			update_option( 'citykleta_last_sync_error', 'No API URL configured.' );
			return;
		}

		$result = $this->fetch_all_pages( $url );

		if ( is_wp_error( $result ) ) {
			update_option( 'citykleta_last_sync_error', $result->get_error_message() );
			return;
		}

		// Types are collected off the bike payload rather than fetched (the
		// API's /bike-types is staff-only, see Citykleta_Settings::PATHS), but
		// still have to become terms before the loop below resolves each bike's
		// type by slug.
		( new Citykleta_Bike_Type_Sync() )->run( $this->collect_bike_types( $result['items'] ) );

		$seen  = array();
		$count = 0;

		foreach ( $result['items'] as $item ) {
			$this->upsert_bike( $item );

			$seen[] = (int) $item['id'];
			++$count;
		}

		// Only a run that walked every page can tell "gone from the API" apart
		// from "we never asked for the page it was on", so a truncated fetch
		// skips retiring altogether rather than unpublish the tail of the
		// catalogue. See fetch_all_pages().
		$retired = $result['complete'] ? $this->retire_missing( $seen ) : 0;

		// Must run AFTER the upsert loop above, not before like
		// Citykleta_Bike_Type_Sync/Citykleta_Map_Settings_Sync: it matches
		// each bike's geofence against its lat/lng, which only exists once
		// upsert_bike() has saved it. Bikes retired just above drop out of it
		// on their own -- every map/destination query filters by
		// post_status = publish.
		( new Citykleta_Destination_Matcher() )->run();

		delete_option( 'citykleta_last_sync_error' );
		update_option( 'citykleta_last_sync', current_time( 'mysql' ) );
		update_option( 'citykleta_last_sync_count', $count );
		update_option( 'citykleta_last_sync_retired', $retired );

		if ( $this->warnings ) {
			update_option( 'citykleta_last_sync_warning', implode( ' ', $this->warnings ) );
		} else {
			delete_option( 'citykleta_last_sync_warning' );
		}
	}

	/**
	 * Fetches every page of the bike list and returns the merged items.
	 *
	 * The API answers a { data, meta } envelope whose meta.has_more says
	 * whether another page exists; the page number goes back as ?page=N. That
	 * parameter name isn't confirmed against the real citykleta-sharing-api
	 * endpoint yet (see CLAUDE.md), so this is written to survive a backend
	 * that ignores it: if a page brings no id we haven't already seen, we're
	 * being handed page 1 over and over and the walk stops. MAX_PAGES is the
	 * same guard against a has_more that never flips.
	 *
	 * Either guard leaves the list incomplete, which is why the return value
	 * carries a 'complete' flag -- the caller must not retire bikes off a list
	 * that may be missing pages.
	 *
	 * @param string $url Base endpoint URL.
	 * @return array|WP_Error { 'items' => array[], 'complete' => bool }, or an error.
	 */
	private function fetch_all_pages( $url ) {
		$items = array();
		$seen  = array();
		$page  = 1;

		while ( true ) {
			$page_url = $page > 1 ? add_query_arg( 'page', $page, $url ) : $url;
			$response = wp_remote_get( $page_url, array( 'timeout' => 15 ) );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );

			if ( 200 !== $code ) {
				return new WP_Error( 'citykleta_sync_http', 'API responded with status code ' . $code . '.' );
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			$rows = $this->extract_items( $body );

			if ( null === $rows ) {
				return new WP_Error( 'citykleta_sync_payload', 'API response is not valid JSON.' );
			}

			$fresh = 0;

			foreach ( $rows as $row ) {
				// Normalised here rather than later so everything downstream --
				// the dedupe right below included -- works on one shape. The
				// real backend keys bikes by app_bike_id, not id, so skipping
				// this step would silently drop every single bike.
				$item = $this->normalize_item( $row );

				if ( ! $item ) {
					continue;
				}

				$id = $item['id'];

				// Deduped across pages, not only within one: a backend
				// paginating an unstable sort can hand back the same bike twice.
				if ( isset( $seen[ $id ] ) ) {
					continue;
				}

				$seen[ $id ] = true;
				$items[]     = $item;
				++$fresh;
			}

			if ( ! $this->has_next_page( $body, $page ) ) {
				return array(
					'items'    => $items,
					'complete' => true,
				);
			}

			if ( 0 === $fresh ) {
				$this->warnings[] = 'The API reported more pages but stopped sending new bikes at page ' . $page . ' (is the "page" parameter being ignored?). No bike was retired this run.';
				break;
			}

			++$page;

			if ( $page > self::MAX_PAGES ) {
				$this->warnings[] = 'Stopped after ' . self::MAX_PAGES . ' pages with the API still reporting more. No bike was retired this run.';
				break;
			}
		}

		return array(
			'items'    => $items,
			'complete' => false,
		);
	}

	/**
	 * Translates one API row into the shape the rest of this class works on.
	 *
	 * citykleta-sharing-api's GET /bikesPublic/sync and the local mock describe
	 * the same bike with different keys, so rather than teach every consumer
	 * both dialects, each row is translated once on the way in. Real-backend
	 * key on the left, canonical key used from here on to the right:
	 *
	 *   app_bike_id            -> id
	 *   name                   -> title
	 *   daily_rate             -> price_per_day   (a string in the payload)
	 *   location.address       -> location
	 *   location.lat/.long     -> coordinates.lat/.lng   (note: long, not lng)
	 *   images[].url           -> images[]        (featured first, absolute)
	 *   status === 'active'    -> available
	 *
	 * Anything already in canonical form is left alone, so mock payloads keep
	 * working untouched. bike_type is a slug on both sides and needs nothing.
	 *
	 * @param mixed $row One decoded row from the API response.
	 * @return array|null Canonical row, or null if it has no usable id.
	 */
	private function normalize_item( $row ) {
		if ( ! is_array( $row ) ) {
			return null;
		}

		$item = $row;
		$id   = 0;

		foreach ( array( 'app_bike_id', 'id' ) as $key ) {
			if ( ! empty( $row[ $key ] ) ) {
				$id = (int) $row[ $key ];
				break;
			}
		}

		// No id means no stable key to upsert against, so the row is unusable.
		if ( ! $id ) {
			return null;
		}

		$item['id'] = $id;

		if ( empty( $item['title'] ) && ! empty( $row['name'] ) ) {
			$item['title'] = $row['name'];
		}

		// Renting is priced per day on the app's side; there is no hourly rate
		// to be had. Kept under its own key rather than written into
		// price_per_hour, which would misprice every bike by a factor of hours.
		if ( isset( $row['daily_rate'] ) && '' !== $row['daily_rate'] ) {
			$item['price_per_day'] = (float) $row['daily_rate'];
		}

		// The real backend nests address and coordinates under location; the
		// mock sends a plain string plus a separate coordinates object.
		if ( isset( $row['location'] ) && is_array( $row['location'] ) ) {
			$location         = $row['location'];
			$item['location'] = isset( $location['address'] ) ? $location['address'] : '';

			$lat = isset( $location['lat'] ) ? $location['lat'] : null;
			$lng = null;

			foreach ( array( 'long', 'lng' ) as $key ) {
				if ( isset( $location[ $key ] ) ) {
					$lng = $location[ $key ];
					break;
				}
			}

			// Left unset when either half is missing: a bike with half a
			// coordinate is worse than one with none, since the map would
			// place it somewhere wrong instead of leaving it out. Today the
			// backend does return null for both on most bikes.
			if ( null !== $lat && null !== $lng && '' !== $lat && '' !== $lng ) {
				$item['coordinates'] = array(
					'lat' => $lat,
					'lng' => $lng,
				);
			}
		}

		if ( ! isset( $item['available'] ) && isset( $row['status'] ) ) {
			$item['available'] = ( 'active' === $row['status'] );
		}

		$item['images'] = $this->normalize_images( isset( $row['images'] ) ? $row['images'] : array() );

		return $item;
	}

	/**
	 * Flattens the images of a row to a list of absolute URLs, featured first.
	 *
	 * The real backend sends objects ({ url, featured }) with site-relative
	 * paths like /uploads/foo.jpg, which can't be fetched on their own, so
	 * they're resolved against the configured API base. The mock sends plain
	 * URL strings, which pass through untouched.
	 *
	 * @param mixed $images Raw images value from the row.
	 * @return string[] Absolute URLs, the featured one first.
	 */
	private function normalize_images( $images ) {
		if ( ! is_array( $images ) ) {
			return array();
		}

		$featured = array();
		$rest     = array();

		foreach ( $images as $image ) {
			$url = is_array( $image ) ? ( isset( $image['url'] ) ? $image['url'] : '' ) : $image;

			if ( ! is_string( $url ) || '' === trim( $url ) ) {
				continue;
			}

			$url = $this->absolute_url( trim( $url ) );

			if ( is_array( $image ) && ! empty( $image['featured'] ) ) {
				$featured[] = $url;
			} else {
				$rest[] = $url;
			}
		}

		return array_merge( $featured, $rest );
	}

	/**
	 * Resolves a possibly site-relative URL against the configured API base.
	 *
	 * @param string $url URL from the payload.
	 * @return string
	 */
	private function absolute_url( $url ) {
		if ( preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}

		$base  = get_option( 'citykleta_api_base_url' );
		$parts = wp_parse_url( $base );

		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return $url;
		}

		$origin = $parts['scheme'] . '://' . $parts['host'];

		if ( ! empty( $parts['port'] ) ) {
			$origin .= ':' . $parts['port'];
		}

		return $origin . '/' . ltrim( $url, '/' );
	}

	/**
	 * Collects the bike types present in a run, as display name keyed by slug.
	 *
	 * Every row of GET /bikesPublic/sync carries both halves (bike_type is the
	 * stable slug, bike_type_name the label the back-office can rename at will),
	 * which is what lets the taxonomy be built without the staff-only
	 * /bike-types endpoint. Rows missing either half are skipped rather than
	 * guessed at: inventing a display name from a slug would fight the
	 * back-office the next time someone renames a type there.
	 *
	 * @param array[] $items Canonical rows from normalize_item().
	 * @return array Display name keyed by slug.
	 */
	private function collect_bike_types( array $items ) {
		$types = array();

		foreach ( $items as $item ) {
			if ( empty( $item['bike_type'] ) || empty( $item['bike_type_name'] ) ) {
				continue;
			}

			$types[ $item['bike_type'] ] = $item['bike_type_name'];
		}

		return $types;
	}

	/**
	 * Pulls the list of rows out of a response body, in either shape
	 * citykleta-sharing-api answers with:
	 *
	 * - A bare array — what GET /bikesPublic/sync returns today: the whole
	 *   catalogue in one go, ignoring the request entirely.
	 * - { items, total, page, limit, pages } — the paginated shape the rest of
	 *   the API uses (staff bikes, orders, reservations, reviews...), and the
	 *   one /bikesPublic/sync would most likely grow into.
	 *
	 * A third shape, { data, meta: { has_more } }, used to be handled here too.
	 * That one was invented by the old bundled mock and exists nowhere in the
	 * real backend, so it went out with the mock itself.
	 *
	 * @param mixed $body Decoded response body.
	 * @return array[]|null Rows, or null if the body isn't a list in any shape.
	 */
	private function extract_items( $body ) {
		if ( ! is_array( $body ) ) {
			return null;
		}

		// A bare JSON array decodes to a list: no string keys to look under.
		// An empty array counts as an empty catalogue, not a broken payload.
		if ( ! $body || isset( $body[0] ) ) {
			return $body;
		}

		if ( isset( $body['items'] ) && is_array( $body['items'] ) ) {
			return $body['items'];
		}

		return null;
	}

	/**
	 * Says whether another page is worth asking for, reading whichever
	 * pagination signal the envelope carries (see extract_items() for the
	 * shapes). A body with no signal at all -- a bare array, notably -- is a
	 * complete answer, not a first page.
	 *
	 * @param mixed $body Decoded response body.
	 * @param int   $page Page just fetched.
	 * @return bool
	 */
	private function has_next_page( $body, $page ) {
		if ( ! is_array( $body ) ) {
			return false;
		}

		// Total page count at the top level, alongside the items.
		if ( isset( $body['pages'] ) ) {
			return $page < (int) $body['pages'];
		}

		return false;
	}

	/**
	 * Unpublishes the bikes that no longer come from the API.
	 *
	 * Only ever touches bikes carrying an external_id, i.e. ones this sync
	 * created. A bike added by hand in wp-admin has none and is never retired,
	 * so the manual-override escape hatch keeps working.
	 *
	 * Retiring means moving to draft, not deleting: the per-day view stats
	 * stay attached to the post, and a bike that comes back needs no special
	 * handling -- upsert_bike() always writes post_status = publish.
	 *
	 * @param int[] $seen external_ids present in this run's API response.
	 * @return int How many bikes were retired.
	 */
	private function retire_missing( array $seen ) {
		$published = get_posts(
			array(
				'post_type'      => Citykleta_Bike_Post_Type::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => 'external_id',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		if ( ! $published ) {
			return 0;
		}

		// A response covering far less than what's currently published reads as
		// a partial answer from a struggling backend, not a mass removal:
		// updating what arrived is safe, unpublishing everything else is not.
		// Erring this way costs a stale bike until the next run; erring the
		// other way empties the site.
		if ( count( $seen ) < count( $published ) * self::RETIRE_SAFETY_RATIO ) {
			$this->warnings[] = sprintf(
				'The API returned %d bike(s) against %d currently published -- too big a drop to trust, so nothing was retired.',
				count( $seen ),
				count( $published )
			);

			return 0;
		}

		// Primes every external_id in one query: get_posts() with
		// fields => ids doesn't populate the meta cache, so the get_post_meta()
		// calls below would otherwise each hit the database.
		update_postmeta_cache( $published );

		$seen    = array_flip( $seen );
		$retired = 0;

		foreach ( $published as $post_id ) {
			$external_id = (int) get_post_meta( $post_id, 'external_id', true );

			if ( isset( $seen[ $external_id ] ) ) {
				continue;
			}

			wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => self::RETIRED_STATUS,
				)
			);

			++$retired;
		}

		return $retired;
	}


	/**
	 * Creates or updates the "bike" post matching a bike from the API.
	 *
	 * @param array $item Bike data, as returned by the API.
	 */
	private function upsert_bike( array $item ) {
		$existing = get_posts(
			array(
				'post_type'      => Citykleta_Bike_Post_Type::POST_TYPE,
				'post_status'    => 'any',
				'meta_key'       => 'external_id',
				'meta_value'     => (int) $item['id'],
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		$title = $this->build_title( $item );

		// The URL slug keeps the owner name for a more identifiable link, even
		// though the visible title doesn't (see build_title()).
		$slug_source = $title;
		if ( ! empty( $item['owner']['name'] ) ) {
			$slug_source .= ' ' . sanitize_text_field( $item['owner']['name'] );
		}

		$post_data = array(
			'post_type'    => Citykleta_Bike_Post_Type::POST_TYPE,
			'post_title'   => $title,
			// Recalculated every sync so the human-friendly slug stays in sync
			// with brand/model/owner; the id-prefixed URL (see
			// Citykleta_Bike_Router) keeps old links working via canonical
			// redirect when it changes.
			'post_name'    => sanitize_title( $slug_source ),
			'post_content' => isset( $item['description'] ) ? sanitize_textarea_field( $item['description'] ) : '',
			'post_status'  => 'publish',
		);

		if ( ! empty( $existing ) ) {
			$post_data['ID'] = $existing[0];
			$post_id         = wp_update_post( $post_data );
		} else {
			$post_id = wp_insert_post( $post_data );
		}

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return;
		}

		update_post_meta( $post_id, 'external_id', (int) $item['id'] );

		$boolean_fields = array( 'available', 'is_electric' );
		$meta_fields    = array( 'model', 'location', 'price_per_day', 'available', 'brand', 'size', 'wheel_size', 'is_electric' );

		// Clears the hourly price left over from the mock era. The real API
		// never sends that key, so the sync would otherwise never overwrite it
		// and every bike synced back then would keep showing a made-up hourly
		// price forever. Safe to drop once no database still has one.
		delete_post_meta( $post_id, 'price_per_hour' );

		foreach ( $meta_fields as $key ) {
			if ( ! array_key_exists( $key, $item ) ) {
				continue;
			}

			$value = $item[ $key ];

			if ( in_array( $key, $boolean_fields, true ) ) {
				$value = $value ? '1' : '0';
			}

			update_post_meta( $post_id, $key, $value );
		}

		// Owner comes as a nested object ({ name, photo }), not flat owner_name/owner_photo.
		if ( isset( $item['owner']['name'] ) ) {
			update_post_meta( $post_id, 'owner_name', sanitize_text_field( $item['owner']['name'] ) );
		}

		if ( isset( $item['owner']['photo'] ) ) {
			update_post_meta( $post_id, 'owner_photo', esc_url_raw( $item['owner']['photo'] ) );
		}

		if ( isset( $item['owner']['phone'] ) ) {
			update_post_meta( $post_id, 'owner_phone', sanitize_text_field( $item['owner']['phone'] ) );
		}

		if ( ! empty( $item['images'] ) && is_array( $item['images'] ) ) {
			$this->sync_images( $post_id, $item['images'] );
		}

		if ( ! empty( $item['bike_type'] ) ) {
			// bike_type is a slug (see Citykleta_Bike_Type_Sync) — look up the
			// matching term instead of creating one from the raw string.
			$type_term = get_term_by( 'slug', sanitize_title( $item['bike_type'] ), Citykleta_Bike_Type_Taxonomy::TAXONOMY );

			if ( $type_term ) {
				wp_set_object_terms( $post_id, array( $type_term->term_id ), Citykleta_Bike_Type_Taxonomy::TAXONOMY );
			}
		}

		// Coordinates come as a nested object ({ lat, lng }), not flat lat/lng.
		if ( isset( $item['coordinates']['lat'], $item['coordinates']['lng'] ) ) {
			update_post_meta( $post_id, 'lat', floatval( $item['coordinates']['lat'] ) );
			update_post_meta( $post_id, 'lng', floatval( $item['coordinates']['lng'] ) );
		}
	}

	/**
	 * Builds the bike's visible post title from brand + model (e.g. "COGA
	 * Supersonic"). The owner name is intentionally left out of the title — it
	 * only goes into the URL slug (see upsert_bike()). Falls back to the API's
	 * own title, then to the id, so the post is never left untitled.
	 *
	 * @param array $item Bike data, as returned by the API.
	 * @return string
	 */
	private function build_title( array $item ) {
		$parts = array_filter(
			array(
				isset( $item['brand'] ) ? sanitize_text_field( $item['brand'] ) : '',
				isset( $item['model'] ) ? sanitize_text_field( $item['model'] ) : '',
			)
		);

		$title = implode( ' ', $parts );

		if ( '' !== $title ) {
			return $title;
		}

		if ( ! empty( $item['title'] ) ) {
			return sanitize_text_field( $item['title'] );
		}

		return 'Bike #' . (int) $item['id'];
	}

	/**
	 * Stores a bike's photos: the first one as the featured image (where the
	 * rest of the plugin already looks for it), the others as attachment IDs
	 * in Citykleta_Bike_Meta::GALLERY_META, in the order the API sent them.
	 *
	 * Capped at Citykleta_Bike_Meta::MAX_IMAGES so a bike with a long photo
	 * roll can't turn one sync into dozens of downloads plus their generated
	 * thumbnail sets — the same cost problem that made the shared-attachment
	 * lookup necessary in the first place (see sync_image()).
	 *
	 * The gallery meta is always rewritten, even to empty: a photo removed in
	 * the app has to disappear here too, rather than linger from an older
	 * sync.
	 *
	 * @param int   $post_id "bike" post ID.
	 * @param array $urls    Absolute image URLs, featured first (see normalize_item()).
	 */
	private function sync_images( $post_id, array $urls ) {
		$urls = array_slice( array_filter( $urls ), 0, Citykleta_Bike_Meta::MAX_IMAGES );

		if ( ! $urls ) {
			delete_post_meta( $post_id, Citykleta_Bike_Meta::GALLERY_META );
			return;
		}

		$this->sync_image( $post_id, esc_url_raw( array_shift( $urls ) ) );

		$gallery_ids = array();

		foreach ( $urls as $url ) {
			$attachment_id = $this->resolve_attachment( esc_url_raw( $url ), $post_id );

			if ( $attachment_id ) {
				$gallery_ids[] = $attachment_id;
			}
		}

		if ( $gallery_ids ) {
			update_post_meta( $post_id, Citykleta_Bike_Meta::GALLERY_META, $gallery_ids );
		} else {
			delete_post_meta( $post_id, Citykleta_Bike_Meta::GALLERY_META );
		}
	}

	/**
	 * Attachment ID for a source URL, downloading it only if no attachment
	 * came from that URL before. Shared by the featured image and the gallery
	 * so both benefit from the one-attachment-per-source-URL rule described
	 * in sync_image().
	 *
	 * @param string $image_url Image URL on the external API.
	 * @param int    $post_id   "bike" post ID the download is attributed to.
	 * @return int Attachment ID, or 0 when the download failed.
	 */
	private function resolve_attachment( $image_url, $post_id ) {
		$attachment_id = $this->find_attachment_by_source( $image_url );

		if ( $attachment_id ) {
			return (int) $attachment_id;
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = $this->sideload_image( $image_url, $post_id );

		if ( is_wp_error( $attachment_id ) ) {
			update_option( 'citykleta_last_image_error', $attachment_id->get_error_message() . ' (' . $image_url . ')' );
			return 0;
		}

		update_post_meta( $attachment_id, self::IMAGE_SOURCE_META, $image_url );

		return (int) $attachment_id;
	}

	/**
	 * Downloads the bike's image (if it changed) and sets it as the post's
	 * featured image. Doesn't re-download if the URL hasn't changed since
	 * the last sync, to avoid wasting extra bandwidth.
	 *
	 * @param int    $post_id   "bike" post ID.
	 * @param string $image_url Image URL on the external API.
	 */
	private function sync_image( $post_id, $image_url ) {
		$previous_url = get_post_meta( $post_id, self::IMAGE_SOURCE_META, true );

		if ( $previous_url === $image_url && has_post_thumbnail( $post_id ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// One attachment per source URL, shared by every bike using it, rather
		// than a fresh download per bike. Several bikes pointing at the same
		// image is normal (the whole test catalogue shares one placeholder),
		// and downloading it once per bike means N downloads plus N sets of
		// generated thumbnails — enough to make a first sync of a modest
		// catalogue take many minutes. WordPress is fine with one attachment
		// being the featured image of several posts.
		$attachment_id = $this->find_attachment_by_source( $image_url );

		if ( ! $attachment_id ) {
			$attachment_id = $this->sideload_image( $image_url, $post_id );

			if ( is_wp_error( $attachment_id ) ) {
				update_option( 'citykleta_last_image_error', $attachment_id->get_error_message() . ' (' . $image_url . ')' );
				return;
			}

			// Stamped on the attachment too, which is what makes the lookup
			// above able to find it on the next bike.
			update_post_meta( $attachment_id, self::IMAGE_SOURCE_META, $image_url );
		}

		delete_option( 'citykleta_last_image_error' );
		set_post_thumbnail( $post_id, $attachment_id );
		update_post_meta( $post_id, self::IMAGE_SOURCE_META, $image_url );
	}

	/**
	 * Finds an attachment already downloaded from a given source URL.
	 *
	 * @param string $image_url Source URL on the external API.
	 * @return int Attachment id, or 0 if this URL hasn't been downloaded yet.
	 */
	private function find_attachment_by_source( $image_url ) {
		$found = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => self::IMAGE_SOURCE_META,
						'value' => $image_url,
					),
				),
			)
		);

		return $found ? (int) $found[0] : 0;
	}

	/**
	 * Downloads an image and attaches it to a post, returning the attachment id.
	 *
	 * Deliberately does NOT use media_sideload_image()/download_url(). Those go
	 * through wp_safe_remote_get(), which runs the URL past
	 * wp_http_validate_url() — and that function rejects, with no filter to
	 * override it, any URL whose port isn't 80, 443 or 8080 (unless it matches
	 * the site's own host and port):
	 *
	 *     if ( empty( $parsed_url['port'] ) ) return $url;
	 *     $port = $parsed_url['port'];
	 *     if ( 80 === $port || 443 === $port || 8080 === $port ) return $url;
	 *     ...
	 *     return false;
	 *
	 * A dev API on http://localhost:8001 trips exactly that, and the failure
	 * surfaces from deep inside WP_Http as "A valid URL was not provided." —
	 * which reads like the URL is malformed when it is perfectly fine and the
	 * file downloads with curl. (The same function also rejects hosts resolving
	 * to loopback/private addresses; that half at least has the
	 * http_request_host_is_external filter, but the port check has none, so
	 * filtering alone can't fix this.)
	 *
	 * So the fetch is done with plain wp_remote_get(), which applies no such
	 * URL policy, and the bytes are handed to media_handle_sideload() as a
	 * local file — that part never looks at the URL.
	 *
	 * @param string $image_url Image URL on the external API.
	 * @param int    $post_id   Post to attach the image to.
	 * @return int|WP_Error Attachment id, or an error.
	 */
	private function sideload_image( $image_url, $post_id ) {
		$response = wp_remote_get( $image_url, array( 'timeout' => 30 ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			return new WP_Error( 'citykleta_image_http', 'Image request returned status code ' . $code . '.' );
		}

		$body = wp_remote_retrieve_body( $response );

		if ( '' === $body ) {
			return new WP_Error( 'citykleta_image_empty', 'Image response was empty.' );
		}

		$filename = $this->image_filename( $image_url, wp_remote_retrieve_header( $response, 'content-type' ) );

		if ( ! $filename ) {
			return new WP_Error( 'citykleta_image_type', 'Could not tell the image type from the URL or Content-Type.' );
		}

		$tmp = wp_tempnam( $filename );

		if ( ! $tmp ) {
			return new WP_Error( 'citykleta_image_tmp', 'Could not create a temporary file for the image.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $tmp, $body ) ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'citykleta_image_write', 'Could not write the downloaded image to disk.' );
		}

		$attachment_id = media_handle_sideload(
			array(
				'name'     => $filename,
				'tmp_name' => $tmp,
			),
			$post_id
		);

		// On success media_handle_sideload() moves the temp file into the
		// uploads dir; on failure it's left behind for us to clear.
		if ( is_wp_error( $attachment_id ) && file_exists( $tmp ) ) {
			wp_delete_file( $tmp );
		}

		return $attachment_id;
	}

	/**
	 * Works out a filename with a usable image extension.
	 *
	 * media_handle_sideload() decides the file type from the name, so a URL
	 * with no extension (or one hidden behind a query string) needs the
	 * Content-Type to fill in the gap.
	 *
	 * @param string $image_url    Image URL.
	 * @param string $content_type Content-Type header from the response.
	 * @return string Filename, or '' when the type can't be determined.
	 */
	private function image_filename( $image_url, $content_type ) {
		$path     = (string) wp_parse_url( $image_url, PHP_URL_PATH );
		$basename = $path ? wp_basename( $path ) : '';

		if ( $basename && preg_match( '/\.(jpe?g|png|gif|webp|avif)$/i', $basename ) ) {
			return sanitize_file_name( $basename );
		}

		$types = array(
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/gif'  => 'gif',
			'image/webp' => 'webp',
			'image/avif' => 'avif',
		);

		// Content-Type can carry parameters, e.g. "image/jpeg; charset=binary".
		$mime = strtolower( trim( strtok( (string) $content_type, ';' ) ) );

		if ( ! isset( $types[ $mime ] ) ) {
			return '';
		}

		$stem = $basename ? preg_replace( '/\.[^.]*$/', '', $basename ) : 'bike-image';

		return sanitize_file_name( ( $stem ? $stem : 'bike-image' ) . '.' . $types[ $mime ] );
	}

	/**
	 * Handles the "Sync now" button on the settings page.
	 */
	public function handle_manual_sync() {
		if ( ! current_user_can( 'manage_options' ) ||
			! isset( $_POST['citykleta_sync_nonce'] ) ||
			! wp_verify_nonce( sanitize_key( $_POST['citykleta_sync_nonce'] ), 'citykleta_manual_sync' ) ) {
			wp_die( 'Unauthorized.' );
		}

		$this->run();

		wp_safe_redirect( add_query_arg( 'citykleta_synced', '1', wp_get_referer() ) );
		exit;
	}
}
