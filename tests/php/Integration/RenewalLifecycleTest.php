<?php
/**
 * Renewal lifecycle integration tests.
 *
 * @package StockGuardForWooCommerceSubscriptions\Tests
 */

declare(strict_types=1);

namespace StockGuardForWooCommerceSubscriptions\Tests\Integration;

use StockGuardForWooCommerceSubscriptions\Tests\Support\TestCase;
use StockGuardForWooCommerceSubscriptions\Tests\Support\TestGateway;

/**
 * Block → partial restock → full recovery → replay, plus callback restoration.
 *
 * @group lifecycle
 */
final class RenewalLifecycleTest extends TestCase {

	public function test_unavailable_stock_delays_the_renewal_without_an_order_or_payment(): void {
		$product      = $this->create_product( array( 'stock' => 0 ) );
		$subscription = $this->create_subscription( array( array( $product, 1 ) ) );

		$this->run_scheduled_payment( $subscription->get_id() );

		$this->assertRenewalBlocked( $subscription->get_id(), array( 'Coffee beans' ) );
		$this->assertSame( array(), $this->renewal_order_ids( $subscription->get_id() ), 'No renewal order should be created.' );
		$this->assertGatewayDispatches( 0 );
		$this->assertSame(
			array(),
			$this->pending_action_ids( self::PAYMENT_ACTION, array( 'subscription_id' => $subscription->get_id() ) ),
			'Scheduled renewals should be paused while the subscription is delayed.'
		);
	}

	public function test_available_stock_lets_the_normal_renewal_proceed(): void {
		$product      = $this->create_product( array( 'stock' => 5 ) );
		$subscription = $this->create_subscription( array( array( $product, 1 ) ) );

		$this->run_scheduled_payment( $subscription->get_id() );

		$renewal_order_ids = $this->renewal_order_ids( $subscription->get_id() );

		$this->assertCount( 1, $renewal_order_ids );
		$this->assertGatewayDispatches( 1 );
		$this->assertSame( 'active', $this->reload( $subscription )->get_status() );
		$this->assertTrue( wc_get_order( $renewal_order_ids[0] )->is_paid() );
		$this->assertBlockingMetadataCleared( $subscription->get_id() );
	}

	public function test_restocking_one_of_two_unavailable_products_keeps_the_subscription_delayed(): void {
		$coffee       = $this->create_product( array( 'stock' => 0 ) );
		$filters      = $this->create_product(
			array(
				'name'  => 'Paper filters',
				'stock' => 0,
			)
		);
		$subscription = $this->create_subscription( array( array( $coffee, 1 ), array( $filters, 1 ) ) );

		$this->run_scheduled_payment( $subscription->get_id() );
		$this->assertRenewalBlocked( $subscription->get_id(), array( 'Coffee beans', 'Paper filters' ) );

		$this->set_stock( $coffee, 5 );

		$this->assertSame( array(), $this->pending_recovery_ids( $subscription->get_id() ), 'No recovery should be queued while an item is still unavailable.' );
		$this->assertSame( 0, $this->run_pending_recovery_actions() );
		$this->assertRenewalBlocked( $subscription->get_id(), array( 'Coffee beans', 'Paper filters' ) );
		$this->assertSame( array(), $this->renewal_order_ids( $subscription->get_id() ) );
		$this->assertGatewayDispatches( 0 );
	}

	public function test_full_restock_recovers_with_exactly_one_order_and_replay_is_a_no_op(): void {
		$coffee       = $this->create_product( array( 'stock' => 0 ) );
		$filters      = $this->create_product(
			array(
				'name'  => 'Paper filters',
				'stock' => 0,
			)
		);
		$subscription = $this->create_subscription( array( array( $coffee, 1 ), array( $filters, 1 ) ) );

		// Block.
		$this->run_scheduled_payment( $subscription->get_id() );
		$this->assertRenewalBlocked( $subscription->get_id() );

		// Partial restock.
		$this->set_stock( $coffee, 5 );
		$this->assertSame( array(), $this->pending_recovery_ids( $subscription->get_id() ) );

		// Full restock and recovery.
		$this->set_stock( $filters, 5 );
		$this->assertCount( 1, $this->pending_recovery_ids( $subscription->get_id() ) );
		$this->assertSame( 1, $this->run_pending_recovery_actions() );

		$renewal_order_ids = $this->renewal_order_ids( $subscription->get_id() );

		$this->assertCount( 1, $renewal_order_ids, 'Recovery should create exactly one renewal order.' );
		$this->assertGatewayDispatches( 1 );
		$this->assertSame( $renewal_order_ids[0], TestGateway::$dispatches[0]['order_id'] );
		$this->assertSame( 'active', $this->reload( $subscription )->get_status() );
		$this->assertBlockingMetadataCleared( $subscription->get_id() );

		// Replay: the same job runs again, and more stock arrives.
		do_action( self::RECOVERY_ACTION, $subscription->get_id() );
		$this->set_stock( $filters, 8 );
		$this->run_pending_recovery_actions();

		$this->assertSame( $renewal_order_ids, $this->renewal_order_ids( $subscription->get_id() ), 'Replaying recovery must not create another order.' );
		$this->assertGatewayDispatches( 1 );
	}

	/**
	 * @dataProvider provide_terminal_statuses
	 *
	 * @param string $terminal_status Status applied before recovery runs.
	 */
	public function test_ended_subscription_is_not_revived_by_a_queued_recovery( string $terminal_status ): void {
		$product      = $this->create_product( array( 'stock' => 0 ) );
		$subscription = $this->create_subscription( array( array( $product, 1 ) ) );

		$this->run_scheduled_payment( $subscription->get_id() );
		$this->assertRenewalBlocked( $subscription->get_id() );

		// Stock returns and a recovery is queued, but the store ends the subscription before the job runs.
		$this->set_stock( $product, 5 );
		$this->assertCount( 1, $this->pending_recovery_ids( $subscription->get_id() ) );

		$this->reload( $subscription )->update_status( $terminal_status );
		$this->run_pending_recovery_actions();
		do_action( self::RECOVERY_ACTION, $subscription->get_id() );

		$this->assertSame( $terminal_status, $this->reload( $subscription )->get_status() );
		$this->assertSame( array(), $this->renewal_order_ids( $subscription->get_id() ) );
		$this->assertGatewayDispatches( 0 );
		$this->assertBlockingMetadataCleared( $subscription->get_id() );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function provide_terminal_statuses(): array {
		return array(
			'cancelled' => array( 'cancelled' ),
			'expired'   => array( 'expired' ),
		);
	}

	public function test_blocked_renewal_restores_core_callbacks_for_the_next_renewal_in_the_same_request(): void {
		$unavailable    = $this->create_product( array( 'stock' => 0 ) );
		$available      = $this->create_product(
			array(
				'name'  => 'Paper filters',
				'stock' => 5,
			)
		);
		$blocked        = $this->create_subscription( array( array( $unavailable, 1 ) ) );
		$renewing       = $this->create_subscription( array( array( $available, 1 ) ) );
		$prepare        = 'WC_Subscriptions_Manager::prepare_renewal';
		$gateway        = 'WC_Subscriptions_Payment_Gateways::gateway_scheduled_subscription_payment';
		$prepare_before = has_action( self::PAYMENT_ACTION, $prepare );
		$gateway_before = has_action( self::PAYMENT_ACTION, $gateway );

		$this->run_scheduled_payment( $blocked->get_id() );
		$this->assertRenewalBlocked( $blocked->get_id() );

		$this->assertSame( $prepare_before, has_action( self::PAYMENT_ACTION, $prepare ), 'prepare_renewal should be restored at its original priority.' );
		$this->assertSame( $gateway_before, has_action( self::PAYMENT_ACTION, $gateway ), 'The gateway payment callback should be restored at its original priority.' );

		$this->run_scheduled_payment( $renewing->get_id() );

		$this->assertCount( 1, $this->renewal_order_ids( $renewing->get_id() ), 'The second subscription should renew normally.' );
		$this->assertSame( 'active', $this->reload( $renewing )->get_status() );
		$this->assertGatewayDispatches( 1 );
		$this->assertSame( array(), $this->renewal_order_ids( $blocked->get_id() ) );
	}
}
