<?php
/**
 * Admin settings page — mounts the React admin app.
 *
 * @package Otaria_Search
 */

declare(strict_types=1);

namespace Otaria_Search;

/**
 * Registers the Settings > Otaria Search admin page.
 *
 * It sits under Settings, next to Connectors where the AI provider is set up,
 * because it mostly holds configuration (model, search thresholds).
 *
 * Script enqueuing is handled automatically by the auto-generated
 * build/pages/otaria-search/page-wp-admin.php loaded via build/build.php.
 */
class Admin {

	const PAGE_SLUG = 'otaria-search-wp-admin';

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
			__( 'Otaria Search', 'otaria-search' ),
			__( 'Otaria Search', 'otaria-search' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the admin page mount point.
	 *
	 * The React app is initialized by Core's boot module, enqueued by
	 * build/pages/otaria-search/page-wp-admin.php.
	 * If the build is missing, a notice is shown instead.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! function_exists( 'otaria_search_otaria_search_wp_admin_render_page' ) ) {
			echo '<div class="wrap"><div class="notice notice-warning inline"><p>';
			echo esc_html__(
				'Otaria Search: build not found. Run `npm run build`.',
				'otaria-search'
			);
			echo '</p></div></div>';
			return;
		}
		otaria_search_otaria_search_wp_admin_render_page();
	}
}
