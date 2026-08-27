# Stock Guard for WooCommerce Subscriptions

Stock Guard for WooCommerce Subscriptions prevents a scheduled subscription renewal from creating an order when one or more subscription items are unavailable. When every item becomes available again, the plugin automatically creates the delayed renewal order and continues the appropriate WooCommerce Subscriptions payment flow.

This is an independent extension and is not affiliated with or endorsed by WooCommerce.

## Features

- Checks every subscription item before WooCommerce Subscriptions creates a scheduled renewal order.
- Detects products that are out of stock, missing, or unable to satisfy the subscription quantity.
- Accounts for variations and products that share managed stock with a parent product.
- Respects backorders when they are enabled for a product.
- Moves the affected subscription to a dedicated `delayed` status while stock is unavailable.
- Adds audit notes to the subscription when a renewal is delayed or recovered.
- Sends the customer a configurable WooCommerce email when the renewal is delayed.
- Rechecks the complete subscription when relevant product stock changes.
- Creates the delayed renewal order automatically after every item is available.
- Supports High-Performance Order Storage (HPOS).
- Uses Action Scheduler for recovery jobs, with WP-Cron as a fallback.

## Requirements

- WordPress 6.7 or later
- PHP 7.4 or later
- WooCommerce
- WooCommerce Subscriptions

## Installation

1. Copy the `stock-guard-for-woocommerce-subscriptions` directory to `wp-content/plugins/`, or upload a ZIP containing that directory from **Plugins > Add New Plugin**.
2. Make sure WooCommerce and WooCommerce Subscriptions are installed and active.
3. Activate **Stock Guard for WooCommerce Subscriptions**.
4. Review the customer email under **WooCommerce > Settings > Emails > Subscription renewal delayed**.

The plugin has no separate settings page. It starts monitoring scheduled subscription renewals after activation.

## How renewal protection works

1. WooCommerce Subscriptions starts a scheduled renewal.
2. The plugin checks the current availability of every product on the subscription before the renewal order is created.
3. If any item is unavailable, the plugin stops renewal-order creation and payment processing for that attempt.
4. The subscription is moved to the `delayed` status and marked as blocked by the plugin.
5. The plugin records the unavailable items, adds a subscription note, and sends the delayed-renewal email once.
6. A relevant stock or stock-management change queues a unique asynchronous recovery check.
7. The recovery job checks every subscription item again. Once the complete subscription can be fulfilled, it returns the subscription to WooCommerce Subscriptions' normal renewal flow and creates the delayed renewal order.

The blocked marker is removed only after WooCommerce Subscriptions successfully returns a valid renewal order.

## Stock availability rules

A subscription item is treated as unavailable when any of the following is true:

- The product referenced by the subscription no longer exists or cannot be loaded.
- WooCommerce reports that the product is out of stock.
- The product manages stock, does not allow backorders, and has less stock than the subscription requires.

When several subscription lines draw from the same stock-managed product, their required quantities are combined before the available stock is evaluated. Products that allow backorders are not blocked for insufficient quantity.

## Payment behavior after recovery

Payment handling follows the subscription's renewal configuration:

| Renewal type | Behavior after stock recovery |
| --- | --- |
| Automatic renewal | The plugin creates the renewal order and asks WooCommerce Subscriptions to trigger the gateway's renewal-payment hook. |
| Manual renewal | WooCommerce Subscriptions creates the renewal order and uses its normal manual-renewal invoice flow. |
| Gateway-scheduled renewal | The plugin creates the renewal order, but the external gateway remains responsible for collecting payment. |
| Renewal that does not need payment | The renewal order is created without calling a payment gateway. |

## Customer email

The plugin registers the **Subscription renewal delayed** customer email with WooCommerce. Store managers can enable or disable it and edit its subject, heading, additional content, and email format under **WooCommerce > Settings > Emails**.

The default message tells the customer:

- Which subscription was affected.
- Which items are currently unavailable.
- That no renewal order was created for the blocked attempt.
- That the store will retry automatically when every item is available.

HTML and plain-text templates are included. They can be overridden in a theme using the standard WooCommerce template paths:

```text
your-theme/woocommerce/emails/customer-renewal-delayed.php
your-theme/woocommerce/emails/plain/customer-renewal-delayed.php
```

## Scheduled actions and logs

Recovery jobs use the action hook `sgfws_process_stock_recovered_renewal` and the Action Scheduler group `stock_guard_for_woocommerce_subscriptions`. If Action Scheduler cannot queue a job, the plugin schedules the same recovery through WP-Cron. A failed recovery is retried up to two more times at five-minute intervals; a later relevant stock change resets that retry limit.

To inspect queued or failed jobs, open **WooCommerce > Status > Scheduled Actions** and search for the recovery hook.

Errors are written to the WooCommerce logger with this source:

```text
stock-guard-for-woocommerce-subscriptions
```

Open **WooCommerce > Status > Logs** and select a log file containing that source when troubleshooting.

## Troubleshooting

### A renewal was not delayed

- Confirm that this plugin, WooCommerce, and WooCommerce Subscriptions are active.
- Confirm that the renewal was triggered through the normal scheduled-renewal action.
- Check the product's stock status, managed quantity, and backorder setting.
- Check the subscription notes and WooCommerce logs for errors.

### A renewal has not resumed

- Confirm that every item on the subscription is currently available in the required quantity.
- Confirm that Action Scheduler or WP-Cron is running normally.
- Look for a pending or failed `sgfws_process_stock_recovered_renewal` action.
- Make stock changes through WooCommerce. Direct database updates do not fire the WooCommerce product hooks the plugin monitors.

### The customer did not receive an email

- Confirm that **Subscription renewal delayed** is enabled under WooCommerce email settings.
- Confirm that the subscription has a valid billing email address.
- Review the subscription notes to see whether WooCommerce reported the email as sent.
- Check the WooCommerce logs and the site's email-delivery configuration.

## Developer reference

The plugin uses the `StockGuardForWooCommerceSubscriptions` PHP namespace and the `sgfws` prefix for persistent metadata and scheduled hooks.

The following action fires when an unavailable item delays a renewal:

```php
/**
 * @param WC_Subscription $subscription      The affected subscription.
 * @param string[]        $unavailable_items Unavailable product names.
 */
add_action(
 'sgfws_subscription_renewal_delayed_notification',
 function ( $subscription, $unavailable_items ) {
  // Add an additional notification or integration here.
 },
 20,
 2
);
```

The plugin stores its renewal state on the subscription using these internal metadata keys:

- `_sgfws_renewal_blocked_at`
- `_sgfws_unavailable_items`

These values are implementation details and may change in a future version. Integrations should prefer documented hooks instead of modifying them directly.

## Development checks

Run PHP syntax checks and WordPress Coding Standards against the plugin before release:

```bash
find . -type f -name '*.php' -print0 | xargs -0 -n1 php -l
phpcs --standard=WordPress --exclude=WordPress.Files.FileName .
markdownlint README.md
```

## License

Stock Guard for WooCommerce Subscriptions is licensed under the GNU General Public License v2.0 or later. See [GNU GPL version 2](https://www.gnu.org/licenses/gpl-2.0.html).
