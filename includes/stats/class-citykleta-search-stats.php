<?php
/**
 * Records what visitors search for on the bike archive, to surface demand
 * (which locations people look for) and — more usefully — supply gaps (searches
 * that returned no bikes).
 *
 * Like the view counter (Citykleta_View_Stats), this AGGREGATES rather than
 * logging every event: a single option (self::OPTION) keyed by a normalized
 * search term, each holding a total count, a zero-result count, a display
 * label and the last-seen date. One small option instead of a row per search,
 * so it can't balloon the database — capped at self::MAX_TERMS distinct terms
 * (the least-searched is pruned to make room for a new one).
 *
 * Capture is server-side, on the archive's main query, so the result count
 * (needed to tell a zero-result search apart) is already known. Only the first
 * page is counted, so the infinite-scroll fetches for pages 2+ don't inflate a
 * single search. A stray bot hitting the URL with ?location= is counted too;
 * if that noise ever matters, move to a browser beacon like the view counter.
 *
 * This class also owns the "Stats" admin page under Shared Bikes. Besides the
 * search tables it renders the app-download funnel on top (Views -> Sign-ups ->
 * Downloads), reading site views from Citykleta_View_Stats::site_total() and the
 * sign-up/download aggregates from Citykleta_Subscriber — this class only lays
 * them out, each module still owns its own data.
 *
 * @package Citykleta_Shared_Bikes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Citykleta_Search_Stats.
 */
class Citykleta_Search_Stats {

	/**
	 * Option holding the aggregated search stats.
	 */
	const OPTION = 'citykleta_search_stats';

	/**
	 * Max distinct terms kept before the least-searched is pruned.
	 */
	const MAX_TERMS = 500;

	/**
	 * Admin page slug.
	 */
	const PAGE_SLUG = 'citykleta-searches';

	/**
	 * Connects the class to WordPress hooks.
	 */
	public function register() {
		add_action( 'wp', array( $this, 'record' ) );
		add_action( 'admin_menu', array( $this, 'add_page' ) );
	}

	/**
	 * Records a search when the bike archive is shown with a location term.
	 * Runs on 'wp', by which point the main query has executed, so
	 * found_posts is available to flag zero-result searches.
	 */
	public function record() {
		if ( is_admin() || ! is_post_type_archive( Citykleta_Bike_Post_Type::POST_TYPE ) ) {
			return;
		}

		// Only the first page: infinite scroll re-requests the archive for
		// pages 2+, and those aren't new searches.
		if ( is_paged() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, a public search URL has no nonce.
		$raw = isset( $_GET['location'] ) ? sanitize_text_field( wp_unslash( $_GET['location'] ) ) : '';
		$label = trim( preg_replace( '/\s+/', ' ', $raw ) );

		if ( '' === $label ) {
			return;
		}

		$key = self::normalize( $label );

		if ( '' === $key ) {
			return;
		}

		global $wp_query;
		$found   = isset( $wp_query->found_posts ) ? (int) $wp_query->found_posts : 0;
		$is_zero = 0 === $found;

		$this->increment( $key, $label, $is_zero );
	}

	/**
	 * Normalizes a term for grouping: lowercased, accent-stripped, only
	 * letters/numbers/spaces, whitespace collapsed. So "Vedado", "vedado " and
	 * "Vedado." all count as the same location.
	 *
	 * @param string $label Raw (sanitized) search term.
	 * @return string Normalized key (may be empty).
	 */
	public static function normalize( $label ) {
		$key = remove_accents( strtolower( $label ) );
		$key = preg_replace( '/[^a-z0-9 ]/', '', $key );
		$key = trim( preg_replace( '/\s+/', ' ', $key ) );

		return $key;
	}

	/**
	 * Bumps a term's counters and saves the option.
	 *
	 * @param string $key     Normalized term key.
	 * @param string $label   Display label (latest raw term).
	 * @param bool   $is_zero Whether this search returned no bikes.
	 */
	private function increment( $key, $label, $is_zero ) {
		$stats = get_option( self::OPTION, array() );

		if ( ! is_array( $stats ) ) {
			$stats = array();
		}

		if ( isset( $stats[ $key ] ) ) {
			$stats[ $key ]['count'] = (int) $stats[ $key ]['count'] + 1;
			$stats[ $key ]['zero']  = (int) $stats[ $key ]['zero'] + ( $is_zero ? 1 : 0 );
			$stats[ $key ]['label'] = $label;
			$stats[ $key ]['last']  = current_time( 'Y-m-d' );
		} else {
			if ( count( $stats ) >= self::MAX_TERMS ) {
				$stats = self::prune_smallest( $stats );
			}

			$stats[ $key ] = array(
				'count' => 1,
				'zero'  => $is_zero ? 1 : 0,
				'label' => $label,
				'last'  => current_time( 'Y-m-d' ),
			);
		}

		// autoload = false: the option can hold up to MAX_TERMS entries, no need
		// to load it on every page.
		update_option( self::OPTION, $stats, false );
	}

	/**
	 * Drops the least-searched term so a new one can take its place once the
	 * cap is reached.
	 *
	 * @param array $stats Current stats.
	 * @return array
	 */
	private static function prune_smallest( array $stats ) {
		$smallest_key   = null;
		$smallest_count = PHP_INT_MAX;

		foreach ( $stats as $term => $data ) {
			$count = (int) $data['count'];
			if ( $count < $smallest_count ) {
				$smallest_count = $count;
				$smallest_key   = $term;
			}
		}

		if ( null !== $smallest_key ) {
			unset( $stats[ $smallest_key ] );
		}

		return $stats;
	}

	/**
	 * Adds the "Stats" page under the Shared Bikes menu. It currently holds the
	 * search tables and is the intended home for the rest of the funnel stats.
	 */
	public function add_page() {
		add_submenu_page(
			'edit.php?post_type=' . Citykleta_Bike_Post_Type::POST_TYPE,
			'Stats',
			'Stats',
			'edit_posts',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Renders the whole Stats page: the app-download funnel on top (the site's
	 * conversion), then the search tables below.
	 */
	public function render_page() {
		echo '<div class="wrap">';
		echo '<h1>Stats</h1>';

		$this->render_download_wall();

		echo '<hr style="margin:2.5em 0;border:0;border-top:1px solid #dcdcde;" />';

		$this->render_searches();

		echo '</div>';
	}

	/**
	 * Renders the app-download wall section: the Views -> Sign-ups -> Downloads
	 * funnel, a few support numbers, and a per-day breakdown.
	 */
	private function render_download_wall() {
		$views          = Citykleta_View_Stats::site_total();
		$signups        = Citykleta_Subscriber::count_signups();
		$downloads      = Citykleta_Subscriber::count_downloads();
		$pending        = Citykleta_Subscriber::count_pending();
		$not_downloaded = max( 0, $signups - $downloads );

		$view_to_signup = $views > 0 ? (int) round( $signups / $views * 100 ) : null;
		$signup_to_dl   = $signups > 0 ? (int) round( $downloads / $signups * 100 ) : null;

		echo '<h2>App download wall</h2>';

		echo '<div style="display:flex;align-items:stretch;gap:8px;max-width:640px;margin:12px 0 20px;">';
		echo self::funnel_step( 'Vistas del sitio', $views, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		echo self::funnel_arrow( $view_to_signup ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::funnel_step( 'Inscripciones', $signups, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::funnel_arrow( $signup_to_dl ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::funnel_step( 'Descargas', $downloads, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';

		echo '<div style="display:flex;gap:12px;max-width:640px;margin-bottom:24px;">';
		echo self::stat_card( 'Conversión inscrito → descarga', null === $signup_to_dl ? '—' : $signup_to_dl . '%' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::stat_card( 'Sin descargar aún', (string) $not_downloaded ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::stat_card( 'Pendientes de HubSpot', (string) $pending ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';

		$this->render_by_day();
	}

	/**
	 * One funnel box.
	 *
	 * @param string $label  Box label.
	 * @param int    $value  Number to show.
	 * @param bool   $accent Whether this is the highlighted (final) step.
	 * @return string HTML.
	 */
	private static function funnel_step( $label, $value, $accent ) {
		$bg    = $accent ? '#f0f6fc' : '#fff';
		$num   = $accent ? '#0a4b78' : '#1d2327';
		$lbl   = $accent ? '#0a4b78' : '#646970';

		return '<div style="flex:1;background:' . $bg . ';border:1px solid #dcdcde;border-radius:12px;padding:16px;text-align:center;">'
			. '<div style="font-size:13px;color:' . $lbl . ';">' . esc_html( $label ) . '</div>'
			. '<div style="font-size:24px;font-weight:600;color:' . $num . ';">' . esc_html( number_format_i18n( $value ) ) . '</div>'
			. '</div>';
	}

	/**
	 * The arrow between two funnel boxes, with the conversion rate under it.
	 *
	 * @param int|null $pct Conversion percentage, or null if undefined (no base).
	 * @return string HTML.
	 */
	private static function funnel_arrow( $pct ) {
		$text = null === $pct ? '—' : $pct . '%';

		return '<div style="display:flex;flex-direction:column;justify-content:center;align-items:center;min-width:52px;color:#646970;">'
			. '<span style="font-size:18px;line-height:1;">&rarr;</span>'
			. '<span style="font-size:12px;margin-top:2px;">' . esc_html( $text ) . '</span>'
			. '</div>';
	}

	/**
	 * A small support stat card.
	 *
	 * @param string $label Card label.
	 * @param string $value Value to show (already formatted).
	 * @return string HTML.
	 */
	private static function stat_card( $label, $value ) {
		return '<div style="flex:1;background:#f6f7f7;border-radius:8px;padding:16px;">'
			. '<div style="font-size:13px;color:#646970;">' . esc_html( $label ) . '</div>'
			. '<div style="font-size:24px;font-weight:600;color:#1d2327;margin-top:2px;">' . esc_html( $value ) . '</div>'
			. '</div>';
	}

	/**
	 * The per-day table: sign-ups and downloads side by side, newest first.
	 *
	 * @param int $days How many days back to consider.
	 */
	private function render_by_day( $days = 14 ) {
		$signups   = Citykleta_Subscriber::signups_by_day( $days );
		$downloads = Citykleta_Subscriber::downloads_by_day( $days );

		$all_days = array_unique( array_merge( array_keys( $signups ), array_keys( $downloads ) ) );
		rsort( $all_days ); // 'Y-m-d' strings sort chronologically; newest first.

		if ( empty( $all_days ) ) {
			echo '<p style="color:#646970;">No sign-ups yet.</p>';
			return;
		}

		echo '<h3 style="margin:0 0 8px;">Últimos días</h3>';
		echo '<table class="widefat striped" style="max-width:480px;">';
		echo '<thead><tr><th>Día</th><th>Inscripciones</th><th>Descargas</th></tr></thead><tbody>';

		foreach ( $all_days as $day ) {
			$s = isset( $signups[ $day ] ) ? (int) $signups[ $day ] : 0;
			$d = isset( $downloads[ $day ] ) ? (int) $downloads[ $day ] : 0;

			echo '<tr><td>' . esc_html( $day ) . '</td><td>' . esc_html( (string) $s ) . '</td><td>' . esc_html( (string) $d ) . '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Renders the two search tables: most searched, and most searched with no
	 * results.
	 */
	private function render_searches() {
		$stats = get_option( self::OPTION, array() );

		if ( ! is_array( $stats ) ) {
			$stats = array();
		}

		echo '<h2>Searches</h2>';

		if ( empty( $stats ) ) {
			echo '<p>No searches recorded yet. Once visitors search the bike archive by location, they show up here.</p>';
			return;
		}

		// Most searched: by total count.
		$by_count = $stats;
		uasort(
			$by_count,
			function ( $a, $b ) {
				return (int) $b['count'] - (int) $a['count'];
			}
		);

		// Supply gaps: only terms with zero-result searches, by that count.
		$gaps = array_filter(
			$stats,
			function ( $data ) {
				return (int) $data['zero'] > 0;
			}
		);
		uasort(
			$gaps,
			function ( $a, $b ) {
				return (int) $b['zero'] - (int) $a['zero'];
			}
		);

		echo '<h3>Most searched locations</h3>';
		self::render_table( array_slice( $by_count, 0, 50, true ), false );

		echo '<h3 style="margin-top:2em;">Searched but not found (supply gaps)</h3>';
		if ( empty( $gaps ) ) {
			echo '<p>No zero-result searches yet — every search matched at least one bike.</p>';
		} else {
			self::render_table( array_slice( $gaps, 0, 50, true ), true );
		}
	}

	/**
	 * Renders one stats table.
	 *
	 * @param array $rows          Term => data, already sorted/sliced.
	 * @param bool  $highlight_gap Whether to lead with the zero-result count.
	 */
	private static function render_table( array $rows, $highlight_gap ) {
		echo '<table class="widefat striped" style="max-width:640px;">';
		echo '<thead><tr>';
		echo '<th>Location</th>';
		echo '<th>Searches</th>';
		echo '<th>No results</th>';
		echo '<th>Last searched</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $data ) {
			$count = (int) $data['count'];
			$zero  = (int) $data['zero'];
			$label = isset( $data['label'] ) ? $data['label'] : '';
			$last  = isset( $data['last'] ) ? $data['last'] : '';

			// Share of this term's searches that found nothing.
			$zero_share = $count > 0 ? round( $zero / $count * 100 ) : 0;
			$zero_cell  = $zero > 0 ? $zero . ' (' . $zero_share . '%)' : '—';

			echo '<tr>';
			echo '<td>' . esc_html( $label ) . '</td>';
			echo '<td>' . esc_html( (string) $count ) . '</td>';
			echo '<td>' . esc_html( $zero_cell ) . '</td>';
			echo '<td>' . esc_html( $last ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		unset( $highlight_gap );
	}
}
