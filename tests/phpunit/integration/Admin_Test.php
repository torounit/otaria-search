<?php
/**
 * Integration tests for the admin page.
 *
 * @package Squirrel_Search
 */

declare(strict_types=1);

namespace Squirrel_Search\Tests\Integration;

use Squirrel_Search\Admin;
use WP_UnitTestCase;

/**
 * Tests for the Settings > Squirrel Search page.
 */
class Admin_Test extends WP_UnitTestCase {

	/**
	 * The page is registered under Settings, next to Connectors.
	 */
	public function test_page_is_registered_under_settings(): void {
		global $submenu;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		( new Admin() )->add_menu();

		$this->assertContains( Admin::PAGE_SLUG, array_column( $submenu['options-general.php'] ?? array(), 2 ) );
		$this->assertArrayNotHasKey( 'tools.php', $submenu ?? array() );
	}

	/**
	 * The page mounts the app with Core's boot module, without a plugin-local copy.
	 */
	public function test_render_page_mounts_app_without_local_boot_asset(): void {
		$this->assertFileDoesNotExist( SQUIRREL_SEARCH_PLUGIN_DIR . 'build/modules/boot/index.min.asset.php' );

		// admin-header.php sets the page title before the page renders.
		$GLOBALS['title'] = 'Squirrel Search'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		ob_start();
		( new Admin() )->render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="squirrel-search-wp-admin-app"', $output );
	}

	/**
	 * Clean up the simulated admin page request.
	 */
	public function tear_down(): void {
		unset( $GLOBALS['title'], $GLOBALS['submenu'] );
		parent::tear_down();
	}
}
