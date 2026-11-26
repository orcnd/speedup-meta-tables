<?php
/**
 * Removes every table and option of the plugin.
 *
 * @package SpeedUp_Meta_Tables
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Drops the plugin's tables and options on the current site.
 */
function speedup_meta_tables_uninstall_site() {
	global $wpdb;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- removing the plugin's own tables.
	$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'speedup_meta_' ) . '%' ) );
	foreach ( $tables as $t ) {
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $t ) );
	}
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'speedup_meta_tables_' ) . '%' ) );
	// phpcs:enable
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	foreach ( array( 'speedup_meta_tables_maintain', 'speedup_meta_tables_drain', 'speedup_meta_tables_build' ) as $hook ) {
		wp_clear_scheduled_hook( $hook );
	}
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $speedup_meta_tables_site ) {
		switch_to_blog( $speedup_meta_tables_site );
		speedup_meta_tables_uninstall_site();
		restore_current_blog();
	}
} else {
	speedup_meta_tables_uninstall_site();
}
