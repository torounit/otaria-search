<?php
/**
 * Admin settings page — mounts the React admin app.
 *
 * @package Squirrel_Search
 */

declare(strict_types=1);

namespace Squirrel_Search;

/**
 * Registers the Settings > Squirrel Search admin page.
 *
 * It sits under Settings, next to Connectors where the AI provider is set up,
 * because it mostly holds configuration (model, search thresholds).
 *
 * Script enqueuing is handled automatically by the auto-generated
 * build/pages/squirrel-search/page-wp-admin.php loaded via build/build.php.
 */
class Admin {

	const PAGE_SLUG    = 'squirrel-search-wp-admin';
	const SETTINGS_KEY = 'squirrel_search_settings';

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
	}

	/**
	 * Register the Settings submenu page.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_options_page(
			__( 'Squirrel Search', 'squirrel-search' ),
			__( 'Squirrel Search', 'squirrel-search' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the admin page mount point.
	 *
	 * The React app is initialized by Core's boot module, enqueued by
	 * build/pages/squirrel-search/page-wp-admin.php.
	 * If the build is missing, a notice is shown instead.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! function_exists( 'squirrel_search_squirrel_search_wp_admin_render_page' ) ) {
			echo '<div class="wrap"><div class="notice notice-warning inline"><p>';
			echo esc_html__(
				'Squirrel Search: build not found. Run `npm run build`.',
				'squirrel-search'
			);
			echo '</p></div></div>';
			return;
		}
		squirrel_search_squirrel_search_wp_admin_render_page();
	}
}
