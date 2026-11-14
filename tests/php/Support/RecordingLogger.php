<?php
/**
 * WooCommerce logger that keeps entries in memory.
 *
 * @package StockGuardForWooCommerceSubscriptions\Tests
 */

declare(strict_types=1);

namespace StockGuardForWooCommerceSubscriptions\Tests\Support;

use WC_Log_Levels;
use WC_Logger_Interface;

/**
 * Records log entries so tests can assert on them.
 *
 * This intentionally does not extend WC_Logger: wc_get_logger() keeps the last
 * logger in a static and would keep reusing a WC_Logger subclass after the test
 * removes its filter.
 */
final class RecordingLogger implements WC_Logger_Interface {

	/**
	 * Recorded entries.
	 *
	 * @var array<int,array{level:string,message:string,context:array<string,mixed>}>
	 */
	public $entries = array();

	/**
	 * Return recorded entries written by a log source at a level.
	 *
	 * @param string $source Log source.
	 * @param string $level  Log level.
	 * @return array<int,array{level:string,message:string,context:array<string,mixed>}>
	 */
	public function entries_for( string $source, string $level = WC_Log_Levels::ERROR ): array {
		return array_values(
			array_filter(
				$this->entries,
				static function ( array $entry ) use ( $source, $level ): bool {
					return $level === $entry['level'] && ( $entry['context']['source'] ?? '' ) === $source;
				}
			)
		);
	}

	/**
	 * Return the messages written by a log source at a level.
	 *
	 * @param string $source Log source.
	 * @param string $level  Log level.
	 * @return array<int,string>
	 */
	public function messages_for( string $source, string $level = WC_Log_Levels::ERROR ): array {
		return array_column( $this->entries_for( $source, $level ), 'message' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function add( $handle, $message, $level = WC_Log_Levels::NOTICE ) {
		$this->log( $level, $message, array( 'source' => $handle ) );

		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function log( $level, $message, $context = array() ) {
		$this->entries[] = array(
			'level'   => (string) $level,
			'message' => (string) $message,
			'context' => (array) $context,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function emergency( $message, $context = array() ) {
		$this->log( WC_Log_Levels::EMERGENCY, $message, $context );
	}

	/**
	 * {@inheritDoc}
	 */
	public function alert( $message, $context = array() ) {
		$this->log( WC_Log_Levels::ALERT, $message, $context );
	}

	/**
	 * {@inheritDoc}
	 */
	public function critical( $message, $context = array() ) {
		$this->log( WC_Log_Levels::CRITICAL, $message, $context );
	}

	/**
	 * {@inheritDoc}
	 */
	public function error( $message, $context = array() ) {
		$this->log( WC_Log_Levels::ERROR, $message, $context );
	}

	/**
	 * {@inheritDoc}
	 */
	public function warning( $message, $context = array() ) {
		$this->log( WC_Log_Levels::WARNING, $message, $context );
	}

	/**
	 * {@inheritDoc}
	 */
	public function notice( $message, $context = array() ) {
		$this->log( WC_Log_Levels::NOTICE, $message, $context );
	}

	/**
	 * {@inheritDoc}
	 */
	public function info( $message, $context = array() ) {
		$this->log( WC_Log_Levels::INFO, $message, $context );
	}

	/**
	 * {@inheritDoc}
	 */
	public function debug( $message, $context = array() ) {
		$this->log( WC_Log_Levels::DEBUG, $message, $context );
	}
}
