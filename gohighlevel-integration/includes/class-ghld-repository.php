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
	const CRON_ENRICH     = 'ghld_enrich_contacts';

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

		// Index what is already cached: the contact list carries fewer custom
		// values than a single-contact fetch, so rebuilding from it would throw
		// away every headshot enrichment has collected.
		$previous = array();
		foreach ( (array) get_option( self::OPTION_CONTACTS, array() ) as $cached ) {
			if ( ! empty( $cached['id'] ) ) {
				$previous[ $cached['id'] ] = $cached;
			}
		}

		$contacts = array();
		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$contact = GHLD_Contact::normalize( $item, $fields, $settings );

			if ( isset( $previous[ $contact['id'] ] ) ) {
				$contact = self::carry_over( $contact, $previous[ $contact['id'] ], $settings );
			}

			$contacts[] = $contact;
		}

		if ( ! empty( $settings['deep_sync'] ) ) {
			$contacts = self::enrich( $contacts, $client, $fields, $settings );
		}

		$contacts = self::localize_photos( $contacts );
		$contacts = self::assign_slugs( $contacts );

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

		self::schedule_continuation();

		/**
		 * Fires after the contact cache has been refreshed.
		 *
		 * @param array $contacts Normalized contacts.
		 */
		do_action( 'ghld_after_sync', $contacts );

		return count( $contacts );
	}

	/**
	 * Re-fetch contacts individually to pick up fields the list leaves out.
	 *
	 * GoHighLevel's paginated contact list does not always carry every custom
	 * field value — file uploads in particular — while the single-contact
	 * endpoint does. Fetching all of them at once would take longer than a
	 * request is allowed to run, so each sync walks a batch, and a cursor keeps
	 * the next one going where this left off. Contacts that already have a
	 * photo are skipped, so a full pass costs less each time round.
	 *
	 * @param array       $contacts Normalized contacts.
	 * @param GHLD_Client $client   API client.
	 * @param array       $fields   Custom field definitions.
	 * @param array       $settings Plugin settings.
	 * @param float|null  $seconds  Time budget override, for a caller that is
	 *                              driving the batches itself.
	 * @param bool        $force    Re-check contacts that already have a
	 *                              headshot, in case it has since changed.
	 * @return array
	 */
	protected static function enrich( array $contacts, GHLD_Client $client, array $fields, array $settings, $seconds = null, $force = false ) {
		$total = count( $contacts );
		if ( 0 === $total ) {
			return $contacts;
		}

		/**
		 * Filter how long a sync may spend fetching contacts individually.
		 *
		 * A time budget rather than a count: it does as much as the request can
		 * safely afford on this host instead of a fixed number that is either
		 * too slow to finish or too slow to survive.
		 *
		 * @param float $seconds Seconds per run.
		 */
		$seconds  = ( null === $seconds )
			? max( 1, (float) apply_filters( 'ghld_enrich_seconds', 20 ) )
			: max( 1, (float) $seconds );
		$cap      = max( 1, (int) apply_filters( 'ghld_enrich_batch', 500 ) );
		$deadline = microtime( true ) + $seconds;

		$state    = self::state();
		$cursor   = isset( $state['enrich_cursor'] ) ? (int) $state['enrich_cursor'] : 0;
		$fetched  = 0;
		$resolved = 0;
		$offset   = 0;

		/**
		 * Filter how long before a contact with no headshot is asked again.
		 *
		 * @param int $cooldown Seconds.
		 */
		$cooldown = (int) apply_filters( 'ghld_enrich_cooldown', DAY_IN_SECONDS );
		$now      = time();

		for ( $offset = 0; $offset < $total; $offset++ ) {
			if ( $fetched >= $cap || microtime( true ) >= $deadline ) {
				break;
			}

			$index = ( $cursor + $offset ) % $total;

			if ( empty( $contacts[ $index ]['id'] ) ) {
				continue;
			}

			// Normally a contact with a headshot needs nothing; a forced run is
			// asking whether what we hold is still true, so it checks everyone.
			if ( ! $force && ! empty( $contacts[ $index ]['photo'] ) ) {
				continue;
			}

			// Asked recently and came back without one: it simply has no
			// headshot, so asking again every sync would spin forever.
			if ( ! empty( $contacts[ $index ]['enriched_at'] ) && ( $now - (int) $contacts[ $index ]['enriched_at'] ) < $cooldown ) {
				continue;
			}

			$raw = $client->get_contact( $contacts[ $index ]['id'] );
			$fetched++;

			if ( is_wp_error( $raw ) ) {
				continue;
			}

			$full = GHLD_Contact::normalize( $raw, $fields, $settings );

			// Only take the fuller record when it actually carries more.
			if ( ! empty( $full['photo'] ) || count( $full['custom'] ) > count( $contacts[ $index ]['custom'] ) ) {
				$contacts[ $index ] = $full;
				if ( ! empty( $full['photo'] ) ) {
					$resolved++;
				}
			}

			$contacts[ $index ]['enriched_at'] = $now;
		}

		// How many are still waiting, so the admin can say whether pressing
		// Sync again will achieve anything.
		$remaining = 0;
		foreach ( $contacts as $contact ) {
			$recent = ! empty( $contact['enriched_at'] ) && ( $now - (int) $contact['enriched_at'] ) < $cooldown;
			if ( ( $force || empty( $contact['photo'] ) ) && ! empty( $contact['id'] ) && ! $recent ) {
				$remaining++;
			}
		}

		self::update_state(
			array(
				'enrich_cursor'    => ( $cursor + $offset ) % $total,
				'enrich_fetched'   => $fetched,
				'enrich_resolved'  => $resolved,
				'enrich_remaining' => $remaining,
			)
		);

		return $contacts;
	}

	/**
	 * Keep the richer parts of a cached contact when rebuilding from the list.
	 *
	 * @param array $fresh    Contact as the contact list describes it.
	 * @param array $cached   The copy already held, possibly enriched.
	 * @param array $settings Plugin settings.
	 * @return array
	 */
	protected static function carry_over( array $fresh, array $cached, array $settings ) {
		$cached_custom = isset( $cached['custom'] ) && is_array( $cached['custom'] ) ? $cached['custom'] : array();

		if ( count( $cached_custom ) > count( $fresh['custom'] ) ) {
			$fresh['custom']    = $cached_custom;
			$fresh['file_urls'] = isset( $cached['file_urls'] ) ? $cached['file_urls'] : array();
			$fresh              = GHLD_Contact::apply_mapping( $fresh, $settings );
		}

		if ( ! empty( $cached['enriched_at'] ) ) {
			$fresh['enriched_at'] = (int) $cached['enriched_at'];
		}

		return $fresh;
	}

	/**
	 * Continue enriching in the background until the pass is done.
	 *
	 * Fetching several hundred contacts one at a time outlasts any single
	 * request, and asking someone to keep pressing a button until it finishes
	 * is not a design. Each run books the next one a minute out and stops on
	 * its own once every contact has been tried.
	 *
	 * @return void
	 */
	public static function continue_enrich() {
		if ( empty( GHLD_Settings::get( 'deep_sync' ) ) || ! GHLD_Settings::is_configured() ) {
			return;
		}

		$contacts = get_option( self::OPTION_CONTACTS, array() );
		if ( ! is_array( $contacts ) || empty( $contacts ) ) {
			return;
		}

		$contacts = self::enrich( $contacts, new GHLD_Client(), self::custom_fields(), GHLD_Settings::all() );
		$contacts = self::localize_photos( $contacts );

		update_option( self::OPTION_CONTACTS, $contacts, false );
		self::$memo = null;

		self::schedule_continuation();
	}

	/**
	 * Run one enrichment batch and report where it got to.
	 *
	 * For a caller that drives the batches itself — the admin's progress bar —
	 * rather than waiting on the background schedule.
	 *
	 * @param float $seconds Time budget for this batch.
	 * @param bool  $reset   Clear the per-contact cooldown first.
	 * @return array|WP_Error
	 */
	public static function enrich_once( $seconds = 10, $reset = false ) {
		if ( ! GHLD_Settings::is_configured() ) {
			return new WP_Error( 'ghld_not_configured', __( 'Add your GoHighLevel API token first.', 'gohighlevel-integration' ) );
		}

		if ( $reset ) {
			self::clear_enrich_cooldown();
		}

		$contacts = get_option( self::OPTION_CONTACTS, array() );
		if ( ! is_array( $contacts ) || empty( $contacts ) ) {
			return new WP_Error( 'ghld_no_contacts', __( 'There are no cached contacts yet — press "Sync now" first.', 'gohighlevel-integration' ) );
		}

		// A forced run is the answer to "this contact's photo is wrong", so it
		// re-reads everyone rather than only filling gaps.
		$contacts = self::enrich( $contacts, new GHLD_Client(), self::custom_fields(), GHLD_Settings::all(), $seconds, $reset );
		$contacts = self::localize_photos( $contacts );

		update_option( self::OPTION_CONTACTS, $contacts, false );
		self::$memo = null;

		$state = self::state();
		$with  = 0;
		foreach ( $contacts as $contact ) {
			if ( ! empty( $contact['photo'] ) ) {
				$with++;
			}
		}

		return array(
			'total'     => count( $contacts ),
			'remaining' => isset( $state['enrich_remaining'] ) ? (int) $state['enrich_remaining'] : 0,
			'fetched'   => isset( $state['enrich_fetched'] ) ? (int) $state['enrich_fetched'] : 0,
			'resolved'  => isset( $state['enrich_resolved'] ) ? (int) $state['enrich_resolved'] : 0,
			'photos'    => $with,
		);
	}

	/**
	 * Book the next enrichment run when work is still outstanding.
	 *
	 * @return void
	 */
	protected static function schedule_continuation() {
		$state = self::state();

		if ( empty( $state['enrich_remaining'] ) ) {
			return;
		}
		if ( wp_next_scheduled( self::CRON_ENRICH ) ) {
			return;
		}

		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON_ENRICH );
	}

	/**
	 * Give every contact a unique, readable slug for its own page.
	 *
	 * Names repeat in a directory this size, so a second Robert Bain becomes
	 * robert-bain-2. Slugs are assigned in the order the API returns contacts,
	 * which is stable between syncs, so a shared link keeps working.
	 *
	 * @param array $contacts Normalized contacts.
	 * @return array
	 */
	protected static function assign_slugs( array $contacts ) {
		$taken = array();

		foreach ( $contacts as $index => $contact ) {
			$base = sanitize_title( $contact['name'] );
			if ( '' === $base ) {
				$base = 'contact';
			}

			$slug   = $base;
			$suffix = 1;
			while ( isset( $taken[ $slug ] ) ) {
				$suffix++;
				$slug = $base . '-' . $suffix;
			}

			$taken[ $slug ]             = true;
			$contacts[ $index ]['slug'] = $slug;
		}

		return $contacts;
	}

	/**
	 * Find a cached contact by slug, within a directory's scope.
	 *
	 * Scope is enforced here too: a contact the directory does not list has no
	 * page, however the URL is typed.
	 *
	 * @param string $slug  Contact slug.
	 * @param array  $scope Resolved scope.
	 * @return array|null
	 */
	public static function find_by_slug( $slug, array $scope ) {
		$slug = sanitize_title( (string) $slug );
		if ( '' === $slug ) {
			return null;
		}

		foreach ( self::apply_scope( self::get_contacts(), $scope ) as $contact ) {
			if ( isset( $contact['slug'] ) && $contact['slug'] === $slug ) {
				return $contact;
			}
		}

		return null;
	}

	/**
	 * Forget that contacts were already asked for a headshot.
	 *
	 * The cooldown stops pointless re-fetching of contacts that have no photo,
	 * but it also means a headshot uploaded today is not looked for until
	 * tomorrow. This clears it so the next run asks everyone again.
	 *
	 * @return int Contacts whose cooldown was cleared.
	 */
	public static function clear_enrich_cooldown() {
		$contacts = get_option( self::OPTION_CONTACTS, array() );
		if ( ! is_array( $contacts ) ) {
			return 0;
		}

		$cleared = 0;
		foreach ( $contacts as $index => $contact ) {
			if ( isset( $contact['enriched_at'] ) ) {
				unset( $contacts[ $index ]['enriched_at'] );
				$cleared++;
			}
		}

		update_option( self::OPTION_CONTACTS, $contacts, false );
		self::update_state( array( 'enrich_cursor' => 0 ) );
		self::$memo = null;

		return $cleared;
	}

	/**
	 * Replace one contact in the cache with a freshly fetched record.
	 *
	 * @param array $contact Normalized contact.
	 * @return bool
	 */
	public static function store_contact( array $contact ) {
		if ( empty( $contact['id'] ) ) {
			return false;
		}

		$contacts = get_option( self::OPTION_CONTACTS, array() );
		$contacts = is_array( $contacts ) ? $contacts : array();

		foreach ( $contacts as $index => $existing ) {
			if ( isset( $existing['id'] ) && $existing['id'] === $contact['id'] ) {
				$contacts[ $index ] = $contact;
				update_option( self::OPTION_CONTACTS, $contacts, false );
				self::$memo = null;

				return true;
			}
		}

		return false;
	}

	/**
	 * Pull protected headshots onto this site.
	 *
	 * GoHighLevel serves a file-upload field through an API endpoint that
	 * answers only to the token, so the URL is useless in a browser. Each sync
	 * downloads a batch; contacts whose copy already exists cost nothing.
	 *
	 * @param array $contacts Normalized contacts.
	 * @return array
	 */
	protected static function localize_photos( array $contacts ) {
		if ( empty( GHLD_Settings::get( 'cache_photos' ) ) ) {
			return $contacts;
		}

		/**
		 * Filter how many headshots are downloaded per sync.
		 *
		 * @param int $budget Downloads per run.
		 */
		$budget     = max( 0, (int) apply_filters( 'ghld_photo_batch', 60 ) );
		$downloaded = 0;
		$pending    = 0;

		foreach ( $contacts as $index => $contact ) {
			$url = isset( $contact['photo'] ) ? (string) $contact['photo'] : '';

			if ( '' === $url || empty( $contact['id'] ) || ! GHLD_Photos::needs_local_copy( $url ) ) {
				continue;
			}

			$local = GHLD_Photos::localize( $url, $contact['id'], false );

			if ( '' === $local && $budget > $downloaded ) {
				$local = GHLD_Photos::localize( $url, $contact['id'], true );
				$downloaded++;
			} elseif ( '' === $local ) {
				$pending++;
				continue;
			}

			if ( '' !== $local ) {
				$contacts[ $index ]['photo'] = $local;
			}
		}

		self::update_state(
			array(
				'photos_downloaded' => $downloaded,
				'photos_pending'    => $pending,
			)
		);

		return $contacts;
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
