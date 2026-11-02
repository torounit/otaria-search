<?php
/**
 * Integration tests for the admin page.
 *
 * @package WP_MariaDB_Vector_Search
 */

declare(strict_types=1);

namespace WP_MariaDB_Vector_Search\Tests\Integration;

use WP_MariaDB_Vector_Search\Admin;
use WP_UnitTestCase;

/**
 * Tests for the Tools > Vector Search page.
 */
class Admin_Test extends WP_UnitTestCase {

	/**
	 * The page mounts the app with Core's boot module, without a plugin-local copy.
	 */
	public function test_render_page_mounts_app_without_local_boot_asset(): void {
		$this->assertFileDoesNotExist( WP_MARIADB_VECTOR_SEARCH_PLUGIN_DIR . 'build/modules/boot/index.min.asset.php' );

		// admin-header.php sets the page title before the page renders.
		$GLOBALS['title'] = 'Vector Search'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		ob_start();
		( new Admin() )->render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="wp-mariadb-vector-search-wp-admin-app"', $output );
	}

	/**
	 * Clean up the simulated admin page request.
	 */
	public function tear_down(): void {
		unset( $GLOBALS['title'] );
		parent::tear_down();
	}
}
