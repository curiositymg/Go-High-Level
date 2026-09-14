<?php
/**
 * The [ghl_directory] shortcode.
 *
 * @package GoHighLevel_Integration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the directory and owns the shared "scope + request" plumbing that the
 * REST endpoint reuses when the filter bar re-queries.
 */
class GHLD_Shortcode {

	const TAG              = 'ghl_directory';
	const INSTANCE_PREFIX  = 'ghld_inst_';
	const QUERY_PREFIX     = 'ghld_';
	const INSTANCE_TTL     = WEEK_IN_SECONDS;

	/**
	 * Register the shortcode and its assets.
	 *
	 * @return void
	 */
	public static function init() {
		add_shortcode( self::TAG, array( __CLASS__, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	/**
	 * Register (and, on pages that use the shortcode, enqueue) CSS/JS.
	 *
	 * @return void
	 */
	public static function register_assets() {
		wp_register_style( 'gohighlevel-integration', GHLD_URL . 'assets/css/gohighlevel-integration.css', array(), GHLD_VERSION );
		wp_register_script( 'gohighlevel-integration', GHLD_URL . 'assets/js/gohighlevel-integration.js', array(), GHLD_VERSION, true );

		wp_localize_script(
			'gohighlevel-integration',
			'GHLDirectory',
			array(
				'endpoint' => esc_url_raw( rest_url( GHLD_Rest::NAMESPACE_V1 . '/contacts' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'prefix'   => self::QUERY_PREFIX,
				'i18n'     => array(
					'loading' => __( 'Loading contacts…', 'gohighlevel-integration' ),
					'error'   => __( 'Could not load contacts. Please try again.', 'gohighlevel-integration' ),
				),
			)
		);

		$post = get_post();
		if ( $post instanceof WP_Post && has_shortcode( (string) $post->post_content, self::TAG ) ) {
			self::enqueue_assets();
		}
	}

	/**
	 * Enqueue the registered assets.
	 *
	 * @return void
	 */
	public static function enqueue_assets() {
		wp_enqueue_style( 'gohighlevel-integration' );
		wp_enqueue_script( 'gohighlevel-integration' );
	}

	/**
	 * Shortcode callback.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function render( $atts ) {
		$scope    = self::build_scope( is_array( $atts ) ? $atts : array() );
		$instance = self::register_instance( $scope );

		// $_GET drives the no-JS fallback; every value is sanitized in parse_request().
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$request = self::parse_request( wp_unslash( $_GET ), $scope );

		self::enqueue_assets();

		$rendered = self::render_results( $scope, $request );

		return GHLD_Template::get(
			'directory',
			array(
				'scope'      => $scope,
				'request'    => $request,
				'instance'   => $instance,
				'results'    => $rendered['results'],
				'pagination' => $rendered['pagination'],
				'facets'     => $rendered['facets'],
				'total'      => $rendered['total'],
				'summary'    => $rendered['summary'],
				'dom_id'     => 'ghld-' . $instance,
			)
		);
	}

	/**
	 * Run a query and render the two HTML fragments the front end swaps.
	 *
	 * @param array $scope   Resolved scope.
	 * @param array $request Sanitized visitor filters.
	 * @return array{results:string,pagination:string,facets:array,total:int,page:int,pages:int,summary:string}
	 */
	public static function render_results( array $scope, array $request ) {
		$query = GHLD_Repository::query( $scope, $request );

		$results = GHLD_Template::get(
			'results',
			array(
				'items' => $query['items'],
				'scope' => $scope,
				'total' => $query['total'],
			)
		);

		$pagination = GHLD_Template::get(
			'pagination',
			array(
				'page'    => $query['page'],
				'pages'   => $query['pages'],
				'request' => $request,
				'scope'   => $scope,
			)
		);

		return array(
			'results'    => $results,
			'pagination' => $pagination,
			'facets'     => $query['facets'],
			'total'      => (int) $query['total'],
			'page'       => (int) $query['page'],
			'pages'      => (int) $query['pages'],
			'summary'    => self::summary( $query ),
		);
	}

	/**
	 * "Showing 1–24 of 108 contacts" line.
	 *
	 * @param array $query Query result.
	 * @return string
	 */
	protected static function summary( array $query ) {
		$total = (int) $query['total'];
		if ( 0 === $total ) {
			return __( 'No contacts found', 'gohighlevel-integration' );
		}

		$first = ( ( (int) $query['page'] - 1 ) * (int) $query['per_page'] ) + 1;
		$last  = min( $total, $first + count( $query['items'] ) - 1 );

		return sprintf(
			/* translators: 1: first result number, 2: last result number, 3: total results. */
			_n( 'Showing %1$d–%2$d of %3$d contact', 'Showing %1$d–%2$d of %3$d contacts', $total, 'gohighlevel-integration' ),
			$first,
			$last,
			$total
		);
	}

	/**
	 * Merge shortcode attributes over the saved settings.
	 *
	 * @param array $atts Raw shortcode attributes.
	 * @return array
	 */
	public static function build_scope( array $atts ) {
		$settings = GHLD_Settings::all();

		$atts = shortcode_atts(
			array(
				'columns'      => (int) $settings['columns'],
				'per_page'     => (int) $settings['per_page'],
				'tags'         => $settings['include_tags'],
				'exclude_tags' => $settings['exclude_tags'],
				'filters'      => implode( ',', (array) $settings['filters'] ),
				'show'         => implode( ',', (array) $settings['show'] ),
				'name_format'  => $settings['name_format'],
				'modal'        => $settings['modal'] ? 'yes' : 'no',
				'modal_show'   => implode( ',', (array) $settings['modal_show'] ),
				'orderby'      => $settings['orderby'],
				'order'        => $settings['order'],
				'layout'       => 'grid',
				'search'       => '',
				'empty'        => __( 'No contacts match your filters.', 'gohighlevel-integration' ),
			),
			array_change_key_case( $atts, CASE_LOWER ),
			self::TAG
		);

		$filters = GHLD_Settings::to_list( $atts['filters'] );
		if ( in_array( 'none', $filters, true ) ) {
			$filters = array();
		}

		$scope = array(
			'tags'         => array_merge(
				GHLD_Settings::to_list( $settings['include_tags'] ),
				GHLD_Settings::to_list( $atts['tags'] )
			),
			'exclude_tags' => array_merge(
				GHLD_Settings::to_list( $settings['exclude_tags'] ),
				GHLD_Settings::to_list( $atts['exclude_tags'] )
			),
			'filters'      => array_values( array_intersect( $filters, self::allowed_filters() ) ),
			'show'         => array_values( array_intersect( GHLD_Settings::to_list( $atts['show'] ), self::allowed_show() ) ),
			'name_format'  => GHLD_Settings::sanitize_choice( $atts['name_format'], GHLD_Settings::name_format_choices(), 'name_title' ),
			'modal'        => self::is_truthy( $atts['modal'] ),
			'modal_show'   => array_values( array_intersect( GHLD_Settings::to_list( $atts['modal_show'] ), self::allowed_show() ) ),
			'columns'      => min( 6, max( 1, (int) $atts['columns'] ) ),
			'per_page'     => min( 200, max( 1, (int) $atts['per_page'] ) ),
			'orderby'      => GHLD_Settings::sanitize_choice( $atts['orderby'], GHLD_Settings::orderby_choices(), 'name' ),
			'order'        => ( 'desc' === strtolower( (string) $atts['order'] ) ) ? 'desc' : 'asc',
			'layout'       => ( 'list' === strtolower( (string) $atts['layout'] ) ) ? 'list' : 'grid',
			'search'       => sanitize_text_field( (string) $atts['search'] ),
			'empty'        => sanitize_text_field( (string) $atts['empty'] ),
		);

		$scope['tags']         = array_values( array_unique( $scope['tags'] ) );
		$scope['exclude_tags'] = array_values( array_unique( $scope['exclude_tags'] ) );

		/**
		 * Filter the resolved directory scope.
		 *
		 * @param array $scope Resolved scope.
		 * @param array $atts  Shortcode attributes.
		 */
		return apply_filters( 'ghld_directory_scope', $scope, $atts );
	}

	/**
	 * Filter controls this plugin knows how to render.
	 *
	 * Custom-field filters (`cf:<key>`) are added on top of this list.
	 *
	 * @return string[]
	 */
	public static function allowed_filters() {
		$base = array( 'search', 'tag', 'city', 'state', 'company', 'sort' );

		foreach ( GHLD_Repository::custom_fields() as $field ) {
			$base[] = 'cf:' . $field['key'];
		}

		return $base;
	}

	/**
	 * Card elements that can be toggled on or off.
	 *
	 * @return string[]
	 */
	public static function allowed_show() {
		$base = array( 'photo', 'title', 'specialty', 'company', 'location', 'address', 'tags', 'email', 'phone', 'fax', 'website', 'bio' );

		foreach ( GHLD_Repository::custom_fields() as $field ) {
			$base[] = 'cf:' . $field['key'];
		}

		return $base;
	}

	/**
	 * Custom field keys already claimed by a field mapping.
	 *
	 * A mapped field is printed in its own place — as the title, the specialty,
	 * the bio, the photo — so it should not also appear as a generic labelled
	 * row when someone ticks it in both lists.
	 *
	 * @return string[]
	 */
	public static function mapped_custom_keys() {
		$keys = array();

		foreach ( array( 'photo_field', 'title_field', 'specialty_field', 'company_field', 'fax_field', 'bio_field' ) as $setting ) {
			$value = (string) GHLD_Settings::get( $setting, '' );
			if ( 0 === strpos( $value, 'cf:' ) ) {
				$keys[] = substr( $value, 3 );
			}
		}

		return $keys;
	}

	/**
	 * The title to print on the name line, if any.
	 *
	 * Empty unless the directory is set to "Name, Title", the contact actually
	 * has a title, and the title is switched on for the card — so unmapping the
	 * title field or unticking it removes the suffix along with everything else.
	 *
	 * @param array $contact Normalized contact.
	 * @param array $scope   Resolved scope.
	 * @return string
	 */
	public static function inline_title( array $contact, array $scope ) {
		$format = isset( $scope['name_format'] ) ? $scope['name_format'] : 'name';
		$show   = isset( $scope['show'] ) ? (array) $scope['show'] : array();
		$title  = isset( $contact['title'] ) ? trim( (string) $contact['title'] ) : '';

		if ( 'name_title' !== $format || '' === $title || ! in_array( 'title', $show, true ) ) {
			return '';
		}

		return $title;
	}

	/**
	 * The full name line as one string, for the dialog heading.
	 *
	 * @param array $contact Normalized contact.
	 * @param array $scope   Resolved scope.
	 * @return string
	 */
	public static function display_name( array $contact, array $scope ) {
		$name  = isset( $contact['name'] ) ? (string) $contact['name'] : '';
		$title = self::inline_title( $contact, $scope );

		return ( '' === $title ) ? $name : $name . ', ' . $title;
	}

	/**
	 * Read a yes/no shortcode attribute.
	 *
	 * @param mixed $value Attribute value.
	 * @return bool
	 */
	public static function is_truthy( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'yes', 'true', 'on' ), true );
	}

	/**
	 * Store a scope so the REST endpoint can only ever query within it.
	 *
	 * Without this the endpoint would take its own tag/filter arguments and a
	 * visitor could widen a deliberately narrowed directory by editing the
	 * request. Every page render refreshes the entry's lifetime.
	 *
	 * @param array $scope Resolved scope.
	 * @return string Instance key.
	 */
	public static function register_instance( array $scope ) {
		$key = substr( md5( (string) wp_json_encode( $scope ) ), 0, 16 );
		set_transient( self::INSTANCE_PREFIX . $key, $scope, self::INSTANCE_TTL );

		return $key;
	}

	/**
	 * Look a scope back up by instance key.
	 *
	 * @param string $key Instance key.
	 * @return array|null
	 */
	public static function get_instance( $key ) {
		$key = preg_replace( '/[^a-f0-9]/', '', (string) $key );
		if ( '' === $key ) {
			return null;
		}

		$scope = get_transient( self::INSTANCE_PREFIX . $key );

		return is_array( $scope ) ? $scope : null;
	}

	/**
	 * Sanitize visitor-supplied filters, dropping anything the scope disallows.
	 *
	 * @param array $source Raw parameters ($_GET or the REST request).
	 * @param array $scope  Resolved scope.
	 * @return array
	 */
	public static function parse_request( array $source, array $scope ) {
		$prefix  = self::QUERY_PREFIX;
		$filters = isset( $scope['filters'] ) ? (array) $scope['filters'] : array();
		$request = array(
			'search'  => isset( $scope['search'] ) ? (string) $scope['search'] : '',
			'typed'   => '',
			'tag'     => '',
			'city'    => '',
			'state'   => '',
			'company' => '',
			'custom'  => array(),
			'orderby' => $scope['orderby'],
			'order'   => $scope['order'],
			'page'    => 1,
		);

		$read = static function ( $key ) use ( $source, $prefix ) {
			$name = $prefix . $key;

			return isset( $source[ $name ] ) && is_scalar( $source[ $name ] ) ? sanitize_text_field( (string) $source[ $name ] ) : '';
		};

		if ( in_array( 'search', $filters, true ) ) {
			$typed = $read( 's' );
			if ( '' !== $typed ) {
				$request['typed']  = $typed;
				$request['search'] = trim( $scope['search'] . ' ' . $typed );
			}
		}

		foreach ( array( 'tag', 'city', 'state', 'company' ) as $key ) {
			if ( in_array( $key, $filters, true ) ) {
				$request[ $key ] = $read( $key );
			}
		}

		foreach ( $filters as $filter ) {
			if ( 0 !== strpos( $filter, 'cf:' ) ) {
				continue;
			}
			$key   = substr( $filter, 3 );
			$value = $read( 'cf_' . $key );
			if ( '' !== $value ) {
				$request['custom'][ $key ] = $value;
			}
		}

		if ( in_array( 'sort', $filters, true ) ) {
			$sort = $read( 'sort' );
			if ( '' !== $sort ) {
				$parts   = array_pad( explode( '-', $sort, 2 ), 2, '' );
				$orderby = GHLD_Settings::sanitize_choice( $parts[0], GHLD_Settings::orderby_choices(), '' );

				// An unrecognized sort key leaves the scope's own sort alone,
				// direction included, rather than half-applying the request.
				if ( '' !== $orderby ) {
					$request['orderby'] = $orderby;
					$request['order']   = ( 'desc' === $parts[1] ) ? 'desc' : 'asc';
				}
			}
		}

		$page            = (int) $read( 'page' );
		$request['page'] = $page > 0 ? $page : 1;

		return $request;
	}

	/**
	 * Query-string arguments representing the current filter state.
	 *
	 * @param array $request Sanitized request.
	 * @return array
	 */
	public static function request_to_query( array $request ) {
		$prefix = self::QUERY_PREFIX;
		$query  = array();

		if ( ! empty( $request['typed'] ) ) {
			$query[ $prefix . 's' ] = $request['typed'];
		}

		foreach ( array( 'tag', 'city', 'state', 'company' ) as $key ) {
			if ( ! empty( $request[ $key ] ) ) {
				$query[ $prefix . $key ] = $request[ $key ];
			}
		}
		foreach ( (array) $request['custom'] as $key => $value ) {
			if ( '' !== $value ) {
				$query[ $prefix . 'cf_' . $key ] = $value;
			}
		}
		if ( ! empty( $request['orderby'] ) ) {
			$query[ $prefix . 'sort' ] = $request['orderby'] . '-' . $request['order'];
		}

		return $query;
	}
}
