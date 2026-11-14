<?php
/**
 * Cross-process recovery test.
 *
 * @package StockGuardForWooCommerceSubscriptions\Tests
 */

declare(strict_types=1);

namespace StockGuardForWooCommerceSubscriptions\Tests\Integration;

use StockGuardForWooCommerceSubscriptions\Tests\Support\TestCase;
use StockGuardForWooCommerceSubscriptions\Tests\Support\TestGateway;

/**
 * Two PHP workers attempt the same recovery at the same time.
 *
 * Stock Guard's in-memory recovery tracking only covers one request, so this
 * runs two real processes against the same database. Fixtures must be
 * committed for the workers to see them; the test removes them afterwards.
 *
 * Run separately with `composer test:concurrency`.
 *
 * @group concurrency
 */
final class ConcurrentRecoveryTest extends TestCase {

	private const WORKER_TIMEOUT = 60;

	/**
	 * Objects committed to the database by this test.
	 *
	 * @var array<string,array<int,int>>
	 */
	private $committed = array(
		'orders'   => array(),
		'products' => array(),
		'users'    => array(),
	);

	/**
	 * Temporary directory the workers use to coordinate.
	 *
	 * @var string
	 */
	private $sync_dir = '';

	public function tear_down() {
		global $wpdb;

		foreach ( $this->committed['orders'] as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( $order ) {
				$order->delete( true );
			}
		}

		foreach ( $this->committed['products'] as $product_id ) {
			$product = wc_get_product( $product_id );

			if ( $product ) {
				$product->delete( true );
			}
		}

		foreach ( $this->committed['users'] as $user_id ) {
			wp_delete_user( $user_id );
		}

		// Hook only: with a group, Action Scheduler matches the empty args literally and leaves the committed job pending.
		as_unschedule_all_actions( self::RECOVERY_ACTION );
		$wpdb->query( 'COMMIT' );

		if ( $this->sync_dir ) {
			array_map( 'unlink', glob( $this->sync_dir . '/*' ) ?: array() );
			rmdir( $this->sync_dir );
		}

		parent::tear_down();
	}

	public function test_two_workers_recovering_the_same_subscription_create_one_order_and_one_payment(): void {
		global $wpdb;

		$product         = $this->create_product( array( 'stock' => 0 ) );
		$subscription    = $this->create_subscription( array( array( $product, 1 ) ) );
		$subscription_id = $subscription->get_id();

		$this->committed['products'][] = $product->get_id();
		$this->committed['orders'][]   = $subscription_id;
		$this->committed['users'][]    = $subscription->get_user_id();

		$this->run_scheduled_payment( $subscription_id );
		$this->assertRenewalBlocked( $subscription_id );
		$this->set_stock( $product, 5 );

		$wpdb->query( 'COMMIT' );

		$results = $this->run_workers( $subscription_id );

		// End this connection's snapshot so the workers' writes are visible.
		$wpdb->query( 'COMMIT' );
		wp_cache_flush();

		$renewal_order_ids              = $this->renewal_order_ids( $subscription_id );
		$this->committed['orders']      = array_merge( $this->committed['orders'], $renewal_order_ids );
		$dispatches_per_worker          = array_map(
			static function ( array $result ): int {
				return count( $result['dispatches'] );
			},
			$results
		);
		$dispatches_recorded_on_orders = 0;

		foreach ( $renewal_order_ids as $renewal_order_id ) {
			$dispatches_recorded_on_orders += count( wc_get_order( $renewal_order_id )->get_meta( TestGateway::DISPATCH_META, false ) );
		}

		$this->assertCount( 1, $renewal_order_ids, 'Concurrent recoveries must create exactly one renewal order.' );
		$this->assertSame( 1, array_sum( $dispatches_per_worker ), 'Exactly one worker should dispatch the renewal payment.' );
		$this->assertSame( 1, $dispatches_recorded_on_orders );
		$this->assertBlockingMetadataCleared( $subscription_id );
	}

	/**
	 * Run the paired recovery workers and return their decoded results.
	 *
	 * @param int $subscription_id Subscription ID.
	 * @return array<string,array{role:string,dispatches:array<int,array<string,mixed>>,error:?string}>
	 */
	private function run_workers( int $subscription_id ): array {
		$this->sync_dir = sys_get_temp_dir() . '/sgfws-workers-' . wp_generate_password( 8, false );
		$this->assertTrue( wp_mkdir_p( $this->sync_dir ) );

		$worker    = dirname( __DIR__ ) . '/bin/recovery-worker.php';
		$processes = array();

		foreach ( array( 'first', 'second' ) as $role ) {
			$output_file = $this->sync_dir . "/{$role}.out";
			$process     = proc_open(
				array( PHP_BINARY, $worker, (string) $subscription_id, $role, $this->sync_dir ),
				array(
					0 => array( 'file', '/dev/null', 'r' ),
					1 => array( 'file', $output_file, 'w' ),
					2 => array( 'file', $output_file, 'a' ),
				),
				$pipes
			);

			$this->assertIsResource( $process, "Could not start the {$role} worker." );
			$processes[ $role ] = array(
				'process' => $process,
				'output'  => $output_file,
			);
		}

		$deadline   = time() + self::WORKER_TIMEOUT;
		$exit_codes = array();

		while ( count( $exit_codes ) < count( $processes ) ) {
			foreach ( $processes as $role => $worker_process ) {
				if ( isset( $exit_codes[ $role ] ) ) {
					continue;
				}

				$status = proc_get_status( $worker_process['process'] );

				if ( ! $status['running'] ) {
					$exit_codes[ $role ] = $status['exitcode'];
					proc_close( $worker_process['process'] );
				} elseif ( time() > $deadline ) {
					proc_terminate( $worker_process['process'] );
					$this->fail( "The {$role} worker timed out." );
				}
			}

			usleep( 50000 );
		}

		$results = array();

		foreach ( $processes as $role => $worker_process ) {
			$output = (string) file_get_contents( $worker_process['output'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$lines  = array_filter( array_map( 'trim', explode( "\n", $output ) ) );
			$result = json_decode( (string) end( $lines ), true );

			$this->assertSame( 0, $exit_codes[ $role ], "The {$role} worker failed:\n{$output}" );
			$this->assertIsArray( $result, "The {$role} worker returned no result:\n{$output}" );

			$results[ $role ] = $result;
		}

		return $results;
	}
}
