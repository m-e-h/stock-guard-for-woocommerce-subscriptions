<?php
/**
 * Plugin Name: Stock Guard for WooCommerce Subscriptions
 * Plugin URI: https://github.com/m-e-h/stock-guard-for-woocommerce-subscriptions
 * Description: Pauses WooCommerce subscription renewals when stock is unavailable and retries them when stock returns.
 * Version: 1.0.0
 * Author: Marty Helmick
 * Author URI: https://github.com/m-e-h
 * Requires at least: 6.7
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC tested up to: 11.2
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: stock-guard-for-woocommerce-subscriptions
 *
 * @package StockGuardForWooCommerceSubscriptions
 */

declare(strict_types=1);

namespace StockGuardForWooCommerceSubscriptions;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/class-plugin.php';

( new Plugin( __FILE__ ) )->register_hooks();
