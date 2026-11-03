<?php
/**
 * Integration tests for Cron_Backfill.
 *
 * Requires MariaDB 11.7+ with VECTOR support.
 *
 * @package Squirrel_Search
 */

declare(strict_types=1);

namespace Squirrel_Search\Tests\Integration;

use Squirrel_Search\Cron_Backfill;
use Squirrel_Search\Indexer;
use Squirrel_Search\Repository;
use Squirrel_Search\Schema;

/**
 * Class Cron_Backfill_Test
 */
class Cron_Backfill_Test extends \WP_UnitTestCase {

	/**
	 * Cron backfill instance under test.
	 *
	 * @var Cron_Backfill
	 */
	private Cron_Backfill $backfill;

	/**
	 * Repository instance.
	 *
	 * @var Repository
	 */
	private Repository $repository;

	/**
	 * Number of vector dimensions used in this test suite.
	 */
	private const DIMS = 4;


	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! Schema::is_vector_supported() ) {
			$this->markTestSkipped( 'MariaDB 11.7+ with VECTOR support is required.' );
		}

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		Schema::drop();
		delete_option( Schema::DB_VERSION_OPTION );
		Schema::install( self::DIMS );
		Fake_Embedding_Provider::select();

		$this->repository = new Repository();
		$indexer          = new Indexer( $this->repository );
		$this->backfill   = new Cron_Backfill( $indexer, 2 );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down(): void {
		Fake_Embedding_Provider::reset();
		delete_transient( Cron_Backfill::PROGRESS_KEY );
		Schema::drop();
		delete_option( Schema::DB_VERSION_OPTION );
		add_filter( 'query', array( $this, '_create_temporary_tables' ) );
		add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		parent::tear_down();
	}

	/** Progress transient is set after schedule(). */
	public function test_schedule_sets_progress_transient(): void {
		$this->backfill->schedule();

		$progress = get_transient( Cron_Backfill::PROGRESS_KEY );
		$this->assertIsArray( $progress );
		$this->assertSame( 0, $progress['offset'] );
	}

	/** Posts are indexed after run_batch(). */
	public function test_run_batch_indexes_posts(): void {
		global $wpdb;
		$table = Schema::table_name();

		$post_id = $this->factory->post->create(
			array(
				'post_title'   => 'B',
				'post_content' => 'Body.',
				'post_status'  => 'publish',
			)
		);

		$this->backfill->schedule();
		$this->backfill->run_batch();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE post_id = %d", $post_id )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertGreaterThan( 0, $count );
	}

	/** Every post is indexed across batches even when posts share a post_date. */
	public function test_run_batch_indexes_every_post_with_same_post_date(): void {
		global $wpdb;
		$table = Schema::table_name();

		$post_ids = $this->factory->post->create_many(
			7,
			array(
				'post_status' => 'publish',
				'post_date'   => '2026-01-01 00:00:00',
			)
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM `{$table}`" );

		$this->backfill->schedule();
		$runs = 0;
		do {
			$this->backfill->run_batch();
			++$runs;
		} while ( $this->backfill->get_progress() && $runs < 10 );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$indexed = array_map( 'intval', $wpdb->get_col( "SELECT DISTINCT post_id FROM `{$table}`" ) );
		$this->assertSame( array(), array_values( array_diff( $post_ids, $indexed ) ) );
	}

	/** Force-reindex replaces existing rows. */
	public function test_run_batch_with_force_reindexes(): void {
		global $wpdb;
		$table = Schema::table_name();

		$post_id = $this->factory->post->create(
			array(
				'post_title'   => 'B',
				'post_content' => 'Body.',
				'post_status'  => 'publish',
			)
		);

		// First index.
		$this->backfill->schedule();
		$this->backfill->run_batch();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$hash1 = $wpdb->get_var(
			$wpdb->prepare( "SELECT content_hash FROM `{$table}` WHERE post_id = %d LIMIT 1", $post_id )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		// Force reindex clears old rows and re-embeds.
		$this->backfill->schedule( true );
		$this->backfill->run_batch();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$hash2 = $wpdb->get_var(
			$wpdb->prepare( "SELECT content_hash FROM `{$table}` WHERE post_id = %d LIMIT 1", $post_id )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		// Content didn't change so hash is same, but it was re-embedded.
		$this->assertSame( $hash1, $hash2 );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE post_id = %d", $post_id )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertGreaterThan( 0, $count );
	}

	/** Null is returned when no backfill is running. */
	public function test_get_progress_returns_null_when_idle(): void {
		$this->assertNull( $this->backfill->get_progress() );
	}

	/** Progress array contains expected keys during a run. */
	public function test_get_progress_returns_array_when_running(): void {
		$this->backfill->schedule();
		$progress = $this->backfill->get_progress();

		$this->assertIsArray( $progress );
		$this->assertArrayHasKey( 'offset', $progress );
		$this->assertArrayHasKey( 'done', $progress );
	}
}
