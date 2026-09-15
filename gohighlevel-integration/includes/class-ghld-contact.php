<?php
/**
 * Turns a raw GoHighLevel contact into the flat shape the templates render.
 *
 * @package GoHighLevel_Integration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Normalizer for API contact payloads.
 *
 * The v1 and v2 APIs differ in small ways (`customField` vs `customFields`,
 * `contactName` vs `name`), so everything downstream — filtering, sorting,
 * templates, REST — works off the normalized array this produces.
 */
class GHLD_Contact {

	/**
	 * Bumped whenever apply_mapping() derives something new.
	 *
	 * It rides along in the mapping hash, so upgrading re-derives every cached
	 * contact on the next page load instead of waiting for a sync.
	 *
	 * @var string
	 */
	const DERIVED_VERSION = '7';

	/**
	 * Normalize one contact.
	 *
	 * @param array $raw       Raw contact from the API.
	 * @param array $field_map Custom field ID => array{key,name,type}.
	 * @param array $settings  Plugin settings (field mapping, gravatar).
	 * @return array
	 */
	public static function normalize( array $raw, array $field_map, array $settings ) {
		$first = self::str( $raw, 'firstName' );
		$last  = self::str( $raw, 'lastName' );
		$name  = trim( $first . ' ' . $last );

		if ( '' === $name ) {
			// `fullNameLowerCase` is GoHighLevel's own lowercased copy of the
			// name, so it comes last — it is a fallback, not a display value.
			foreach ( array( 'contactName', 'name', 'fullNameLowerCase' ) as $key ) {
				$candidate = self::str( $raw, $key );
				if ( '' !== $candidate ) {
					$name = $candidate;
					break;
				}
			}
		}

		$first = self::capitalize_name( $first );
		$last  = self::capitalize_name( $last );
		$name  = self::capitalize_name( $name );

		$custom_data = self::custom_values( $raw, $field_map );
		$custom      = $custom_data['values'];
		$email  = sanitize_email( self::str( $raw, 'email' ) );
		$photo  = '';
		foreach ( array( 'profilePhoto', 'profile_photo', 'avatar', 'photoUrl' ) as $key ) {
			$photo = self::url( self::str( $raw, $key ) );
			if ( '' !== $photo ) {
				break;
			}
		}

		$contact = array(
			'id'            => self::str( $raw, 'id' ),
			'first_name'    => $first,
			'last_name'     => $last,
			'name'          => $name,
			'email'         => is_email( $email ) ? $email : '',
			'phone'         => self::str( $raw, 'phone' ),
			'company_raw'   => self::first_str( $raw, array( 'companyName', 'company', 'businessName' ) ),
			'company'       => '',
			'website'       => self::url( self::str( $raw, 'website' ) ),
			'address'       => self::first_str( $raw, array( 'address1', 'address' ) ),
			'city'          => self::str( $raw, 'city' ),
			'state'         => self::str( $raw, 'state' ),
			'postal_code'   => self::first_str( $raw, array( 'postalCode', 'postal_code' ) ),
			'country'       => self::str( $raw, 'country' ),
			'source'        => self::str( $raw, 'source' ),
			'date_added'    => self::timestamp( self::first_str( $raw, array( 'dateAdded', 'createdAt', 'dateUpdated' ) ) ),
			'tags'          => self::tags( $raw ),
			'custom'        => $custom,
			// URLs that arrived as uploaded files, live ones first. The payload
			// identifies custom fields by ID only, so when the field
			// definitions are unavailable this is the one way left to find an
			// uploaded headshot.
			'file_urls'     => $custom_data['files'],
			'profile_photo' => $photo,
		);

		$contact['initials']  = self::initials( $name );
		$contact['sort_name'] = self::sort_key( $last, $first, $name );
		$contact              = self::apply_mapping( $contact, $settings );

		/**
		 * Filter a normalized contact before it is cached.
		 *
		 * @param array $contact Normalized contact.
		 * @param array $raw     Raw API payload.
		 */
		return apply_filters( 'ghld_normalize_contact', $contact, $raw );
	}

	/**
	 * Join non-empty values into one comma-separated line.
	 *
	 * @param array $values Values to join.
	 * @return string
	 */
	public static function join_values( array $values ) {
		$parts = array();

		foreach ( $values as $value ) {
			$value = trim( (string) $value );
			if ( '' !== $value && ! in_array( $value, $parts, true ) ) {
				$parts[] = $value;
			}
		}

		return implode( ', ', $parts );
	}

	/**
	 * Title-case a name that arrived without any capitals.
	 *
	 * GoHighLevel records are frequently imported all-lowercase, and its own
	 * `fullNameLowerCase` field is lowercase by definition, which renders as
	 * "ayman aboulela" on a card. Names that already carry a capital are left
	 * exactly as they are, so deliberate spellings — DeShawn, McDonald,
	 * van der Berg — are never flattened.
	 *
	 * @param string $name Name as it came from the API.
	 * @return string
	 */
	public static function capitalize_name( $name ) {
		$name = trim( (string) $name );
		if ( '' === $name ) {
			return '';
		}

		// Any existing capital means the casing was intentional.
		if ( preg_match( '/\p{Lu}/u', $name ) ) {
			return $name;
		}

		if ( function_exists( 'mb_convert_case' ) ) {
			// MB_CASE_TITLE breaks on spaces, hyphens and periods, so
			// "eshraq al-jaghbeer" and "hari k. r. baddigam" both come out right.
			$name = mb_convert_case( $name, MB_CASE_TITLE, 'UTF-8' );
		} else {
			$name = ucwords( $name, " \t\r\n\f\v-'." );
		}

		// Title casing stops at an apostrophe, which leaves "O'brien". Only a
		// single letter before the apostrophe is treated as a name prefix, so
		// this catches O'Brien and D'Angelo without touching anything else.
		return (string) preg_replace_callback(
			'/\b(\p{L}\x27)(\p{Ll})/u',
			static function ( $matches ) {
				return $matches[1] . ( function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $matches[2], 'UTF-8' ) : strtoupper( $matches[2] ) );
			},
			$name
		);
	}

	/**
	 * Flatten custom field values into key => scalar/string.
	 *
	 * @param array $raw       Raw contact.
	 * @param array $field_map Custom field ID => definition.
	 * @return array
	 */
	protected static function custom_values( array $raw, array $field_map ) {
		$entries = array();

		// v2 sends `customFields`, v1 sends `customField`; both as a list of
		// {id, value} pairs. Some responses key the object by field ID instead,
		// carry the field key alongside the ID, or name the value `field_value`.
		foreach ( array( 'customFields', 'customField' ) as $property ) {
			if ( ! isset( $raw[ $property ] ) || ! is_array( $raw[ $property ] ) ) {
				continue;
			}
			foreach ( $raw[ $property ] as $index => $entry ) {
				if ( is_array( $entry ) && isset( $entry['id'] ) ) {
					$entries[] = array(
						'id'    => (string) $entry['id'],
						'key'   => self::entry_key( $entry ),
						'value' => self::entry_value( $entry ),
					);
				} elseif ( ! is_array( $entry ) && ! is_int( $index ) ) {
					$entries[] = array(
						'id'    => (string) $index,
						'key'   => '',
						'value' => $entry,
					);
				}
			}
		}

		$values = array();
		$files  = array();
		foreach ( $entries as $entry ) {
			$is_file = self::is_document_value( $entry['value'] );
			$value   = self::flatten_value( $entry['value'] );
			if ( '' === $value ) {
				continue;
			}

			// Prefer the key from the synced field definitions, then one the
			// payload carried itself, and only then the raw ID — so a mapping
			// like cf:member_profile_photo still resolves when the custom-field
			// definitions could not be fetched.
			if ( isset( $field_map[ $entry['id'] ]['key'] ) ) {
				$key = $field_map[ $entry['id'] ]['key'];
			} elseif ( '' !== $entry['key'] ) {
				$key = $entry['key'];
			} else {
				$key = sanitize_key( $entry['id'] );
			}

			$values[ $key ] = $value;

			if ( $is_file ) {
				$files[] = $value;
			}

			// Keep the payload's own key as an alias when it differs, so either
			// spelling can be mapped.
			if ( '' !== $entry['key'] && $entry['key'] !== $key && ! isset( $values[ $entry['key'] ] ) ) {
				$values[ $entry['key'] ] = $value;
			}
		}

		return array(
			'values' => $values,
			'files'  => $files,
		);
	}

	/**
	 * Whether a raw custom value is an uploaded file rather than text.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	protected static function is_document_value( $value ) {
		if ( ! is_array( $value ) ) {
			return false;
		}
		if ( isset( $value['url'] ) || isset( $value['documentId'] ) ) {
			return true;
		}

		foreach ( $value as $item ) {
			if ( is_array( $item ) && ( isset( $item['url'] ) || isset( $item['documentId'] ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Reduce a custom field value to a string.
	 *
	 * Most values are scalars or a flat list (a multi-select). File uploads are
	 * the awkward case: they arrive as a list of {url: ...} objects, or as an
	 * object keyed by document ID, and stringifying those yields "Array".
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	protected static function flatten_value( $value ) {
		if ( is_scalar( $value ) ) {
			return trim( (string) $value );
		}
		if ( ! is_array( $value ) ) {
			return '';
		}

		// A single {url: ...} object.
		if ( isset( $value['url'] ) && is_scalar( $value['url'] ) ) {
			return trim( (string) $value['url'] );
		}

		$entries = array();
		foreach ( $value as $item ) {
			if ( is_scalar( $item ) ) {
				$entries[] = array(
					'value'   => (string) $item,
					'deleted' => false,
					'image'   => true,
				);
				continue;
			}
			if ( ! is_array( $item ) ) {
				continue;
			}

			$found = '';
			foreach ( array( 'url', 'value', 'name' ) as $property ) {
				if ( isset( $item[ $property ] ) && is_scalar( $item[ $property ] ) ) {
					$found = (string) $item[ $property ];
					break;
				}
			}
			if ( '' === $found ) {
				continue;
			}

			$meta = ( isset( $item['meta'] ) && is_array( $item['meta'] ) ) ? $item['meta'] : array();

			$entries[] = array(
				'value'   => $found,
				'deleted' => ! empty( $meta['deleted'] ),
				'image'   => empty( $meta['mimetype'] ) || 0 === strpos( (string) $meta['mimetype'], 'image/' ),
			);
		}

		// Replacing an upload leaves the old one in the field, flagged deleted,
		// and its URL no longer serves the file. Live uploads come first, and
		// images ahead of other attachments, so the first URL is the usable one.
		usort(
			$entries,
			static function ( $a, $b ) {
				if ( $a['deleted'] !== $b['deleted'] ) {
					return $a['deleted'] ? 1 : -1;
				}
				if ( $a['image'] !== $b['image'] ) {
					return $a['image'] ? -1 : 1;
				}

				return 0;
			}
		);

		$parts = array();
		foreach ( $entries as $entry ) {
			$parts[] = $entry['value'];
		}

		return trim( implode( ', ', $parts ) );
	}

	/**
	 * Field key carried on a custom-field entry, if any.
	 *
	 * @param array $entry Raw custom field entry.
	 * @return string
	 */
	protected static function entry_key( array $entry ) {
		foreach ( array( 'fieldKey', 'key' ) as $property ) {
			if ( ! empty( $entry[ $property ] ) && is_string( $entry[ $property ] ) ) {
				return GHLD_Client::field_key( array( 'fieldKey' => $entry[ $property ] ) );
			}
		}

		return '';
	}

	/**
	 * Value carried on a custom-field entry, whatever it is called.
	 *
	 * @param array $entry Raw custom field entry.
	 * @return mixed
	 */
	protected static function entry_value( array $entry ) {
		foreach ( array( 'value', 'field_value', 'fieldValue' ) as $property ) {
			if ( isset( $entry[ $property ] ) ) {
				return $entry[ $property ];
			}
		}

		return '';
	}

	/**
	 * Resolve a "which field holds this?" setting to a value.
	 *
	 * Settings hold either a core contact key (e.g. `company`) or a custom field
	 * key prefixed with `cf:`.
	 *
	 * @param array  $settings Plugin settings.
	 * @param string $setting  Setting name holding the mapping.
	 * @param array  $custom   Flattened custom values.
	 * @param array  $contact  Contact built so far.
	 * @return string
	 */
	protected static function mapped_value( array $settings, $setting, array $custom, array $contact ) {
		$field = isset( $settings[ $setting ] ) ? (string) $settings[ $setting ] : '';
		if ( '' === $field ) {
			return '';
		}

		if ( 0 === strpos( $field, 'cf:' ) ) {
			$key = substr( $field, 3 );

			return isset( $custom[ $key ] ) ? (string) $custom[ $key ] : '';
		}

		return isset( $contact[ $field ] ) && is_string( $contact[ $field ] ) ? $contact[ $field ] : '';
	}

	/**
	 * Recompute the settings-driven parts of a contact.
	 *
	 * Kept separate from normalize() so changing a field mapping in the admin
	 * only re-derives these values from what is already cached — no re-fetch.
	 *
	 * @param array $contact  Normalized contact.
	 * @param array $settings Plugin settings.
	 * @return array
	 */
	public static function apply_mapping( array $contact, array $settings ) {
		$custom = isset( $contact['custom'] ) && is_array( $contact['custom'] ) ? $contact['custom'] : array();

		// Idempotent: a name that already carries a capital is returned as-is.
		foreach ( array( 'name', 'first_name', 'last_name' ) as $key ) {
			if ( isset( $contact[ $key ] ) ) {
				$contact[ $key ] = self::capitalize_name( $contact[ $key ] );
			}
		}

		// The practice name usually lives in a custom field; the CRM's own
		// Company value is the fallback when that field is empty.
		$contact['company']   = self::mapped_value( $settings, 'company_field', $custom, $contact );
		if ( '' === $contact['company'] ) {
			$contact['company'] = isset( $contact['company_raw'] ) ? (string) $contact['company_raw'] : '';
		}

		$contact['fax']       = self::mapped_value( $settings, 'fax_field', $custom, $contact );
		$contact['title']     = self::mapped_value( $settings, 'title_field', $custom, $contact );
		// Specialties live in two separate single-line fields; they read as one
		// comma-separated line ("Infectious Disease, Internal Medicine").
		$contact['specialty'] = self::join_values(
			array(
				self::mapped_value( $settings, 'specialty_field', $custom, $contact ),
				self::mapped_value( $settings, 'specialty_field_2', $custom, $contact ),
			)
		);
		$contact['bio']   = self::mapped_value( $settings, 'bio_field', $custom, $contact );
		$contact['photo'] = self::photo( $contact, $settings );

		$contact['search_key'] = self::search_key( $contact );

		return $contact;
	}

	/**
	 * Hash of every setting apply_mapping() reads.
	 *
	 * @param array $settings Plugin settings.
	 * @return string
	 */
	public static function mapping_hash( array $settings ) {
		$relevant = array( 'derived' => self::DERIVED_VERSION );
		foreach ( array( 'photo_field', 'title_field', 'specialty_field', 'specialty_field_2', 'company_field', 'fax_field', 'bio_field', 'use_gravatar' ) as $key ) {
			$relevant[ $key ] = isset( $settings[ $key ] ) ? $settings[ $key ] : '';
		}

		return md5( (string) wp_json_encode( $relevant ) );
	}

	/**
	 * Resolve the contact photo URL.
	 *
	 * Order: the mapped custom field, then GoHighLevel's own profile photo, then
	 * Gravatar if the site opted in. Falling through to an empty string is fine —
	 * the card renders initials instead.
	 *
	 * @param array $contact  Normalized contact.
	 * @param array $settings Plugin settings.
	 * @return string
	 */
	protected static function photo( array $contact, array $settings ) {
		$field  = isset( $settings['photo_field'] ) ? (string) $settings['photo_field'] : '';
		$custom = isset( $contact['custom'] ) && is_array( $contact['custom'] ) ? $contact['custom'] : array();

		if ( 0 === strpos( $field, 'cf:' ) ) {
			$key = substr( $field, 3 );
			$url = isset( $custom[ $key ] ) ? self::first_url( $custom[ $key ] ) : '';
			if ( '' !== $url ) {
				return self::localized( $url, $contact );
			}

			// The mapped key isn't there. GoHighLevel identifies custom fields
			// by ID in the contact payload, so this is what a missing or
			// unreadable field-definition list looks like — the upload is
			// present, just not under a name we can match. Use it anyway.
			if ( ! empty( $contact['file_urls'] ) && is_array( $contact['file_urls'] ) ) {
				foreach ( $contact['file_urls'] as $candidate ) {
					$url = self::first_url( $candidate );
					if ( '' !== $url ) {
						return self::localized( $url, $contact );
					}
				}
			}
		}

		if ( '' !== $field && ! empty( $contact['profile_photo'] ) ) {
			return self::localized( (string) $contact['profile_photo'], $contact );
		}

		// Gravatar means handing a hash of the contact's email to a third party,
		// so it stays opt-in.
		if ( ! empty( $settings['use_gravatar'] ) && ! empty( $contact['email'] ) ) {
			return 'https://www.gravatar.com/avatar/' . md5( self::lower( trim( $contact['email'] ) ) ) . '?s=300&d=mp';
		}

		return '';
	}

	/**
	 * Prefer a cached local copy of a headshot when one exists.
	 *
	 * Never downloads: this runs while rendering, and a page view must not wait
	 * on GoHighLevel. Sync does the fetching.
	 *
	 * @param string $url     Source URL.
	 * @param array  $contact Contact being normalized.
	 * @return string
	 */
	protected static function localized( $url, array $contact ) {
		if ( ! class_exists( 'GHLD_Photos' ) || ! GHLD_Photos::needs_local_copy( $url ) ) {
			return $url;
		}

		$local = GHLD_Photos::localize( $url, isset( $contact['id'] ) ? $contact['id'] : '', false );

		return ( '' !== $local ) ? $local : $url;
	}

	/**
	 * First usable URL in a custom field value.
	 *
	 * A GoHighLevel file-upload field holds more than a bare URL: its value can
	 * flatten to several comma-separated URLs (one per uploaded file). Take the
	 * first that is actually usable rather than failing on the whole string.
	 *
	 * @param string $value Flattened custom field value.
	 * @return string
	 */
	public static function first_url( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		// Split first: a flattened multi-value would otherwise be accepted whole.
		if ( false !== strpos( $value, ',' ) ) {
			foreach ( explode( ',', $value ) as $candidate ) {
				$url = self::url( trim( $candidate ) );
				if ( '' !== $url ) {
					return $url;
				}
			}
		}

		return self::url( $value );
	}

	/**
	 * Tag list, lowercased and de-duplicated.
	 *
	 * @param array $raw Raw contact.
	 * @return string[]
	 */
	protected static function tags( array $raw ) {
		$tags = isset( $raw['tags'] ) ? $raw['tags'] : array();
		if ( is_string( $tags ) ) {
			$tags = explode( ',', $tags );
		}
		if ( ! is_array( $tags ) ) {
			return array();
		}

		$out = array();
		foreach ( $tags as $tag ) {
			if ( is_array( $tag ) ) {
				$tag = isset( $tag['name'] ) ? $tag['name'] : '';
			}
			$tag = trim( (string) $tag );
			if ( '' !== $tag ) {
				$out[] = $tag;
			}
		}

		$out = array_values( array_unique( $out ) );
		sort( $out );

		return $out;
	}

	/**
	 * Sortable name: last name first, falling back to the display name.
	 *
	 * @param string $last  Last name.
	 * @param string $first First name.
	 * @param string $name  Display name.
	 * @return string
	 */
	protected static function sort_key( $last, $first, $name ) {
		$key = trim( $last . ' ' . $first );
		if ( '' === $key ) {
			$key = $name;
		}

		return self::lower( $key );
	}

	/**
	 * Haystack used by the search box.
	 *
	 * @param array $contact Normalized contact.
	 * @return string
	 */
	protected static function search_key( array $contact ) {
		$custom = array();
		foreach ( (array) $contact['custom'] as $value ) {
			$value = (string) $value;
			// A URL-valued field (a headshot, a booking link) is never useful as
			// search text, and would otherwise match on fragments of the domain.
			if ( '' !== $value && ! preg_match( '#^https?://#i', $value ) ) {
				$custom[] = $value;
			}
		}

		$parts = array(
			$contact['name'],
			$contact['company'],
			$contact['title'],
			$contact['specialty'],
			$contact['bio'],
			$contact['city'],
			$contact['state'],
			$contact['email'],
			$contact['phone'],
			implode( ' ', $contact['tags'] ),
			implode( ' ', $custom ),
		);

		return self::lower( implode( ' ', array_filter( $parts ) ) );
	}

	/**
	 * Up to two initials for the fallback avatar.
	 *
	 * @param string $name Display name.
	 * @return string
	 */
	protected static function initials( $name ) {
		$name = trim( (string) $name );
		if ( '' === $name ) {
			return '?';
		}

		$words    = preg_split( '/\s+/', $name );
		$initials = '';
		foreach ( $words as $word ) {
			$char = function_exists( 'mb_substr' ) ? mb_substr( $word, 0, 1 ) : substr( $word, 0, 1 );
			if ( '' !== trim( $char ) ) {
				$initials .= $char;
			}
			if ( strlen( $initials ) >= 2 ) {
				break;
			}
		}

		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $initials ) : strtoupper( $initials );
	}

	/**
	 * Multibyte-safe lowercase.
	 *
	 * @param string $value Input.
	 * @return string
	 */
	public static function lower( $value ) {
		$value = (string) $value;

		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value );
	}

	/**
	 * Read a string key from the raw payload.
	 *
	 * @param array  $raw Raw contact.
	 * @param string $key Key to read.
	 * @return string
	 */
	protected static function str( array $raw, $key ) {
		if ( ! isset( $raw[ $key ] ) || is_array( $raw[ $key ] ) ) {
			return '';
		}

		return trim( (string) $raw[ $key ] );
	}

	/**
	 * First non-empty of several keys.
	 *
	 * @param array    $raw  Raw contact.
	 * @param string[] $keys Keys to try in order.
	 * @return string
	 */
	protected static function first_str( array $raw, array $keys ) {
		foreach ( $keys as $key ) {
			$value = self::str( $raw, $key );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Validate and normalize a URL, allowing bare "example.com" input.
	 *
	 * @param string $value Candidate URL.
	 * @return string Empty string when the value isn't a usable http(s) URL.
	 */
	protected static function url( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		// A URL carries no whitespace; anything that does is prose, or several
		// values that were flattened into one string.
		if ( preg_match( '/\s/', $value ) ) {
			return '';
		}

		if ( ! preg_match( '#^https?://#i', $value ) ) {
			if ( ! preg_match( '#^[a-z0-9.-]+\.([a-z]{2,})(/|$)#i', $value, $matches ) ) {
				return '';
			}

			// "headshot.jpg" looks exactly like a bare domain, so a file
			// extension with no path is a filename, not a host to prepend
			// https:// to.
			if ( false === strpos( $value, '/' ) && in_array( strtolower( $matches[1] ), self::file_extensions(), true ) ) {
				return '';
			}

			$value = 'https://' . $value;
		}

		return esc_url_raw( $value );
	}

	/**
	 * Extensions that mark a bare string as a filename rather than a host.
	 *
	 * @return string[]
	 */
	protected static function file_extensions() {
		return array(
			'jpg',
			'jpeg',
			'png',
			'gif',
			'webp',
			'svg',
			'bmp',
			'tif',
			'tiff',
			'heic',
			'pdf',
			'doc',
			'docx',
			'txt',
			'csv',
			'zip',
			'mp4',
			'mov',
		);
	}

	/**
	 * Parse an API date into a Unix timestamp.
	 *
	 * @param string $value Date string or epoch milliseconds.
	 * @return int
	 */
	protected static function timestamp( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return 0;
		}
		if ( ctype_digit( $value ) ) {
			$number = (float) $value;

			return (int) ( $number > 100000000000 ? $number / 1000 : $number );
		}

		$stamp = strtotime( $value );

		return $stamp ? (int) $stamp : 0;
	}
}
