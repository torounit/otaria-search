<?php
/**
 * Integration tests for the Plugin bootstrap.
 *
 * @package Otaria_Search
 */

declare(strict_types=1);

namespace Otaria_Search\Tests\Integration;

use Otaria_Search\Plugin;
use WP_UnitTestCase;

/**
 * Tests for the WordPress version requirement.
 */
class Plugin_Test extends WP_UnitTestCase {

	/**
	 * The test environment runs WordPress 7.2+, so the embedding API is available.
	 */
	public function test_embedding_api_is_available(): void {
		$this->assertTrue( Plugin::is_embedding_api_available() );
	}

	/**
	 * The notice explains that WordPress 7.2 is required.
	 */
	public function test_notice_requires_embedding_api_mentions_wordpress_7_2(): void {
		ob_start();
		( new Plugin() )->notice_requires_embedding_api();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $output );
		$this->assertStringContainsString( 'WordPress 7.2', $output );
	}
}
