<?php
/**
 * Plugin Name:       GoHighLevel Integration
 * Plugin URI:        https://github.com/curiositymg/Go-High-Level
 * Description:       Pulls contacts from GoHighLevel (LeadConnector) and renders them as a filterable directory with the [ghl_directory] shortcode.
 * Version:           1.3.0
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

define( 'GHLD_VERSION', '1.3.0' );
define( 'GHLD_FILE', __FILE__ );
define( 'GHLD_PATH', plugin_dir_path( __FILE__ ) );
define( 'GHLD_URL', plugin_dir_url( __FILE__ ) );

require_once GHLD_PATH . 'includes/class-ghld-settings.php';
require_once GHLD_PATH . 'includes/class-ghld-client.php';
require_once GHLD_PATH . 'includes/class-ghld-contact.php';
require_once GHLD_PATH . 'includes/class-ghld-repository.php';
require_once GHLD_PATH . 'includes/class-ghld-template.php';
require_once GHLD_PATH . 'includes/class-ghld-shortcode.php';
require_once GHLD_PATH . 'includes/class-ghld-rest.php';
require_once GHLD_PATH . 'includes/class-ghld-admin.php';

/**
 * Boot the plugin once WordPress has loaded its own pluggable pieces.
 */
function ghld_init() {
	GHLD_Shortcode::init();
	GHLD_Rest::init();

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
