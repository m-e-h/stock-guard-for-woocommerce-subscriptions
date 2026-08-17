<?php
/**
 * Customer renewal delayed email.
 *
 * @package StockGuardForWooCommerceSubscriptions
 */

declare(strict_types=1);

namespace StockGuardForWooCommerceSubscriptions\Emails;

use WC_Email;
use WC_Subscription;

defined( 'ABSPATH' ) || exit;

/**
 * Notifies a customer that an out-of-stock item delayed a renewal.
 */
final class CustomerRenewalDelayed extends WC_Email {

	/**
	 * Create the email.
	 *
	 * @param string $template_base Plugin template directory.
	 *
	 * @since 1.0.0
	 */
	public function __construct( $template_base ) {
		$this->id             = 'sgfws_customer_subscription_renewal_delayed';
		$this->customer_email = true;
		$this->title          = __( 'Subscription renewal delayed', 'stock-guard-for-woocommerce-subscriptions' );
		$this->description    = __( 'Notifies customers when a subscription renewal is delayed because one or more items are unavailable.', 'stock-guard-for-woocommerce-subscriptions' );
		$this->email_group    = 'order-changes';
		$this->template_html  = 'emails/customer-renewal-delayed.php';
		$this->template_plain = 'emails/plain/customer-renewal-delayed.php';
		$this->template_base  = trailingslashit( $template_base );
		$this->placeholders   = array(
			'{subscription_number}' => '',
			'{unavailable_items}'   => '',
		);

		add_action( 'sgfws_subscription_renewal_delayed_notification', array( $this, 'trigger' ), 10, 2 );

		parent::__construct();
	}

	/**
	 * Return the default subject.
	 *
	 * @return string
	 *
	 * @since 1.0.0
	 */
	public function get_default_subject() {
		return __( 'Your {site_title} subscription renewal is delayed', 'stock-guard-for-woocommerce-subscriptions' );
	}

	/**
	 * Return the default heading.
	 *
	 * @return string
	 *
	 * @since 1.0.0
	 */
	public function get_default_heading() {
		return __( 'Your subscription renewal is on hold', 'stock-guard-for-woocommerce-subscriptions' );
	}

	/**
	 * Return the default additional content.
	 *
	 * @return string
	 *
	 * @since 1.0.0
	 */
	public function get_default_additional_content() {
		return __( 'You do not need to take any action. We will retry the renewal automatically when every item is available.', 'stock-guard-for-woocommerce-subscriptions' );
	}

	/**
	 * Send the delayed renewal notification.
	 *
	 * @internal
	 *
	 * @param mixed         $subscription      Subscription object.
	 * @param array<string> $unavailable_items Unavailable product names.
	 */
	public function trigger( $subscription, $unavailable_items = array() ) {
		if ( ! $subscription instanceof WC_Subscription ) {
			return;
		}

		$this->setup_locale();

		try {
			$this->object                                = $subscription;
			$this->recipient                             = $subscription->get_billing_email();
			$this->placeholders['{subscription_number}'] = $subscription->get_order_number();
			$this->placeholders['{unavailable_items}']   = implode( ', ', array_map( 'wp_strip_all_tags', $unavailable_items ) );

			if ( $this->send_notification() ) {
				$subscription->add_order_note(
					sprintf(
						/* translators: %s: customer email address. */
						__( 'Renewal delayed email sent to %s.', 'stock-guard-for-woocommerce-subscriptions' ),
						$this->get_recipient()
					)
				);
			}
		} finally {
			$this->restore_locale();
		}
	}

	/**
	 * Return HTML email content.
	 *
	 * @return string
	 *
	 * @since 1.0.0
	 */
	public function get_content_html() {
		return wc_get_template_html(
			$this->template_html,
			array(
				'subscription'       => $this->object,
				'unavailable_items'  => $this->get_unavailable_items(),
				'email_heading'      => $this->get_heading(),
				'additional_content' => $this->get_additional_content(),
				'sent_to_admin'      => false,
				'plain_text'         => false,
				'email'              => $this,
			),
			'',
			$this->template_base
		);
	}

	/**
	 * Return plain-text email content.
	 *
	 * @return string
	 *
	 * @since 1.0.0
	 */
	public function get_content_plain() {
		return wc_get_template_html(
			$this->template_plain,
			array(
				'subscription'       => $this->object,
				'unavailable_items'  => $this->get_unavailable_items(),
				'email_heading'      => $this->get_heading(),
				'additional_content' => $this->get_additional_content(),
				'sent_to_admin'      => false,
				'plain_text'         => true,
				'email'              => $this,
			),
			'',
			$this->template_base
		);
	}

	/**
	 * Return unavailable item names stored on the subscription.
	 *
	 * @return array<string>
	 */
	private function get_unavailable_items() {
		if ( ! $this->object instanceof WC_Subscription ) {
			return array();
		}

		$items = $this->object->get_meta( '_sgfws_unavailable_items', true );

		return is_array( $items ) ? array_map( 'strval', $items ) : array();
	}
}
