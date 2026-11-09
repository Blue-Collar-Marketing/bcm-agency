<?php
/**
 * Runs only when the plugin is deleted from wp-admin (not on deactivate or update).
 *
 * The activity log is kept by default, so deleting the plugin can't be used to
 * erase the record of what was done. To remove it too, add this to wp-config.php
 * before deleting the plugin:
 *
 *     define( 'BCM_AGENCY_DELETE_LOG_ON_UNINSTALL', true );
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( defined( 'BCM_AGENCY_DELETE_LOG_ON_UNINSTALL' ) && BCM_AGENCY_DELETE_LOG_ON_UNINSTALL ) {
	global $wpdb;
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}bcm_agency_log" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	delete_option( 'bcm_agency_log_db_version' );
}
