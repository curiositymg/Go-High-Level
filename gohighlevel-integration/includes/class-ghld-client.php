<?php
/**
 * GoHighLevel REST client.
 *
 * @package GoHighLevel_Integration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper over the two GoHighLevel contact APIs.
 *
 * - `v2` is the current LeadConnector API (services.leadconnectorhq.com). It
 *   authenticates with a Private Integration token or an OAuth access token and
 *   requires an explicit `locationId` on every request.
 * - `v1` is the legacy API (rest.gohighlevel.com/v1) used with a sub-account API
 *   key. The key is already location-scoped, so no location ID is sent.
 *
 * Both return `{ contacts: [...], meta: {...} }` and page with
 * `startAfterId` + `startAfter`, so the paging loop is shared.
 */
class GHLD_Client {

	const V2_BASE       = 'https://services.leadconnectorhq.com';
	const V1_BASE       = 'https://rest.gohighlevel.com/v1';
	const V2_API_HEADER = '2021-07-28';
	const PAGE_SIZE     = 100;
	const MAX_PAGES     = 200;

	/**
	 * API token or key.
	 *
	 * @var string
	 */
	protected $token;

	/**
	 * Location (sub-account) ID, v2 only.
	 *
	 * @var string
	 */
	protected $location_id;

	/**
	 * Either 'v2' or 'v1'.
	 *
	 * @var string
	 */
	protected $api_version;

	/**
	 * Constructor.
	 *
	 * @param array $args Optional overrides: token, location_id, api_version.
	 */
	public function __construct( array $args = array() ) {
		$this->token       = isset( $args['token'] ) ? (string) $args['token'] : GHLD_Settings::token();
		$this->location_id = isset( $args['location_id'] ) ? (string) $args['location_id'] : GHLD_Settings::location_id();
		$this->api_version = isset( $args['api_version'] ) ? (string) $args['api_version'] : (string) GHLD_Settings::get( 'api_version', 'v2' );
	}

	/**
	 * Fetch every contact in the location, following pagination.
	 *
	 * A failure on any page returns a WP_Error rather than a partial list — the
	 * caller keeps serving the previously cached set instead of replacing a full
	 * directory with a truncated one.
	 *
	 * @param int $max Hard ceiling on contacts fetched. 0 = no ceiling.
	 * @return array|WP_Error Raw contact arrays as returned by the API.
	 */
	public function get_contacts( $max = 0 ) {
		if ( '' === $this->token ) {
			return new WP_Error( 'ghld_no_token', __( 'No GoHighLevel API token is configured.', 'gohighlevel-integration' ) );
		}
		if ( 'v2' === $this->api_version && '' === $this->location_id ) {
			return new WP_Error( 'ghld_no_location', __( 'The GoHighLevel API v2 needs a Location ID.', 'gohighlevel-integration' ) );
		}

		$contacts       = array();
		$seen           = array();
		$start_after_id = '';
		$start_after    = '';

		for ( $page = 0; $page < self::MAX_PAGES; $page++ ) {
			$query = array( 'limit' => self::PAGE_SIZE );
			if ( 'v2' === $this->api_version ) {
				$query['locationId'] = $this->location_id;
			}
			if ( '' !== $start_after_id ) {
				$query['startAfterId'] = $start_after_id;
			}
			if ( '' !== $start_after ) {
				$query['startAfter'] = $start_after;
			}

			$body = $this->get( '/contacts/', $query );
			if ( is_wp_error( $body ) ) {
				return $body;
			}

			$batch = isset( $body['contacts'] ) && is_array( $body['contacts'] ) ? $body['contacts'] : array();
			if ( empty( $batch ) ) {
				break;
			}

			foreach ( $batch as $contact ) {
				if ( ! is_array( $contact ) ) {
					continue;
				}
				$id = isset( $contact['id'] ) ? (string) $contact['id'] : '';
				if ( '' !== $id && isset( $seen[ $id ] ) ) {
					continue;
				}
				if ( '' !== $id ) {
					$seen[ $id ] = true;
				}
				$contacts[] = $contact;

				if ( $max > 0 && count( $contacts ) >= $max ) {
					return $contacts;
				}
			}

			if ( count( $batch ) < self::PAGE_SIZE ) {
				break;
			}

			$cursor = $this->next_cursor( $body, $batch );
			if ( $cursor['id'] === $start_after_id && $cursor['after'] === $start_after ) {
				break; // No forward progress; stop rather than loop on the same page.
			}
			if ( '' === $cursor['id'] && '' === $cursor['after'] ) {
				break;
			}

			$start_after_id = $cursor['id'];
			$start_after    = $cursor['after'];
		}

		return $contacts;
	}

	/**
	 * Fetch the location's custom field definitions.
	 *
	 * @return array|WP_Error Map of field ID => array{key,name,type}.
	 */
	public function get_custom_fields() {
		if ( '' === $this->token ) {
			return new WP_Error( 'ghld_no_token', __( 'No GoHighLevel API token is configured.', 'gohighlevel-integration' ) );
		}

		if ( 'v2' === $this->api_version ) {
			if ( '' === $this->location_id ) {
				return new WP_Error( 'ghld_no_location', __( 'The GoHighLevel API v2 needs a Location ID.', 'gohighlevel-integration' ) );
			}
			$body = $this->get( '/locations/' . rawurlencode( $this->location_id ) . '/customFields', array() );
		} else {
			$body = $this->get( '/custom-fields/', array() );
		}

		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$raw    = isset( $body['customFields'] ) && is_array( $body['customFields'] ) ? $body['customFields'] : array();
		$fields = array();

		foreach ( $raw as $field ) {
			if ( ! is_array( $field ) || empty( $field['id'] ) ) {
				continue;
			}
			$name = isset( $field['name'] ) ? (string) $field['name'] : (string) $field['id'];

			$fields[ (string) $field['id'] ] = array(
				'key'  => self::field_key( $field ),
				'name' => $name,
				'type' => isset( $field['dataType'] ) ? (string) $field['dataType'] : '',
			);
		}

		return $fields;
	}

	/**
	 * Cheap credentials check: request a single contact.
	 *
	 * @return true|WP_Error
	 */
	public function test_connection() {
		$query = array( 'limit' => 1 );
		if ( 'v2' === $this->api_version ) {
			if ( '' === $this->location_id ) {
				return new WP_Error( 'ghld_no_location', __( 'The GoHighLevel API v2 needs a Location ID.', 'gohighlevel-integration' ) );
			}
			$query['locationId'] = $this->location_id;
		}

		$body = $this->get( '/contacts/', $query );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		return true;
	}

	/**
	 * Stable, human-readable key for a custom field.
	 *
	 * GoHighLevel's `fieldKey` looks like "contact.job_title"; the "contact."
	 * prefix carries no information here, so it is stripped.
	 *
	 * @param array $field Raw custom field definition.
	 * @return string
	 */
	public static function field_key( array $field ) {
		$key = isset( $field['fieldKey'] ) ? (string) $field['fieldKey'] : '';
		if ( '' !== $key ) {
			$parts = explode( '.', $key );
			$key   = (string) end( $parts );
		}
		if ( '' === $key && isset( $field['name'] ) ) {
			$key = (string) $field['name'];
		}

		$key = sanitize_key( str_replace( array( ' ', '-' ), '_', $key ) );

		return '' === $key ? 'field_' . substr( md5( wp_json_encode( $field ) ), 0, 8 ) : $key;
	}

	/**
	 * Work out the cursor for the next page.
	 *
	 * The API normally returns it in `meta`; when it doesn't, the last contact of
	 * the current batch supplies the same two values.
	 *
	 * @param array $body  Decoded response body.
	 * @param array $batch Contacts from this page.
	 * @return array{id:string,after:string}
	 */
	protected function next_cursor( array $body, array $batch ) {
		$meta  = isset( $body['meta'] ) && is_array( $body['meta'] ) ? $body['meta'] : array();
		$id    = isset( $meta['startAfterId'] ) ? (string) $meta['startAfterId'] : '';
		$after = isset( $meta['startAfter'] ) ? (string) $meta['startAfter'] : '';

		if ( '' !== $id ) {
			return array(
				'id'    => $id,
				'after' => $after,
			);
		}

		$last = end( $batch );
		if ( ! is_array( $last ) ) {
			return array(
				'id'    => '',
				'after' => '',
			);
		}

		$id = isset( $last['id'] ) ? (string) $last['id'] : '';
		if ( '' === $after && isset( $last['dateAdded'] ) ) {
			$stamp = strtotime( (string) $last['dateAdded'] );
			if ( $stamp ) {
				$after = (string) ( $stamp * 1000 );
			}
		}

		return array(
			'id'    => $id,
			'after' => $after,
		);
	}

	/**
	 * Perform a GET request and decode the JSON body.
	 *
	 * @param string $path  Path below the API base, with leading slash.
	 * @param array  $query Query string arguments.
	 * @return array|WP_Error
	 */
	protected function get( $path, array $query ) {
		$base = ( 'v2' === $this->api_version ) ? self::V2_BASE : self::V1_BASE;
		$url  = $base . $path;
		if ( ! empty( $query ) ) {
			$url = add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $query ) ), $url );
		}

		$headers = array(
			'Authorization' => 'Bearer ' . $this->token,
			'Accept'        => 'application/json',
		);
		if ( 'v2' === $this->api_version ) {
			$headers['Version'] = self::V2_API_HEADER;
		}

		$response = wp_remote_get(
			$url,
			array(
				'headers' => $headers,
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'ghld_http_' . $code,
				sprintf(
					/* translators: 1: HTTP status code, 2: error message from GoHighLevel. */
					__( 'GoHighLevel returned HTTP %1$d: %2$s', 'gohighlevel-integration' ),
					$code,
					self::error_message( $body )
				),
				array( 'status' => $code )
			);
		}

		if ( ! is_array( $body ) ) {
			return new WP_Error( 'ghld_bad_json', __( 'GoHighLevel returned a response that could not be parsed as JSON.', 'gohighlevel-integration' ) );
		}

		return $body;
	}

	/**
	 * Pull a readable message out of an error body.
	 *
	 * @param mixed $body Decoded response body.
	 * @return string
	 */
	protected static function error_message( $body ) {
		if ( ! is_array( $body ) ) {
			return __( 'no details returned', 'gohighlevel-integration' );
		}

		foreach ( array( 'message', 'error', 'msg' ) as $key ) {
			if ( ! isset( $body[ $key ] ) ) {
				continue;
			}
			$value = $body[ $key ];
			if ( is_array( $value ) ) {
				$value = implode( '; ', array_map( 'strval', $value ) );
			}
			$value = trim( (string) $value );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return __( 'no details returned', 'gohighlevel-integration' );
	}
}
