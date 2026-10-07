<?php
/**
 * Looks at the directory the way the public sees it.
 *
 * @package GoHighLevel_Integration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fetches the public directory page without a login and reports what came back.
 *
 * "It looks right to me but wrong to everybody else" cannot be investigated
 * from a logged-in admin screen, because the admin is the one visitor a page
 * cache deliberately treats differently. So the plugin asks for its own page
 * over HTTP with no cookies — exactly what a stranger gets — and reports the
 * render stamp, the cache headers and whether the fields are in the markup.
 *
 * It asks twice: once for the plain URL, and once with a unique query argument.
 * A page cache almost always treats a never-before-seen URL as a miss and
 * builds it fresh, so the second response is the live page. If the two differ,
 * the plain URL is being served from a cache, and the difference is the proof.
 */
class GHLD_Diagnostics {

	/**
	 * Where a probe's findings wait for the page to render them.
	 */
	const TRANSIENT = 'ghld_probe';

	/**
	 * The address of the page the directory shortcode is on.
	 *
	 * @return string Empty when no published post carries the shortcode.
	 */
	public static function find_directory_url() {
		global $wpdb;

		// A LIKE over post_content is the only way to find a shortcode's page:
		// there is no index of which post uses which shortcode. Most recently
		// edited wins, which is the one being worked on.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_content LIKE %s ORDER BY post_modified DESC LIMIT 1",
				'%' . $wpdb->esc_like( '[' . GHLD_Shortcode::TAG ) . '%'
			)
		);

		return $id ? (string) get_permalink( (int) $id ) : '';
	}

	/**
	 * Fetch the directory page twice and compare.
	 *
	 * @param string $url Directory URL.
	 * @return array
	 */
	public static function probe( $url ) {
		$buster = add_query_arg( 'ghld_cache_check', (string) time(), $url );

		return array(
			'url'    => $url,
			'cached' => self::request( $url ),
			'fresh'  => self::request( $buster ),
			'layers' => GHLD_Purge::targets(),
			'purge'  => GHLD_Purge::last(),
			'now'    => time(),
		);
	}

	/**
	 * One cookie-free request, described.
	 *
	 * @param string $url URL to fetch.
	 * @return array
	 */
	protected static function request( $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 20,
				'redirection' => 3,
				// No cookies: this has to be an anonymous request, or it tells
				// us only what the administrator already sees.
				'cookies'     => array(),
				'headers'     => array(
					'Cache-Control' => 'no-cache',
					'Pragma'        => 'no-cache',
				),
				'user-agent'  => 'GoHighLevel Integration cache check',
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'error' => $response->get_error_message() );
		}

		$body = (string) wp_remote_retrieve_body( $response );

		return array(
			'status'      => (int) wp_remote_retrieve_response_code( $response ),
			'bytes'       => strlen( $body ),
			'marker'      => self::marker( $body ),
			'cards'       => substr_count( $body, 'ghld-grid-item' ),
			'specialties' => substr_count( $body, 'ghld-specialty' ),
			'stylesheet'  => self::stylesheet( $body ),
			'headers'     => self::cache_headers( $response ),
		);
	}

	/**
	 * Read the render stamp the shortcode leaves in the markup.
	 *
	 * @param string $body Response body.
	 * @return array
	 */
	public static function marker( $body ) {
		if ( ! preg_match( '/<!--\s*ghld\s+([^>]*?)-->/', $body, $match ) ) {
			return array();
		}

		$facts = array();
		foreach ( preg_split( '/\s+/', trim( $match[1] ) ) as $pair ) {
			$bits = explode( '=', $pair, 2 );
			if ( 2 === count( $bits ) && '' !== $bits[0] ) {
				$facts[ $bits[0] ] = $bits[1];
			}
		}

		return $facts;
	}

	/**
	 * The plugin version the page's stylesheet URL asks for.
	 *
	 * Stale HTML asks for the stylesheet that shipped with it, so this dates the
	 * markup independently of the render stamp — and explains a page that has
	 * the right fields but none of the styling.
	 *
	 * @param string $body Response body.
	 * @return string
	 */
	public static function stylesheet( $body ) {
		if ( preg_match( '/gohighlevel-integration\.css\?ver=([0-9a-zA-Z.\-]+)/', $body, $match ) ) {
			return $match[1];
		}

		return preg_match( '/gohighlevel-integration\.css/', $body ) ? __( '(no version)', 'gohighlevel-integration' ) : '';
	}

	/**
	 * The response headers that say whether something cached this.
	 *
	 * @param array|WP_Error $response Response from wp_remote_get().
	 * @return array
	 */
	public static function cache_headers( $response ) {
		$interesting = array(
			'x-cache',
			'x-cache-hits',
			'x-cacheable',
			'age',
			'cache-control',
			'cf-cache-status',
			'x-litespeed-cache',
			'x-rocket-nginx-serving',
			'x-nitro-cache',
			'x-wpe-backend',
			'x-wpengine-cache',
			'server',
		);

		$headers = wp_remote_retrieve_headers( $response );
		$found   = array();

		foreach ( $interesting as $name ) {
			$value = is_object( $headers ) && method_exists( $headers, 'offsetGet' )
				? $headers->offsetGet( $name )
				: ( isset( $headers[ $name ] ) ? $headers[ $name ] : '' );

			if ( is_array( $value ) ) {
				$value = implode( ', ', $value );
			}

			if ( '' !== (string) $value && null !== $value ) {
				$found[ $name ] = (string) $value;
			}
		}

		return $found;
	}

	/**
	 * What the two responses, taken together, mean.
	 *
	 * @param array $probe Result of probe().
	 * @return array Verdict heading and explanation.
	 */
	public static function verdict( array $probe ) {
		$cached = isset( $probe['cached'] ) ? $probe['cached'] : array();
		$fresh  = isset( $probe['fresh'] ) ? $probe['fresh'] : array();

		if ( ! empty( $cached['error'] ) ) {
			return array(
				'state' => 'error',
				'title' => __( 'Could not reach the page.', 'gohighlevel-integration' ),
				'body'  => sprintf(
					/* translators: %s: error message. */
					__( 'WordPress could not request its own page: %s. That blocks this check, not the directory itself.', 'gohighlevel-integration' ),
					$cached['error']
				),
			);
		}

		if ( empty( $cached['marker'] ) && empty( $fresh['marker'] ) ) {
			return array(
				'state' => 'error',
				'title' => __( 'The directory is not on that page.', 'gohighlevel-integration' ),
				'body'  => __( 'Neither response carried this plugin\'s render stamp, so the page fetched is not rendering the [ghl_directory] shortcode. Check that the URL below is the page the shortcode is on — a second, older copy of the page is the usual answer.', 'gohighlevel-integration' ),
			);
		}

		$cached_at = isset( $cached['marker']['rendered'] ) ? $cached['marker']['rendered'] : '';
		$fresh_at  = isset( $fresh['marker']['rendered'] ) ? $fresh['marker']['rendered'] : '';
		$same      = ( '' !== $cached_at && $cached_at === $fresh_at );

		if ( $same ) {
			return array(
				'state' => 'ok',
				'title' => __( 'The public page is live, not cached.', 'gohighlevel-integration' ),
				'body'  => __( 'Both requests were built just now, so visitors are getting this install\'s current output. If somebody still reports missing details, they are looking at a different address, or at their own browser\'s copy — a hard reload settles that.', 'gohighlevel-integration' ),
			);
		}

		$version_gap = isset( $cached['marker']['v'], $fresh['marker']['v'] )
			&& $cached['marker']['v'] !== $fresh['marker']['v'];

		return array(
			'state' => 'warning',
			'title' => __( 'Visitors are being served a cached copy.', 'gohighlevel-integration' ),
			'body'  => $version_gap
				? sprintf(
					/* translators: 1: cached version, 2: current version. */
					__( 'The plain URL returned HTML built by version %1$s, while a fresh request returns %2$s. Something in front of WordPress is holding an old page. Clearing from here only works if that cache is listed below; if the list is empty, purge from your host\'s dashboard, or turn on "Never cache the directory page".', 'gohighlevel-integration' ),
					$cached['marker']['v'],
					$fresh['marker']['v']
				)
				: __( 'The plain URL returned HTML that was built earlier, while a fresh request is built now. Something in front of WordPress is holding an old page. Clearing from here only works if that cache is listed below; if the list is empty, purge from your host\'s dashboard, or turn on "Never cache the directory page".', 'gohighlevel-integration' ),
		);
	}
}
