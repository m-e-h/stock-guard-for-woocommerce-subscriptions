<?php
/**
 * PHPUnit bootstrap for the Stock Guard integration suite.
 *
 * Installs a disposable WordPress site with WooCommerce, WooCommerce
 * Subscriptions, and Stock Guard active. The order storage mode is selected with
 * the SGFWS_TESTS_HPOS environment variable (1 = HPOS, 0 = posts storage).
 *
 * @package StockGuardForWooCommerceSubscriptions\Tests
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.AlternativeFunctions -- Test bootstrap only.

use Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer;

require dirname( __DIR__, 2 ) . '/vendor/autoload.php';

define( 'WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php' );
require_once WP_TESTS_CONFIG_FILE_PATH;

foreach ( array( 'woocommerce/woocommerce.php', 'woocommerce-subscriptions/woocommerce-subscriptions.php' ) as $sgfws_dependency ) {
	if ( ! is_readable( SGFWS_TESTS_PLUGINS_DIR . '/' . $sgfws_dependency ) ) {
		fwrite( STDERR, 'Missing dependency ' . SGFWS_TESTS_PLUGINS_DIR . '/' . $sgfws_dependency . '. Set SGFWS_TESTS_PLUGINS_DIR to a directory containing WooCommerce and WooCommerce Subscriptions.' . PHP_EOL );
		exit( 1 );
	}
}

/**
 * Create the test database, or empty it when it exists.
 *
 * The WordPress installer only drops core tables. WooCommerce, HPOS, and
 * Action Scheduler tables would otherwise carry rows into the next run, where
 * recycled IDs collide with new fixtures.
 *
 * @param string $table_prefix Test table prefix.
 */
function sgfws_tests_reset_database( $table_prefix ) {
	$host   = DB_HOST;
	$port   = null;
	$socket = null;

	if ( preg_match( '/^(.*?):(.+)$/', $host, $matches ) ) {
		$host = '' === $matches[1] ? 'localhost' : $matches[1];

		if ( ctype_digit( $matches[2] ) ) {
			$port = (int) $matches[2];
		} else {
			$socket = $matches[2];
		}
	}

	mysqli_report( MYSQLI_REPORT_OFF );
	$link = mysqli_connect( $host, DB_USER, DB_PASSWORD, '', $port, $socket );

	if ( ! $link ) {
		fwrite( STDERR, 'Could not connect to MySQL at ' . DB_HOST . ': ' . mysqli_connect_error() . PHP_EOL );
		exit( 1 );
	}

	mysqli_query( $link, 'CREATE DATABASE IF NOT EXISTS `' . str_replace( '`', '``', DB_NAME ) . '`' );
	mysqli_select_db( $link, DB_NAME );

	$tables = mysqli_query( $link, "SHOW TABLES LIKE '" . mysqli_real_escape_string( $link, addcslashes( $table_prefix, '_%' ) ) . "%'" );

	if ( $tables ) {
		mysqli_query( $link, 'SET foreign_key_checks = 0' );

		foreach ( mysqli_fetch_all( $tables ) as $table ) {
			mysqli_query( $link, 'DROP TABLE IF EXISTS `' . str_replace( '`', '``', $table[0] ) . '`' );
		}
	}

	mysqli_close( $link );
}

// Set WP_TESTS_SKIP_INSTALL=1 to reuse the previous run's database.
if ( '1' !== getenv( 'WP_TESTS_SKIP_INSTALL' ) ) {
	sgfws_tests_reset_database( $table_prefix );
}

require_once getenv( 'WP_PHPUNIT__DIR' ) . '/includes/functions.php';
require_once __DIR__ . '/environment.php';

sgfws_tests_register_environment();

// Install after Action Scheduler's data store (init, priority 1) and WooCommerce's post types (init, priority 5) are ready.
tests_add_filter(
	'init',
	static function () {
		WC_Install::install();

		// Create the HPOS tables in both modes so switching modes never hits a missing table.
		$data_synchronizer = wc_get_container()->get( DataSynchronizer::class );

		if ( ! $data_synchronizer->check_orders_table_exists() ) {
			$data_synchronizer->create_database_tables();
		}

		// Reload capabilities after install. See https://core.trac.wordpress.org/ticket/28374.
		$GLOBALS['wp_roles'] = null;
		wp_roles();

		// Without the site URL lock, Subscriptions treats the test site as a staging copy and forces manual renewals.
		WCS_Staging::set_duplicate_site_url_lock();
	},
	20
);

require getenv( 'WP_PHPUNIT__DIR' ) . '/includes/bootstrap.php';

/*
 * The WordPress test case snapshots hooks before the first test and restores
 * that snapshot after every test. Instantiate objects that register hooks in
 * their constructors now so their hooks are part of the snapshot.
 */
WC()->payment_gateways();
WC()->mailer();

printf(
	'Order storage: %s' . PHP_EOL,
	\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'HPOS' : 'posts'
);
