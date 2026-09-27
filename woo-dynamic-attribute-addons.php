<?php
/**
 * Plugin Name: ویژگی‌های قیمت‌دار هوشمند ووکامرس (WooCommerce Dynamic Attribute Add-ons)
 * Plugin URI: https://dookht.com
 * Description: افزودن هزینه اضافی پویا به مقادیر ویژگی‌های محصول، نمایش دکمه‌های کارتی شیک در برگه محصول، محاسبه آنی قیمت و درج ردیف مجزا در فاکتور بدون نیاز به ساخت متغیرهای تکراری.
 * Version: 1.5.4
 * Author: dookht.com
 * Text Domain: wdaa
 * Domain Path: /languages
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! defined( 'WDAA_VERSION' ) ) {
	define( 'WDAA_VERSION', '1.5.4' );
}
if ( ! defined( 'WDAA_PLUGIN_FILE' ) ) {
	define( 'WDAA_PLUGIN_FILE', __FILE__ );
}
if ( ! defined( 'WDAA_PLUGIN_DIR' ) ) {
	define( 'WDAA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'WDAA_PLUGIN_URL' ) ) {
	define( 'WDAA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

// Declare HPOS (High-Performance Order Storage) compatibility
add_action( 'before_woocommerce_init', function() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

require_once WDAA_PLUGIN_DIR . 'includes/class-wdaa-attribute-addons.php';

// Instantiate the plugin
WDAA_Attribute_Addons::get_instance();
