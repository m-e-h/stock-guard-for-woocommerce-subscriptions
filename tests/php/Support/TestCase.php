<?php
/**
 * Base integration test case.
 *
 * @package StockGuardForWooCommerceSubscriptions\Tests
 */

declare(strict_types=1);

namespace StockGuardForWooCommerceSubscriptions\Tests\Support;

use ActionScheduler;
use ActionScheduler_Store;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Subscription;
use WC_Product_Subscription_Variation;
use WC_Product_Variable_Subscription;
use WC_Subscription;
use WP_UnitTestCase;

/**
 * Fixtures and helpers shared by the Stock Guard integration tests.
 *
 * Tests drive the plugin through the same hooks WooCommerce, WooCommerce
 * Subscriptions, and Action Scheduler use in production, against real objects
 * in a real database.
 */
abstract class TestCase extends WP_UnitTestCase {

	protected const ACTION_GROUP           = 'stock_guard_for_woocommerce_subscriptions';
	protected const LOG_SOURCE             = 'stock-guard-for-woocommerce-subscriptions';
	protected const META_BLOCKED_AT        = '_sgfws_renewal_blocked_at';
	protected const META_RECOVERY_ATTEMPTS = '_sgfws_recovery_attempts';
	protected const META_UNAVAILABLE_ITEMS = '_sgfws_unavailable_items';
	protected const PAYMENT_ACTION         = 'woocommerce_scheduled_subscription_payment';
	protected const RECOVERY_ACTION        = 'sgfws_process_stock_recovered_renewal';
	protected const EMAIL_ID               = 'sgfws_customer_subscription_renewal_delayed';
	protected const EMAIL_CLASS_KEY        = 'SGFWS_Customer_Subscription_Renewal_Delayed';

	/**
	 * Logger capturing WooCommerce log entries for the current test.
	 *
	 * @var RecordingLogger
	 */
	protected $logger;

	/**
	 * Prepare per-test state.
	 */
	public function set_up() {
		parent::set_up();


		TestGateway::reset();
		reset_phpmailer_instance();

		$this->logger = new RecordingLogger();
		add_filter(
			'woocommerce_logging_class',
			function () {
				return $this->logger;
			}
		);

		foreach ( array( TestGateway::ID, ScheduledTestGateway::ID ) as $gateway_id ) {
			add_action( 'woocommerce_scheduled_subscription_payment_' . $gateway_id, array( TestGateway::class, 'record_scheduled_payment' ), 10, 2 );
		}
	}

	/**
	 * Create a simple subscription product.
	 *
	 * @param array<string,mixed> $args {
	 *     Optional product properties.
	 *
	 *     @type string   $name         Product name.
	 *     @type string   $price        Recurring price.
	 *     @type bool     $manage_stock Whether stock is managed.
	 *     @type int|null $stock        Stock quantity when managed.
	 *     @type string   $backorders   no, notify, or yes.
	 *     @type string   $stock_status Stock status when not managed.
	 * }
	 */
	protected function create_product( array $args = array() ): WC_Product_Subscription {
		$args = array_merge(
			array(
				'name'         => 'Coffee beans',
				'price'        => '10',
				'manage_stock' => true,
				'stock'        => 10,
				'backorders'   => 'no',
				'stock_status' => 'instock',
			),
			$args
		);

		$product = new WC_Product_Subscription();
		$product->set_name( $args['name'] );
		$product->set_regular_price( $args['price'] );
		$product->update_meta_data( '_subscription_price', $args['price'] );
		$product->update_meta_data( '_subscription_period', 'month' );
		$product->update_meta_data( '_subscription_period_interval', '1' );
		$product->set_manage_stock( $args['manage_stock'] );
		$product->set_backorders( $args['backorders'] );

		if ( $args['manage_stock'] ) {
			$product->set_stock_quantity( $args['stock'] );
		} else {
			$product->set_stock_status( $args['stock_status'] );
		}

		$product->save();

		return $product;
	}

	/**
	 * Create a variable subscription product whose variations share the parent's stock.
	 *
	 * @param int           $parent_stock    Stock managed on the parent.
	 * @param array<string> $variation_names Attribute option for each variation.
	 * @return array{0:WC_Product_Variable_Subscription,1:array<string,WC_Product_Subscription_Variation>}
	 */
	protected function create_variable_product( int $parent_stock, array $variation_names = array( 'Small', 'Large' ) ): array {
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'Size' );
		$attribute->set_options( $variation_names );
		$attribute->set_visible( true );
		$attribute->set_variation( true );

		$parent = new WC_Product_Variable_Subscription();
		$parent->set_name( 'Tea box' );
		$parent->set_attributes( array( $attribute ) );
		$parent->set_manage_stock( true );
		$parent->set_stock_quantity( $parent_stock );
		$parent->set_backorders( 'no' );
		$parent->save();

		$variations = array();

		foreach ( $variation_names as $variation_name ) {
			$variation = new WC_Product_Subscription_Variation();
			$variation->set_parent_id( $parent->get_id() );
			$variation->set_attributes( array( 'size' => $variation_name ) );
			$variation->set_regular_price( '10' );
			$variation->update_meta_data( '_subscription_price', '10' );
			$variation->update_meta_data( '_subscription_period', 'month' );
			$variation->update_meta_data( '_subscription_period_interval', '1' );
			$variation->set_manage_stock( false );
			$variation->save();

			$variations[ $variation_name ] = wc_get_product( $variation->get_id() );
		}

		return array( wc_get_product( $parent->get_id() ), $variations );
	}

	/**
	 * Create an active monthly subscription.
	 *
	 * @param array<int,array{0:WC_Product,1:int}> $lines Product and quantity for each line item.
	 * @param array<string,mixed>                  $args {
	 *     Optional subscription properties.
	 *
	 *     @type string $payment_method Gateway ID. Empty for none.
	 *     @type bool   $manual         Whether the subscription requires manual renewal.
	 *     @type string $billing_email  Customer email address.
	 *     @type string $first_name     Customer first name.
	 * }
	 */
	protected function create_subscription( array $lines, array $args = array() ): WC_Subscription {
		$args = array_merge(
			array(
				'payment_method' => TestGateway::ID,
				'manual'         => false,
				'billing_email'  => 'customer@example.com',
				'first_name'     => 'Jamie',
			),
			$args
		);

		$customer_id  = self::factory()->user->create( array( 'role' => 'customer' ) );
		$subscription = wcs_create_subscription(
			array(
				'customer_id'      => $customer_id,
				'billing_period'   => 'month',
				'billing_interval' => 1,
				'start_date'       => gmdate( 'Y-m-d H:i:s', strtotime( '-1 month' ) ),
			)
		);

		$this->assertInstanceOf( WC_Subscription::class, $subscription, 'The subscription fixture could not be created.' );

		foreach ( $lines as $line ) {
			$subscription->add_product( $line[0], $line[1] );
		}

		$subscription->set_billing_first_name( $args['first_name'] );
		$subscription->set_billing_email( $args['billing_email'] );
		$subscription->set_requires_manual_renewal( $args['manual'] );

		if ( $args['payment_method'] ) {
			$subscription->set_payment_method( $args['payment_method'] );
		}

		$subscription->calculate_totals();
		$subscription->update_dates( array( 'next_payment' => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ) ) );
		$subscription->save();
		$subscription->update_status( 'active' );

		return $this->reload( $subscription );
	}

	/**
	 * Load a fresh copy of a subscription from the database.
	 *
	 * @param WC_Subscription|int $subscription Subscription or ID.
	 */
	protected function reload( $subscription ): WC_Subscription {
		$subscription_id = $subscription instanceof WC_Subscription ? $subscription->get_id() : (int) $subscription;
		$fresh           = wcs_get_subscription( $subscription_id );

		$this->assertInstanceOf( WC_Subscription::class, $fresh, "Subscription {$subscription_id} could not be loaded." );

		return $fresh;
	}

	/**
	 * Set a product's managed stock the way WooCommerce does after an inventory change.
	 *
	 * @param WC_Product $product  Product.
	 * @param int        $quantity New stock quantity.
	 */
	protected function set_stock( WC_Product $product, int $quantity ): void {
		wc_update_product_stock( wc_get_product( $product->get_id() ), $quantity );
	}

	/**
	 * Run the subscription's scheduled renewal through Action Scheduler.
	 *
	 * Falls back to firing the hook directly when Subscriptions did not schedule
	 * an action (for example after an earlier attempt unscheduled it), which is
	 * what a manual "process renewal" or a retried job does.
	 *
	 * @param int $subscription_id Subscription ID.
	 */
	protected function run_scheduled_payment( int $subscription_id ): void {
		$action_ids = $this->pending_action_ids( self::PAYMENT_ACTION, array( 'subscription_id' => $subscription_id ) );

		if ( empty( $action_ids ) ) {
			do_action( self::PAYMENT_ACTION, $subscription_id );
			return;
		}

		$this->run_action( (int) reset( $action_ids ) );
	}

	/**
	 * Run every pending recovery job, including delayed retries, once.
	 *
	 * @return int Number of jobs run.
	 */
	protected function run_pending_recovery_actions(): int {
		$action_ids = $this->pending_action_ids( self::RECOVERY_ACTION );

		foreach ( $action_ids as $action_id ) {
			$this->run_action( (int) $action_id );
		}

		return count( $action_ids );
	}

	/**
	 * Execute an Action Scheduler action the way a queue runner would.
	 *
	 * @param int $action_id Action ID.
	 */
	protected function run_action( int $action_id ): void {
		ActionScheduler::runner()->process_action( $action_id, 'PHPUnit' );

		$this->assertSame(
			ActionScheduler_Store::STATUS_COMPLETE,
			ActionScheduler::store()->get_status( (string) $action_id ),
			"Action {$action_id} did not complete. A callback threw an exception; check the action's log."
		);
	}

	/**
	 * Return pending Action Scheduler action IDs.
	 *
	 * @param string            $hook Action hook.
	 * @param array<mixed>|null $args Exact action arguments, or null for any.
	 * @return array<int,int>
	 */
	protected function pending_action_ids( string $hook, ?array $args = null ): array {
		$query = array(
			'hook'     => $hook,
			'status'   => ActionScheduler_Store::STATUS_PENDING,
			'per_page' => -1,
		);

		if ( null !== $args ) {
			$query['args'] = $args;
		}

		return array_map( 'intval', array_values( as_get_scheduled_actions( $query, 'ids' ) ) );
	}

	/**
	 * Return pending recovery job IDs for one subscription.
	 *
	 * @param int $subscription_id Subscription ID.
	 * @return array<int,int>
	 */
	protected function pending_recovery_ids( int $subscription_id ): array {
		return $this->pending_action_ids( self::RECOVERY_ACTION, array( $subscription_id ) );
	}

	/**
	 * Return the IDs of a subscription's renewal orders.
	 *
	 * @param int $subscription_id Subscription ID.
	 * @return array<int,int>
	 */
	protected function renewal_order_ids( int $subscription_id ): array {
		return array_map( 'intval', array_values( $this->reload( $subscription_id )->get_related_orders( 'ids', 'renewal' ) ) );
	}

	/**
	 * Return the renewal-delayed emails captured by the mock mailer.
	 *
	 * @return array<int,object>
	 */
	protected function delayed_emails(): array {
		$heading = 'subscription renewal is delayed';

		return array_values(
			array_filter(
				tests_retrieve_phpmailer_instance()->mock_sent,
				static function ( array $mail ) use ( $heading ): bool {
					return false !== stripos( $mail['subject'], $heading );
				}
			)
		);
	}

	/**
	 * Return Stock Guard error messages written to the WooCommerce log.
	 *
	 * @return array<int,string>
	 */
	protected function logged_errors(): array {
		return $this->logger->messages_for( self::LOG_SOURCE );
	}

	/**
	 * Return the registered renewal-delayed email.
	 */
	protected function delayed_email(): \WC_Email {
		$emails = WC()->mailer()->get_emails();

		$this->assertArrayHasKey( self::EMAIL_CLASS_KEY, $emails, 'The renewal delayed email is not registered.' );

		return $emails[ self::EMAIL_CLASS_KEY ];
	}

	/**
	 * Assert that a subscription is delayed by Stock Guard with no renewal order.
	 *
	 * @param int           $subscription_id   Subscription ID.
	 * @param array<string> $unavailable_items Expected unavailable item names, or null to skip.
	 */
	protected function assertRenewalBlocked( int $subscription_id, ?array $unavailable_items = null ): void {
		$subscription = $this->reload( $subscription_id );

		$this->assertSame( 'delayed', $subscription->get_status(), 'The subscription should be delayed.' );
		$this->assertNotEmpty( $subscription->get_meta( self::META_BLOCKED_AT ), 'The blocked marker should be set.' );

		if ( null !== $unavailable_items ) {
			$this->assertSame( $unavailable_items, $subscription->get_meta( self::META_UNAVAILABLE_ITEMS ) );
		}
	}

	/**
	 * Assert that none of Stock Guard's blocking metadata remains.
	 *
	 * @param int $subscription_id Subscription ID.
	 */
	protected function assertBlockingMetadataCleared( int $subscription_id ): void {
		$subscription = $this->reload( $subscription_id );

		foreach ( array( self::META_BLOCKED_AT, self::META_RECOVERY_ATTEMPTS, self::META_UNAVAILABLE_ITEMS ) as $meta_key ) {
			$this->assertEmpty( $subscription->get_meta( $meta_key ), "{$meta_key} should be cleared." );
		}
	}

	/**
	 * Assert the number of renewal-payment dispatches made to a gateway in this process.
	 *
	 * @param int    $expected   Expected dispatch count.
	 * @param string $gateway_id Gateway ID.
	 */
	protected function assertGatewayDispatches( int $expected, string $gateway_id = TestGateway::ID ): void {
		$this->assertCount( $expected, TestGateway::dispatches_for( $gateway_id ), "Unexpected number of {$gateway_id} payment dispatches." );
	}
}
