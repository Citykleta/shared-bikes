<?php
/**
 * One-time move to the `bike_type` tree (type → subtype), from whichever of
 * two earlier states the install is in:
 *
 *   A. The two old FLAT taxonomies, `bike_usage` + `bike_type` (any install
 *      that never ran a tree migration — production, possibly).
 *   B. The interim tree, `bike_kind` (this tree's name for a few days in
 *      2026-10; only the local dev install ever had it).
 *
 * Runs once, on the first request after the update, from citykleta_init()
 * (needs the taxonomies registered first). Done = self::DONE_OPTION holds the
 * date it ran.
 *
 * **Step 1, always: move the old flat terms out of the way.** The tree reuses
 * the name `bike_type`, which the old flat terms still hold. Left in place,
 * they'd show up mixed into the tree and every bike would point at both. So
 * their rows are renamed to `bike_type_legacy` / `bike_usage_legacy`. This has
 * to come first: until it runs, anything under `bike_type` is the old flat
 * list.
 *
 * **Step 2, depending on the state:**
 *   - B: the interim `bike_kind` rows are renamed to `bike_type`. Term IDs,
 *     parents, meta and every bike's assignment are untouched — they hang off
 *     term_taxonomy_id, not the taxonomy name.
 *   - A: the tree is built from the legacy terms: each usage → a top-level
 *     type, each old type → a subtype under it (with self::PARENT_OVERRIDES
 *     where the old mapping couldn't decide), self::BECOME_TAGS → bike_tag
 *     terms, and every bike reassigned.
 *
 * **Step 3: the legacy terms are deleted** (decision of 2026-10-05, on every
 * install) — see self::purge_legacy(). From then on the migration can no
 * longer be undone. It only ever runs once the tree exists: if building it
 * failed somewhere, the legacy terms are the only copy of the classification,
 * so they're kept and the purge retries on a later request.
 *
 * Renaming the `taxonomy` column of wp_term_taxonomy is the only way to
 * rename a taxonomy — WordPress has no API for it — and it's safe for the
 * same reason: nothing else stores the taxonomy name by row. The one derived
 * cache, the `{taxonomy}_children` hierarchy option, is cleared after.
 *
 * @package Citykleta_Shared_Bikes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Citykleta_Bike_Type_Migration.
 */
class Citykleta_Bike_Type_Migration {

	/**
	 * Option set once the migration has run (holds the date).
	 */
	const DONE_OPTION = 'citykleta_bike_type_tree_migrated';

	/**
	 * Option set once the legacy terms have been deleted (holds the date).
	 * Separate from DONE_OPTION because the purge has its own precondition —
	 * see self::purge_legacy() — and may run on a later request than the move.
	 */
	const PURGED_OPTION = 'citykleta_bike_type_legacy_purged';

	/**
	 * The interim version's own "done" flag — cleared once superseded.
	 */
	const INTERIM_DONE_OPTION = 'citykleta_bike_kind_migrated';

	/**
	 * Option used as a lock while it runs — add_option() fails if it already
	 * exists, which makes it a cheap atomic guard against two simultaneous
	 * first requests both migrating and doubling every term.
	 */
	const LOCK_OPTION = 'citykleta_bike_type_migrating';

	/**
	 * A lock older than this (seconds) is a crashed run, not a running one.
	 */
	const LOCK_TTL = 300;

	/**
	 * Names of the old flat taxonomies, as they were…
	 */
	const OLD_TYPE  = 'bike_type';
	const OLD_USAGE = 'bike_usage';

	/**
	 * …and as they're kept, out of the way.
	 */
	const LEGACY_TYPE  = 'bike_type_legacy';
	const LEGACY_USAGE = 'bike_usage_legacy';

	/**
	 * The interim tree's name.
	 */
	const INTERIM = 'bike_kind';

	/**
	 * Old usage term meta: the bike_type term IDs it "included".
	 */
	const LEGACY_USAGE_TYPES_META = 'bike_types';

	/**
	 * Where each subtype goes when the old mapping can't decide, keyed by
	 * subtype slug → OLD usage slug.
	 *
	 * Product decisions (2026-10), not something to derive: a hierarchy gives
	 * a subtype ONE type, and these were the ones that had two (Downhill, Fat
	 * bike, Gravel, Touring) or none (Cargo trycle, Tall bike).
	 */
	const PARENT_OVERRIDES = array(
		'downhill'     => 'extreme',
		'fatbike'      => 'extreme',
		'gravel'       => 'offroad',
		'touring'      => 'road',
		'cargo-trycle' => 'cargo',
		'tall-bike'    => 'extreme',
	);

	/**
	 * Old types that stop being subtypes and become tags: slug → tag name.
	 */
	const BECOME_TAGS = array(
		'tandem'  => 'Tandem',
		'trailer' => 'Trailer',
	);

	/**
	 * Runs the migration if it hasn't run yet. Cheap when it has: one
	 * autoloaded option read.
	 */
	public static function maybe_run() {
		$migrated = (bool) get_option( self::DONE_OPTION );

		if ( $migrated && get_option( self::PURGED_OPTION ) ) {
			return;
		}

		if ( ! add_option( self::LOCK_OPTION, time(), '', 'no' ) ) {
			// Someone else is migrating — unless their lock is stale, in
			// which case clear it so the next request can retry.
			$since = (int) get_option( self::LOCK_OPTION );

			if ( $since && time() - $since > self::LOCK_TTL ) {
				delete_option( self::LOCK_OPTION );
			}

			return;
		}

		try {
			if ( ! $migrated ) {
				self::run();
				update_option( self::DONE_OPTION, current_time( 'mysql' ) );
				delete_option( self::INTERIM_DONE_OPTION );
			}

			if ( self::purge_legacy() ) {
				update_option( self::PURGED_OPTION, current_time( 'mysql' ) );
			}
		} finally {
			delete_option( self::LOCK_OPTION );
		}
	}

	/**
	 * The migration itself.
	 */
	private static function run() {
		// Step 1 — the old flat terms step aside. Until this runs, anything
		// under `bike_type` IS the old flat list (the tree only ever gets that
		// name from this method, which then marks itself done).
		self::rename_taxonomy( self::OLD_TYPE, self::LEGACY_TYPE );
		self::rename_taxonomy( self::OLD_USAGE, self::LEGACY_USAGE );

		// Step 2.
		if ( self::row_count( self::INTERIM ) > 0 ) {
			self::rename_taxonomy( self::INTERIM, Citykleta_Bike_Type_Taxonomy::TAXONOMY );
		} else {
			self::build_from_legacy();
		}

		Citykleta_Bike_Type_Taxonomy::unclassified_id();

		// New URLs (/bike-type/…). The flag is the one citykleta_init()
		// already checks last, after every taxonomy is registered, so the
		// flush covers all of them.
		update_option( 'citykleta_destination_flush_rewrite_flag', 1 );
	}

	/**
	 * Deletes the legacy flat terms for good — with their meta and every
	 * bike's relationship to them — through wp_delete_term(), which cleans all
	 * three; raw SQL would leave orphaned meta and relationships behind.
	 *
	 * Refuses while the tree is empty. If the build failed on some install,
	 * the legacy terms are the only copy of the classification left, and
	 * deleting them would lose it for good; the purge simply retries on the
	 * next request instead.
	 *
	 * @return bool Whether the legacy taxonomies are now empty.
	 */
	private static function purge_legacy() {
		$legacy = array( self::LEGACY_TYPE, self::LEGACY_USAGE );

		if ( ! self::row_count( self::LEGACY_TYPE ) && ! self::row_count( self::LEGACY_USAGE ) ) {
			return true;
		}

		if ( ! self::row_count( Citykleta_Bike_Type_Taxonomy::TAXONOMY ) ) {
			return false;
		}

		self::register_legacy();

		foreach ( $legacy as $taxonomy ) {
			$ids = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'fields'     => 'ids',
				)
			);

			foreach ( is_wp_error( $ids ) ? array() : $ids as $term_id ) {
				wp_delete_term( (int) $term_id, $taxonomy );
			}
		}

		return ! self::row_count( self::LEGACY_TYPE ) && ! self::row_count( self::LEGACY_USAGE );
	}

	/**
	 * Renames every term_taxonomy row of one taxonomy to another name.
	 *
	 * @param string $from Current taxonomy name.
	 * @param string $to   New taxonomy name.
	 */
	private static function rename_taxonomy( $from, $to ) {
		global $wpdb;

		if ( ! self::row_count( $from ) ) {
			return;
		}

		$wpdb->update( $wpdb->term_taxonomy, array( 'taxonomy' => $to ), array( 'taxonomy' => $from ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		// The hierarchy cache lives in an option named after the taxonomy;
		// both names' copies are now wrong.
		delete_option( $from . '_children' );
		delete_option( $to . '_children' );
		wp_cache_flush();
	}

	/**
	 * How many term_taxonomy rows a taxonomy has — read from the table, so
	 * it works whether or not the taxonomy is registered.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return int
	 */
	private static function row_count( $taxonomy ) {
		global $wpdb;

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", $taxonomy )
		);
	}

	/**
	 * Builds the tree from the legacy flat terms (state A).
	 */
	private static function build_from_legacy() {
		self::register_legacy();

		$tax = Citykleta_Bike_Type_Taxonomy::TAXONOMY;

		$subtypes = self::legacy_terms( self::LEGACY_TYPE );
		$usages   = self::legacy_terms( self::LEGACY_USAGE );

		if ( ! $subtypes && ! $usages ) {
			return;
		}

		// --- 1. Usages → top-level types. -----------------------------------

		$subtype_slugs = wp_list_pluck( $subtypes, 'slug' );
		$type_by_slug  = array(); // Old usage slug → new type term ID.

		// In display order, so when a subtype was listed under two usages and
		// has no override, the one shown first wins.
		usort( $usages, array( 'Citykleta_Bike_Type_Taxonomy', 'compare_order' ) );

		foreach ( $usages as $usage ) {
			$slug = $usage->slug;

			// One taxonomy means one slug space: the usage "Carretera" was
			// `road`, and so is the subtype "Road" — which must keep it, since
			// it's the slug the API sends. The type gives way.
			if ( in_array( $slug, $subtype_slugs, true ) ) {
				$slug = sanitize_title( $usage->name );
			}

			if ( in_array( $slug, $subtype_slugs, true ) ) {
				$slug .= '-type';
			}

			// An older bike_usage save baked "Bike types: X, Y" onto the end of
			// the description. The search dropdown appends the same list live
			// ("Inc. X, Y"), so carrying the tail over would show it twice.
			$description = trim( preg_replace( '/\s*Bike types:.*$/s', '', (string) $usage->description ) );

			$type_id = self::upsert( $tax, $usage->name, $slug, 0, $description );

			if ( ! $type_id ) {
				continue;
			}

			update_term_meta(
				$type_id,
				Citykleta_Bike_Type_Taxonomy::META_KEY_ORDER,
				(int) get_term_meta( $usage->term_id, Citykleta_Bike_Type_Taxonomy::META_KEY_ORDER, true )
			);

			$type_by_slug[ $usage->slug ] = $type_id;
		}

		// --- 2. Which type each subtype goes under. -------------------------

		$parent_of = array(); // Old subtype term ID → new type term ID.

		foreach ( $usages as $usage ) {
			if ( empty( $type_by_slug[ $usage->slug ] ) ) {
				continue;
			}

			$listed = get_term_meta( $usage->term_id, self::LEGACY_USAGE_TYPES_META, true );

			foreach ( is_array( $listed ) ? $listed : array() as $subtype_id ) {
				if ( ! isset( $parent_of[ (int) $subtype_id ] ) ) {
					$parent_of[ (int) $subtype_id ] = $type_by_slug[ $usage->slug ];
				}
			}
		}

		foreach ( $subtypes as $subtype ) {
			if ( isset( self::PARENT_OVERRIDES[ $subtype->slug ] ) ) {
				$usage_slug = self::PARENT_OVERRIDES[ $subtype->slug ];

				if ( ! empty( $type_by_slug[ $usage_slug ] ) ) {
					$parent_of[ $subtype->term_id ] = $type_by_slug[ $usage_slug ];
				}
			}
		}

		// --- 3. Old types → subtypes (or tags). -----------------------------

		$new_id  = array(); // Old subtype term ID → new subtype ID.
		$tag_for = array(); // Old subtype term ID → tag name.

		foreach ( $subtypes as $subtype ) {
			if ( isset( self::BECOME_TAGS[ $subtype->slug ] ) ) {
				$tag_for[ $subtype->term_id ] = self::BECOME_TAGS[ $subtype->slug ];
				wp_insert_term( self::BECOME_TAGS[ $subtype->slug ], Citykleta_Bike_Tag_Taxonomy::TAXONOMY );
				continue;
			}

			$parent = isset( $parent_of[ $subtype->term_id ] )
				? $parent_of[ $subtype->term_id ]
				: Citykleta_Bike_Type_Taxonomy::unclassified_id();

			$child_id = self::upsert( $tax, $subtype->name, $subtype->slug, $parent, $subtype->description );

			if ( ! $child_id ) {
				continue;
			}

			$active = get_term_meta( $subtype->term_id, Citykleta_Bike_Type_Sync::ACTIVE_META_KEY, true );

			if ( '' !== $active ) {
				update_term_meta( $child_id, Citykleta_Bike_Type_Sync::ACTIVE_META_KEY, $active );
			}

			$new_id[ $subtype->term_id ] = $child_id;
		}

		// --- 4. Every bike. -------------------------------------------------

		$bike_ids = get_posts(
			array(
				'post_type'      => Citykleta_Bike_Post_Type::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		foreach ( $bike_ids as $bike_id ) {
			$old = wp_get_object_terms( $bike_id, self::LEGACY_TYPE, array( 'fields' => 'ids' ) );
			$old = is_wp_error( $old ) ? array() : array_map( 'intval', $old );

			$assigned = array();
			$tags     = array();

			foreach ( $old as $old_id ) {
				if ( isset( $new_id[ $old_id ] ) ) {
					$assigned[] = $new_id[ $old_id ];
				} elseif ( isset( $tag_for[ $old_id ] ) ) {
					$tags[] = $tag_for[ $old_id ];
				}
			}

			if ( '1' === (string) get_post_meta( $bike_id, 'is_electric', true ) ) {
				$tags[] = Citykleta_Bike_Tag_Taxonomy::ELECTRIC;
			}

			if ( $assigned ) {
				wp_set_object_terms( $bike_id, $assigned, $tax );
			}

			if ( $tags ) {
				wp_set_object_terms( $bike_id, array_unique( $tags ), Citykleta_Bike_Tag_Taxonomy::TAXONOMY );
			}
		}
	}

	/**
	 * Registers the legacy taxonomies just enough to read them — no UI, no
	 * URLs.
	 */
	private static function register_legacy() {
		foreach ( array( self::LEGACY_TYPE, self::LEGACY_USAGE ) as $taxonomy ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			register_taxonomy(
				$taxonomy,
				Citykleta_Bike_Post_Type::POST_TYPE,
				array(
					'public'       => false,
					'show_ui'      => false,
					'show_in_rest' => false,
					'rewrite'      => false,
				)
			);
		}
	}

	/**
	 * All terms of a legacy taxonomy.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return WP_Term[]
	 */
	private static function legacy_terms( $taxonomy ) {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			)
		);

		return is_wp_error( $terms ) ? array() : $terms;
	}

	/**
	 * Finds a term by slug or creates it. An existing one is left as it is —
	 * parent included — so re-running after a partial failure never moves a
	 * subtype someone already placed by hand.
	 *
	 * @param string $taxonomy    Taxonomy.
	 * @param string $name        Term name.
	 * @param string $slug        Term slug.
	 * @param int    $parent      Parent term ID (0 for none).
	 * @param string $description Description.
	 * @return int Term ID, or 0 on failure.
	 */
	private static function upsert( $taxonomy, $name, $slug, $parent, $description ) {
		$existing = get_term_by( 'slug', $slug, $taxonomy );

		if ( $existing ) {
			return (int) $existing->term_id;
		}

		$inserted = wp_insert_term(
			$name,
			$taxonomy,
			array(
				'slug'        => $slug,
				'parent'      => (int) $parent,
				'description' => (string) $description,
			)
		);

		return is_wp_error( $inserted ) ? 0 : (int) $inserted['term_id'];
	}
}
