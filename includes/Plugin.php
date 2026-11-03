<?php
/**
 * Main plugin class.
 *
 * @package Otaria_Search
 */

declare(strict_types=1);

namespace Otaria_Search;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bootstrap plugin lifecycle and hooks.
 */
class Plugin {

	const DEFAULT_DIMENSIONS = 1536;

	/**
	 * Initialize plugin hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		$this->register_hooks();
	}

	/**
	 * Register runtime hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		if ( ! Schema::is_vector_supported() ) {
			otaria_search_log( 'register_hooks: VECTOR not supported by this database; indexing hooks NOT registered.' );
			add_action( 'admin_notices', array( $this, 'notice_no_vector' ) );
			return;
		}

		$this->maybe_install_schema();
		otaria_search_log( 'register_hooks: schema installed=' . ( Schema::is_installed() ? 'yes' : 'no' ) . '.' );

		$repository = new Repository();
		$indexer    = new Indexer( $repository );
		$backfill   = new Cron_Backfill( $indexer );
		$search     = new Search( $repository );
		$admin      = new Admin();

		// Register Abilities.
		Abilities::register();

		// Register Settings.
		Settings::register();

		// Index posts on save / delete.
		add_action( 'save_post', array( $indexer, 'index_post' ) );
		add_action( 'delete_post', array( $indexer, 'delete_post' ) );
		add_action( 'trashed_post', array( $indexer, 'delete_post' ) );
		add_action( 'update_option_' . Settings::OPTION_NAME, array( $indexer, 'remove_deselected_post_types' ), 10, 2 );
		add_action(
			'add_option_' . Settings::OPTION_NAME,
			static fn( string $option, mixed $value ) => $indexer->remove_deselected_post_types( array(), $value ),
			10,
			2
		);

		$backfill->register_hooks();
		$search->register_hooks();
		$admin->register_hooks();

		if ( defined( 'WP_CLI' ) && \WP_CLI ) {
			\WP_CLI::add_command( 'otaria-search', new CLI( $indexer ) );
		}
	}

	/**
	 * Show an admin notice when the database does not support VECTOR.
	 *
	 * @return void
	 */
	public function notice_no_vector(): void {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'Otaria Search requires MariaDB 11.7 or higher with VECTOR support.', 'otaria-search' );
		echo '</p></div>';
	}

	/**
	 * Install the schema if not yet installed or outdated.
	 *
	 * Called on every plugins_loaded so the table is always present
	 * regardless of whether the activation hook ran (e.g. manual installs).
	 *
	 * @return void
	 */
	private function maybe_install_schema(): void {
		if ( get_option( Schema::DB_VERSION_OPTION ) === Schema::DB_VERSION ) {
			return;
		}

		$settings   = get_option( Settings::OPTION_NAME, array() );
		$dimensions = isset( $settings['dimensions'] ) ? (int) $settings['dimensions'] : self::DEFAULT_DIMENSIONS;
		Schema::install( $dimensions );
	}

	/**
	 * Plugin activation: create / upgrade the embeddings table.
	 *
	 * @return void
	 */
	public static function activate(): void {
		if ( ! Schema::is_vector_supported() ) {
			return;
		}

		$settings   = get_option( Settings::OPTION_NAME, array() );
		$dimensions = isset( $settings['dimensions'] ) ? (int) $settings['dimensions'] : self::DEFAULT_DIMENSIONS;
		Schema::install( $dimensions );
	}

	/**
	 * Plugin deactivation: clear pending cron events.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		$timestamp = wp_next_scheduled( Cron_Backfill::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, Cron_Backfill::CRON_HOOK );
		}
	}
}
