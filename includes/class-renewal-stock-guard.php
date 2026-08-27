<?php
/**
 * Subscription renewal stock guard.
 *
 * @package StockGuardForWooCommerceSubscriptions
 */

declare(strict_types=1);

namespace StockGuardForWooCommerceSubscriptions;

use Throwable;
use WC_Order;
use WC_Product;
use WC_Subscription;
use WC_Subscriptions_Core_Plugin;
use WC_Subscriptions_Manager;
use WC_Subscriptions_Payment_Gateways;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Pauses and recovers subscription renewals based on product availability.
 */
final class RenewalStockGuard {

	private const ACTION_GROUP           = 'stock_guard_for_woocommerce_subscriptions';
	private const EMAIL_ACTION           = 'sgfws_subscription_renewal_delayed_notification';
	private const LOG_SOURCE             = 'stock-guard-for-woocommerce-subscriptions';
	private const MAX_RECOVERY_ATTEMPTS  = 3;
	private const META_BLOCKED_AT        = '_sgfws_renewal_blocked_at';
	private const META_RECOVERY_ATTEMPTS = '_sgfws_recovery_attempts';
	private const META_UNAVAILABLE_ITEMS = '_sgfws_unavailable_items';
	private const RECOVERY_ACTION        = 'sgfws_process_stock_recovered_renewal';
	private const RECOVERY_RETRY_DELAY   = 300;
	private const STATUS_DELAYED         = 'delayed';
	private const STATUS_DELAYED_KEY     = 'wc-delayed';

	/**
	 * Whether the core renewal preparation callback was removed for the current action.
	 *
	 * @var bool
	 */
	private $removed_prepare_callback = false;

	/**
	 * Whether the core gateway callback was removed for the current action.
	 *
	 * @var bool
	 */
	private $removed_gateway_callback = false;

	/**
	 * Subscription IDs currently being recovered in this request.
	 *
	 * @var array<int,bool>
	 */
	private $recovering_subscription_ids = array();

	/**
	 * Register WooCommerce and Subscriptions hooks.
	 *
	 * @since 1.0.0
	 */
	public function register_hooks() {
		add_filter( 'woocommerce_register_shop_order_post_statuses', array( $this, 'register_delayed_post_status' ) );
		add_filter( 'wcs_subscription_statuses', array( $this, 'register_delayed_subscription_status' ) );
		add_filter( 'woocommerce_can_subscription_be_updated_to_delayed', array( $this, 'allow_transition_to_delayed' ), 10, 2 );
		add_filter( 'woocommerce_can_subscription_be_updated_to_on-hold', array( $this, 'allow_delayed_transition_to_on_hold' ), 10, 2 );
		add_filter( 'woocommerce_can_subscription_be_updated_to_active', array( $this, 'allow_recovered_transition_to_active' ), 10, 2 );
		add_filter( 'woocommerce_subscriptions_gateway_status_updated_hook_prefix', array( $this, 'map_delayed_gateway_status_hook' ), 10, 3 );
		add_action( 'woocommerce_subscription_status_delayed', array( $this, 'handle_subscription_delayed' ) );
		add_action( 'woocommerce_scheduled_subscription_payment', array( $this, 'handle_scheduled_subscription_payment' ), 0, 1 );
		add_action( 'woocommerce_product_set_stock', array( $this, 'handle_product_stock_change' ), 20, 1 );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'handle_product_stock_change' ), 20, 1 );
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'handle_product_stock_status_change' ), 20, 3 );
		add_action( 'woocommerce_variation_set_stock_status', array( $this, 'handle_product_stock_status_change' ), 20, 3 );
		add_action( 'woocommerce_product_object_updated_props', array( $this, 'handle_product_object_updated_props' ), 20, 2 );
		add_action( self::RECOVERY_ACTION, array( $this, 'handle_recovery_action' ), 10, 1 );
		add_action( 'woocommerce_subscription_status_updated', array( $this, 'handle_subscription_status_updated' ), 20, 3 );
	}

	/**
	 * Register the delayed post status with WooCommerce.
	 *
	 * @internal
	 *
	 * @param array<string,array<string,mixed>> $post_statuses Registered order post statuses.
	 * @return array<string,array<string,mixed>>
	 */
	public function register_delayed_post_status( $post_statuses ) {
		$post_statuses[ self::STATUS_DELAYED_KEY ] = array(
			'label'                     => _x( 'Delayed', 'Subscription status', 'stock-guard-for-woocommerce-subscriptions' ),
			'public'                    => false,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			/* translators: %s: number of subscriptions. */
			'label_count'               => _n_noop( 'Delayed <span class="count">(%s)</span>', 'Delayed <span class="count">(%s)</span>', 'stock-guard-for-woocommerce-subscriptions' ),
		);

		return $post_statuses;
	}

	/**
	 * Add delayed to the statuses available to subscriptions.
	 *
	 * @internal
	 *
	 * @param array<string,string> $subscription_statuses Registered subscription statuses.
	 * @return array<string,string>
	 */
	public function register_delayed_subscription_status( $subscription_statuses ) {
		$subscription_statuses[ self::STATUS_DELAYED_KEY ] = _x( 'Delayed', 'Subscription status', 'stock-guard-for-woocommerce-subscriptions' );

		return $subscription_statuses;
	}

	/**
	 * Use the gateway's suspension hook while retaining the delayed status.
	 *
	 * @internal
	 *
	 * @param string          $hook_prefix  Gateway status hook prefix.
	 * @param WC_Subscription $subscription Subscription object.
	 * @param string          $new_status   New subscription status.
	 * @return string
	 */
	public function map_delayed_gateway_status_hook( $hook_prefix, $subscription, $new_status ) {
		if ( $subscription instanceof WC_Subscription && self::STATUS_DELAYED === $new_status ) {
			return 'woocommerce_subscription_on-hold_';
		}

		return $hook_prefix;
	}

	/**
	 * Allow an active subscription to be delayed by the stock guard.
	 *
	 * @internal
	 *
	 * @param bool            $can_be_updated Whether the transition is currently allowed.
	 * @param WC_Subscription $subscription   Subscription object.
	 * @return bool
	 */
	public function allow_transition_to_delayed( $can_be_updated, $subscription ) {
		return $can_be_updated || (
			$subscription instanceof WC_Subscription
			&& $subscription->has_status( array( 'active', 'on-hold' ) )
			&& $subscription->get_meta( self::META_BLOCKED_AT, true )
		);
	}

	/**
	 * Allow recovery to hand a delayed subscription back to the core renewal flow.
	 *
	 * @internal
	 *
	 * @param bool            $can_be_updated Whether the transition is currently allowed.
	 * @param WC_Subscription $subscription   Subscription object.
	 * @return bool
	 */
	public function allow_delayed_transition_to_on_hold( $can_be_updated, $subscription ) {
		return $can_be_updated || ( $subscription instanceof WC_Subscription && $subscription->has_status( self::STATUS_DELAYED ) );
	}

	/**
	 * Allow a successfully recovered subscription to return to active.
	 *
	 * @internal
	 *
	 * @param bool            $can_be_updated Whether the transition is currently allowed.
	 * @param WC_Subscription $subscription   Subscription object.
	 * @return bool
	 */
	public function allow_recovered_transition_to_active( $can_be_updated, $subscription ) {
		return $can_be_updated || (
			$subscription instanceof WC_Subscription
			&& $subscription->has_status( array( self::STATUS_DELAYED, 'on-hold' ) )
			&& isset( $this->recovering_subscription_ids[ $subscription->get_id() ] )
		);
	}

	/**
	 * Pause core subscription events and notifications while renewal is delayed.
	 *
	 * @internal
	 *
	 * @param WC_Subscription $subscription Subscription object.
	 */
	public function handle_subscription_delayed( $subscription ) {
		if ( ! $subscription instanceof WC_Subscription ) {
			return;
		}

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			$action_args  = array( 'subscription_id' => $subscription->get_id() );
			$action_hooks = array(
				'woocommerce_scheduled_subscription_trial_end',
				'woocommerce_scheduled_subscription_payment',
				'woocommerce_scheduled_subscription_expiration',
				'woocommerce_scheduled_subscription_end_of_prepaid_term',
			);

			foreach ( $action_hooks as $action_hook ) {
				as_unschedule_all_actions( $action_hook, $action_args );
			}

			$last_renewal_order_id = absint( $subscription->get_last_order( 'ids', 'renewal' ) );

			if ( $last_renewal_order_id ) {
				as_unschedule_all_actions(
					'woocommerce_scheduled_subscription_payment_retry',
					array( 'order_id' => $last_renewal_order_id )
				);
			}
		}

		if ( class_exists( WC_Subscriptions_Core_Plugin::class ) ) {
			$core_plugin = WC_Subscriptions_Core_Plugin::instance();

			if ( is_callable( array( $core_plugin->notifications_scheduler, 'unschedule_all_notifications' ) ) ) {
				$core_plugin->notifications_scheduler->unschedule_all_notifications( $subscription );
			}
		}
	}

	/**
	 * Stop a scheduled renewal before Subscriptions creates its renewal order.
	 *
	 * @internal
	 *
	 * @param int $subscription_id Subscription ID.
	 */
	public function handle_scheduled_subscription_payment( $subscription_id ) {
		$subscription_id = absint( $subscription_id );

		if ( isset( $this->recovering_subscription_ids[ $subscription_id ] ) || ! function_exists( 'wcs_get_subscription' ) ) {
			return;
		}

		$subscription = wcs_get_subscription( $subscription_id );

		if ( ! $subscription instanceof WC_Subscription ) {
			return;
		}

		if ( $subscription->get_meta( self::META_BLOCKED_AT, true ) ) {
			if ( ! $subscription->has_status( array( 'active', self::STATUS_DELAYED, 'on-hold' ) ) ) {
				return;
			}

			$this->suppress_core_renewal_callbacks();
			$unavailable_items = $this->get_unavailable_item_names( $subscription );

			if ( empty( $unavailable_items ) ) {
				$this->queue_recovery_action( $subscription_id );
			} else {
				$subscription->delete_meta_data( self::META_RECOVERY_ATTEMPTS );
				$subscription->update_meta_data( self::META_UNAVAILABLE_ITEMS, $unavailable_items );
				$subscription->save();
			}
			return;
		}

		if ( ! $subscription->has_status( 'active' ) ) {
			return;
		}

		$unavailable_items = $this->get_unavailable_item_names( $subscription );

		if ( empty( $unavailable_items ) ) {
			return;
		}

		$this->suppress_core_renewal_callbacks();

		$items_list = implode( ', ', $unavailable_items );

		$subscription->delete_meta_data( self::META_RECOVERY_ATTEMPTS );
		$subscription->update_meta_data( self::META_BLOCKED_AT, gmdate( 'Y-m-d H:i:s' ) );
		$subscription->update_meta_data( self::META_UNAVAILABLE_ITEMS, $unavailable_items );

		$note = sprintf(
			/* translators: %s: comma-separated unavailable product names. */
			__( 'Renewal paused before order creation because these items are unavailable: %s. The renewal will be retried when all items are available.', 'stock-guard-for-woocommerce-subscriptions' ),
			$items_list
		);

		try {
			if ( $subscription->can_be_updated_to( self::STATUS_DELAYED ) ) {
				$subscription->update_status( self::STATUS_DELAYED, $note );
			} else {
				$subscription->save();
				$subscription->add_order_note( $note );
			}
		} catch ( Throwable $throwable ) {
			$subscription->save();
			$subscription->add_order_note( $note );
			$this->log_error(
				'Renewal was blocked, but the subscription could not be moved to delayed.',
				array(
					'subscription_id' => $subscription_id,
					'exception'       => $throwable,
				)
			);
		}

		try {
			$this->send_delayed_renewal_email( $subscription, $unavailable_items );
		} catch ( Throwable $throwable ) {
			$subscription->add_order_note(
				__( 'The renewal delayed email could not be sent. Check the WooCommerce logs for details.', 'stock-guard-for-woocommerce-subscriptions' )
			);
			$this->log_error(
				'The renewal delayed email could not be sent.',
				array(
					'subscription_id' => $subscription_id,
					'exception'       => $throwable,
				)
			);
		}
	}

	/**
	 * Queue recovery checks after a product's stock quantity changes.
	 *
	 * @internal
	 *
	 * @param mixed $product Product object.
	 */
	public function handle_product_stock_change( $product ) {
		if ( $product instanceof WC_Product ) {
			$this->queue_recoveries_for_product( $product );
		}
	}

	/**
	 * Queue recovery checks after a product's stock status changes.
	 *
	 * @internal
	 *
	 * @param int        $product_id   Product ID.
	 * @param string     $stock_status New stock status.
	 * @param WC_Product $product      Product object.
	 */
	public function handle_product_stock_status_change( $product_id, $stock_status, $product ) {
		unset( $product_id, $stock_status );

		if ( $product instanceof WC_Product ) {
			$this->queue_recoveries_for_product( $product );
		}
	}

	/**
	 * Queue recovery checks when stock-management settings change.
	 *
	 * @internal
	 *
	 * @param WC_Product    $product       Product object.
	 * @param array<string> $updated_props Updated product properties.
	 */
	public function handle_product_object_updated_props( $product, $updated_props ) {
		if ( ! $product instanceof WC_Product || ! is_array( $updated_props ) ) {
			return;
		}

		$stock_props = array( 'backorders', 'manage_stock', 'stock_quantity', 'stock_status' );

		if ( array_intersect( $stock_props, $updated_props ) ) {
			$this->queue_recoveries_for_product( $product );
		}
	}

	/**
	 * Create and process a renewal after every subscription item becomes available.
	 *
	 * @internal
	 *
	 * @param int $subscription_id Subscription ID.
	 */
	public function handle_recovery_action( $subscription_id ) {
		$subscription_id = absint( $subscription_id );

		if ( ! function_exists( 'wcs_get_subscription' ) || ! function_exists( 'wcs_create_renewal_order' ) ) {
			return;
		}

		$subscription = wcs_get_subscription( $subscription_id );

		if ( ! $subscription instanceof WC_Subscription || ! $subscription->get_meta( self::META_BLOCKED_AT, true ) ) {
			return;
		}

		if ( ! $subscription->has_status( array( 'active', self::STATUS_DELAYED, 'on-hold' ) ) ) {
			$this->clear_blocked_metadata( $subscription );
			return;
		}

		$unavailable_items = $this->get_unavailable_item_names( $subscription );

		if ( ! empty( $unavailable_items ) ) {
			$subscription->delete_meta_data( self::META_RECOVERY_ATTEMPTS );
			$subscription->update_meta_data( self::META_UNAVAILABLE_ITEMS, $unavailable_items );
			$subscription->save();
			return;
		}

		$this->recovering_subscription_ids[ $subscription_id ] = true;

		try {
			$order_note     = __( 'Stock is available. Creating the delayed renewal order.', 'stock-guard-for-woocommerce-subscriptions' );
			$renewal_order  = false;
			$current_status = $subscription->get_status();

			if ( is_callable( array( WC_Subscriptions_Manager::class, 'process_renewal' ) ) ) {
				$renewal_order = WC_Subscriptions_Manager::process_renewal( $subscription_id, $current_status, $order_note );
			}

			if ( false === $renewal_order ) {
				$renewal_order = wcs_create_renewal_order( $subscription );
			}

			if ( $renewal_order instanceof WP_Error ) {
				$this->handle_failed_recovery( $subscription );
				$this->log_error(
					'WooCommerce Subscriptions could not create the delayed renewal order.',
					array(
						'subscription_id' => $subscription_id,
						'error'           => $renewal_order,
					)
				);
				return;
			}

			if ( ! $renewal_order instanceof WC_Order ) {
				$this->handle_failed_recovery( $subscription );
				$this->log_error(
					'WooCommerce Subscriptions returned an invalid delayed renewal order.',
					array( 'subscription_id' => $subscription_id )
				);
				return;
			}

			$this->clear_blocked_metadata( $subscription );

			if (
				$subscription->has_status( self::STATUS_DELAYED )
				|| (
					$subscription->has_status( 'on-hold' )
					&& $subscription->payment_method_supports( 'gateway_scheduled_payments' )
				)
			) {
				$subscription->update_status(
					'active',
					__( 'Stock is available and the delayed renewal order was created. The subscription has resumed.', 'stock-guard-for-woocommerce-subscriptions' )
				);
			}

			$subscription->add_order_note(
				sprintf(
					/* translators: %s: renewal order number. */
					__( 'Stock is available. Renewal order #%s was created automatically.', 'stock-guard-for-woocommerce-subscriptions' ),
					$renewal_order->get_order_number()
				)
			);

			$this->process_recovered_renewal_payment( $renewal_order, $subscription );
		} catch ( Throwable $throwable ) {
			$this->handle_failed_recovery( $subscription );
			$subscription->add_order_note(
				__( 'Automatic renewal recovery could not be completed. Check the WooCommerce logs for details.', 'stock-guard-for-woocommerce-subscriptions' )
			);
			$this->log_error(
				'Automatic renewal recovery failed.',
				array(
					'subscription_id' => $subscription_id,
					'exception'       => $throwable,
				)
			);
		} finally {
			unset( $this->recovering_subscription_ids[ $subscription_id ] );
		}
	}

	/**
	 * Clear stale plugin metadata after a subscription leaves a blocked status.
	 *
	 * @internal
	 *
	 * @param WC_Subscription $subscription Subscription object.
	 * @param string          $new_status    New subscription status.
	 * @param string          $old_status    Previous subscription status.
	 */
	public function handle_subscription_status_updated( $subscription, $new_status, $old_status ) {
		unset( $old_status );

		if (
			$subscription instanceof WC_Subscription
			&& ! in_array( $new_status, array( self::STATUS_DELAYED, 'on-hold' ), true )
			&& $subscription->get_meta( self::META_BLOCKED_AT, true )
		) {
			$this->clear_blocked_metadata( $subscription );
		}
	}

	/**
	 * Restore core renewal callbacks after the blocked action finishes.
	 *
	 * @internal
	 *
	 * @param int $subscription_id Subscription ID.
	 */
	public function handle_restore_core_renewal_callbacks( $subscription_id ) {
		unset( $subscription_id );

		if ( $this->removed_prepare_callback ) {
			add_action( 'woocommerce_scheduled_subscription_payment', 'WC_Subscriptions_Manager::prepare_renewal', 1, 1 );
		}

		if ( $this->removed_gateway_callback ) {
			add_action( 'woocommerce_scheduled_subscription_payment', 'WC_Subscriptions_Payment_Gateways::gateway_scheduled_subscription_payment', 10, 1 );
		}

		$this->removed_prepare_callback = false;
		$this->removed_gateway_callback = false;

		remove_action( 'woocommerce_scheduled_subscription_payment', array( $this, 'handle_restore_core_renewal_callbacks' ), PHP_INT_MAX );
	}

	/**
	 * Return unavailable product names, accounting for shared stock.
	 *
	 * @param WC_Subscription $subscription Subscription object.
	 * @return array<string>
	 */
	private function get_unavailable_item_names( WC_Subscription $subscription ) {
		$stock_requirements = array();
		$unavailable_items  = array();

		foreach ( $subscription->get_items() as $item ) {
			$product  = $item->get_product();
			$quantity = max( 1, (int) $item->get_quantity() );

			if ( ! $product instanceof WC_Product ) {
				$unavailable_items[] = $item->get_name();
				continue;
			}

			if ( ! $product->is_in_stock() ) {
				$unavailable_items[] = $product->get_name();
				continue;
			}

			if ( ! $product->managing_stock() || $product->backorders_allowed() ) {
				continue;
			}

			$stock_product_id = absint( $product->get_stock_managed_by_id() );

			if ( ! $stock_product_id ) {
				$stock_product_id = $product->get_id();
			}

			if ( ! isset( $stock_requirements[ $stock_product_id ] ) ) {
				$stock_requirements[ $stock_product_id ] = array(
					'names'    => array(),
					'product'  => $product,
					'quantity' => 0,
				);
			}

			$stock_requirements[ $stock_product_id ]['names'][]   = $product->get_name();
			$stock_requirements[ $stock_product_id ]['quantity'] += $quantity;
		}

		foreach ( $stock_requirements as $stock_product_id => $requirement ) {
			$stock_product = wc_get_product( $stock_product_id );
			$stock_product = $stock_product instanceof WC_Product ? $stock_product : $requirement['product'];

			if ( ! $stock_product->has_enough_stock( $requirement['quantity'] ) ) {
				$unavailable_items = array_merge( $unavailable_items, $requirement['names'] );
			}
		}

		$unavailable_items = array_map( 'strval', $unavailable_items );
		$unavailable_items = array_filter( $unavailable_items );

		return array_values( array_unique( $unavailable_items ) );
	}

	/**
	 * Queue unique recovery actions for blocked subscriptions containing a product.
	 *
	 * @param WC_Product $product Product object.
	 */
	private function queue_recoveries_for_product( WC_Product $product ) {
		if ( ! function_exists( 'wcs_get_subscriptions' ) ) {
			return;
		}

		$subscriptions = wcs_get_subscriptions(
			array(
				'subscriptions_per_page' => -1,
				'subscription_status'    => array( 'active', self::STATUS_DELAYED, 'on-hold' ),
				'product_id'             => $product->get_id(),
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => self::META_BLOCKED_AT,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		foreach ( $subscriptions as $subscription ) {
			if ( empty( $this->get_unavailable_item_names( $subscription ) ) ) {
				$subscription->delete_meta_data( self::META_RECOVERY_ATTEMPTS );
				$subscription->save();
				$this->queue_recovery_action( $subscription->get_id() );
			}
		}
	}

	/**
	 * Queue one asynchronous recovery action per subscription.
	 *
	 * @param int $subscription_id Subscription ID.
	 */
	private function queue_recovery_action( $subscription_id ) {
		$args = array( absint( $subscription_id ) );

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			$action_id = as_enqueue_async_action( self::RECOVERY_ACTION, $args, self::ACTION_GROUP, true );

			if ( 0 === $action_id ) {
				$has_scheduled_action = function_exists( 'as_has_scheduled_action' )
					&& as_has_scheduled_action( self::RECOVERY_ACTION, $args, self::ACTION_GROUP );

				if ( $has_scheduled_action ) {
					return;
				}

				$this->log_error(
					'Action Scheduler could not queue a delayed renewal recovery.',
					array( 'subscription_id' => $subscription_id )
				);
				$this->queue_wp_cron_recovery_action( $args, $subscription_id );
			}
			return;
		}

		$this->queue_wp_cron_recovery_action( $args, $subscription_id );
	}

	/**
	 * Queue a delayed retry after a recovery failure.
	 *
	 * @param int $subscription_id Subscription ID.
	 */
	private function queue_recovery_retry_action( $subscription_id ) {
		$args      = array( absint( $subscription_id ) );
		$timestamp = time() + self::RECOVERY_RETRY_DELAY;

		if ( function_exists( 'as_schedule_single_action' ) ) {
			$action_id = as_schedule_single_action( $timestamp, self::RECOVERY_ACTION, $args, self::ACTION_GROUP );

			if ( 0 !== $action_id ) {
				return;
			}

			$this->log_error(
				'Action Scheduler could not queue a delayed renewal recovery retry.',
				array( 'subscription_id' => $subscription_id )
			);
		}

		$this->queue_wp_cron_recovery_action( $args, $subscription_id, self::RECOVERY_RETRY_DELAY );
	}

	/**
	 * Use WP-Cron when Action Scheduler cannot queue the recovery.
	 *
	 * @param array<int> $args            Recovery action arguments.
	 * @param int        $subscription_id Subscription ID.
	 * @param int        $delay           Number of seconds before the action should run.
	 */
	private function queue_wp_cron_recovery_action( array $args, $subscription_id, $delay = 1 ) {
		if ( ! wp_next_scheduled( self::RECOVERY_ACTION, $args ) ) {
			$result = wp_schedule_single_event( time() + absint( $delay ), self::RECOVERY_ACTION, $args, true );

			if ( $result instanceof WP_Error ) {
				$this->log_error(
					'WP-Cron could not queue a delayed renewal recovery.',
					array(
						'subscription_id' => $subscription_id,
						'error'           => $result,
					)
				);
			}
		}
	}

	/**
	 * Process payment for an automatic renewal order without creating another order.
	 *
	 * @param WC_Order        $renewal_order Renewal order.
	 * @param WC_Subscription $subscription  Subscription object.
	 */
	private function process_recovered_renewal_payment( WC_Order $renewal_order, WC_Subscription $subscription ) {
		if (
			! $renewal_order->needs_payment()
			|| $subscription->is_manual()
			|| $subscription->payment_method_supports( 'gateway_scheduled_payments' )
		) {
			return;
		}

		if ( is_callable( array( WC_Subscriptions_Payment_Gateways::class, 'trigger_gateway_renewal_payment_hook' ) ) ) {
			WC_Subscriptions_Payment_Gateways::trigger_gateway_renewal_payment_hook( $renewal_order );
			return;
		}

		$this->log_error(
			'WooCommerce Subscriptions could not start payment for the recovered renewal order.',
			array(
				'subscription_id' => $subscription->get_id(),
				'order_id'        => $renewal_order->get_id(),
			)
		);
	}

	/**
	 * Prevent core Subscriptions callbacks from continuing the blocked renewal action.
	 */
	private function suppress_core_renewal_callbacks() {
		if ( $this->removed_prepare_callback || $this->removed_gateway_callback ) {
			return;
		}

		$this->removed_prepare_callback = remove_action(
			'woocommerce_scheduled_subscription_payment',
			'WC_Subscriptions_Manager::prepare_renewal',
			1
		);
		$this->removed_gateway_callback = remove_action(
			'woocommerce_scheduled_subscription_payment',
			'WC_Subscriptions_Payment_Gateways::gateway_scheduled_subscription_payment',
			10
		);

		if ( $this->removed_prepare_callback || $this->removed_gateway_callback ) {
			add_action(
				'woocommerce_scheduled_subscription_payment',
				array( $this, 'handle_restore_core_renewal_callbacks' ),
				PHP_INT_MAX,
				1
			);
		}
	}

	/**
	 * Trigger the customer notification through the WooCommerce mailer.
	 *
	 * @param WC_Subscription $subscription      Subscription object.
	 * @param array<string>   $unavailable_items Unavailable product names.
	 */
	private function send_delayed_renewal_email( WC_Subscription $subscription, array $unavailable_items ) {
		if ( ! function_exists( 'WC' ) || ! WC() ) {
			return;
		}

		WC()->mailer();

		/**
		 * Fires when an unavailable item delays a subscription renewal.
		 *
		 * @param WC_Subscription $subscription      Subscription object.
		 * @param array<string>    $unavailable_items Unavailable product names.
		 *
		 * @since 1.0.0
		 */
		do_action( self::EMAIL_ACTION, $subscription, $unavailable_items );
	}

	/**
	 * Restore the delayed status and queue a bounded retry after recovery fails.
	 *
	 * @param WC_Subscription $subscription Subscription object.
	 */
	private function handle_failed_recovery( WC_Subscription $subscription ) {
		if (
			! $subscription->get_meta( self::META_BLOCKED_AT, true )
			|| ! $subscription->has_status( array( 'active', self::STATUS_DELAYED, 'on-hold' ) )
		) {
			return;
		}

		try {
			if ( ! $subscription->has_status( self::STATUS_DELAYED ) ) {
				$subscription->update_status(
					self::STATUS_DELAYED,
					__( 'The renewal recovery attempt failed. The subscription remains delayed.', 'stock-guard-for-woocommerce-subscriptions' )
				);
			}

			$recovery_attempts = absint( $subscription->get_meta( self::META_RECOVERY_ATTEMPTS, true ) ) + 1;
			$subscription->update_meta_data( self::META_RECOVERY_ATTEMPTS, $recovery_attempts );
			$subscription->save();

			if ( self::MAX_RECOVERY_ATTEMPTS > $recovery_attempts ) {
				$this->queue_recovery_retry_action( $subscription->get_id() );
				return;
			}

			$subscription->add_order_note(
				sprintf(
					/* translators: %d: number of automatic recovery attempts. */
					__( 'Automatic renewal recovery failed %d times and will pause until another relevant stock change.', 'stock-guard-for-woocommerce-subscriptions' ),
					$recovery_attempts
				)
			);
		} catch ( Throwable $throwable ) {
			$this->log_error(
				'The failed renewal recovery could not be rescheduled.',
				array(
					'subscription_id' => $subscription->get_id(),
					'exception'       => $throwable,
				)
			);
		}
	}

	/**
	 * Remove metadata used to identify a plugin-blocked renewal.
	 *
	 * @param WC_Subscription $subscription Subscription object.
	 */
	private function clear_blocked_metadata( WC_Subscription $subscription ) {
		$subscription->delete_meta_data( self::META_BLOCKED_AT );
		$subscription->delete_meta_data( self::META_RECOVERY_ATTEMPTS );
		$subscription->delete_meta_data( self::META_UNAVAILABLE_ITEMS );
		$subscription->save();
	}

	/**
	 * Write an error to the WooCommerce log.
	 *
	 * @param string              $message Error message.
	 * @param array<string,mixed> $context Error context.
	 */
	private function log_error( $message, array $context ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		$context['source'] = self::LOG_SOURCE;
		wc_get_logger()->error( $message, $context );
	}
}
