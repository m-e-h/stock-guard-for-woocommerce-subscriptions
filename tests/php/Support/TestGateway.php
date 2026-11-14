<?php
/**
 * Payment gateway that records renewal-payment dispatches without charging anyone.
 *
 * @package StockGuardForWooCommerceSubscriptions\Tests
 */

declare(strict_types=1);

namespace StockGuardForWooCommerceSubscriptions\Tests\Support;

use RuntimeException;
use WC_Order;
use WC_Payment_Gateway;

/**
 * Automatic-renewal test gateway.
 *
 * WooCommerce Subscriptions dispatches renewal payments through the
 * `woocommerce_scheduled_subscription_payment_{gateway_id}` action. The test
 * case attaches record_scheduled_payment() to that action for each gateway.
 */
class TestGateway extends WC_Payment_Gateway {

	public const ID = 'sgfws_test_gateway';

	/**
	 * Order meta written on every dispatch, so dispatches made by other PHP
	 * processes can be counted too.
	 */
	public const DISPATCH_META = '_sgfws_test_gateway_dispatch';

	public const MODE_COMPLETE = 'complete';
	public const MODE_THROW    = 'throw';

	/**
	 * What the gateway does when a renewal payment is dispatched.
	 *
	 * @var string
	 */
	public static $mode = self::MODE_COMPLETE;

	/**
	 * Dispatches recorded in this PHP process.
	 *
	 * @var array<int,array{gateway:string,order_id:int,amount:float}>
	 */
	public static $dispatches = array();

	/**
	 * Configure the gateway.
	 */
	public function __construct() {
		$this->id                 = static::ID;
		$this->method_title       = 'Stock Guard test gateway';
		$this->method_description = 'Records renewal payments for the Stock Guard test suite.';
		$this->title              = $this->method_title;
		$this->enabled            = 'yes';
		$this->has_fields         = false;
		$this->supports           = static::supported_features();
	}

	/**
	 * Return the gateway's supported features.
	 *
	 * @return array<int,string>
	 */
	protected static function supported_features(): array {
		return array(
			'products',
			'subscriptions',
			'subscription_cancellation',
			'subscription_suspension',
			'subscription_reactivation',
			'subscription_amount_changes',
			'subscription_date_changes',
			'multiple_subscriptions',
		);
	}

	/**
	 * Forget recorded dispatches and restore the default behavior.
	 */
	public static function reset(): void {
		self::$mode       = self::MODE_COMPLETE;
		self::$dispatches = array();
	}

	/**
	 * Return dispatches recorded for one gateway in this process.
	 *
	 * @param string $gateway_id Gateway ID.
	 * @return array<int,array{gateway:string,order_id:int,amount:float}>
	 */
	public static function dispatches_for( string $gateway_id ): array {
		return array_values(
			array_filter(
				self::$dispatches,
				static function ( array $dispatch ) use ( $gateway_id ): bool {
					return $gateway_id === $dispatch['gateway'];
				}
			)
		);
	}

	/**
	 * Record a renewal-payment dispatch.
	 *
	 * @param float|string $amount Amount to charge.
	 * @param WC_Order     $order  Renewal order.
	 *
	 * @throws RuntimeException When the gateway is configured to fail.
	 */
	public static function record_scheduled_payment( $amount, $order ): void {
		$gateway_id = $order->get_payment_method();

		self::$dispatches[] = array(
			'gateway'  => $gateway_id,
			'order_id' => $order->get_id(),
			'amount'   => (float) $amount,
		);

		$order->add_meta_data( self::DISPATCH_META, $gateway_id );
		$order->save();

		if ( self::MODE_THROW === self::$mode ) {
			throw new RuntimeException( 'Simulated gateway failure.' );
		}

		$order->payment_complete( 'sgfws-test-' . $order->get_id() );
	}
}
