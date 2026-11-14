<?php
/**
 * Standalone recovery worker used by ConcurrentRecoveryTest.
 *
 * Boots WordPress against the test database in its own PHP process and runs
 * one delayed-renewal recovery, the way an Action Scheduler runner or a
 * WP-Cron request would.
 *
 * Usage: php recovery-worker.php <subscription_id> <first|second> <sync_dir>
 *
 * To make the race deterministic, the "first" worker pauses after Stock Guard
 * decides to recover but before Subscriptions creates the renewal order. The
 * "second" worker waits for that pause, runs its recovery to completion, and
 * then lets the first worker continue. Both waits are bounded so a worker
 * blocked by a lock cannot deadlock the test.
 *
 * @package StockGuardForWooCommerceSubscriptions\Tests
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.AlternativeFunctions -- Test worker only.

use StockGuardForWooCommerceSubscriptions\Tests\Support\ScheduledTestGateway;
use StockGuardForWooCommerceSubscriptions\Tests\Support\TestGateway;

if ( 'cli' !== PHP_SAPI || 4 !== $argc ) {
	fwrite( STDERR, 'Usage: php recovery-worker.php <subscription_id> <first|second> <sync_dir>' . PHP_EOL );
	exit( 2 );
}

$sgfws_subscription_id = (int) $argv[1];
$sgfws_role            = $argv[2];
$sgfws_sync_dir        = rtrim( $argv[3], '/' );
$sgfws_wait_seconds    = 20;

$sgfws_wait_for = static function ( string $file ) use ( $sgfws_wait_seconds ): bool {
	$deadline = microtime( true ) + $sgfws_wait_seconds;

	while ( ! file_exists( $file ) ) {
		if ( microtime( true ) > $deadline ) {
			return false;
		}

		usleep( 20000 );
	}

	return true;
};

require dirname( __DIR__, 3 ) . '/vendor/autoload.php';
require dirname( __DIR__ ) . '/wp-tests-config.php';
require getenv( 'WP_PHPUNIT__DIR' ) . '/includes/functions.php';
require dirname( __DIR__ ) . '/environment.php';

define( 'DISABLE_WP_CRON', true );

tests_reset__SERVER();
sgfws_tests_register_environment();

require ABSPATH . 'wp-settings.php';

foreach ( array( TestGateway::ID, ScheduledTestGateway::ID ) as $sgfws_gateway_id ) {
	add_action( 'woocommerce_scheduled_subscription_payment_' . $sgfws_gateway_id, array( TestGateway::class, 'record_scheduled_payment' ), 10, 2 );
}

if ( 'first' === $sgfws_role ) {
	// wcs_create_order_from_subscription() validates the order type before it creates anything.
	add_filter(
		'wcs_new_order_types',
		static function ( $types ) use ( $sgfws_sync_dir, $sgfws_wait_for ) {
			static $paused = false;

			if ( ! $paused ) {
				$paused = true;
				touch( $sgfws_sync_dir . '/first-paused' );
				$sgfws_wait_for( $sgfws_sync_dir . '/second-done' );
			}

			return $types;
		}
	);
} else {
	$sgfws_wait_for( $sgfws_sync_dir . '/first-paused' );
}

$sgfws_error = null;

try {
	do_action( 'sgfws_process_stock_recovered_renewal', $sgfws_subscription_id );
} catch ( Throwable $throwable ) {
	$sgfws_error = get_class( $throwable ) . ': ' . $throwable->getMessage();
}

if ( 'second' === $sgfws_role ) {
	touch( $sgfws_sync_dir . '/second-done' );
}

echo wp_json_encode(
	array(
		'role'       => $sgfws_role,
		'dispatches' => TestGateway::$dispatches,
		'error'      => $sgfws_error,
	)
) . PHP_EOL;

exit( null === $sgfws_error ? 0 : 1 );
