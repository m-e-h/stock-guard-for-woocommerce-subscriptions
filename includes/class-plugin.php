<?php
/**
 * Plugin bootstrap.
 *
 * @package StockGuardForWooCommerceSubscriptions
 */

declare(strict_types=1);

namespace StockGuardForWooCommerceSubscriptions;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use StockGuardForWooCommerceSubscriptions\Emails\CustomerRenewalDelayed;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the plugin after its dependencies are available.
 */
final class Plugin {

	/**
	 * Main plugin file.
	 *
	 * @var string
	 */
	private $plugin_file;

	/**
	 * Renewal stock guard.
	 *
	 * @var RenewalStockGuard|null
	 */
	private $renewal_stock_guard;

	/**
	 * Set the main plugin file.
	 *
	 * @param string $plugin_file Main plugin file.
	 *
	 * @since 1.0.0
	 */
	public function __construct( $plugin_file ) {
		$this->plugin_file = $plugin_file;
	}

	/**
	 * Register bootstrap hooks.
	 *
	 * @since 1.0.0
	 */
	public function register_hooks() {
		add_action( 'before_woocommerce_init', array( $this, 'handle_before_woocommerce_init' ) );
		add_action( 'plugins_loaded', array( $this, 'handle_plugins_loaded' ), 20 );
	}

	/**
	 * Declare compatibility with WooCommerce custom order tables.
	 *
	 * @internal
	 */
	public function handle_before_woocommerce_init() {
		if ( class_exists( FeaturesUtil::class ) ) {
			FeaturesUtil::declare_compatibility( 'custom_order_tables', $this->plugin_file, true );
		}
	}

	/**
	 * Load the plugin when WooCommerce and WooCommerce Subscriptions are active.
	 *
	 * @internal
	 */
	public function handle_plugins_loaded() {
		if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Subscriptions' ) ) {
			add_action( 'admin_notices', array( $this, 'handle_admin_notices' ) );
			return;
		}

		require_once __DIR__ . '/class-renewal-stock-guard.php';

		$this->renewal_stock_guard = new RenewalStockGuard();
		$this->renewal_stock_guard->register_hooks();

		add_filter( 'woocommerce_email_classes', array( $this, 'handle_woocommerce_email_classes' ) );
	}

	/**
	 * Show a dependency notice when the plugin cannot start.
	 *
	 * @internal
	 */
	public function handle_admin_notices() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		?>
		<div class="notice notice-error">
			<p>
				<?php esc_html_e( 'Stock Guard for WooCommerce Subscriptions requires WooCommerce and WooCommerce Subscriptions to be active.', 'stock-guard-for-woocommerce-subscriptions' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Register the customer renewal delayed email.
	 *
	 * @internal
	 *
	 * @param array<string,\WC_Email> $email_classes Registered WooCommerce emails.
	 * @return array<string,\WC_Email>
	 */
	public function handle_woocommerce_email_classes( $email_classes ) {
		require_once __DIR__ . '/emails/class-customer-renewal-delayed.php';

		$email_classes[ CustomerRenewalDelayed::class ] = new CustomerRenewalDelayed(
			dirname( $this->plugin_file ) . '/templates/'
		);

		return $email_classes;
	}
}
