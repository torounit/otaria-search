<?php
/**
 * Integration tests for otaria_search_log().
 *
 * @package Otaria_Search
 */

declare(strict_types=1);

namespace Otaria_Search\Tests\Integration;

/**
 * Class Log_Test
 */
class Log_Test extends \WP_UnitTestCase {

	/**
	 * Stands in for the log PHP writes to, which core sets from WP_DEBUG_LOG.
	 *
	 * @var string
	 */
	private string $log_path;

	/**
	 * The error_log setting to restore.
	 *
	 * @var string
	 */
	private string $previous_error_log;

	/**
	 * Point PHP's error log at a temporary file.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		$this->log_path = (string) tempnam( get_temp_dir(), 'otaria-search-log' );
		// phpcs:ignore WordPress.PHP.IniSet.Risky -- Core does the same for WP_DEBUG_LOG.
		$this->previous_error_log = (string) ini_set( 'error_log', $this->log_path );
	}

	/**
	 * Restore PHP's error log.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		// phpcs:ignore WordPress.PHP.IniSet.Risky -- Restores the original value.
		ini_set( 'error_log', $this->previous_error_log );
		wp_delete_file( $this->log_path );
		parent::tear_down();
	}

	/**
	 * Messages go to the log core configured, prefixed so they can be grepped.
	 *
	 * @return void
	 */
	public function test_writes_to_the_php_error_log(): void {
		otaria_search_log( 'hello' );

		$this->assertStringContainsString( '[otaria-search] hello', implode( '', (array) file( $this->log_path ) ) );
	}

	/**
	 * The plugin does not pick a file itself: WP_DEBUG_LOG may be off.
	 *
	 * @return void
	 */
	public function test_does_not_write_to_the_default_debug_log(): void {
		$before = $this->default_debug_log();

		otaria_search_log( 'hello' );

		$this->assertSame( $before, $this->default_debug_log() );
	}

	/**
	 * Contents of wp-content/debug.log.
	 *
	 * @return string[]
	 */
	private function default_debug_log(): array {
		$path = WP_CONTENT_DIR . '/debug.log';
		return file_exists( $path ) ? (array) file( $path ) : array();
	}
}
