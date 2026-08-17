=== Stock Guard for WooCommerce Subscriptions ===
Requires at least: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Pauses WooCommerce subscription renewals when stock is unavailable and retries them when stock returns.

== Description ==

This plugin checks every item before WooCommerce Subscriptions creates a scheduled renewal order.

If an item is out of stock, or there is not enough managed stock for the subscription quantity, the plugin:

* Stops the renewal before order creation and payment processing.
* Moves the subscription to on-hold when the payment method supports suspension.
* Adds an audit note to the subscription.
* Sends the customer a configurable WooCommerce email.

When every subscription item becomes available, a unique asynchronous action creates the delayed renewal order and runs the normal WooCommerce Subscriptions payment flow. Manual renewals receive the normal renewal invoice. Gateways that manage recurring payments externally remain responsible for collecting the payment after the renewal order is created.

Configure the customer notification under WooCommerce > Settings > Emails > Subscription renewal delayed.

== Requirements ==

* WooCommerce
* WooCommerce Subscriptions

== Changelog ==

= 1.0.0 =
* Initial release.
