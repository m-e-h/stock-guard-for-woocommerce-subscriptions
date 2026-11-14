<?php
/**
 * Shared test environment setup.
 *
 * Loaded by the PHPUnit bootstrap and by the standalone concurrency workers so
 * both see the same plugins, storage mode, and payment gateways. Requires the
 * WordPress test library's `tests_add_filter()` helper.
 *
 * @package StockGuardForWooCommerceSubscriptions\Tests
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Test environment only.

use StockGuardForWooCommerceSubscriptions\Tests\Support\ScheduledTestGateway;
use StockGuardForWooCommerceSubscriptions\Tests\Support\TestGateway;

/**
 * Register the hooks that load and configure WooCommerce, Subscriptions, and Stock Guard.
 */
function sgfws_tests_register_environment() {
	tests_add_filter(
		'muplugins_loaded',
		static function () {
			require SGFWS_TESTS_PLUGINS_DIR . '/woocommerce/woocommerce.php';
			require SGFWS_TESTS_PLUGINS_DIR . '/woocommerce-subscriptions/woocommerce-subscriptions.php';
			require SGFWS_TESTS_PLUGIN_DIR . '/stock-guard-for-woocommerce-subscriptions.php';
		}
	);

	// Pin the order storage mode for the whole run: SGFWS_TESTS_HPOS=1 for HPOS, 0 for posts storage.
	tests_add_filter(
		'pre_option_woocommerce_custom_orders_table_enabled',
		static function () {
			return SGFWS_TESTS_HPOS ? 'yes' : 'no';
		}
	);
	tests_add_filter(
		'pre_option_woocommerce_custom_orders_table_data_sync_enabled',
		static function () {
			return 'no';
		}
	);

	tests_add_filter(
		'woocommerce_payment_gateways',
		static function ( $gateways ) {
			$gateways[] = TestGateway::class;
			$gateways[] = ScheduledTestGateway::class;

			return $gateways;
		}
	);

	// Never let the suite reach the network.
	tests_add_filter(
		'pre_http_request',
		static function () {
			return new WP_Error( 'sgfws_tests_http_blocked', 'HTTP requests are disabled in the Stock Guard test suite.' );
		}
	);
}
