<?php
/**
 * Keeps generated views out of search results.
 *
 * @package GoHighLevel_Integration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Marks contact pages and filtered views noindex.
 *
 * A contact page and a filtered grid are the same page with different query
 * arguments, so left alone they read as a few hundred near-duplicates of the
 * directory. The directory page itself is untouched and stays indexable.
 *
 * Crawling is deliberately still allowed: a page has to be fetched for its
 * noindex to be seen, so blocking these in robots.txt would achieve the
 * opposite of what it looks like.
 */
class GHLD_Seo {

	/**
	 * Hook the robots output and the SEO plugins that would override it.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'print_robots' ), 1 );

		// Yoast and Rank Math print their own robots tag; without these they
		// would cheerfully say index alongside ours.
		add_filter( 'wpseo_robots', array( __CLASS__, 'filter_robots_string' ), 20 );
		add_filter( 'wpseo_robots_array', array( __CLASS__, 'filter_robots_array' ), 20 );
		add_filter( 'rank_math/frontend/robots', array( __CLASS__, 'filter_robots_array' ), 20 );
		add_filter( 'aioseo_robots_meta', array( __CLASS__, 'filter_robots_array' ), 20 );
	}

	/**
	 * Whether the current request is a generated view rather than the directory.
	 *
	 * @return bool
	 */
	public static function is_restricted() {
		if ( is_admin() || empty( GHLD_Settings::get( 'noindex_generated', 1 ) ) ) {
			return false;
		}

		if ( '' !== GHLD_Shortcode::requested_slug() ) {
			return true;
		}

		// Any filter, sort or page argument: the same content, rearranged.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		foreach ( array_keys( (array) $_GET ) as $key ) {
			if ( 0 === strpos( (string) $key, GHLD_Shortcode::QUERY_PREFIX ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Print the robots tag.
	 *
	 * @return void
	 */
	public static function print_robots() {
		if ( ! self::is_restricted() ) {
			return;
		}

		// "follow" so links out of the page still carry weight, and so the
		// directory page itself is reached normally.
		echo '<meta name="robots" content="noindex, follow" />' . "\n";
	}

	/**
	 * Force noindex in an SEO plugin's robots array.
	 *
	 * @param mixed $robots Robots directives.
	 * @return mixed
	 */
	public static function filter_robots_array( $robots ) {
		if ( ! is_array( $robots ) || ! self::is_restricted() ) {
			return $robots;
		}

		$robots['index'] = 'noindex';

		return $robots;
	}

	/**
	 * Force noindex in an SEO plugin's robots string.
	 *
	 * @param string $robots Robots directives.
	 * @return string
	 */
	public static function filter_robots_string( $robots ) {
		return self::is_restricted() ? 'noindex, follow' : $robots;
	}
}
