<?php
/**
 * Test gateway that collects renewal payments on its own schedule.
 *
 * @package StockGuardForWooCommerceSubscriptions\Tests
 */

declare(strict_types=1);

namespace StockGuardForWooCommerceSubscriptions\Tests\Support;

/**
 * Gateway advertising `gateway_scheduled_payments`, like PayPal Standard.
 */
final class ScheduledTestGateway extends TestGateway {

	public const ID = 'sgfws_test_scheduled_gateway';

	/**
	 * {@inheritDoc}
	 */
	protected static function supported_features(): array {
		return array_merge( parent::supported_features(), array( 'gateway_scheduled_payments' ) );
	}
}
