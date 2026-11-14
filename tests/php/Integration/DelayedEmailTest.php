<?php
/**
 * Customer renewal delayed email tests.
 *
 * @package StockGuardForWooCommerceSubscriptions\Tests
 */

declare(strict_types=1);

namespace StockGuardForWooCommerceSubscriptions\Tests\Integration;

use RuntimeException;
use StockGuardForWooCommerceSubscriptions\Tests\Support\TestCase;
use WC_Subscription;

/**
 * Mail is captured by the WordPress test suite's MockPHPMailer.
 *
 * @group email
 */
final class DelayedEmailTest extends TestCase {

	/**
	 * Email type configured before the test, restored afterwards.
	 *
	 * @var string
	 */
	private $original_email_type;

	public function set_up() {
		parent::set_up();

		$this->original_email_type = $this->delayed_email()->email_type;
	}

	public function tear_down() {
		$this->delayed_email()->email_type = $this->original_email_type;

		parent::tear_down();
	}

	public function test_one_notification_per_blocked_episode(): void {
		$product      = $this->create_product( array( 'stock' => 0 ) );
		$subscription = $this->create_subscription( array( array( $product, 1 ) ) );

		$this->run_scheduled_payment( $subscription->get_id() );
		$this->assertCount( 1, $this->delayed_emails() );

		// Repeated renewal attempts during the same episode.
		do_action( self::PAYMENT_ACTION, $subscription->get_id() );
		do_action( self::PAYMENT_ACTION, $subscription->get_id() );
		$this->assertCount( 1, $this->delayed_emails(), 'Repeated attempts in one episode must not resend the email.' );

		// Recover, then run out of stock again at the next renewal: a new episode.
		$this->set_stock( $product, 1 );
		$this->run_pending_recovery_actions();
		$this->assertSame( 'active', $this->reload( $subscription )->get_status() );

		$this->set_stock( $product, 0 );
		do_action( self::PAYMENT_ACTION, $subscription->get_id() );

		$this->assertRenewalBlocked( $subscription->get_id() );
		$this->assertCount( 2, $this->delayed_emails(), 'A new blocked episode should notify the customer again.' );
	}

	public function test_disabled_email_is_not_sent(): void {
		add_filter( 'woocommerce_email_enabled_' . self::EMAIL_ID, '__return_false' );

		$subscription = $this->block_new_subscription();

		$this->assertSame( array(), $this->delayed_emails() );
		$this->assertRenewalBlocked( $subscription->get_id() );
		$this->assertFalse( $this->has_note( $subscription, 'Renewal delayed email sent' ) );
	}

	public function test_missing_recipient_is_not_sent(): void {
		// WooCommerce backfills an empty billing email from the customer account, so remove the recipient at send time.
		add_filter( 'woocommerce_email_recipient_' . self::EMAIL_ID, '__return_empty_string' );

		$subscription = $this->block_new_subscription();

		$this->assertSame( array(), $this->delayed_emails() );
		$this->assertRenewalBlocked( $subscription->get_id() );
		$this->assertFalse( $this->has_note( $subscription, 'Renewal delayed email sent' ) );
		$this->assertSame( array(), $this->logged_errors() );
	}

	public function test_email_names_the_subscription_and_unavailable_products(): void {
		$coffee       = $this->create_product( array( 'stock' => 0 ) );
		$filters      = $this->create_product(
			array(
				'name'  => 'Paper filters',
				'stock' => 0,
			)
		);
		$in_stock     = $this->create_product(
			array(
				'name'  => 'Ceramic mug',
				'stock' => 5,
			)
		);
		$subscription = $this->create_subscription( array( array( $coffee, 1 ), array( $filters, 1 ), array( $in_stock, 1 ) ) );

		$this->run_scheduled_payment( $subscription->get_id() );

		$emails = $this->delayed_emails();
		$this->assertCount( 1, $emails );

		$mail = $emails[0];
		$body = $mail['body'];

		$this->assertSame( 'customer@example.com', $mail['to'][0][0] );
		$this->assertStringContainsString( 'Stock Guard Tests', $mail['subject'] );
		$this->assertStringContainsString( 'Hi Jamie,', $body );
		$this->assertStringContainsString( 'subscription #' . $subscription->get_order_number() . ' because', $body );
		$this->assertStringContainsString( '<li>Coffee beans</li>', $body );
		$this->assertStringContainsString( '<li>Paper filters</li>', $body );
		$this->assertStringNotContainsString( 'Ceramic mug', $body, 'Available products should not be listed.' );
		$this->assertStringContainsString( esc_url( $subscription->get_view_order_url() ), $body );
		$this->assertTrue( $this->has_note( $subscription, 'Renewal delayed email sent to customer@example.com' ) );
	}

	public function test_html_email_escapes_product_names(): void {
		kses_remove_filters();

		$subscription = $this->block_new_subscription( array(), 'Tea & <b>Biscuits</b>' );
		$emails       = $this->delayed_emails();

		$this->assertCount( 1, $emails );
		$this->assertStringNotContainsString( '<b>Biscuits</b>', $emails[0]['body'] );
		$this->assertStringContainsString( 'Tea &amp; &lt;b&gt;Biscuits&lt;/b&gt;', $emails[0]['body'] );
		$this->assertRenewalBlocked( $subscription->get_id(), array( 'Tea & <b>Biscuits</b>' ) );
	}

	public function test_plain_text_email_is_readable(): void {
		kses_remove_filters();
		$this->delayed_email()->email_type = 'plain';

		$subscription = $this->block_new_subscription( array(), 'Tea & <b>Biscuits</b>' );
		$emails       = $this->delayed_emails();

		$this->assertCount( 1, $emails );

		$body = $emails[0]['body'];

		$this->assertStringContainsString( '= Your subscription renewal is delayed =', $body );
		$this->assertStringContainsString( 'Hi Jamie,', $body );
		$this->assertStringContainsString( '- Tea & <b>Biscuits</b>', $body, 'Plain text should show the product name literally, without entities.' );
		$this->assertStringContainsString( 'subscription #' . $subscription->get_order_number(), $body );
		$this->assertStringContainsString( 'View subscription: ' . $subscription->get_view_order_url(), str_replace( "\n", ' ', $body ) );
		$this->assertStringNotContainsString( '&amp;', $body );
		$this->assertDoesNotMatchRegularExpression( '/<(p|ul|li|a|div|table)\b/i', $body, 'Plain text should not contain HTML markup.' );
	}

	public function test_email_failure_leaves_renewal_protection_intact(): void {
		add_action(
			'sgfws_subscription_renewal_delayed_notification',
			static function () {
				throw new RuntimeException( 'Simulated mail transport failure.' );
			},
			1
		);

		$subscription = $this->block_new_subscription();

		$this->assertRenewalBlocked( $subscription->get_id(), array( 'Coffee beans' ) );
		$this->assertSame( array(), $this->renewal_order_ids( $subscription->get_id() ) );
		$this->assertGatewayDispatches( 0 );
		$this->assertContains( 'The renewal delayed email could not be sent.', $this->logged_errors() );
		$this->assertTrue( $this->has_note( $subscription, 'The renewal delayed email could not be sent' ) );
	}

	public function test_preview_supplies_sample_subscription_data(): void {
		$email = apply_filters( 'woocommerce_prepare_email_for_preview', $this->delayed_email() );

		$this->assertInstanceOf( WC_Subscription::class, $email->object );
		$this->assertSame( '12346', $email->placeholders['{subscription_number}'] );

		$html = $email->get_content_html();

		$this->assertStringContainsString( 'Hi John,', $html );
		$this->assertStringContainsString( 'subscription #12346', $html );
		$this->assertStringContainsString( '<li>Example subscription product</li>', $html );
	}

	/**
	 * Create a subscription with one out-of-stock product and block its renewal.
	 *
	 * @param array<string,mixed> $subscription_args Subscription fixture arguments.
	 * @param string              $product_name      Product name.
	 */
	private function block_new_subscription( array $subscription_args = array(), string $product_name = 'Coffee beans' ): WC_Subscription {
		$product = $this->create_product(
			array(
				'name'  => $product_name,
				'stock' => 0,
			)
		);

		$subscription = $this->create_subscription( array( array( $product, 1 ) ), $subscription_args );
		$this->run_scheduled_payment( $subscription->get_id() );

		return $this->reload( $subscription );
	}

	/**
	 * Whether a subscription has a note containing some text.
	 *
	 * @param WC_Subscription $subscription Subscription.
	 * @param string          $needle       Expected text.
	 */
	private function has_note( WC_Subscription $subscription, string $needle ): bool {
		foreach ( wc_get_order_notes( array( 'order_id' => $subscription->get_id() ) ) as $note ) {
			if ( false !== strpos( $note->content, $needle ) ) {
				return true;
			}
		}

		return false;
	}
}
