<?php
/**
 * Plugin Name:       eFacturiPlus for WooCommerce
 * Plugin URI:        https://www.efacturiplus.ro/app/site/facturare-woocommerce
 * Description:       Issues invoices in eFacturiPlus from WooCommerce orders and sends them to Romania's e-Factura system (SPV).
 * Version:           3.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            CommerceStream Innovate SRL
 * Author URI:        https://www.efacturiplus.ro
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       efacturiplus
 * WC requires at least: 8.0
 * WC tested up to:   11.2
 *
 * @package eFacturiPlus
 */

defined( 'ABSPATH' ) || exit;

define( 'EFACTURIPLUS_VERSION', '3.0.0' );
define( 'EFACTURIPLUS_FILE', __FILE__ );

require_once __DIR__ . '/includes/class-efacturiplus-client.php';
require_once __DIR__ . '/includes/class-efacturiplus-mapper.php';
require_once __DIR__ . '/includes/class-efacturiplus-orders.php';
require_once __DIR__ . '/includes/class-efacturiplus-settings.php';
require_once __DIR__ . '/includes/class-efacturiplus-checkout.php';

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', EFACTURIPLUS_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', EFACTURIPLUS_FILE, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		EFacturiPlus_Settings::init();
		EFacturiPlus_Orders::init();
		EFacturiPlus_Checkout::init();
	}
);
