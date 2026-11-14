<?php
/**
 * Payment routing after recovery.
 *
 * @package StockGuardForWooCommerceSubscriptions\Tests
 */

declare(strict_types=1);

namespace StockGuardForWooCommerceSubscriptions\Tests\Integration;

use StockGuardForWooCommerceSubscriptions\Tests\Support\ScheduledTestGateway;
use StockGuardForWooCommerceSubscriptions\Tests\Support\TestCase;
use StockGuardForWooCommerceSubscriptions\Tests\Support\TestGateway;
use WC_Order;

/**
 * Checks which gateway, if any, is asked to collect the recovered renewal.
 *
 * @group payments
 */
final class PaymentRoutingTest extends TestCase {

	public function test_automatic_renewal_dispatches_payment_once_for_the_recovered_order(): void {
		$subscription_id = $this->block_and_recover( array( 'payment_method' => TestGateway::ID ) );
		$renewal_order   = $this->only_renewal_order( $subscription_id );

		$this->assertGatewayDispatches( 1, TestGateway::ID );
		$this->assertSame( $renewal_order->get_id(), TestGateway::$dispatches[0]['order_id'] );
		$this->assertEqualsWithDelta( (float) $renewal_order->get_total(), TestGateway::$dispatches[0]['amount'], 0.001 );
		$this->assertTrue( $renewal_order->is_paid() );
		$this->assertSame( 'active', $this->reload( $subscription_id )->get_status() );
	}

	public function test_manual_renewal_creates_an_invoice_without_a_gateway_dispatch(): void {
		$subscription_id = $this->block_and_recover(
			array(
				'payment_method' => '',
				'manual'         => true,
			)
		);
		$renewal_order   = $this->only_renewal_order( $subscription_id );

		$this->assertSame( array(), TestGateway::$dispatches );
		$this->assertTrue( $renewal_order->needs_payment(), 'The invoice should await customer payment.' );
		$this->assertSame( 'pending', $renewal_order->get_status() );
		$this->assertSame( 'on-hold', $this->reload( $subscription_id )->get_status() );
	}

	public function test_gateway_scheduled_renewal_leaves_collection_to_the_gateway(): void {
		$subscription_id = $this->block_and_recover( array( 'payment_method' => ScheduledTestGateway::ID ) );
		$renewal_order   = $this->only_renewal_order( $subscription_id );

		$this->assertSame( array(), TestGateway::$dispatches );
		$this->assertTrue( $renewal_order->needs_payment(), 'The external gateway is responsible for collecting payment.' );
		$this->assertSame( 'active', $this->reload( $subscription_id )->get_status() );
	}

	public function test_renewal_that_needs_no_payment_does_not_call_a_gateway(): void {
		$subscription_id = $this->block_and_recover( array( 'payment_method' => TestGateway::ID ), '0' );
		$renewal_order   = $this->only_renewal_order( $subscription_id );

		$this->assertSame( array(), TestGateway::$dispatches );
		$this->assertFalse( $renewal_order->needs_payment() );
		$this->assertSame( 'active', $this->reload( $subscription_id )->get_status() );
	}

	public function test_payment_exception_after_order_creation_never_creates_a_second_order(): void {
		TestGateway::$mode = TestGateway::MODE_THROW;

		$product         = $this->create_product( array( 'stock' => 0 ) );
		$subscription_id = $this->create_subscription( array( array( $product, 1 ) ) )->get_id();

		$this->run_scheduled_payment( $subscription_id );
		$this->set_stock( $product, 5 );
		$this->run_pending_recovery_actions();

		$renewal_order_ids = $this->renewal_order_ids( $subscription_id );

		$this->assertCount( 1, $renewal_order_ids );
		$this->assertGatewayDispatches( 1 );
		$this->assertContains( 'Automatic renewal recovery failed.', $this->logged_errors() );

		// Everything that could re-run recovery: queued retries, the job itself, and later stock changes.
		$this->run_pending_recovery_actions();
		do_action( self::RECOVERY_ACTION, $subscription_id );
		$this->set_stock( $product, 9 );
		$this->run_pending_recovery_actions();

		$this->assertSame( $renewal_order_ids, $this->renewal_order_ids( $subscription_id ), 'The delayed renewal must keep a single order.' );
		$this->assertGatewayDispatches( 1 );
	}

	/**
	 * Block a renewal on stock, restock, and run recovery.
	 *
	 * @param array<string,mixed> $subscription_args Subscription fixture arguments.
	 * @param string              $price             Product price.
	 * @return int Subscription ID.
	 */
	private function block_and_recover( array $subscription_args, string $price = '10' ): int {
		$product         = $this->create_product(
			array(
				'stock' => 0,
				'price' => $price,
			)
		);
		$subscription_id = $this->create_subscription( array( array( $product, 1 ) ), $subscription_args )->get_id();

		$this->run_scheduled_payment( $subscription_id );
		$this->assertRenewalBlocked( $subscription_id );

		$this->set_stock( $product, 5 );
		$this->assertSame( 1, $this->run_pending_recovery_actions() );
		$this->assertBlockingMetadataCleared( $subscription_id );

		return $subscription_id;
	}

	/**
	 * Return the subscription's only renewal order.
	 *
	 * @param int $subscription_id Subscription ID.
	 */
	private function only_renewal_order( int $subscription_id ): WC_Order {
		$renewal_order_ids = $this->renewal_order_ids( $subscription_id );

		$this->assertCount( 1, $renewal_order_ids, 'Recovery should create exactly one renewal order.' );

		return wc_get_order( $renewal_order_ids[0] );
	}
}
