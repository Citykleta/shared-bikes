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
 * This class also owns the "Citykleta Stats" wp-admin dashboard widget. Besides
 * the search tables it renders the bike-request funnel on top (Views -> Requests
 * -> Confirmed), reading site views from Citykleta_View_Stats::site_total() and
 * the request counts from Citykleta_Bike_Request — this class only lays them
 * out, each module still owns its own data.
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
	 * Connects the class to WordPress hooks.
	 */
	public function register() {
		add_action( 'wp', array( $this, 'record' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'add_dashboard_widget' ) );
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
	 * Adds the "Citykleta Stats" widget to the wp-admin dashboard. Same
	 * capability the old "Stats" page required, since this is still the same
	 * data (search terms, bike-request funnel).
	 */
	public function add_dashboard_widget() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'citykleta_search_stats',
			'Citykleta Stats',
			array( $this, 'render_widget' )
		);
	}

	/**
	 * Renders the whole widget: the bike-request funnel on top (the site's
	 * conversion), then the search tables below.
	 */
	public function render_widget() {
		// Tables run edge to edge of the box (cancelling its 12px side padding)
		// with light borders, like core's own widgets (e.g. the 404 log).
		echo '<style>
			#citykleta_search_stats table.widefat { width: calc(100% + 24px) !important; max-width: none; margin-left: -12px; margin-right: -12px; border-width: 1px 0; border-color: #e5e5e5; box-shadow: none; }
			#citykleta_search_stats table.widefat th, #citykleta_search_stats table.widefat td { border-color: #f0f0f1; padding: 8px 12px; }
			#citykleta_search_stats h3, #citykleta_search_stats h4 { font-weight: 600; }
			#citykleta_search_stats table.widefat thead th { border-bottom-color: #e5e5e5; }
		</style>';

		$this->render_requests();

		echo '<hr style="margin:1.5em 0;border:0;border-top:1px solid #dcdcde;" />';

		$this->render_searches();
	}

	/**
	 * Renders the bike-request section: the Views -> Requests -> Confirmed
	 * funnel, the requests still waiting on someone, and the last 30 days of attempts.
	 * "Confirmed" is set by hand on each request in wp-admin, so it is the
	 * closest thing WP has to a real rental.
	 */
	private function render_requests() {
		$views     = Citykleta_View_Stats::site_total();
		$requests  = Citykleta_Bike_Request::count();
		$confirmed = Citykleta_Bike_Request::count( Citykleta_Bike_Request::STATUS_CONFIRMED );

		// With no base yet (0 views / 0 requests) the rate is shown as 0%, not a
		// dash — the user prefers always seeing a number.
		$view_to_request    = $views > 0 ? (int) round( $requests / $views * 100 ) : 0;
		$request_to_confirm = $requests > 0 ? (int) round( $confirmed / $requests * 100 ) : 0;

		echo '<h3 style="margin-top:0;">Bike requests <span style="font-weight:400;color:#646970;">(total)</span></h3>';

		echo '<div style="display:flex;flex-wrap:wrap;gap:8px;margin:12px 0 20px;">';
		echo self::funnel_step( 'Vistas de bicis', $views, false, $view_to_request ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		echo self::funnel_step( 'Solicitudes', $requests, false, $request_to_confirm ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::funnel_step( 'Confirmadas', $confirmed, true, null ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';

		$list_url = admin_url( 'edit.php?post_type=' . Citykleta_Bike_Request::POST_TYPE );
		$no_phone = self::bikes_without_phone();

		echo '<div style="display:flex;gap:12px;margin-bottom:12px;">';
		echo self::stat_card( 'Nuevas sin atender', (string) Citykleta_Bike_Request::count( Citykleta_Bike_Request::STATUS_NEW ), add_query_arg( 'request_status', Citykleta_Bike_Request::STATUS_NEW, $list_url ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::stat_card( 'Contactadas', (string) Citykleta_Bike_Request::count( Citykleta_Bike_Request::STATUS_CONTACTED ), add_query_arg( 'request_status', Citykleta_Bike_Request::STATUS_CONTACTED, $list_url ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::stat_card( 'Bicis sin teléfono', $no_phone['missing'] . ' de ' . $no_phone['total'], admin_url( 'edit.php?post_type=' . Citykleta_Bike_Post_Type::POST_TYPE . '&citykleta_phone=missing' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';

		echo '<p style="margin:0 0 24px;"><a href="' . esc_url( $list_url ) . '">See all requests &rarr;</a></p>';

		$this->render_attempts();
	}

	/**
	 * Renders every request attempt of the last $days days, split into the ones
	 * that were stored and the ones that failed, with the failures broken down
	 * by reason. Bots caught by the honeypot are shown apart and never count as
	 * a failed attempt.
	 *
	 * @param int $days How many days back to consider.
	 */
	private function render_attempts( $days = 30 ) {
		$totals  = Citykleta_Bike_Request::attempt_totals( $days );
		$reasons = array();
		$failed  = 0;

		foreach ( Citykleta_Bike_Request::failure_outcomes() as $outcome => $label ) {
			if ( ! empty( $totals[ $outcome ] ) ) {
				$reasons[ $label ] = (int) $totals[ $outcome ];
				$failed           += (int) $totals[ $outcome ];
			}
		}

		$saved    = isset( $totals[ Citykleta_Bike_Request::OUTCOME_SAVED ] ) ? (int) $totals[ Citykleta_Bike_Request::OUTCOME_SAVED ] : 0;
		$spam     = isset( $totals[ Citykleta_Bike_Request::OUTCOME_SPAM ] ) ? (int) $totals[ Citykleta_Bike_Request::OUTCOME_SPAM ] : 0;
		$attempts = $saved + $failed;
		$rate     = $attempts > 0 ? (int) round( $saved / $attempts * 100 ) : 0;

		echo '<h4 style="margin:0 0 8px;">Pedidos (últimos ' . (int) $days . ' días)</h4>';

		echo '<div style="display:flex;gap:12px;margin-bottom:12px;">';
		echo self::stat_card( 'Exitosos', (string) $saved ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::stat_card( 'Fallidos', (string) $failed ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::stat_card( 'Tasa de éxito', $rate . '%' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';

		if ( empty( $reasons ) ) {
			echo '<p style="color:#646970;margin:0 0 24px;">No failed attempts.</p>';
		} else {
			arsort( $reasons );

			echo '<table class="widefat striped" style="width:100%;margin-bottom:8px;">';
			echo '<thead><tr><th>Motivo del fallo</th><th>Intentos</th></tr></thead><tbody>';

			foreach ( $reasons as $label => $count ) {
				echo '<tr><td>' . esc_html( $label ) . '</td><td>' . esc_html( (string) $count ) . '</td></tr>';
			}

			echo '</tbody></table>';
			echo '<p style="color:#646970;font-size:12px;margin:0 0 24px;">"Sin nombre" and "Correo inválido" stop the visitor in the form. The other reasons happen after WhatsApp has already opened: the visitor reached the owner, but no request was stored.</p>';
		}

		if ( $spam > 0 ) {
			echo '<p style="color:#646970;font-size:12px;margin:-16px 0 24px;">Bots blocked by the honeypot: ' . (int) $spam . ' (not counted above).</p>';
		}
	}

	/**
	 * One funnel box. When $next_pct is given, the conversion rate to the
	 * following step is shown inside this same box instead of a separate
	 * arrow column — narrow layouts (like the dashboard widget) don't have
	 * room for a third flex item per step just for an arrow.
	 *
	 * @param string   $label    Box label.
	 * @param int      $value    Number to show.
	 * @param bool     $accent   Whether this is the highlighted (final) step.
	 * @param int|null $next_pct Conversion percentage to the next step, or null for the last step.
	 * @return string HTML.
	 */
	private static function funnel_step( $label, $value, $accent, $next_pct ) {
		$bg  = $accent ? '#f0f6fc' : '#fff';
		$num = $accent ? '#0a4b78' : '#1d2327';
		$lbl = $accent ? '#0a4b78' : '#646970';

		$conversion = null !== $next_pct
			? '<div style="font-size:12px;color:#8c8f94;margin-top:4px;">&rarr; ' . esc_html( $next_pct . '%' ) . '</div>'
			: '';

		return '<div style="flex:1;min-width:110px;background:' . $bg . ';border:1px solid #dcdcde;border-radius:12px;padding:16px;text-align:center;">'
			. '<div style="font-size:13px;color:' . $lbl . ';">' . esc_html( $label ) . '</div>'
			. '<div style="font-size:24px;font-weight:600;color:' . $num . ';">' . esc_html( number_format_i18n( $value ) ) . '</div>'
			. $conversion // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
			. '</div>';
	}

	/**
	 * A small support stat card.
	 *
	 * @param string $label Card label.
	 * @param string $value Value to show (already formatted).
	 * @return string HTML.
	 */
	private static function stat_card( $label, $value, $url = '' ) {
		$card = '<div style="background:#f6f7f7;border:1px solid #dcdcde;border-radius:12px;padding:16px;">'
			. '<div style="font-size:13px;color:#646970;">' . esc_html( $label ) . '</div>'
			. '<div style="font-size:24px;font-weight:600;color:#1d2327;margin-top:2px;">' . esc_html( $value ) . '</div>'
			. '</div>';

		// The whole card is the link when there is somewhere to drill into.
		if ( '' === $url ) {
			return '<div style="flex:1;">' . $card . '</div>';
		}

		return '<a href="' . esc_url( $url ) . '" style="flex:1;text-decoration:none;display:block;">' . $card . '</a>';
	}

	/**
	 * How many published bikes have no usable owner phone, i.e. cannot be
	 * contacted through the WhatsApp button.
	 *
	 * @return array{missing:int,total:int}
	 */
	private static function bikes_without_phone() {
		$ids = get_posts(
			array(
				'post_type'      => Citykleta_Bike_Post_Type::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		$missing = 0;

		foreach ( $ids as $id ) {
			if ( '' === Citykleta_Bike_Request_Form::owner_phone( $id ) ) {
				++$missing;
			}
		}

		return array(
			'missing' => $missing,
			'total'   => count( $ids ),
		);
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

		echo '<h3>Searches</h3>';

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

		echo '<h4>Most searched locations</h4>';
		self::render_table( array_slice( $by_count, 0, 10, true ), false );

		echo '<h4 style="margin-top:2em;">Searched but not found (supply gaps)</h4>';
		if ( empty( $gaps ) ) {
			echo '<p>No zero-result searches yet — every search matched at least one bike.</p>';
		} else {
			self::render_table( array_slice( $gaps, 0, 10, true ), true );
		}
	}

	/**
	 * Renders one stats table.
	 *
	 * @param array $rows          Term => data, already sorted/sliced.
	 * @param bool  $highlight_gap Whether to lead with the zero-result count.
	 */
	private static function render_table( array $rows, $highlight_gap ) {
		echo '<table class="widefat striped" style="width:100%;">';
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
