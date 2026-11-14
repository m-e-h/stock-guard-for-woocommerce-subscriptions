<?php
/**
 * Plugin Name: Stock Guard E2E fixtures
 * Description: Deterministic scenario controls for the Stock Guard Playwright suite. Mounted only into the disposable Playground site.
 *
 * Exposes REST routes under sgfws-e2e/v1 so browser tests can create a
 * subscription, trigger its scheduled renewal, restock, and run the queued
 * recovery job without waiting for WP-Cron or Action Scheduler's runner.
 *
 * @package StockGuardForWooCommerceSubscriptions\Tests
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- E2E fixture only.

defined( 'ABSPATH' ) || exit;

// Refuse to run anywhere except the local Playground server started by the suite.
if ( ! defined( 'SGFWS_E2E_KEY' ) || '' === SGFWS_E2E_KEY ) {
	return;
}

const SGFWS_E2E_GATEWAY_ID = 'sgfws_e2e_gateway';
const SGFWS_E2E_PASSWORD   = 'customer-password';

// Scheduled work runs only when a test asks for it. WP-Cron is off via DISABLE_WP_CRON in blueprint.json.
add_filter( 'action_scheduler_allow_async_request_runner', '__return_false' );

/**
 * Whether the request carries the suite's fixture key.
 *
 * @param WP_REST_Request $request REST request.
 */
function sgfws_e2e_authorized( WP_REST_Request $request ): bool {
	return hash_equals( SGFWS_E2E_KEY, (string) $request->get_header( 'x-sgfws-e2e-key' ) );
}

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}

		/**
		 * Automatic-renewal gateway that marks renewals paid without charging anyone.
		 */
		final class SGFWS_E2E_Gateway extends WC_Payment_Gateway {

			/**
			 * Configure the gateway.
			 */
			public function __construct() {
				$this->id                 = SGFWS_E2E_GATEWAY_ID;
				$this->method_title       = 'E2E test gateway';
				$this->method_description = 'Completes renewal payments for the Stock Guard E2E suite.';
				$this->title              = 'E2E test gateway';
				$this->enabled            = 'yes';
				$this->supports           = array(
					'products',
					'subscriptions',
					'subscription_cancellation',
					'subscription_suspension',
					'subscription_reactivation',
					'subscription_amount_changes',
					'subscription_date_changes',
				);
			}
		}

		add_filter(
			'woocommerce_payment_gateways',
			static function ( $gateways ) {
				$gateways[] = SGFWS_E2E_Gateway::class;
				return $gateways;
			}
		);

		add_action(
			'woocommerce_scheduled_subscription_payment_' . SGFWS_E2E_GATEWAY_ID,
			static function ( $amount, $order ) {
				unset( $amount );
				$order->payment_complete( 'sgfws-e2e-' . $order->get_id() );
			},
			10,
			2
		);
	},
	30
);

add_action(
	'rest_api_init',
	static function () {
		// Public readiness probe for Playwright's webServer check. Playground serves requests before the blueprint finishes activating plugins.
		register_rest_route(
			'sgfws-e2e/v1',
			'/ready',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static function () {
					$ready = function_exists( 'WC' )
						&& class_exists( 'WC_Subscriptions' )
						&& class_exists( 'StockGuardForWooCommerceSubscriptions\\RenewalStockGuard' )
						&& get_option( 'wc_subscriptions_siteurl' )
						&& 'no' === get_option( 'woocommerce_newly_installed' );

					return new WP_REST_Response( array( 'ready' => (bool) $ready ), $ready ? 200 : 503 );
				},
			)
		);

		$routes = array(
			'/scenario'                          => 'sgfws_e2e_create_scenario',
			'/subscriptions/(?P<id>\d+)/renew'   => 'sgfws_e2e_renew',
			'/products/(?P<id>\d+)/stock'        => 'sgfws_e2e_set_stock',
			'/recovery/run'                      => 'sgfws_e2e_run_recovery',
			'/subscriptions/(?P<id>\d+)'         => 'sgfws_e2e_subscription_state',
		);

		foreach ( $routes as $route => $callback ) {
			register_rest_route(
				'sgfws-e2e/v1',
				$route,
				array(
					'methods'             => '/subscriptions/(?P<id>\d+)' === $route ? 'GET' : 'POST',
					'callback'            => $callback,
					'permission_callback' => 'sgfws_e2e_authorized',
				)
			);
		}
	}
);

/**
 * Create a customer with an active subscription to an out-of-stock product.
 *
 * @param WP_REST_Request $request REST request with an optional `label`.
 */
function sgfws_e2e_create_scenario( WP_REST_Request $request ): WP_REST_Response {
	$label    = sanitize_key( (string) $request->get_param( 'label' ) );
	$label    = $label ? $label : wp_generate_password( 6, false );
	$username = 'customer_' . $label;

	$customer_id = wc_create_new_customer( $username . '@example.com', $username, SGFWS_E2E_PASSWORD, array( 'first_name' => 'Jamie' ) );

	if ( is_wp_error( $customer_id ) ) {
		return new WP_REST_Response( array( 'error' => $customer_id->get_error_message() ), 500 );
	}

	$product = new WC_Product_Subscription();
	$product->set_name( 'Single-origin coffee ' . $label );
	$product->set_regular_price( '12' );
	$product->update_meta_data( '_subscription_price', '12' );
	$product->update_meta_data( '_subscription_period', 'month' );
	$product->update_meta_data( '_subscription_period_interval', '1' );
	$product->set_manage_stock( true );
	$product->set_stock_quantity( 0 );
	$product->set_backorders( 'no' );
	$product->save();

	$subscription = wcs_create_subscription(
		array(
			'customer_id'      => $customer_id,
			'billing_period'   => 'month',
			'billing_interval' => 1,
			'start_date'       => gmdate( 'Y-m-d H:i:s', strtotime( '-1 month' ) ),
		)
	);
	$subscription->add_product( $product, 1 );
	$subscription->set_billing_first_name( 'Jamie' );
	$subscription->set_billing_email( $username . '@example.com' );
	$subscription->set_payment_method( SGFWS_E2E_GATEWAY_ID );
	$subscription->set_requires_manual_renewal( false );
	$subscription->calculate_totals();
	$subscription->update_dates( array( 'next_payment' => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ) ) );
	$subscription->save();
	$subscription->update_status( 'active' );

	return new WP_REST_Response(
		array(
			'customer'       => array(
				'username' => $username,
				'password' => SGFWS_E2E_PASSWORD,
			),
			'productId'      => $product->get_id(),
			'productName'    => $product->get_name(),
			'subscriptionId' => $subscription->get_id(),
		)
	);
}

/**
 * Fire the subscription's scheduled renewal, as Action Scheduler would.
 *
 * @param WP_REST_Request $request REST request.
 */
function sgfws_e2e_renew( WP_REST_Request $request ): WP_REST_Response {
	$subscription_id = absint( $request['id'] );

	do_action( 'woocommerce_scheduled_subscription_payment', $subscription_id );

	return sgfws_e2e_subscription_state( $request );
}

/**
 * Set a product's stock through WooCommerce so stock hooks fire.
 *
 * @param WP_REST_Request $request REST request with `quantity`.
 */
function sgfws_e2e_set_stock( WP_REST_Request $request ): WP_REST_Response {
	$product = wc_get_product( absint( $request['id'] ) );

	if ( ! $product ) {
		return new WP_REST_Response( array( 'error' => 'Product not found.' ), 404 );
	}

	wc_update_product_stock( $product, absint( $request->get_param( 'quantity' ) ) );

	return new WP_REST_Response( array( 'stock' => wc_get_product( $product->get_id() )->get_stock_quantity() ) );
}

/**
 * Run every pending Stock Guard recovery job now.
 */
function sgfws_e2e_run_recovery(): WP_REST_Response {
	$action_ids = as_get_scheduled_actions(
		array(
			'hook'     => 'sgfws_process_stock_recovered_renewal',
			'status'   => ActionScheduler_Store::STATUS_PENDING,
			'per_page' => -1,
		),
		'ids'
	);

	foreach ( $action_ids as $action_id ) {
		ActionScheduler::runner()->process_action( $action_id, 'E2E' );
	}

	return new WP_REST_Response( array( 'ran' => count( $action_ids ) ) );
}

/**
 * Return the state the browser tests assert against.
 *
 * @param WP_REST_Request $request REST request.
 */
function sgfws_e2e_subscription_state( WP_REST_Request $request ): WP_REST_Response {
	$subscription = wcs_get_subscription( absint( $request['id'] ) );

	if ( ! $subscription ) {
		return new WP_REST_Response( array( 'error' => 'Subscription not found.' ), 404 );
	}

	$renewal_orders = array_map(
		static function ( WC_Order $order ) {
			return array(
				'id'      => $order->get_id(),
				'number'  => $order->get_order_number(),
				'status'  => $order->get_status(),
				'editUrl' => $order->get_edit_order_url(),
			);
		},
		array_values( $subscription->get_related_orders( 'all', 'renewal' ) )
	);

	return new WP_REST_Response(
		array(
			'status'        => $subscription->get_status(),
			'number'        => $subscription->get_order_number(),
			'editUrl'       => $subscription->get_edit_order_url(),
			'renewalOrders' => $renewal_orders,
		)
	);
}
