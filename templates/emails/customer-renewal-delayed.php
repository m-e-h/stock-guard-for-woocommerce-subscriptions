<?php
/**
 * Customer renewal delayed email.
 *
 * @package StockGuardForWooCommerceSubscriptions\Templates\Emails
 * @version 1.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! $subscription instanceof WC_Subscription ) {
	return;
}

do_action( 'woocommerce_email_header', $email_heading, $email );

$first_name = $subscription->get_billing_first_name();
?>

<p>
	<?php
	if ( $first_name ) {
		printf(
			/* translators: %s: customer's first name. */
			esc_html__( 'Hi %s,', 'stock-guard-for-woocommerce-subscriptions' ),
			esc_html( $first_name )
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
		esc_html__( 'We could not process the renewal for subscription #%s because the following items are currently out of stock:', 'stock-guard-for-woocommerce-subscriptions' ),
		esc_html( $subscription->get_order_number() )
	);
	?>
</p>

<?php if ( $unavailable_items ) : ?>
	<ul>
		<?php foreach ( $unavailable_items as $item_name ) : ?>
			<li><?php echo esc_html( $item_name ); ?></li>
		<?php endforeach; ?>
	</ul>
<?php endif; ?>

<p>
	<?php esc_html_e( 'The store did not create a renewal order for this attempt. We will retry automatically once all items are back in stock, which we expect to be soon.', 'stock-guard-for-woocommerce-subscriptions' ); ?>
</p>

<p>
	<a class="button" href="<?php echo esc_url( $subscription->get_view_order_url() ); ?>">
		<?php esc_html_e( 'View subscription', 'stock-guard-for-woocommerce-subscriptions' ); ?>
	</a>
</p>

<?php if ( $additional_content ) : ?>
	<div class="email-additional-content">
		<?php echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) ); ?>
	</div>
<?php endif; ?>

<?php do_action( 'woocommerce_email_footer', $email ); ?>
