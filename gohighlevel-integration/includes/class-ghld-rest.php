<?php
/**
 * REST endpoint backing the filter bar.
 *
 * @package GoHighLevel_Integration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Serves filtered/paginated directory fragments.
 *
 * The endpoint renders the same templates the shortcode does and returns HTML,
 * so cards have exactly one implementation. It is public — it only ever exposes
 * what the shortcode on the page already renders, and never accepts its own tag
 * scope: the `instance` key resolves to the scope the page registered.
 */
class GHLD_Rest {

	const NAMESPACE_V1 = 'gohighlevel-integration/v1';

	/**
	 * Hook the route registration.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register the contacts route.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/contacts',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_contacts' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'instance' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Handle a filter-bar request.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_contacts( WP_REST_Request $request ) {
		$scope = GHLD_Shortcode::get_instance( $request->get_param( 'instance' ) );

		if ( null === $scope ) {
			// The scope expired (or was never registered on this site); a page
			// reload re-registers it, so tell the client to fall back to a submit.
			return new WP_Error(
				'ghld_unknown_instance',
				__( 'This directory needs to be reloaded before it can be filtered.', 'gohighlevel-integration' ),
				array( 'status' => 404 )
			);
		}

		$parsed   = GHLD_Shortcode::parse_request( $request->get_params(), $scope );
		$rendered = GHLD_Shortcode::render_results( $scope, $parsed );

		return rest_ensure_response(
			array(
				'results'    => $rendered['results'],
				'pagination' => $rendered['pagination'],
				'summary'    => $rendered['summary'],
				'total'      => $rendered['total'],
				'page'       => $rendered['page'],
				'pages'      => $rendered['pages'],
			)
		);
	}
}
