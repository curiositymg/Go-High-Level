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
	const HANDLE           = 'gohighlevel-integration';
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
		add_filter( 'the_content', array( __CLASS__, 'isolate_profile' ), 1 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
	}

	/**
	 * Register (and, on pages that use the shortcode, enqueue) CSS/JS.
	 *
	 * @return void
	 */
	public static function register_assets() {
		wp_register_style( self::HANDLE, GHLD_URL . 'assets/css/gohighlevel-integration.css', array(), GHLD_VERSION );
		wp_register_script( self::HANDLE, GHLD_URL . 'assets/js/gohighlevel-integration.js', array(), GHLD_VERSION, true );

		wp_localize_script(
			self::HANDLE,
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

		if ( self::page_has_directory() ) {
			self::enqueue_assets();
		}
	}

	/**
	 * Enqueue the registered assets.
	 *
	 * @return void
	 */
	public static function enqueue_assets() {
		wp_enqueue_style( self::HANDLE );
		wp_enqueue_script( self::HANDLE );
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

		self::enqueue_assets();

		// A contact in the URL replaces the grid with that contact's page.
		$requested = self::requested_slug();
		if ( '' !== $requested ) {
			$contact = GHLD_Repository::find_by_slug( $requested, $scope );

			if ( null !== $contact ) {
				return self::mark(
					GHLD_Template::get(
						'contact-profile',
						array(
							'contact' => $contact,
							'scope'   => $scope,
							'back'    => self::directory_url(),
						)
					),
					'profile'
				);
			}
		}

		// $_GET drives the no-JS fallback; every value is sanitized in parse_request().
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$request = self::parse_request( wp_unslash( $_GET ), $scope );

		$rendered = self::render_results( $scope, $request );

		return self::mark(
			GHLD_Template::get(
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
			),
			'directory'
		);
	}

	/**
	 * Make sure the stylesheet actually reaches the page.
	 *
	 * Enqueuing is the right way to ask for a stylesheet, and it is not a
	 * guarantee that one arrives. The <link> is written into <head>, which has
	 * already been sent by the time a shortcode inside the content runs, so a
	 * page whose directory is rendered by a builder, a widget or a template
	 * rather than from post_content misses that window; WordPress will print
	 * such a stylesheet in the footer instead, unless an optimisation plugin —
	 * which typically runs for anonymous visitors and not for logged-in
	 * administrators — drops or rewrites it on the way out. Either way the
	 * styling is missing for everybody except the person checking.
	 *
	 * So the markup carries its own stylesheet whenever the page has not
	 * already printed one. A <link> in the body is valid, is part of the HTML a
	 * cache stores, and costs nothing when the head got there first.
	 *
	 * @return string Markup to put in front of the directory, possibly empty.
	 */
	protected static function stylesheet_fallback() {
		// Before wp_head, the normal path still has its chance.
		if ( ! did_action( 'wp_head' ) || wp_style_is( self::HANDLE, 'done' ) ) {
			return '';
		}

		if ( ! empty( GHLD_Settings::get( 'inline_css', 0 ) ) ) {
			$css = self::stylesheet_contents();

			if ( '' !== $css ) {
				return '<style id="ghld-css-inline">' . $css . '</style>' . "\n";
			}
		}

		return sprintf(
			'<link rel="stylesheet" id="ghld-css-late" href="%s" />' . "\n",
			esc_url( GHLD_URL . 'assets/css/gohighlevel-integration.css?ver=' . GHLD_VERSION )
		);
	}

	/**
	 * The stylesheet's own text, for inlining.
	 *
	 * The last resort for an install where the file is on disk but its URL does
	 * not resolve — a rewritten asset host, a CDN that never fetched it, a
	 * permission that stops the webserver serving it. Inlining sidesteps the
	 * URL entirely.
	 *
	 * @return string
	 */
	protected static function stylesheet_contents() {
		$path = GHLD_PATH . 'assets/css/gohighlevel-integration.css';

		if ( ! is_readable( $path ) ) {
			return '';
		}

		$css = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		// The file is ours, but it is going inside a <style> element, and
		// nothing that could close one early belongs in there.
		return str_replace( array( '</style', '<script' ), '', (string) $css );
	}

	/**
	 * How the stylesheet reached this page, for the render stamp.
	 *
	 * Read off what the fallback actually emitted rather than decided again, so
	 * the stamp cannot claim a route the page did not take.
	 *
	 * @param string $fallback Output of stylesheet_fallback().
	 * @return string
	 */
	protected static function stylesheet_route( $fallback ) {
		if ( '' !== $fallback ) {
			return false === strpos( $fallback, '<style' ) ? 'late' : 'inline';
		}

		return wp_style_is( self::HANDLE, 'done' ) ? 'head' : 'queued';
	}

	/**
	 * Stamp the output with what produced it.
	 *
	 * An HTML comment, so it costs a visitor nothing and shows a browser
	 * nothing — but it answers the one question that cannot otherwise be
	 * answered from outside: is the page somebody is looking at the page this
	 * install would render right now, or a copy a cache kept? "rendered" is the
	 * moment this HTML was built. If it reads minutes ago, the visitor has the
	 * live page and the problem is in the data. If it reads days ago, they have
	 * a cached copy and the data is irrelevant.
	 *
	 * @param string $html Rendered markup.
	 * @param string $kind directory or profile.
	 * @return string
	 */
	protected static function mark( $html, $kind ) {
		$state    = GHLD_Repository::state();
		$synced   = isset( $state['synced_at'] ) ? (int) $state['synced_at'] : 0;
		$fallback = self::stylesheet_fallback();

		$facts = array(
			'v'        => GHLD_VERSION,
			'view'     => $kind,
			'post'     => (string) get_the_ID(),
			'contacts' => isset( $state['count'] ) ? (string) (int) $state['count'] : '0',
			// ISO 8601, so no value contains a space: the stamp is read back by
			// splitting on whitespace, and a space inside a value would cut it
			// in half.
			'synced'   => $synced ? gmdate( 'Y-m-d\\TH:i\\Z', $synced ) : 'never',
			'rendered' => gmdate( 'Y-m-d\\TH:i:s\\Z' ),
			'css'      => self::stylesheet_route( $fallback ),
		);

		$pairs = array();
		foreach ( $facts as $key => $value ) {
			// Nothing here can close the comment early, but a value that could
			// would break the page rather than the comment.
			$pairs[] = $key . '=' . str_replace( array( '--', '>', ' ' ), '', (string) $value );
		}

		return "<!-- ghld " . implode( ' ', $pairs ) . " -->\n" . $fallback . $html;
	}

	/**
	 * Whether the page being served shows the directory.
	 *
	 * @return bool
	 */
	public static function page_has_directory() {
		$post = get_post();

		return ( $post instanceof WP_Post ) && has_shortcode( (string) $post->post_content, self::TAG );
	}

	/**
	 * The contact whose page is being rendered, for code running inside it.
	 *
	 * @var array|null
	 */
	protected static $current_contact = null;

	/**
	 * Set or clear the contact being rendered.
	 *
	 * @param array|null $contact Normalized contact.
	 * @return void
	 */
	public static function set_current_contact( $contact ) {
		self::$current_contact = is_array( $contact ) ? $contact : null;
	}

	/**
	 * The contact whose page is being rendered.
	 *
	 * Lets a shortcode in the contact-page panel address the physician it is
	 * sitting beside — "Are you Dr. Josten?" needs to know who that is.
	 *
	 * @return array|null
	 */
	public static function current_contact() {
		return self::$current_contact;
	}

	/**
	 * Replace {placeholders} in the contact-page panel.
	 *
	 * Covers the common case without anybody writing PHP at all.
	 *
	 * @param string $content Panel markup.
	 * @param array  $contact Normalized contact.
	 * @return string
	 */
	public static function fill_placeholders( $content, array $contact ) {
		$content = (string) $content;

		if ( false === strpos( $content, '{' ) ) {
			return $content;
		}

		$values = array(
			'{name}'       => isset( $contact['name'] ) ? $contact['name'] : '',
			'{first_name}' => isset( $contact['first_name'] ) ? $contact['first_name'] : '',
			'{last_name}'  => isset( $contact['last_name'] ) ? $contact['last_name'] : '',
			'{title}'      => isset( $contact['title'] ) ? $contact['title'] : '',
			'{specialty}'  => isset( $contact['specialty'] ) ? $contact['specialty'] : '',
			'{company}'    => isset( $contact['company'] ) ? $contact['company'] : '',
			'{city}'       => isset( $contact['city'] ) ? $contact['city'] : '',
			'{state}'      => isset( $contact['state'] ) ? $contact['state'] : '',
			'{email}'      => isset( $contact['email'] ) ? $contact['email'] : '',
			'{phone}'      => isset( $contact['phone'] ) ? $contact['phone'] : '',
			'{slug}'       => isset( $contact['slug'] ) ? $contact['slug'] : '',
		);

		foreach ( $values as $token => $value ) {
			$content = str_replace( $token, esc_html( (string) $value ), $content );
		}

		return $content;
	}

	/**
	 * Show only the contact on a contact page.
	 *
	 * The directory lives on an ordinary page, so everything else that page
	 * carries — intro copy, banners, calls to action — renders around the
	 * profile. On a contact page that is somebody else's page wrapped around
	 * one person, so the content is reduced to the directory shortcode alone
	 * and the shortcode renders the profile in its place.
	 *
	 * Runs before do_shortcode, and keeps the shortcode with its attributes
	 * rather than rebuilding it, so the directory's scope is preserved.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public static function isolate_profile( $content ) {
		if ( ! is_singular() || ! is_main_query() || ! in_the_loop() ) {
			return $content;
		}
		if ( empty( GHLD_Settings::get( 'isolate_profile', 1 ) ) ) {
			return $content;
		}
		if ( '' === self::requested_slug() || ! has_shortcode( $content, self::TAG ) ) {
			return $content;
		}

		if ( preg_match( '/\[' . self::TAG . '[^\]]*\]/', $content, $matches ) ) {
			return $matches[0];
		}

		return $content;
	}

	/**
	 * Mark the body so a theme can hide anything outside the content area.
	 *
	 * @param string[] $classes Body classes.
	 * @return string[]
	 */
	public static function body_class( $classes ) {
		if ( '' !== self::requested_slug() ) {
			$classes[] = 'ghld-contact-page';
		}

		return $classes;
	}

	/**
	 * The contact slug asked for in the URL, if any.
	 *
	 * @return string
	 */
	public static function requested_slug() {
		$key = self::QUERY_PREFIX . 'contact';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return sanitize_title( wp_unslash( $_GET[ $key ] ) );
		}

		$query_var = get_query_var( $key );

		return is_scalar( $query_var ) ? sanitize_title( (string) $query_var ) : '';
	}

	/**
	 * Link to one contact's page.
	 *
	 * Relative, like the pagination links, so it resolves against whichever
	 * page carries the shortcode — including when the markup is rendered for
	 * the REST endpoint, where there is no current page to build from.
	 *
	 * @param array $contact Normalized contact.
	 * @return string
	 */
	public static function profile_url( array $contact, array $request = array() ) {
		if ( empty( $contact['slug'] ) ) {
			return '';
		}

		// Everything the visitor had applied rides along, so the back link can
		// put them back where they were rather than at page one.
		$query = empty( $request ) ? array() : self::request_to_query( $request );
		if ( ! empty( $request['page'] ) && (int) $request['page'] > 1 ) {
			$query[ self::QUERY_PREFIX . 'page' ] = (int) $request['page'];
		}

		$query[ self::QUERY_PREFIX . 'contact' ] = $contact['slug'];

		return '?' . http_build_query( $query );
	}

	/**
	 * Link to the directory with every filter dropped.
	 *
	 * Query arguments that are not this plugin's — a campaign tag, say — are
	 * left alone, since clearing the filters is not the same as clearing the
	 * URL.
	 *
	 * @return string
	 */
	public static function clear_url() {
		$query = array();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		foreach ( (array) $_GET as $key => $value ) {
			$key = (string) $key;

			if ( 0 === strpos( $key, self::QUERY_PREFIX ) || ! is_scalar( $value ) ) {
				continue;
			}

			$query[ $key ] = sanitize_text_field( wp_unslash( (string) $value ) );
		}

		if ( ! empty( $query ) ) {
			return '?' . http_build_query( $query );
		}

		$path = wp_parse_url( home_url( add_query_arg( array() ) ), PHP_URL_PATH );

		return ( is_string( $path ) && '' !== $path ) ? $path : '/';
	}

	/**
	 * Link back to the directory itself.
	 *
	 * @return string
	 */
	public static function directory_url() {
		$query = array();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		foreach ( (array) $_GET as $key => $value ) {
			$key = (string) $key;

			if ( 0 !== strpos( $key, self::QUERY_PREFIX ) || ! is_scalar( $value ) ) {
				continue;
			}
			// Everything except the contact itself: that is what going back means.
			if ( self::QUERY_PREFIX . 'contact' === $key ) {
				continue;
			}

			$query[ $key ] = sanitize_text_field( wp_unslash( (string) $value ) );
		}

		if ( ! empty( $query ) ) {
			return '?' . http_build_query( $query );
		}

		$path = wp_parse_url( home_url( add_query_arg( array() ) ), PHP_URL_PATH );

		return ( is_string( $path ) && '' !== $path ) ? $path : '/';
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
				'items'   => $query['items'],
				'scope'   => $scope,
				'total'   => $query['total'],
				'request' => array_merge( $request, array( 'page' => $query['page'] ) ),
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
				'view'         => $settings['view'],
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
			'view'         => GHLD_Settings::sanitize_choice( $atts['view'], GHLD_Settings::view_choices(), 'page' ),
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

		foreach ( array( 'photo_field', 'title_field', 'specialty_field', 'specialty_field_2', 'company_field', 'fax_field', 'email_field', 'phone_field', 'bio_field' ) as $setting ) {
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
	 * The name line as markup, breaking a run of credentials onto its own line.
	 *
	 * "Kamel Elzawahry, MD, FACP, FAAN, FAHA, FAHS" is a name and five
	 * credentials. Kept on one line it wraps wherever it runs out of room,
	 * stranding a comma at the start of the next. More than one credential —
	 * which is to say, a comma inside the title — gets a line of its own, with
	 * the first comma left attached to the name where it belongs.
	 *
	 * @param array $contact Normalized contact.
	 * @param array $scope   Resolved scope.
	 * @return string Escaped HTML.
	 */
	public static function name_line_html( array $contact, array $scope ) {
		$name  = isset( $contact['name'] ) ? (string) $contact['name'] : '';
		$title = self::inline_title( $contact, $scope );

		if ( '' === $title ) {
			return esc_html( $name );
		}

		if ( self::has_several_credentials( $title ) ) {
			return esc_html( $name ) . ',<span class="ghld-name-title ghld-name-credentials">' . esc_html( $title ) . '</span>';
		}

		return esc_html( $name ) . '<span class="ghld-name-title">, ' . esc_html( $title ) . '</span>';
	}

	/**
	 * Whether a title holds more than one credential.
	 *
	 * @param string $title Title as mapped.
	 * @return bool
	 */
	public static function has_several_credentials( $title ) {
		return false !== strpos( (string) $title, ',' );
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
