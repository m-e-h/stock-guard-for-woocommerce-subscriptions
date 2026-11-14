<?php
/**
 * Stock-availability rule tests.
 *
 * @package StockGuardForWooCommerceSubscriptions\Tests
 */

declare(strict_types=1);

namespace StockGuardForWooCommerceSubscriptions\Tests\Integration;

use StockGuardForWooCommerceSubscriptions\Tests\Support\TestCase;

/**
 * Exercises the availability rules through the public scheduled-renewal hook.
 *
 * @group stock-rules
 */
final class StockRulesTest extends TestCase {

	/**
	 * @dataProvider provide_single_product_rules
	 *
	 * @param array<string,mixed> $product_args Product fixture properties.
	 * @param int                 $quantity     Subscription line quantity.
	 * @param bool                $blocked      Whether the renewal should be blocked.
	 */
	public function test_single_product_rules( array $product_args, int $quantity, bool $blocked ): void {
		$product      = $this->create_product( $product_args );
		$subscription = $this->create_subscription( array( array( $product, $quantity ) ) );

		$this->run_scheduled_payment( $subscription->get_id() );

		$this->assertRenewalOutcome( $subscription->get_id(), $blocked, array( 'Coffee beans' ) );
	}

	/**
	 * @return array<string,array{0:array<string,mixed>,1:int,2:bool}>
	 */
	public function provide_single_product_rules(): array {
		return array(
			'quantity 3, stock 2'                             => array( array( 'stock' => 2 ), 3, true ),
			'quantity 3, stock 3'                             => array( array( 'stock' => 3 ), 3, false ),
			'insufficient stock, backorders allowed'          => array(
				array(
					'stock'      => 1,
					'backorders' => 'yes',
				),
				3,
				false,
			),
			'no stock, backorders allowed (on backorder)'     => array(
				array(
					'stock'      => 0,
					'backorders' => 'notify',
				),
				3,
				false,
			),
			'managed stock at zero, no backorders'            => array( array( 'stock' => 0 ), 1, true ),
			'explicitly out of stock, stock not managed'      => array(
				array(
					'manage_stock' => false,
					'stock_status' => 'outofstock',
				),
				1,
				true,
			),
			'stock not managed, in stock'                     => array(
				array(
					'manage_stock' => false,
					'stock_status' => 'instock',
				),
				50,
				false,
			),
			'stock not managed, on backorder'                 => array(
				array(
					'manage_stock' => false,
					'stock_status' => 'onbackorder',
				),
				1,
				false,
			),
		);
	}

	public function test_product_deleted_after_subscription_creation_blocks_safely(): void {
		$product      = $this->create_product( array( 'name' => 'Discontinued roast' ) );
		$subscription = $this->create_subscription( array( array( $product, 1 ) ) );

		$product->delete( true );
		$this->assertFalse( wc_get_product( $product->get_id() ), 'The product fixture should be gone.' );

		$this->run_scheduled_payment( $subscription->get_id() );

		$this->assertRenewalOutcome( $subscription->get_id(), true, array( 'Discontinued roast' ) );
		$this->assertSame( array(), $this->logged_errors() );
	}

	/**
	 * @dataProvider provide_shared_simple_stock
	 *
	 * @param int  $stock   Stock on the shared product.
	 * @param bool $blocked Whether the renewal should be blocked.
	 */
	public function test_two_lines_drawing_from_one_product_combine_their_quantities( int $stock, bool $blocked ): void {
		$product      = $this->create_product( array( 'stock' => $stock ) );
		$subscription = $this->create_subscription( array( array( $product, 2 ), array( $product, 2 ) ) );

		$this->assertCount( 2, $subscription->get_items(), 'The fixture should have two separate line items.' );

		$this->run_scheduled_payment( $subscription->get_id() );

		$this->assertRenewalOutcome( $subscription->get_id(), $blocked, array( 'Coffee beans' ) );
	}

	/**
	 * @return array<string,array{0:int,1:bool}>
	 */
	public function provide_shared_simple_stock(): array {
		return array(
			'need 4, stock 3' => array( 3, true ),
			'need 4, stock 4' => array( 4, false ),
		);
	}

	/**
	 * @dataProvider provide_shared_parent_stock
	 *
	 * @param int  $parent_stock Stock managed on the parent product.
	 * @param bool $blocked      Whether the renewal should be blocked.
	 */
	public function test_variations_sharing_parent_inventory_combine_their_quantities( int $parent_stock, bool $blocked ): void {
		list( $parent, $variations ) = $this->create_variable_product( $parent_stock );

		$this->assertSame( $parent->get_id(), $variations['Small']->get_stock_managed_by_id(), 'The variation should use parent-managed stock.' );

		// Each variation alone fits in stock; together they need 3.
		$subscription = $this->create_subscription(
			array(
				array( $variations['Small'], 1 ),
				array( $variations['Large'], 2 ),
			)
		);

		$this->run_scheduled_payment( $subscription->get_id() );

		$this->assertRenewalOutcome(
			$subscription->get_id(),
			$blocked,
			array( $variations['Small']->get_name(), $variations['Large']->get_name() )
		);
	}

	/**
	 * @return array<string,array{0:int,1:bool}>
	 */
	public function provide_shared_parent_stock(): array {
		return array(
			'need 3, parent stock 2' => array( 2, true ),
			'need 3, parent stock 3' => array( 3, false ),
		);
	}

	public function test_replenishing_parent_inventory_recovers_only_affected_variation_subscriptions(): void {
		list( $parent, $variations ) = $this->create_variable_product( 2 );

		$affected  = $this->create_subscription(
			array(
				array( $variations['Small'], 1 ),
				array( $variations['Large'], 2 ),
			)
		);
		$unrelated = $this->create_subscription( array( array( $this->create_product( array( 'stock' => 0 ) ), 1 ) ) );

		$this->run_scheduled_payment( $affected->get_id() );
		$this->run_scheduled_payment( $unrelated->get_id() );
		$this->assertRenewalBlocked( $affected->get_id() );
		$this->assertRenewalBlocked( $unrelated->get_id() );

		// The stock change happens on the parent, not on either variation.
		$this->set_stock( $parent, 3 );

		$this->assertCount( 1, $this->pending_recovery_ids( $affected->get_id() ), 'The variation subscription should be found from the parent stock change.' );
		$this->assertSame( array(), $this->pending_recovery_ids( $unrelated->get_id() ) );

		$this->run_pending_recovery_actions();

		$this->assertCount( 1, $this->renewal_order_ids( $affected->get_id() ) );
		$this->assertSame( 'active', $this->reload( $affected )->get_status() );
		$this->assertBlockingMetadataCleared( $affected->get_id() );
		$this->assertRenewalBlocked( $unrelated->get_id() );
		$this->assertSame( array(), $this->renewal_order_ids( $unrelated->get_id() ) );
	}

	/**
	 * @dataProvider provide_stock_setting_changes
	 *
	 * @param callable $make_available Changes the product so the renewal can proceed.
	 */
	public function test_stock_setting_changes_queue_recovery( callable $make_available ): void {
		$product      = $this->create_product( array( 'stock' => 1 ) );
		$subscription = $this->create_subscription( array( array( $product, 2 ) ) );

		$this->run_scheduled_payment( $subscription->get_id() );
		$this->assertRenewalBlocked( $subscription->get_id() );

		$product = wc_get_product( $product->get_id() );
		$make_available( $product );
		$product->save();

		$this->assertCount( 1, $this->pending_recovery_ids( $subscription->get_id() ) );
		$this->run_pending_recovery_actions();
		$this->assertCount( 1, $this->renewal_order_ids( $subscription->get_id() ) );
	}

	/**
	 * @return array<string,array{0:callable}>
	 */
	public function provide_stock_setting_changes(): array {
		return array(
			'backorders enabled'      => array(
				static function ( $product ) {
					$product->set_backorders( 'yes' );
				},
			),
			'stock management turned off' => array(
				static function ( $product ) {
					$product->set_manage_stock( false );
					$product->set_stock_status( 'instock' );
				},
			),
			'quantity edited in admin' => array(
				static function ( $product ) {
					$product->set_stock_quantity( 5 );
				},
			),
		);
	}

	/**
	 * Assert whether the renewal was blocked or went through.
	 *
	 * @param int           $subscription_id   Subscription ID.
	 * @param bool          $blocked           Whether the renewal should be blocked.
	 * @param array<string> $unavailable_items Expected unavailable item names when blocked.
	 */
	private function assertRenewalOutcome( int $subscription_id, bool $blocked, array $unavailable_items ): void {
		if ( $blocked ) {
			$this->assertRenewalBlocked( $subscription_id, $unavailable_items );
			$this->assertSame( array(), $this->renewal_order_ids( $subscription_id ), 'A blocked renewal must not create an order.' );
			$this->assertGatewayDispatches( 0 );
			return;
		}

		$this->assertNotSame( 'delayed', $this->reload( $subscription_id )->get_status() );
		$this->assertBlockingMetadataCleared( $subscription_id );
		$this->assertCount( 1, $this->renewal_order_ids( $subscription_id ), 'An allowed renewal should create one order.' );
		$this->assertGatewayDispatches( 1 );
	}
}
