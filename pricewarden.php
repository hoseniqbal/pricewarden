<?php
/**
 * Plugin Name:       PriceWarden: Wrong Price Protection for WooCommerce
 * Plugin URI:        https://github.com/hoseniqbal/pricewarden
 * Description:       Learns the normal price of every product, stops checkouts at a price far below it or at zero, and emails you about big price drops after edits and imports.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            Iqbal Hossen
 * Author URI:        https://profiles.wordpress.org/iqbal511138/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pricewarden
 * WC requires at least: 7.6
 * WC tested up to:   11.3
 *
 * @package PriceWarden
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PRICEWARDEN_VERSION', '1.0.0' );
define( 'PRICEWARDEN_DB_VERSION', '1' );
define( 'PRICEWARDEN_FILE', __FILE__ );
define( 'PRICEWARDEN_PATH', plugin_dir_path( __FILE__ ) );
define( 'PRICEWARDEN_URL', plugin_dir_url( __FILE__ ) );

require_once PRICEWARDEN_PATH . 'includes/class-pricewarden-settings.php';
require_once PRICEWARDEN_PATH . 'includes/class-pricewarden-log.php';
require_once PRICEWARDEN_PATH . 'includes/class-pricewarden-features.php';
require_once PRICEWARDEN_PATH . 'includes/class-pricewarden-admin.php';
require_once PRICEWARDEN_PATH . 'includes/class-pricewarden-plugin.php';

register_activation_hook( __FILE__, array( 'PriceWarden_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'PriceWarden_Plugin', 'deactivate' ) );

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

add_action( 'plugins_loaded', array( 'PriceWarden_Plugin', 'init' ) );
