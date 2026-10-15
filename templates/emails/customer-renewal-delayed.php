<?php
/**
 * Customer renewal delayed email.
 *
 * @package StockGuardForWooCommerceSubscriptions\Templates\Emails
 * @version 1.0.0
 */

use Automattic\WooCommerce\Utilities\FeaturesUtil;

defined( 'ABSPATH' ) || exit;

if ( ! $subscription instanceof WC_Subscription ) {
	return;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Uses the plugin's sgfws prefix within wc_get_template() scope.
$sgfws_email_improvements_enabled = class_exists( FeaturesUtil::class )
	&& is_callable( array( FeaturesUtil::class, 'feature_is_enabled' ) )
	&& FeaturesUtil::feature_is_enabled( 'email_improvements' );

// Use WooCommerce's shared email header and footer hooks.
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<?php echo $sgfws_email_improvements_enabled ? '<div class="email-introduction">' : ''; ?>
<p>
	<?php
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
	?>
</p>

<p>
	<?php
	printf(
		/* translators: %s: subscription number. */
		esc_html__( 'We could not process the renewal for subscription #%s because the following items are currently unavailable:', 'stock-guard-for-woocommerce-subscriptions' ),
		esc_html( $subscription->get_order_number() )
	);
	?>
</p>
<?php echo $sgfws_email_improvements_enabled ? '</div>' : ''; ?>

<?php if ( $unavailable_items ) : ?>
	<ul>
		<?php // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Uses the plugin's sgfws prefix within wc_get_template() scope. ?>
		<?php foreach ( $unavailable_items as $sgfws_item_name ) : ?>
			<li><?php echo esc_html( $sgfws_item_name ); ?></li>
		<?php endforeach; ?>
	</ul>
<?php endif; ?>

<p>
	<?php esc_html_e( 'The store did not create a renewal order for this attempt. We will retry automatically as soon as every item is available.', 'stock-guard-for-woocommerce-subscriptions' ); ?>
</p>

<p>
	<a class="button" href="<?php echo esc_url( $subscription->get_view_order_url() ); ?>">
		<?php esc_html_e( 'View subscription', 'stock-guard-for-woocommerce-subscriptions' ); ?>
	</a>
</p>

<?php
if ( $additional_content ) {
	echo $sgfws_email_improvements_enabled ? '<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation"><tr><td class="email-additional-content">' : '';
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
	echo $sgfws_email_improvements_enabled ? '</td></tr></table>' : '';
}
?>

<?php
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's shared email footer.
do_action( 'woocommerce_email_footer', $email );
