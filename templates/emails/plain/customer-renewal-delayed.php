<?php
/**
 * Customer renewal delayed email (plain text).
 *
 * @package StockGuardForWooCommerceSubscriptions\Templates\Emails\Plain
 * @version 1.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! $subscription instanceof WC_Subscription ) {
	return;
}

echo '= ' . esc_html( $email_heading ) . " =\n\n";

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Uses the plugin's sgfws prefix within wc_get_template() scope.
$sgfws_first_name = $subscription->get_billing_first_name();

if ( $sgfws_first_name ) {
	printf(
		/* translators: %s: customer's first name. */
		esc_html__( 'Hi %s,', 'stock-guard-for-woocommerce-subscriptions' ),
		esc_html( $sgfws_first_name )
	);
} else {
	esc_html_e( 'Hello,', 'stock-guard-for-woocommerce-subscriptions' );
}

echo "\n\n";

printf(
	/* translators: %s: subscription number. */
	esc_html__( 'We could not process the renewal for subscription #%s because the following items are currently unavailable:', 'stock-guard-for-woocommerce-subscriptions' ),
	esc_html( $subscription->get_order_number() )
);

echo "\n\n";

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Uses the plugin's sgfws prefix within wc_get_template() scope.
foreach ( $unavailable_items as $sgfws_item_name ) {
	echo '- ' . esc_html( $sgfws_item_name ) . "\n";
}

echo "\n";
esc_html_e( 'The store did not create a renewal order for this attempt. We will retry automatically as soon as every item is available.', 'stock-guard-for-woocommerce-subscriptions' );
echo "\n\n";

printf(
	/* translators: %s: subscription URL. */
	esc_html__( 'View subscription: %s', 'stock-guard-for-woocommerce-subscriptions' ),
	esc_url( $subscription->get_view_order_url() )
);

if ( $additional_content ) {
	echo "\n\n" . esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) );
}

echo "\n\n";
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's shared email footer.
echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
