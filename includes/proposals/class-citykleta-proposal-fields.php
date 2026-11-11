<?php
/**
 * Turns what the owner form sends into named fields.
 *
 * Elementor's Atomic Form Webhook posts every field under the TEXT OF ITS LABEL
 * ("First name", "Número de serie"), or under the field's ID when no label is
 * linked to it (checked with the Webhook probe; see docs/plan-despliegue.md,
 * section 2 C, in the citykleta-sharing-api repository). So the label a person
 * edits in Elementor IS the key that arrives. This class matches those keys
 * against a list of accepted spellings (no accents, no case, no punctuation) and
 * keeps whatever it does not recognise in `extra`: a renamed label never loses
 * data, it only shows up as "missing" for the field it used to fill.
 *
 * Photos are found by VALUE, not by key: any value holding the URL of a file in
 * uploads/elementor/forms/ is a photo, whatever the file field is called.
 *
 * @package Citykleta_Shared_Bikes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Citykleta_Proposal_Fields.
 */
class Citykleta_Proposal_Fields {

	/**
	 * Keys Elementor adds to every submission. They are not form fields.
	 */
	const SYSTEM_KEYS = array( 'form_id', 'form_name', 'post_id', 'site_url', 'timestamp' );

	/**
	 * A complete proposal has at least this many photos (BUSINESS-PRD.md, "Fotos
	 * de las bicis": 3 to 5).
	 */
	const MIN_PHOTOS = 3;

	/**
	 * Accepted spellings of each field, already slugged (see self::slug()). A
	 * trailing * matches any ending, for labels that are a whole sentence
	 * ("Acepto los Términos y Condiciones"). Filterable, so a label can be added
	 * without touching this file.
	 *
	 * @return array<string,array<int,string>>
	 */
	public static function aliases() {
		return apply_filters(
			'citykleta_proposal_field_aliases',
			array(
				'first_name'   => array( 'nombre', 'nombres', 'nombre_completo', 'name', 'first_name' ),
				'last_name'    => array( 'apellido*', 'last_name' ),
				'email'        => array( 'correo*', 'email*', 'e_mail*' ),
				'whatsapp'     => array( 'whatsapp*', 'telefono*', 'tel', 'phone*', 'celular*', 'movil*' ),
				'bike_type'    => array( 'tipo*', 'type', 'bike_type' ),
				'brand'        => array( 'marca*', 'brand*' ),
				'model'        => array( 'modelo*', 'model*' ),
				'size'         => array( 'talla*', 'size*' ),
				'wheel_size'   => array( 'tamano_de_rueda*', 'rueda*', 'wheel_size*' ),
				'description'  => array( 'descripcion*', 'description*', 'mensaje*', 'message*' ),
				'serial_no'    => array( 'numero_de_serie*', 'serie*', 'serial*' ),
				'lat'          => array( 'lat', 'latitud*', 'latitude*' ),
				'long'         => array( 'long', 'lng', 'longitud*', 'longitude*' ),
				'neighborhood' => array( 'barrio*', 'neighborhood*' ),
				'address'      => array( 'referencia*', 'direccion*', 'address*' ),
				'daily_rate'   => array( 'precio*', 'price*', 'daily_rate*' ),
				'consent'      => array( 'consentimiento*', 'consent*', 'acepto*', 'accept*' ),
			)
		);
	}

	/**
	 * Fields a complete proposal must have. The app's own rules (BUSINESS-PRD.md,
	 * "Publicar una bici") ask for a type, a price, a description and a location;
	 * the serial number is optional on purpose.
	 *
	 * @return array<int,string>
	 */
	public static function required() {
		return apply_filters(
			'citykleta_proposal_required_fields',
			array( 'first_name', 'email', 'whatsapp', 'bike_type', 'description', 'daily_rate', 'lat', 'long', 'address', 'consent' )
		);
	}

	/**
	 * "Número de serie (opcional)" -> "numero_de_serie_opcional".
	 *
	 * @param string $key A field key as it arrived.
	 * @return string
	 */
	public static function slug( $key ) {
		$key = strtolower( remove_accents( (string) $key ) );

		return trim( (string) preg_replace( '/[^a-z0-9]+/', '_', $key ), '_' );
	}

	/**
	 * Which field a key fills, or '' when it is not recognised.
	 *
	 * @param string $key A field key as it arrived.
	 * @return string
	 */
	public static function canonical_for( $key ) {
		$slug = self::slug( $key );

		if ( '' === $slug ) {
			return '';
		}

		foreach ( self::aliases() as $canonical => $spellings ) {
			foreach ( $spellings as $spelling ) {
				if ( '*' === substr( $spelling, -1 ) ) {
					if ( 0 === strpos( $slug, rtrim( $spelling, '*' ) ) ) {
						return $canonical;
					}
				} elseif ( $slug === $spelling ) {
					return $canonical;
				}
			}
		}

		return '';
	}

	/**
	 * Reads an application/x-www-form-urlencoded body keeping the keys exactly as
	 * sent. PHP's own parser turns spaces and dots in a key into underscores
	 * ("First name" -> "First_name"), which would hide the label; this does not.
	 * `key[0]=a&key[1]=b` becomes a list.
	 *
	 * @param string $raw The raw request body.
	 * @return array<string,string|array<int,string>>
	 */
	public static function parse_raw_body( $raw ) {
		$out = array();

		foreach ( explode( '&', (string) $raw ) as $pair ) {
			if ( '' === $pair ) {
				continue;
			}

			$parts = explode( '=', $pair, 2 );
			$key   = urldecode( $parts[0] );
			$value = isset( $parts[1] ) ? urldecode( $parts[1] ) : '';

			if ( preg_match( '/^(.*)\[\d*\]$/s', $key, $match ) ) {
				if ( ! isset( $out[ $match[1] ] ) || ! is_array( $out[ $match[1] ] ) ) {
					$out[ $match[1] ] = array();
				}
				$out[ $match[1] ][] = $value;
			} else {
				$out[ $key ] = $value;
			}
		}

		return $out;
	}

	/**
	 * Relative paths of every proposal photo found in a value (a URL, or a list
	 * of them).
	 *
	 * @param mixed $value A field value.
	 * @return array<int,string>
	 */
	private static function photo_paths( $value ) {
		$paths  = array();
		$values = is_array( $value ) ? $value : array( $value );

		foreach ( $values as $item ) {
			if ( ! is_string( $item ) || false === stripos( $item, 'http' ) ) {
				continue;
			}

			if ( preg_match_all( '#https?://[^\s,"\'<>]+#i', $item, $matches ) ) {
				foreach ( $matches[0] as $url ) {
					$relative = Citykleta_Bike_Proposal::relative_upload_path( $url );

					if ( '' !== $relative ) {
						$paths[] = $relative;
					}
				}
			}
		}

		return $paths;
	}

	/**
	 * Sorts what arrived into named fields, tags, photos and the rest.
	 *
	 * An unticked checkbox is not sent at all, so a ticked one that is not the
	 * consent box is read as a tag ("Eléctrica", "Tándem"), kept by its label.
	 *
	 * @param array<string,mixed> $raw Fields as sent (see parse_raw_body()).
	 * @return array{fields:array<string,string>,tags:array<int,string>,extra:array<string,mixed>,photos:array<int,string>,system:array<string,string>}
	 */
	public static function normalize( array $raw ) {
		$out = array(
			'fields' => array(),
			'tags'   => array(),
			'extra'  => array(),
			'photos' => array(),
			'system' => array(),
		);

		foreach ( $raw as $key => $value ) {
			$key = (string) $key;

			if ( in_array( $key, self::SYSTEM_KEYS, true ) ) {
				$out['system'][ $key ] = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
				continue;
			}

			$photos = self::photo_paths( $value );

			if ( $photos ) {
				$out['photos'] = array_merge( $out['photos'], $photos );
				continue;
			}

			if ( is_array( $value ) ) {
				$out['extra'][ sanitize_text_field( $key ) ] = array_map( 'sanitize_text_field', array_map( 'strval', $value ) );
				continue;
			}

			$value     = trim( (string) $value );
			$canonical = self::canonical_for( $key );

			if ( 'consent' === $canonical ) {
				$out['fields']['consent'] = ( '' !== $value && 'off' !== strtolower( $value ) ) ? '1' : '';
			} elseif ( '' !== $canonical ) {
				if ( '' !== $value && ! isset( $out['fields'][ $canonical ] ) ) {
					$out['fields'][ $canonical ] = self::clean( $canonical, $value );
				}
			} elseif ( 'on' === strtolower( $value ) ) {
				$out['tags'][] = sanitize_text_field( $key );
			} elseif ( '' !== $value ) {
				$out['extra'][ sanitize_text_field( $key ) ] = sanitize_textarea_field( $value );
			}
		}

		$out['photos'] = array_values( array_unique( $out['photos'] ) );

		return $out;
	}

	/**
	 * Sanitises one value by the kind of field it fills.
	 *
	 * @param string $canonical The field it fills.
	 * @param string $value     The value.
	 * @return string
	 */
	private static function clean( $canonical, $value ) {
		if ( 'email' === $canonical ) {
			return sanitize_email( $value );
		}

		if ( 'description' === $canonical ) {
			return sanitize_textarea_field( $value );
		}

		return sanitize_text_field( $value );
	}

	/**
	 * What a complete proposal still lacks: the names of the empty required
	 * fields, plus "photos" when there are fewer than MIN_PHOTOS.
	 *
	 * @param array $normalized The result of normalize().
	 * @return array<int,string>
	 */
	public static function missing( array $normalized ) {
		$missing = array();

		foreach ( self::required() as $name ) {
			if ( ! isset( $normalized['fields'][ $name ] ) || '' === $normalized['fields'][ $name ] ) {
				$missing[] = $name;
			}
		}

		if ( count( $normalized['photos'] ) < self::MIN_PHOTOS ) {
			$missing[] = 'photos';
		}

		return $missing;
	}
}
