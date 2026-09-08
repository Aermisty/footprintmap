<?php
/**
 * Uninstall FootprintMap — drop table & delete options.
 *
 * @package FootprintMap
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Drop locations table.
$table = $wpdb->prefix . 'footprintmap_locations';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

// Delete options.
delete_option( 'footprintmap_settings' );
