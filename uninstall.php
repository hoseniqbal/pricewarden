<?php
/**
 * Uninstall: remove data only when the owner asked for it.
 *
 * @package PriceWarden
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$pricewarden_settings = get_option( 'pricewarden_settings', array() );
if ( ! is_array( $pricewarden_settings ) || empty( $pricewarden_settings['delete_data'] ) || 'yes' !== $pricewarden_settings['delete_data'] ) {
	return;
}

global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- uninstall.
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'pricewarden_log' ) );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall, own option names.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'pricewarden_' ) . '%' ) );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall, own meta keys.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_pricewarden_' ) . '%' ) );
wp_clear_scheduled_hook( 'pricewarden_hourly' );
wp_clear_scheduled_hook( 'pricewarden_daily' );
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'pricewarden' );
}
