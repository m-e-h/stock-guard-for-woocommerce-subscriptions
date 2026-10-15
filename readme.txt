=== Stock Guard for WooCommerce Subscriptions ===
Contributors: m-e-h
Tags: woocommerce, subscriptions, stock, inventory, renewals
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Pauses WooCommerce subscription renewals when stock is unavailable and retries them when stock returns.

== Description ==

Stock Guard checks every subscription item before WooCommerce Subscriptions creates a scheduled renewal order. If an item is unavailable, the renewal waits until the complete subscription can be fulfilled.

The plugin requires [WooCommerce](https://wordpress.org/plugins/woocommerce/) and [WooCommerce Subscriptions](https://woocommerce.com/products/woocommerce-subscriptions/), a separate commercial extension. WooCommerce Subscriptions is not included. Stock Guard is an independent extension and is not affiliated with or endorsed by WooCommerce.

= When stock is unavailable =

* Stops the scheduled renewal before order creation and payment processing.
* Moves the subscription to a dedicated Delayed status.
* Records the unavailable items in a subscription note.
* Sends the customer a configurable WooCommerce email.

= When stock returns =

A relevant product stock change queues a recovery check. Once every item is available in the required quantity, the plugin creates the delayed renewal order and continues the normal WooCommerce Subscriptions payment flow.

Automatic renewals use the gateway's renewal payment hook. Manual renewals receive the normal renewal invoice and remain on hold until it is paid. Gateways that schedule recurring payments externally remain responsible for collecting payment; Stock Guard does not control their billing schedules.

= Stock handling =

* Checks stock status and managed quantities, including variations.
* Combines quantities when subscription lines share managed stock.
* Respects backorders when enabled for a product.
* Supports High-Performance Order Storage (HPOS).
* Uses Action Scheduler for recovery jobs, with WP-Cron as a fallback.

== Installation ==

1. Install and activate WooCommerce and WooCommerce Subscriptions.
2. Upload the Stock Guard ZIP through Plugins > Add New Plugin > Upload Plugin, then activate it.
3. Review the customer notification under WooCommerce > Settings > Emails > Subscription renewal delayed.

There is no separate settings page. Scheduled renewal protection starts after activation. Action Scheduler or WP-Cron must be running for automatic recovery.

== Frequently Asked Questions ==

= Does this work without WooCommerce Subscriptions? =

No. It extends scheduled renewals in the commercial WooCommerce Subscriptions plugin. It does not add subscription billing to WooCommerce by itself.

= Does this prevent every out-of-stock payment? =

It intercepts scheduled renewals handled by WooCommerce Subscriptions before their renewal order is created. It does not affect the initial checkout or orders already created. Payments scheduled directly by an external gateway remain subject to that gateway's behavior.

= When is a delayed renewal retried? =

A relevant change to product stock or stock-management settings queues an asynchronous recovery check. Every subscription item must be available. Make stock changes through WooCommerce; direct database updates do not trigger these checks.

If recovery fails, the plugin retries up to two more times at five-minute intervals. Another relevant stock change resets the retry limit.

= Where can I configure the email? =

Open WooCommerce > Settings > Emails > Subscription renewal delayed. You can change the subject, heading, additional content, and format, or disable the notification. HTML and plain-text templates support standard WooCommerce theme overrides.

= How do I troubleshoot a delayed renewal? =

Check the subscription notes and confirm that every item is available in the required quantity. Under WooCommerce > Status > Scheduled Actions, search for sgfws_process_stock_recovered_renewal. Errors appear under WooCommerce > Status > Logs with the source stock-guard-for-woocommerce-subscriptions.

= What happens if I deactivate or delete the plugin? =

Stock checks, recovery jobs, and the custom Delayed status are unavailable while the plugin is inactive. Resolve delayed subscriptions before deactivating it. Existing subscription notes, renewal metadata, and WooCommerce email settings are retained when the plugin is deleted.

= Does the plugin send data to an external service? =

Stock Guard does not add telemetry or external service requests. It uses subscription and product data already stored in WooCommerce to check availability and sends customer notifications through the site's configured email system. WooCommerce and payment gateways operate under their own settings and policies.

== Changelog ==

= 1.0.0 =
* Initial release.
