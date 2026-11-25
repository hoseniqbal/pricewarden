<?php
/**
 * Bootstrap.
 *
 * @package PriceWarden
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires everything together.
 */
class PriceWarden_Plugin {

	/**
	 * Runs on plugins_loaded.
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		if ( get_option( 'pricewarden_db_version' ) !== PRICEWARDEN_DB_VERSION ) {
			PriceWarden_Log::install();
			PriceWarden_Features::install();
		}
		add_action( 'pricewarden_hourly', array( 'PriceWarden_Features', 'hourly' ) );
		add_action( 'pricewarden_daily', array( __CLASS__, 'daily' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		PriceWarden_Features::init();
		if ( is_admin() ) {
			PriceWarden_Admin::init();
		}
	}

	/**
	 * Make sure the jobs are scheduled.
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( 'pricewarden_hourly' ) ) {
			wp_schedule_event( time() + 10 * MINUTE_IN_SECONDS, 'hourly', 'pricewarden_hourly' );
		}
		if ( ! wp_next_scheduled( 'pricewarden_daily' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'pricewarden_daily' );
		}
	}

	/**
	 * Daily housekeeping.
	 *
	 * @return void
	 */
	public static function daily() {
		PriceWarden_Log::purge();
		PriceWarden_Features::daily();
	}

	/**
	 * Activation.
	 *
	 * @return void
	 */
	public static function activate() {
		PriceWarden_Log::install();
		if ( class_exists( 'WooCommerce' ) ) {
			PriceWarden_Features::install();
			PriceWarden_Features::activate();
		}
	}

	/**
	 * Deactivation.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'pricewarden_hourly' );
		wp_clear_scheduled_hook( 'pricewarden_daily' );
	}
}
