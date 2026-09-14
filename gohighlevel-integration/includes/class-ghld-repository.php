<?php
/**
 * Cached contact store and the query engine behind the directory.
 *
 * @package GoHighLevel_Integration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Owns the cached copy of the location's contacts.
 *
 * Contacts live in a non-autoloaded option rather than a transient so an expired
 * cache still has data to serve while a refresh is attempted — if GoHighLevel is
 * down, the directory keeps rendering the last good set instead of going blank.
 */
class GHLD_Repository {

	const OPTION_CONTACTS = 'ghld_contacts';
	const OPTION_FIELDS   = 'ghld_custom_fields';
	const OPTION_STATE    = 'ghld_sync_state';
	const CRON_HOOK       = 'ghld_sync_contacts';

	/**
	 * In-request memo so several shortcodes on one page share one read.
	 *
	 * @var array|null
	 */
	protected static $memo = null;

	/**
	 * All cached contacts, refreshing first if the cache has gone stale.
	 *
	 * @return array
	 */
	public static function get_contacts() {
		if ( null !== self::$memo ) {
			return self::$memo;
		}

		$state = self::state();
		$stale = ( time() - (int) $state['synced_at'] ) > GHLD_Settings::cache_seconds();

		if ( ( $stale || empty( $state['count'] ) ) && GHLD_Settings::is_configured() && self::may_retry( $state ) ) {
			$result = self::sync();
			if ( is_wp_error( $result ) ) {
				// Fall through to whatever is cached; the error is recorded in state.
				unset( $result );
			}
		}

		$contacts = get_option( self::OPTION_CONTACTS, array() );
		if ( ! is_array( $contacts ) ) {
			$contacts = array();
		}

		// Field mappings can change without the contact data changing; re-derive
		// the mapped values in place rather than forcing a re-fetch.
		$settings = GHLD_Settings::all();
		$hash     = GHLD_Contact::mapping_hash( $settings );
		$state    = self::state();
		if ( ! empty( $contacts ) && $hash !== $state['mapping'] ) {
			foreach ( $contacts as $index => $contact ) {
				$contacts[ $index ] = GHLD_Contact::apply_mapping( $contact, $settings );
			}
			update_option( self::OPTION_CONTACTS, $contacts, false );
			self::update_state( array( 'mapping' => $hash ) );
		}

		self::$memo = $contacts;

		return $contacts;
	}

	/**
	 * Pull every contact from GoHighLevel and replace the cache.
	 *
	 * @return int|WP_Error Number of contacts stored, or the failure.
	 */
	public static function sync() {
		if ( ! GHLD_Settings::is_configured() ) {
			$error = new WP_Error( 'ghld_not_configured', __( 'Add your GoHighLevel API token (and Location ID for API v2) first.', 'gohighlevel-integration' ) );
			self::record_failure( $error );

			return $error;
		}

		$client = new GHLD_Client();

		$fields = $client->get_custom_fields();
		if ( is_wp_error( $fields ) ) {
			// Custom fields are a nicety — without them custom values fall back to
			// their raw IDs, so a failure here shouldn't abort the contact sync.
			$fields = get_option( self::OPTION_FIELDS, array() );
			$fields = is_array( $fields ) ? $fields : array();
		} else {
			update_option( self::OPTION_FIELDS, $fields, false );
		}

		$raw = $client->get_contacts();
		if ( is_wp_error( $raw ) ) {
			self::record_failure( $raw );

			return $raw;
		}

		$settings = GHLD_Settings::all();
		$contacts = array();
		foreach ( $raw as $item ) {
			if ( is_array( $item ) ) {
				$contacts[] = GHLD_Contact::normalize( $item, $fields, $settings );
			}
		}

		update_option( self::OPTION_CONTACTS, $contacts, false );
		self::update_state(
			array(
				'synced_at'  => time(),
				'count'      => count( $contacts ),
				'error'      => '',
				'failed_at'  => 0,
				'mapping'    => GHLD_Contact::mapping_hash( $settings ),
			)
		);
		self::$memo = $contacts;

		/**
		 * Fires after the contact cache has been refreshed.
		 *
		 * @param array $contacts Normalized contacts.
		 */
		do_action( 'ghld_after_sync', $contacts );

		return count( $contacts );
	}

	/**
	 * Filter, sort and paginate the cached contacts.
	 *
	 * @param array $scope   Shortcode/settings scope: tags, exclude_tags, per_page, orderby, order.
	 * @param array $request Visitor-supplied filters: search, tag, city, state, company, custom, page, orderby, order.
	 * @return array{items:array,total:int,page:int,pages:int,per_page:int,facets:array}
	 */
	public static function query( array $scope, array $request ) {
		$contacts = self::apply_scope( self::get_contacts(), $scope );
		$facets   = self::facets( $contacts, $scope );
		$matches  = array();

		$search = isset( $request['search'] ) ? GHLD_Contact::lower( trim( (string) $request['search'] ) ) : '';
		$terms  = '' === $search ? array() : preg_split( '/\s+/', $search );

		foreach ( $contacts as $contact ) {
			if ( ! self::matches( $contact, $request, $terms ) ) {
				continue;
			}
			$matches[] = $contact;
		}

		$orderby = isset( $request['orderby'] ) ? $request['orderby'] : $scope['orderby'];
		$order   = isset( $request['order'] ) ? $request['order'] : $scope['order'];
		$matches = self::sort( $matches, $orderby, $order );

		$per_page = max( 1, (int) $scope['per_page'] );
		$total    = count( $matches );
		$pages    = max( 1, (int) ceil( $total / $per_page ) );
		$page     = min( $pages, max( 1, isset( $request['page'] ) ? (int) $request['page'] : 1 ) );

		return array(
			'items'    => array_slice( $matches, ( $page - 1 ) * $per_page, $per_page ),
			'total'    => $total,
			'page'     => $page,
			'pages'    => $pages,
			'per_page' => $per_page,
			'facets'   => $facets,
		);
	}

	/**
	 * Apply the tag scope that the site owner (not the visitor) controls.
	 *
	 * @param array $contacts Contacts to narrow.
	 * @param array $scope    Scope with `tags` and `exclude_tags` lists.
	 * @return array
	 */
	protected static function apply_scope( array $contacts, array $scope ) {
		$include = array_map( array( 'GHLD_Contact', 'lower' ), isset( $scope['tags'] ) ? (array) $scope['tags'] : array() );
		$exclude = array_map( array( 'GHLD_Contact', 'lower' ), isset( $scope['exclude_tags'] ) ? (array) $scope['exclude_tags'] : array() );

		if ( empty( $include ) && empty( $exclude ) ) {
			return $contacts;
		}

		$out = array();
		foreach ( $contacts as $contact ) {
			$tags = array_map( array( 'GHLD_Contact', 'lower' ), $contact['tags'] );

			if ( ! empty( $include ) && ! array_intersect( $include, $tags ) ) {
				continue;
			}
			if ( ! empty( $exclude ) && array_intersect( $exclude, $tags ) ) {
				continue;
			}
			$out[] = $contact;
		}

		return $out;
	}

	/**
	 * Test one contact against the visitor's filters.
	 *
	 * @param array $contact Normalized contact.
	 * @param array $request Visitor filters.
	 * @param array $terms   Pre-split lowercase search terms.
	 * @return bool
	 */
	protected static function matches( array $contact, array $request, array $terms ) {
		foreach ( $terms as $term ) {
			if ( '' !== $term && false === strpos( $contact['search_key'], $term ) ) {
				return false;
			}
		}

		if ( ! empty( $request['tag'] ) ) {
			$wanted = GHLD_Contact::lower( $request['tag'] );
			$tags   = array_map( array( 'GHLD_Contact', 'lower' ), $contact['tags'] );
			if ( ! in_array( $wanted, $tags, true ) ) {
				return false;
			}
		}

		foreach ( array( 'city', 'state', 'company' ) as $key ) {
			if ( empty( $request[ $key ] ) ) {
				continue;
			}
			if ( GHLD_Contact::lower( $request[ $key ] ) !== GHLD_Contact::lower( $contact[ $key ] ) ) {
				return false;
			}
		}

		if ( ! empty( $request['custom'] ) && is_array( $request['custom'] ) ) {
			foreach ( $request['custom'] as $key => $value ) {
				if ( '' === $value ) {
					continue;
				}
				$actual = isset( $contact['custom'][ $key ] ) ? $contact['custom'][ $key ] : '';
				if ( GHLD_Contact::lower( $value ) !== GHLD_Contact::lower( $actual ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Sort matched contacts.
	 *
	 * @param array  $contacts Contacts to sort.
	 * @param string $orderby  name|company|date_added.
	 * @param string $order    asc|desc.
	 * @return array
	 */
	protected static function sort( array $contacts, $orderby, $order ) {
		$direction = ( 'desc' === $order ) ? -1 : 1;

		usort(
			$contacts,
			static function ( $a, $b ) use ( $orderby, $direction ) {
				if ( 'date_added' === $orderby ) {
					$result = (int) $a['date_added'] - (int) $b['date_added'];
				} elseif ( 'first_name' === $orderby ) {
					$result = strcmp( GHLD_Contact::lower( $a['name'] ), GHLD_Contact::lower( $b['name'] ) );
				} elseif ( 'company' === $orderby ) {
					$result = strcmp( GHLD_Contact::lower( $a['company'] ), GHLD_Contact::lower( $b['company'] ) );
				} else {
					$result = strcmp( $a['sort_name'], $b['sort_name'] );
				}

				if ( 0 === $result ) {
					$result = strcmp( $a['sort_name'], $b['sort_name'] );
				}

				return $result * $direction;
			}
		);

		return $contacts;
	}

	/**
	 * Build the option lists the filter bar offers.
	 *
	 * Facets come from the scoped set *before* the visitor's filters are applied,
	 * so choosing a city doesn't empty out the tag dropdown.
	 *
	 * @param array $contacts Scoped contacts.
	 * @param array $scope    Scope (its `filters` list decides what is built).
	 * @return array
	 */
	public static function facets( array $contacts, array $scope ) {
		$filters = isset( $scope['filters'] ) ? (array) $scope['filters'] : array();
		$facets  = array(
			'tag'     => array(),
			'city'    => array(),
			'state'   => array(),
			'company' => array(),
			'custom'  => array(),
		);

		$custom_keys = array();
		foreach ( $filters as $filter ) {
			if ( 0 === strpos( $filter, 'cf:' ) ) {
				$custom_keys[] = substr( $filter, 3 );
			}
		}

		$hidden = self::scope_tag( $scope );

		foreach ( $contacts as $contact ) {
			foreach ( $contact['tags'] as $tag ) {
				if ( '' !== $hidden && GHLD_Contact::lower( $tag ) === $hidden ) {
					continue;
				}
				$facets['tag'][ $tag ] = isset( $facets['tag'][ $tag ] ) ? $facets['tag'][ $tag ] + 1 : 1;
			}
			foreach ( array( 'city', 'state', 'company' ) as $key ) {
				$value = trim( (string) $contact[ $key ] );
				if ( '' !== $value ) {
					$facets[ $key ][ $value ] = isset( $facets[ $key ][ $value ] ) ? $facets[ $key ][ $value ] + 1 : 1;
				}
			}
			foreach ( $custom_keys as $key ) {
				$value = isset( $contact['custom'][ $key ] ) ? trim( (string) $contact['custom'][ $key ] ) : '';
				if ( '' === $value ) {
					continue;
				}
				if ( ! isset( $facets['custom'][ $key ] ) ) {
					$facets['custom'][ $key ] = array();
				}
				$facets['custom'][ $key ][ $value ] = isset( $facets['custom'][ $key ][ $value ] ) ? $facets['custom'][ $key ][ $value ] + 1 : 1;
			}
		}

		foreach ( array( 'tag', 'city', 'state', 'company' ) as $key ) {
			ksort( $facets[ $key ], SORT_NATURAL | SORT_FLAG_CASE );
		}
		foreach ( $facets['custom'] as $key => $values ) {
			ksort( $values, SORT_NATURAL | SORT_FLAG_CASE );
			$facets['custom'][ $key ] = $values;
		}

		return $facets;
	}

	/**
	 * The tag that every contact in this directory necessarily carries.
	 *
	 * When the scope is narrowed to exactly one tag, that tag is true of every
	 * card, so offering it as a filter and printing it on every card is noise.
	 * Two or more scope tags do distinguish contacts, so those stay visible.
	 *
	 * @param array $scope Resolved scope.
	 * @return string Lowercased tag, or '' when nothing should be hidden.
	 */
	public static function scope_tag( array $scope ) {
		$tags = isset( $scope['tags'] ) ? array_values( (array) $scope['tags'] ) : array();

		return ( 1 === count( $tags ) ) ? GHLD_Contact::lower( $tags[0] ) : '';
	}

	/**
	 * Custom field definitions discovered on the last sync.
	 *
	 * @return array Field ID => array{key,name,type}.
	 */
	public static function custom_fields() {
		$fields = get_option( self::OPTION_FIELDS, array() );

		return is_array( $fields ) ? $fields : array();
	}

	/**
	 * Sync bookkeeping: last success, last error, cached count.
	 *
	 * @return array
	 */
	public static function state() {
		$state = get_option( self::OPTION_STATE, array() );
		$state = is_array( $state ) ? $state : array();

		return array_merge(
			array(
				'synced_at' => 0,
				'failed_at' => 0,
				'count'     => 0,
				'error'     => '',
				'mapping'   => '',
			),
			$state
		);
	}

	/**
	 * Merge values into the sync state.
	 *
	 * @param array $values Values to store.
	 * @return void
	 */
	protected static function update_state( array $values ) {
		update_option( self::OPTION_STATE, array_merge( self::state(), $values ), false );
	}

	/**
	 * Record a failed sync.
	 *
	 * @param WP_Error $error Failure.
	 * @return void
	 */
	protected static function record_failure( WP_Error $error ) {
		self::update_state(
			array(
				'failed_at' => time(),
				'error'     => $error->get_error_message(),
			)
		);
	}

	/**
	 * Back off after a failure so every page view doesn't re-hit a dead API.
	 *
	 * @param array $state Sync state.
	 * @return bool
	 */
	protected static function may_retry( array $state ) {
		if ( empty( $state['failed_at'] ) ) {
			return true;
		}

		return ( time() - (int) $state['failed_at'] ) > ( 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Drop everything cached (contacts, custom fields, sync state).
	 *
	 * @return void
	 */
	public static function flush() {
		delete_option( self::OPTION_CONTACTS );
		delete_option( self::OPTION_FIELDS );
		delete_option( self::OPTION_STATE );
		self::$memo = null;
	}
}
