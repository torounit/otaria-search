<?php
/**
 * Plugin Name:       WP MariaDB Vector Search
 * Plugin URI:        https://github.com/torounit/wp-mariadb-vector-search
 * Description:       Base scaffold for a MariaDB vector search WordPress plugin.
 * Version:           0.2.0
 * Requires at least: 7.2
 * Requires PHP:      8.2
 * Author:            Toro_Unit
 * Author URI:        https://torounit.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-mariadb-vector-search
 * Update URI:        https://github.com/torounit/wp-mariadb-vector-search
 *
 * @package WP_MariaDB_Vector_Search
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WP_MARIADB_VECTOR_SEARCH_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_MARIADB_VECTOR_SEARCH_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

if ( file_exists( __DIR__ . '/build/build.php' ) ) {
	require_once __DIR__ . '/build/build.php';
}

/**
 * Create a core embedding builder bound to the model selected in plugin settings.
 *
 * Thin layer over wp_ai_client_embedding(). When no model is selected the
 * builder is returned without one, so generating returns a core WP_Error.
 *
 * @param string|string[] ...$input Text(s) to embed, or a single list of texts.
 * @return WP_AI_Client_Embedding_Builder
 */
function wp_mariadb_vector_search_embedding( ...$input ): WP_AI_Client_Embedding_Builder {
	$settings = get_option( WP_MariaDB_Vector_Search\Settings::OPTION_NAME, array() );
	$provider = is_array( $settings ) ? (string) ( $settings['provider'] ?? '' ) : '';
	$model    = is_array( $settings ) ? (string) ( $settings['model'] ?? '' ) : '';
	$builder  = wp_ai_client_embedding( ...$input );

	if ( '' !== $provider && '' !== $model ) {
		$builder = $builder->using_provider_model( $provider, $model );
	}

	return $builder;
}

/**
 * Write a diagnostic message to the PHP error log when WP_DEBUG is enabled.
 *
 * All messages are prefixed with "[wp-mariadb-vector-search]" so they can be
 * grepped from wp-content/debug.log to diagnose indexing failures.
 *
 * @param string $message Human-readable description of the event.
 * @return void
 */
function wp_mariadb_vector_search_log( string $message ): void {
	if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
		return;
	}

	$log_path = ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) )
		? WP_DEBUG_LOG
		: ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/debug.log' : '' );

	$line = '[wp-mariadb-vector-search] ' . $message . PHP_EOL;

	if ( '' !== $log_path ) {
		$dir = dirname( $log_path );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( $line, 3, $log_path );
	} else {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( $line );
	}
}


register_activation_hook( __FILE__, array( 'WP_MariaDB_Vector_Search\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WP_MariaDB_Vector_Search\\Plugin', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		( new WP_MariaDB_Vector_Search\Plugin() )->init();
	}
);
