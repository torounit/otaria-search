<?php
/**
 * Uninstall Otaria Search.
 *
 * @package Otaria_Search
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/vendor/autoload.php';

Otaria_Search\Schema::drop();

delete_option( Otaria_Search\Schema::DB_VERSION_OPTION );
delete_option( Otaria_Search\Settings::OPTION_NAME );
delete_transient( Otaria_Search\Cron_Backfill::PROGRESS_KEY );

$timestamp = wp_next_scheduled( Otaria_Search\Cron_Backfill::CRON_HOOK );
if ( $timestamp ) {
	wp_unschedule_event( $timestamp, Otaria_Search\Cron_Backfill::CRON_HOOK );
}
