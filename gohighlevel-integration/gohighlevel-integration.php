<?php
/**
 * Plugin Name:       GoHighLevel Integration
 * Plugin URI:        https://github.com/curiositymg/Go-High-Level
 * Description:       Pulls contacts from GoHighLevel (LeadConnector) and renders them as a filterable directory with the [ghl_directory] shortcode.
 * Version:           1.11.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Curiosity Marketing Group
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       gohighlevel-integration
 *
 * @package GoHighLevel_Integration
 */

defined( 'ABSPATH' ) || exit;

define( 'GHLD_VERSION', '1.11.1' );
define( 'GHLD_FILE', __FILE__ );
define( 'GHLD_PATH', plugin_dir_path( __FILE__ ) );
define( 'GHLD_URL', plugin_dir_url( __FILE__ ) );

require_once GHLD_PATH . 'includes/class-ghld-settings.php';
require_once GHLD_PATH . 'includes/class-ghld-client.php';
require_once GHLD_PATH . 'includes/class-ghld-contact.php';
require_once GHLD_PATH . 'includes/class-ghld-photos.php';
require_once GHLD_PATH . 'includes/class-ghld-repository.php';
require_once GHLD_PATH . 'includes/class-ghld-template.php';
require_once GHLD_PATH . 'includes/class-ghld-shortcode.php';
require_once GHLD_PATH . 'includes/class-ghld-rest.php';
require_once GHLD_PATH . 'includes/class-ghld-seo.php';
require_once GHLD_PATH . 'includes/class-ghld-admin.php';

/**
 * Apply one-time upgrades to stored settings.
 *
 * A stored value always beats a changed default, so a site that has ever saved
 * the settings screen keeps whatever it saved. Anything that has to reach
 * existing installs has to be migrated here.
 *
 * @return void
 */
function ghld_migrate() {
	$applied = (int) get_option( 'ghld_migration', 0 );

	if ( $applied >= 3 ) {
		return;
	}

	if ( $applied < 2 ) {
		// Headshots in a file-upload field only arrive via the single-contact
		// endpoint, so full-record fetching has to be on for them to work.
		GHLD_Settings::update( array( 'deep_sync' => 1 ) );
	}

	// Cached contacts hold values that were flattened by the code that cached
	// them. A file-upload field flattens to its URLs in the order the payload
	// listed them, so a contact cached before uploads were ordered live-first
	// keeps a replaced file's dead URL — and because that counts as "has a
	// photo", nothing would ever re-fetch it. Drop the cache so the next sync
	// rebuilds every contact with the current code.
	GHLD_Repository::flush();

	update_option( 'ghld_migration', 3 );
}

/**
 * Boot the plugin once WordPress has loaded its own pluggable pieces.
 */
function ghld_init() {
	ghld_migrate();
	GHLD_Shortcode::init();
	GHLD_Rest::init();
	GHLD_Seo::init();

	if ( is_admin() ) {
		GHLD_Admin::init();
	}
}
add_action( 'plugins_loaded', 'ghld_init' );

/**
 * Background refresh of the cached contact set.
 */
function ghld_run_scheduled_sync() {
	GHLD_Repository::sync();
}
add_action( GHLD_Repository::CRON_HOOK, 'ghld_run_scheduled_sync' );

/**
 * Continue fetching full contact records in the background.
 */
function ghld_run_enrichment() {
	GHLD_Repository::continue_enrich();
}
add_action( GHLD_Repository::CRON_ENRICH, 'ghld_run_enrichment' );

/**
 * Register the recurring sync on activation.
 */
function ghld_activate() {
	if ( ! wp_next_scheduled( GHLD_Repository::CRON_HOOK ) ) {
		wp_schedule_event( time() + MINUTE_IN_SECONDS, 'hourly', GHLD_Repository::CRON_HOOK );
	}
}
register_activation_hook( __FILE__, 'ghld_activate' );

/**
 * Drop the recurring sync on deactivation. Cached contacts are left in place
 * so a deactivate/reactivate cycle doesn't force a full re-fetch; uninstall.php
 * is what actually removes stored data.
 */
function ghld_deactivate() {
	$timestamp = wp_next_scheduled( GHLD_Repository::CRON_HOOK );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, GHLD_Repository::CRON_HOOK );
	}
}
register_deactivation_hook( __FILE__, 'ghld_deactivate' );
