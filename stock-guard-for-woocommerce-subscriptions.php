<?php
/**
 * Plugin Name: Stock Guard for WooCommerce Subscriptions
 * Description: Pauses WooCommerce subscription renewals when stock is unavailable and retries them when stock returns.
 * Version: 1.0.0
 * Author: Marty Helmick
 * Author URI: https://github.com/m-e-h
 * Requires at least: 6.7
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce, woocommerce-subscriptions
 * Text Domain: stock-guard-for-woocommerce-subscriptions
 *
 * @package StockGuardForWooCommerceSubscriptions
 */

declare(strict_types=1);

namespace StockGuardForWooCommerceSubscriptions;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/class-plugin.php';

( new Plugin( __FILE__ ) )->register_hooks();
