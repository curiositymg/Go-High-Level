<?php
/**
 * Removes everything the plugin stored.
 *
 * @package GoHighLevel_Integration
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$ghld_options = array(
	'ghld_settings',
	'ghld_contacts',
	'ghld_custom_fields',
	'ghld_sync_state',
);

foreach ( $ghld_options as $ghld_option ) {
	delete_option( $ghld_option );
}

// Shortcode scopes are stored as transients keyed by an instance hash.
global $wpdb;

$ghld_like = $wpdb->esc_like( '_transient_ghld_inst_' ) . '%';
$ghld_keys = $wpdb->get_col(
	$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $ghld_like )
);

foreach ( (array) $ghld_keys as $ghld_key ) {
	delete_transient( str_replace( '_transient_', '', $ghld_key ) );
}

wp_clear_scheduled_hook( 'ghld_sync_contacts' );
