<?php
/**
 * Uninstall Squirrel Search.
 *
 * @package Squirrel_Search
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/vendor/autoload.php';

Squirrel_Search\Schema::drop();

delete_option( Squirrel_Search\Schema::DB_VERSION_OPTION );
delete_option( Squirrel_Search\Settings::OPTION_NAME );
delete_transient( Squirrel_Search\Cron_Backfill::PROGRESS_KEY );

$timestamp = wp_next_scheduled( Squirrel_Search\Cron_Backfill::CRON_HOOK );
if ( $timestamp ) {
	wp_unschedule_event( $timestamp, Squirrel_Search\Cron_Backfill::CRON_HOOK );
}
