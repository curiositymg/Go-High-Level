<?php
/**
 * Clears the page caches that sit in front of the directory.
 *
 * @package GoHighLevel_Integration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Purges host and plugin page caches after the directory changes.
 *
 * A managed host serves a cached copy of a page to logged-out visitors and
 * bypasses it for logged-in ones, so an administrator sees every change at once
 * while the public keeps whatever was cached last. That is indistinguishable
 * from a plugin bug from the outside, so the plugin clears those caches itself
 * whenever what the page would render has actually changed.
 *
 * Every purge records which handlers it was actually able to call. A purge that
 * reaches nothing is the useful answer to "they still see the old page": the
 * caching lives somewhere PHP cannot reach from here, and no amount of
 * re-purging will help.
 */
class GHLD_Purge {

	/**
	 * Where the last purge is recorded.
	 */
	const OPTION_LAST = 'ghld_last_purge';

	/**
	 * Register the cache-control side of things.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_no_cache' ), 1 );
	}

	/**
	 * Every cache this plugin knows how to clear, and whether it is here.
	 *
	 * The purge walks this list, and the settings screen prints it, so what the
	 * admin is told matches what actually runs.
	 *
	 * @return array Label => array( callable, available ).
	 */
	public static function targets() {
		$targets = array();

		// WP Engine: page cache, object cache, then the CDN. The CDN is the
		// layer that keeps serving old HTML after the others are cleared.
		$wpe = array(
			'purge_varnish_cache' => __( 'WP Engine page cache', 'gohighlevel-integration' ),
			'purge_memcached'     => __( 'WP Engine object cache', 'gohighlevel-integration' ),
			'clear_maxcdn_cache'  => __( 'WP Engine CDN', 'gohighlevel-integration' ),
		);
		foreach ( $wpe as $method => $label ) {
			$targets[ $label ] = array(
				'call'      => array( 'WpeCommon', $method ),
				'available' => class_exists( 'WpeCommon' ) && method_exists( 'WpeCommon', $method ),
			);
		}

		$functions = array(
			'rocket_clean_domain'                => __( 'WP Rocket', 'gohighlevel-integration' ),
			'w3tc_flush_all'                     => __( 'W3 Total Cache', 'gohighlevel-integration' ),
			'wp_cache_clear_cache'               => __( 'WP Super Cache', 'gohighlevel-integration' ),
			'sg_cachepress_purge_cache'          => __( 'SG Optimizer', 'gohighlevel-integration' ),
			'cache_enabler_clear_complete_cache' => __( 'Cache Enabler', 'gohighlevel-integration' ),
			'breeze_clear_all_cache'             => __( 'Breeze', 'gohighlevel-integration' ),
			'wphb_clear_page_cache'              => __( 'Hummingbird', 'gohighlevel-integration' ),
			'pantheon_clear_edge_all'            => __( 'Pantheon edge cache', 'gohighlevel-integration' ),
		);
		foreach ( $functions as $function => $label ) {
			$targets[ $label ] = array(
				'call'      => $function,
				'available' => function_exists( $function ),
			);
		}

		$statics = array(
			'autoptimizeCache'         => array( 'clearall', __( 'Autoptimize', 'gohighlevel-integration' ) ),
			'comet_cache'              => array( 'clear', __( 'Comet Cache', 'gohighlevel-integration' ) ),
			'Swift_Performance_Cache'  => array( 'clear_all_cache', __( 'Swift Performance', 'gohighlevel-integration' ) ),
		);
		foreach ( $statics as $class => $details ) {
			$targets[ $details[1] ] = array(
				'call'      => array( $class, $details[0] ),
				'available' => class_exists( $class ) && method_exists( $class, $details[0] ),
			);
		}

		return $targets;
	}

	/**
	 * Ask every cache we can reach to clear.
	 *
	 * Each call is guarded: these belong to other plugins and hosts, any of
	 * which may be absent or renamed, and none of them is allowed to take a
	 * sync down with it.
	 *
	 * @param string $reason What prompted the purge.
	 * @return array Labels of the caches that were actually asked to clear.
	 */
	public static function flush( $reason = '' ) {
		if ( empty( GHLD_Settings::get( 'purge_cache', 1 ) ) ) {
			return array();
		}

		$ran = array();

		foreach ( self::targets() as $label => $target ) {
			if ( empty( $target['available'] ) || ! is_callable( $target['call'] ) ) {
				continue;
			}

			call_user_func( $target['call'] );
			$ran[] = $label;
		}

		// Caches that listen for an action rather than exposing a function.
		// There is no way to know whether anybody answered, so these are not
		// counted as reached.
		do_action( 'litespeed_purge_all' );
		do_action( 'wpfc_clear_all_cache', true );
		do_action( 'nitropack_integration_purge_all' );
		do_action( 'ghld_purge_also', (string) $reason );

		if ( self::clear_elementor() ) {
			$ran[] = __( 'Elementor CSS cache', 'gohighlevel-integration' );
		}

		self::record( $reason, $ran );

		/**
		 * Fires after the directory has asked the caches to clear.
		 *
		 * @param string $reason settings, sync, contact or manual.
		 * @param array  $ran    Labels of the caches reached.
		 */
		do_action( 'ghld_purged_cache', (string) $reason, $ran );

		return $ran;
	}

	/**
	 * Clear Elementor's rendered CSS, which it caches per page.
	 *
	 * @return bool Whether Elementor was there to clear.
	 */
	protected static function clear_elementor() {
		if ( ! class_exists( '\\Elementor\\Plugin' ) ) {
			return false;
		}

		$elementor = \Elementor\Plugin::$instance;

		if ( ! isset( $elementor->files_manager ) || ! method_exists( $elementor->files_manager, 'clear_cache' ) ) {
			return false;
		}

		$elementor->files_manager->clear_cache();

		return true;
	}

	/**
	 * Note what the last purge managed to do.
	 *
	 * @param string $reason What prompted it.
	 * @param array  $ran    Labels reached.
	 * @return void
	 */
	protected static function record( $reason, array $ran ) {
		update_option(
			self::OPTION_LAST,
			array(
				'at'     => time(),
				'reason' => (string) $reason,
				'ran'    => $ran,
			),
			false
		);
	}

	/**
	 * The last purge, for the settings screen to report.
	 *
	 * @return array
	 */
	public static function last() {
		$last = get_option( self::OPTION_LAST, array() );

		return is_array( $last ) ? $last : array();
	}

	/**
	 * Tell the caches not to keep this page, when asked to.
	 *
	 * The escape hatch for a cache that purging cannot reach: rather than
	 * clearing a stale copy after the fact, never let one be stored. Costs the
	 * page its cache hit, which is why it is off unless chosen.
	 *
	 * @return void
	 */
	public static function maybe_no_cache() {
		if ( is_admin() || empty( GHLD_Settings::get( 'no_cache_directory', 0 ) ) ) {
			return;
		}

		if ( ! GHLD_Shortcode::page_has_directory() ) {
			return;
		}

		// Read by W3 Total Cache, WP Super Cache, LiteSpeed, SG Optimizer and
		// others; harmless where nothing reads it.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		do_action( 'litespeed_control_set_nocache', 'GoHighLevel directory' );

		nocache_headers();

		if ( ! headers_sent() ) {
			header( 'Cache-Control: no-cache, no-store, must-revalidate, max-age=0' );
		}
	}

	/**
	 * A fingerprint of everything the cards and pages actually print.
	 *
	 * Lets a sync purge only when the directory would look different, rather
	 * than every hour regardless.
	 *
	 * @param array $contacts Normalized contacts.
	 * @return string
	 */
	public static function fingerprint( array $contacts ) {
		$parts = array();

		foreach ( $contacts as $contact ) {
			$row = array();
			foreach ( array( 'id', 'name', 'title', 'photo', 'specialty', 'company', 'city', 'state', 'address', 'phone', 'email', 'fax', 'slug' ) as $key ) {
				$row[] = isset( $contact[ $key ] ) ? (string) $contact[ $key ] : '';
			}
			$parts[] = implode( '|', $row );
		}

		return md5( implode( "\n", $parts ) );
	}
}
