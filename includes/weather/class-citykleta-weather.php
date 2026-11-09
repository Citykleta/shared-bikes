<?php
/**
 * Daily weather forecast for a point, from Open-Meteo, cached on the server.
 *
 * Fetched by the server and not by the visitor's browser on purpose: one
 * request per place every few hours, however many people open the page —
 * which matters on Cuban connections, where every outgoing call is slow and
 * may simply fail.
 *
 * Open-Meteo is free, needs no key or account, and asks for attribution
 * (CC BY 4.0) — Citykleta_Weather::CREDIT_URL, printed under the forecast.
 *
 * Two caches, so a dead connection never leaves the page empty:
 *   - fresh  (FRESH_TTL): served as-is, no request at all.
 *   - stale  (STALE_TTL): last good forecast, served when a refresh fails.
 * A failed refresh is also remembered for RETRY_AFTER, so an outage costs one
 * slow request every few minutes instead of one per page view.
 *
 * @package Citykleta_Shared_Bikes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Citykleta_Weather.
 */
class Citykleta_Weather {

	const API_URL     = 'https://api.open-meteo.com/v1/forecast';
	const CREDIT_URL  = 'https://open-meteo.com/';
	const TIMEZONE    = 'America/Havana';
	const MAX_DAYS    = 7;
	const FRESH_TTL   = 3 * HOUR_IN_SECONDS;
	const STALE_TTL   = 2 * DAY_IN_SECONDS;
	const RETRY_AFTER = 15 * MINUTE_IN_SECONDS;
	const TIMEOUT     = 4;

	/**
	 * Daily forecast for a point.
	 *
	 * Always asks Open-Meteo for MAX_DAYS and trims afterwards, so every widget
	 * on the same place shares one cache entry whatever its "days" setting.
	 *
	 * @param float $lat  Latitude.
	 * @param float $lng  Longitude.
	 * @param int   $days How many days, today first.
	 * @return array List of days: date (Y-m-d), code, max, min, rain (% or
	 *               null), uv (or null). Empty when there is nothing to show.
	 */
	public static function daily( $lat, $lng, $days = 3 ) {
		$days = max( 1, min( self::MAX_DAYS, (int) $days ) );
		$key  = self::cache_key( $lat, $lng );

		$fresh = get_transient( $key );
		if ( is_array( $fresh ) ) {
			return self::from_today( $fresh, $days );
		}

		$stale = get_transient( $key . '_stale' );

		if ( ! get_transient( $key . '_failed' ) ) {
			$fetched = self::fetch( $lat, $lng );

			if ( $fetched ) {
				set_transient( $key, $fetched, self::FRESH_TTL );
				set_transient( $key . '_stale', $fetched, self::STALE_TTL );
				return self::from_today( $fetched, $days );
			}

			set_transient( $key . '_failed', 1, self::RETRY_AFTER );
		}

		return is_array( $stale ) ? self::from_today( $stale, $days ) : array();
	}

	/**
	 * One cache entry per ~1 km cell: two decimals is plenty for weather, and
	 * nearby destinations end up sharing a request.
	 *
	 * @param float $lat Latitude.
	 * @param float $lng Longitude.
	 * @return string
	 */
	private static function cache_key( $lat, $lng ) {
		return 'citykleta_weather_' . md5( sprintf( '%.2f,%.2f', $lat, $lng ) );
	}

	/**
	 * Drops days already past (a stale copy can be up to two days old) and
	 * keeps the first $days.
	 *
	 * @param array $forecast Cached days.
	 * @param int   $days     How many to keep.
	 * @return array
	 */
	private static function from_today( array $forecast, $days ) {
		$today = wp_date( 'Y-m-d', null, new DateTimeZone( self::TIMEZONE ) );

		$upcoming = array_filter(
			$forecast,
			function ( $day ) use ( $today ) {
				return $day['date'] >= $today;
			}
		);

		return array_slice( array_values( $upcoming ), 0, $days );
	}

	/**
	 * Asks Open-Meteo and normalises its column-per-field answer into one row
	 * per day.
	 *
	 * @param float $lat Latitude.
	 * @param float $lng Longitude.
	 * @return array Days, or an empty array on any failure.
	 */
	private static function fetch( $lat, $lng ) {
		$url = add_query_arg(
			array(
				'latitude'      => sprintf( '%.4f', $lat ),
				'longitude'     => sprintf( '%.4f', $lng ),
				'daily'         => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max,uv_index_max',
				'timezone'      => self::TIMEZONE,
				'forecast_days' => self::MAX_DAYS,
			),
			self::API_URL
		);

		$response = wp_remote_get( $url, array( 'timeout' => self::TIMEOUT ) );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		$body  = json_decode( wp_remote_retrieve_body( $response ), true );
		$daily = isset( $body['daily'] ) && is_array( $body['daily'] ) ? $body['daily'] : array();

		if ( empty( $daily['time'] ) || ! is_array( $daily['time'] ) ) {
			return array();
		}

		$column = function ( $name, $i ) use ( $daily ) {
			return isset( $daily[ $name ][ $i ] ) && is_numeric( $daily[ $name ][ $i ] ) ? (float) $daily[ $name ][ $i ] : null;
		};

		$days = array();

		foreach ( $daily['time'] as $i => $date ) {
			$max = $column( 'temperature_2m_max', $i );
			$min = $column( 'temperature_2m_min', $i );

			// A day without temperatures has nothing worth printing.
			if ( null === $max || null === $min || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $date ) ) {
				continue;
			}

			$code = $column( 'weather_code', $i );

			$days[] = array(
				'date' => $date,
				'code' => null === $code ? null : (int) $code,
				'max'  => $max,
				'min'  => $min,
				'rain' => $column( 'precipitation_probability_max', $i ),
				'uv'   => $column( 'uv_index_max', $i ),
			);
		}

		return $days;
	}

	/**
	 * Spanish label and emoji for a WMO weather code (the scheme Open-Meteo
	 * uses). Emoji instead of icon files: nothing to ship or download.
	 *
	 * @param int|null $code WMO code.
	 * @return array { label: string, icon: string }
	 */
	public static function describe( $code ) {
		$map = array(
			0  => array( 'Despejado', '☀️' ),
			1  => array( 'Mayormente despejado', '🌤️' ),
			2  => array( 'Parcialmente nublado', '⛅' ),
			3  => array( 'Nublado', '☁️' ),
			45 => array( 'Niebla', '🌫️' ),
			48 => array( 'Niebla', '🌫️' ),
			51 => array( 'Llovizna', '🌦️' ),
			53 => array( 'Llovizna', '🌦️' ),
			55 => array( 'Llovizna', '🌦️' ),
			61 => array( 'Lluvia', '🌧️' ),
			63 => array( 'Lluvia', '🌧️' ),
			65 => array( 'Lluvia fuerte', '🌧️' ),
			80 => array( 'Chubascos', '🌦️' ),
			81 => array( 'Chubascos', '🌧️' ),
			82 => array( 'Chubascos fuertes', '🌧️' ),
			95 => array( 'Tormenta', '⛈️' ),
			96 => array( 'Tormenta', '⛈️' ),
			99 => array( 'Tormenta', '⛈️' ),
		);

		$entry = isset( $map[ $code ] ) ? $map[ $code ] : array( '', '' );

		return array(
			'label' => $entry[0],
			'icon'  => $entry[1],
		);
	}
}
