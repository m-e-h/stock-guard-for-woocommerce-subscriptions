<?php
/**
 * WordPress test-suite configuration.
 *
 * Every value can be overridden with an environment variable so the suite runs
 * on Local, in CI, or anywhere else with a disposable MySQL database.
 *
 * @package StockGuardForWooCommerceSubscriptions\Tests
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Test configuration only.

$sgfws_tests_env = static function ( $name, $fallback ) {
	$value = getenv( $name );

	return ( false === $value || '' === $value ) ? $fallback : $value;
};

$sgfws_plugin_dir = dirname( __DIR__, 2 );

/*
 * Local exposes MySQL through a Unix socket listed in $MYSQL_HOME/my.cnf. Use it
 * when the suite runs through `direnv exec`, and plain localhost otherwise.
 */
$sgfws_default_db_host = 'localhost';
$sgfws_mysql_home      = getenv( 'MYSQL_HOME' );

if ( $sgfws_mysql_home && is_readable( $sgfws_mysql_home . '/my.cnf' ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- WordPress is not loaded yet.
	$sgfws_my_cnf = (string) file_get_contents( $sgfws_mysql_home . '/my.cnf' );

	if ( preg_match( '/^\s*socket\s*=\s*(\S+)/m', $sgfws_my_cnf, $sgfws_socket_match ) ) {
		$sgfws_default_db_host = 'localhost:' . $sgfws_socket_match[1];
	}
}

define( 'ABSPATH', rtrim( $sgfws_tests_env( 'SGFWS_TESTS_WP_DIR', dirname( $sgfws_plugin_dir, 3 ) ), '/' ) . '/' );

define( 'DB_NAME', $sgfws_tests_env( 'SGFWS_TESTS_DB_NAME', 'sgfws_tests' ) );
define( 'DB_USER', $sgfws_tests_env( 'SGFWS_TESTS_DB_USER', 'root' ) );
define( 'DB_PASSWORD', $sgfws_tests_env( 'SGFWS_TESTS_DB_PASSWORD', 'root' ) );
define( 'DB_HOST', $sgfws_tests_env( 'SGFWS_TESTS_DB_HOST', $sgfws_default_db_host ) );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

/*
 * The test installer drops every table in DB_NAME. Refuse to run against the
 * database used by the surrounding site.
 */
if ( in_array( DB_NAME, array( '', 'local', 'wordpress' ), true ) ) {
	fwrite( STDERR, 'Refusing to run tests against database "' . DB_NAME . '". Set SGFWS_TESTS_DB_NAME to a disposable database.' . PHP_EOL ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	exit( 1 );
}

$table_prefix = 'wptests_';

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Stock Guard Tests' );
define( 'WP_PHP_BINARY', PHP_BINARY );
define( 'WPLANG', '' );
define( 'WP_DEBUG', true );

// Keep the host site's must-use plugins and Query Monitor's db.php drop-in out of the test process.
define( 'WPMU_PLUGIN_DIR', __DIR__ . '/no-mu-plugins' );
define( 'QM_DISABLED', true );

// Subscriptions wraps order creation in SQL transactions, which would commit the test-case transaction.
define( 'WC_USE_TRANSACTIONS', false );

define( 'SGFWS_TESTS_PLUGIN_DIR', $sgfws_plugin_dir );
define( 'SGFWS_TESTS_PLUGINS_DIR', rtrim( $sgfws_tests_env( 'SGFWS_TESTS_PLUGINS_DIR', dirname( $sgfws_plugin_dir ) ), '/' ) );
define( 'SGFWS_TESTS_HPOS', '0' !== $sgfws_tests_env( 'SGFWS_TESTS_HPOS', '1' ) );

unset( $sgfws_tests_env, $sgfws_plugin_dir, $sgfws_default_db_host, $sgfws_mysql_home, $sgfws_my_cnf, $sgfws_socket_match );
