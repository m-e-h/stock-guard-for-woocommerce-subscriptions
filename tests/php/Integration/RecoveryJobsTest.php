<?php
/**
 * Recovery job scheduling and failure-handling tests.
 *
 * @package StockGuardForWooCommerceSubscriptions\Tests
 */

declare(strict_types=1);

namespace StockGuardForWooCommerceSubscriptions\Tests\Integration;

use RuntimeException;
use StockGuardForWooCommerceSubscriptions\Tests\Support\ScheduledTestGateway;
use StockGuardForWooCommerceSubscriptions\Tests\Support\TestCase;
use StockGuardForWooCommerceSubscriptions\Tests\Support\TestGateway;
use WC_Product;
use WC_Subscription;

/**
 * Protects the "recover automatically, exactly once" promise.
 *
 * @group recovery
 */
final class RecoveryJobsTest extends TestCase {

	private const RETRY_DELAY = 300;

	public function test_repeated_stock_hooks_queue_only_one_recovery(): void {
		list( $product, $subscription ) = $this->create_blocked_subscription();

		$this->set_stock( $product, 5 );
		$this->set_stock( $product, 6 );

		$product = wc_get_product( $product->get_id() );
		$product->set_backorders( 'notify' );
		$product->save();

		$this->assertCount( 1, $this->pending_recovery_ids( $subscription->get_id() ) );
		$this->assertSame( array(), $this->logged_errors() );
		$this->assertFalse( wp_next_scheduled( self::RECOVERY_ACTION, array( $subscription->get_id() ) ), 'A duplicate must not fall back to WP-Cron.' );

		$this->run_pending_recovery_actions();

		$this->assertCount( 1, $this->renewal_order_ids( $subscription->get_id() ) );
		$this->assertGatewayDispatches( 1 );
	}

	public function test_replaying_a_completed_recovery_creates_no_additional_order_or_payment(): void {
		list( $product, $subscription ) = $this->create_blocked_subscription();

		$this->set_stock( $product, 5 );
		$this->run_pending_recovery_actions();

		$renewal_order_ids = $this->renewal_order_ids( $subscription->get_id() );
		$this->assertCount( 1, $renewal_order_ids );

		// The same job delivered again by Action Scheduler, by the WP-Cron fallback, and by a late scheduled renewal.
		as_enqueue_async_action( self::RECOVERY_ACTION, array( $subscription->get_id() ), self::ACTION_GROUP, true );
		$this->run_pending_recovery_actions();
		do_action( self::RECOVERY_ACTION, $subscription->get_id() );

		$this->assertSame( $renewal_order_ids, $this->renewal_order_ids( $subscription->get_id() ) );
		$this->assertGatewayDispatches( 1 );
		$this->assertSame( array(), $this->logged_errors() );
	}

	/**
	 * @dataProvider provide_order_creation_failures
	 *
	 * @param string   $gateway_id     Subscription payment method.
	 * @param callable $inject_failure Installs the failure.
	 * @param string   $logged_error   Expected Stock Guard log message.
	 */
	public function test_failed_order_creation_keeps_the_renewal_blocked_and_schedules_a_retry( string $gateway_id, callable $inject_failure, string $logged_error ): void {
		list( $product, $subscription ) = $this->create_blocked_subscription( array( 'payment_method' => $gateway_id ) );

		$this->set_stock( $product, 5 );
		$inject_failure();
		$this->run_pending_recovery_actions();

		$this->assertRenewalBlocked( $subscription->get_id() );
		$this->assertSame( 1, (int) $this->reload( $subscription )->get_meta( self::META_RECOVERY_ATTEMPTS ) );
		$this->assertSame( array(), $this->renewal_order_ids( $subscription->get_id() ) );
		$this->assertGatewayDispatches( 0, $gateway_id );
		$this->assertSame( array( $logged_error ), $this->logged_errors() );
		$this->assertRetryScheduled( $subscription->get_id() );
	}

	/**
	 * @return array<string,array{0:string,1:callable,2:string}>
	 */
	public function provide_order_creation_failures(): array {
		$reject_renewal_order_type = static function () {
			add_filter(
				'wcs_new_order_types',
				static function ( $types ) {
					return array_values( array_diff( $types, array( 'renewal_order' ) ) );
				}
			);
		};

		return array(
			// Subscriptions' process_renewal() turns a WP_Error from order creation into an exception.
			'automatic renewal, Subscriptions throws on WP_Error' => array(
				TestGateway::ID,
				$reject_renewal_order_type,
				'Automatic renewal recovery failed.',
			),
			// Gateway-scheduled renewals skip process_renewal(), so Stock Guard receives the WP_Error itself.
			'gateway-scheduled renewal, WP_Error returned'        => array(
				ScheduledTestGateway::ID,
				$reject_renewal_order_type,
				'WooCommerce Subscriptions could not create the delayed renewal order.',
			),
			'order creation throws'                               => array(
				TestGateway::ID,
				static function () {
					add_filter(
						'wcs_new_order_types',
						static function () {
							throw new RuntimeException( 'Simulated order creation failure.' );
						}
					);
				},
				'Automatic renewal recovery failed.',
			),
		);
	}

	public function test_recovery_stops_after_three_failed_attempts_until_a_relevant_stock_change(): void {
		list( $product, $subscription ) = $this->create_blocked_subscription( array( 'payment_method' => ScheduledTestGateway::ID ) );
		$subscription_id                = $subscription->get_id();
		$fail_order_creation            = static function ( $types ) {
			return array_values( array_diff( $types, array( 'renewal_order' ) ) );
		};

		add_filter( 'wcs_new_order_types', $fail_order_creation );
		$this->set_stock( $product, 5 );

		for ( $attempt = 1; $attempt <= 3; $attempt++ ) {
			$this->assertSame( 1, $this->run_pending_recovery_actions(), "Attempt {$attempt} should have exactly one job to run." );
			$this->assertSame( $attempt, (int) $this->reload( $subscription_id )->get_meta( self::META_RECOVERY_ATTEMPTS ) );
		}

		$this->assertSame( array(), $this->pending_recovery_ids( $subscription_id ), 'No retry should be scheduled after the third failure.' );
		$this->assertFalse( wp_next_scheduled( self::RECOVERY_ACTION, array( $subscription_id ) ) );
		$this->assertCount( 3, $this->logged_errors() );
		$this->assertRenewalBlocked( $subscription_id );
		$this->assertSubscriptionHasNote( $subscription_id, 'failed 3 times' );

		// The underlying problem is fixed and stock changes again.
		remove_filter( 'wcs_new_order_types', $fail_order_creation );
		$this->set_stock( $product, 6 );

		$this->assertEmpty( $this->reload( $subscription_id )->get_meta( self::META_RECOVERY_ATTEMPTS ), 'A relevant stock change should reset the attempt counter.' );
		$this->assertSame( 1, $this->run_pending_recovery_actions() );
		$this->assertCount( 1, $this->renewal_order_ids( $subscription_id ) );
		$this->assertBlockingMetadataCleared( $subscription_id );
	}

	public function test_action_scheduler_enqueue_failure_falls_back_to_wp_cron(): void {
		list( $product, $subscription ) = $this->create_blocked_subscription();

		add_filter( 'pre_as_enqueue_async_action', '__return_zero' );
		$this->set_stock( $product, 5 );
		remove_filter( 'pre_as_enqueue_async_action', '__return_zero' );

		$this->assertSame( array(), $this->pending_recovery_ids( $subscription->get_id() ) );
		$this->assertNotFalse( wp_next_scheduled( self::RECOVERY_ACTION, array( $subscription->get_id() ) ), 'Recovery should be scheduled through WP-Cron.' );
		$this->assertContains( 'Action Scheduler could not queue a delayed renewal recovery.', $this->logged_errors() );

		$this->run_wp_cron_recovery( $subscription->get_id() );

		$this->assertCount( 1, $this->renewal_order_ids( $subscription->get_id() ) );
		$this->assertGatewayDispatches( 1 );
	}

	public function test_action_scheduler_duplicate_does_not_add_a_wp_cron_fallback(): void {
		list( $product, $subscription ) = $this->create_blocked_subscription();

		$this->set_stock( $product, 5 );
		$this->assertCount( 1, $this->pending_recovery_ids( $subscription->get_id() ) );

		// Action Scheduler returns 0 for this enqueue because an identical action is pending.
		$returned_ids = array();
		add_action(
			'action_scheduler_stored_action',
			static function ( $action_id ) use ( &$returned_ids ) {
				$returned_ids[] = $action_id;
			}
		);
		$this->set_stock( $product, 6 );

		$this->assertSame( array(), $returned_ids, 'No new action should be stored for a duplicate.' );
		$this->assertCount( 1, $this->pending_recovery_ids( $subscription->get_id() ) );
		$this->assertFalse( wp_next_scheduled( self::RECOVERY_ACTION, array( $subscription->get_id() ) ), 'A duplicate must not add a WP-Cron job.' );
		$this->assertSame( array(), $this->logged_errors() );
	}

	public function test_retry_scheduling_failure_falls_back_to_delayed_wp_cron(): void {
		list( $product, $subscription ) = $this->create_blocked_subscription( array( 'payment_method' => ScheduledTestGateway::ID ) );

		add_filter(
			'wcs_new_order_types',
			static function ( $types ) {
				return array_values( array_diff( $types, array( 'renewal_order' ) ) );
			}
		);
		$this->set_stock( $product, 5 );
		add_filter( 'pre_as_schedule_single_action', '__return_zero' );
		$this->run_pending_recovery_actions();

		$next_cron = wp_next_scheduled( self::RECOVERY_ACTION, array( $subscription->get_id() ) );

		$this->assertNotFalse( $next_cron );
		$this->assertEqualsWithDelta( time() + self::RETRY_DELAY, $next_cron, 5 );
		$this->assertContains( 'Action Scheduler could not queue a delayed renewal recovery retry.', $this->logged_errors() );
	}

	/**
	 * Create a subscription whose renewal Stock Guard has already blocked.
	 *
	 * @param array<string,mixed> $subscription_args Subscription fixture arguments.
	 * @return array{0:WC_Product,1:WC_Subscription}
	 */
	private function create_blocked_subscription( array $subscription_args = array() ): array {
		$product      = $this->create_product( array( 'stock' => 0 ) );
		$subscription = $this->create_subscription( array( array( $product, 1 ) ), $subscription_args );

		$this->run_scheduled_payment( $subscription->get_id() );
		$this->assertRenewalBlocked( $subscription->get_id() );

		return array( $product, $this->reload( $subscription ) );
	}

	/**
	 * Run a recovery scheduled through WP-Cron the way wp-cron.php would.
	 *
	 * @param int $subscription_id Subscription ID.
	 */
	private function run_wp_cron_recovery( int $subscription_id ): void {
		$args      = array( $subscription_id );
		$timestamp = wp_next_scheduled( self::RECOVERY_ACTION, $args );

		$this->assertNotFalse( $timestamp );

		wp_unschedule_event( $timestamp, self::RECOVERY_ACTION, $args );
		do_action_ref_array( self::RECOVERY_ACTION, $args );
	}

	/**
	 * Assert that one delayed retry is pending.
	 *
	 * @param int $subscription_id Subscription ID.
	 */
	private function assertRetryScheduled( int $subscription_id ): void {
		$this->assertCount( 1, $this->pending_recovery_ids( $subscription_id ), 'One retry should be scheduled.' );
		$this->assertEqualsWithDelta(
			time() + self::RETRY_DELAY,
			as_next_scheduled_action( self::RECOVERY_ACTION, array( $subscription_id ), self::ACTION_GROUP ),
			5
		);
	}

	/**
	 * Assert that a subscription note contains some text.
	 *
	 * @param int    $subscription_id Subscription ID.
	 * @param string $needle          Expected text.
	 */
	private function assertSubscriptionHasNote( int $subscription_id, string $needle ): void {
		$notes = array_map(
			static function ( $note ) {
				return $note->content;
			},
			wc_get_order_notes( array( 'order_id' => $subscription_id ) )
		);

		$this->assertNotEmpty(
			array_filter(
				$notes,
				static function ( string $note ) use ( $needle ): bool {
					return false !== strpos( $note, $needle );
				}
			),
			"No subscription note contains \"{$needle}\"."
		);
	}
}
