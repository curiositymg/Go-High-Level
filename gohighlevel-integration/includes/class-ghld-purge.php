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
 */
class GHLD_Purge {

	/**
	 * Ask every cache we can reach to clear.
	 *
	 * Each call is guarded: these belong to other plugins and hosts, any of
	 * which may be absent or renamed, and none of them is allowed to take a
	 * sync down with it.
	 *
	 * @param string $reason What prompted the purge.
	 * @return void
	 */
	public static function flush( $reason = '' ) {
		if ( empty( GHLD_Settings::get( 'purge_cache', 1 ) ) ) {
			return;
		}

		// WP Engine: page cache, object cache, then the CDN. The CDN is the
		// layer that keeps serving old HTML after the others are cleared.
		if ( class_exists( 'WpeCommon' ) ) {
			foreach ( array( 'purge_varnish_cache', 'purge_memcached', 'clear_maxcdn_cache' ) as $method ) {
				if ( method_exists( 'WpeCommon', $method ) ) {
					call_user_func( array( 'WpeCommon', $method ) );
				}
			}
		}

		foreach ( array( 'rocket_clean_domain', 'w3tc_flush_all', 'wp_cache_clear_cache', 'sg_cachepress_purge_cache', 'cache_enabler_clear_complete_cache' ) as $function ) {
			if ( function_exists( $function ) ) {
				call_user_func( $function );
			}
		}

		// LiteSpeed and WP Fastest Cache listen for these.
		do_action( 'litespeed_purge_all' );
		do_action( 'wpfc_clear_all_cache', true );

		// Elementor caches its rendered CSS and data per page.
		if ( class_exists( '\\Elementor\\Plugin' ) ) {
			$elementor = \Elementor\Plugin::$instance;

			if ( isset( $elementor->files_manager ) && method_exists( $elementor->files_manager, 'clear_cache' ) ) {
				$elementor->files_manager->clear_cache();
			}
		}

		/**
		 * Fires after the directory has asked the caches to clear.
		 *
		 * @param string $reason settings, sync or contact.
		 */
		do_action( 'ghld_purged_cache', (string) $reason );
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
