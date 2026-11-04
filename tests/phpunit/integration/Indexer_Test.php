<?php
/**
 * Integration tests for the Indexer class.
 *
 * Requires MariaDB 11.7+ with VECTOR support.
 * Embeddings come from Fake_Embedding_Provider through the core AI Client,
 * so no real AI provider is needed.
 *
 * @package Otaria_Search
 */

declare(strict_types=1);

namespace Otaria_Search\Tests\Integration;

use Otaria_Search\Indexer;
use Otaria_Search\Repository;
use Otaria_Search\Schema;
use Otaria_Search\Settings;

/**
 * Class Indexer_Test
 */
class Indexer_Test extends \WP_UnitTestCase {

	/**
	 * Indexer instance under test.
	 *
	 * @var Indexer
	 */
	private Indexer $indexer;

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
		$this->indexer    = new Indexer( $this->repository );
	}

	/**
	 * The provider receives the post as text, with its image alt text.
	 */
	public function test_index_post_embeds_text_of_html_content(): void {
		Fake_Embedding_Provider::$inputs = array();

		// Saving the post may already index it.
		$post_id = $this->factory->post->create(
			array(
				'post_title'   => 'Title',
				'post_content' => '<p>Body</p><img src="cat.jpg" alt="Sleeping cat">',
				'post_status'  => 'publish',
			)
		);
		$this->indexer->index_post( $post_id );

		$this->assertSame( array( "Title\n\nBody Sleeping cat" ), Fake_Embedding_Provider::$inputs );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down(): void {
		Fake_Embedding_Provider::reset();
		// Schema::drop() commits the test transaction (DDL); clean up before it.
		delete_option( Settings::OPTION_NAME );
		Schema::drop();
		delete_option( Schema::DB_VERSION_OPTION );
		add_filter( 'query', array( $this, '_create_temporary_tables' ) );
		add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		parent::tear_down();
	}

	/** Chunks are stored after index_post(). */
	public function test_index_post_stores_chunks(): void {
		global $wpdb;
		$table = Schema::table_name();

		$post_id = $this->factory->post->create(
			array(
				'post_title'   => 'Hello',
				'post_content' => 'World content here.',
				'post_status'  => 'publish',
			)
		);

		$this->indexer->index_post( $post_id );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE post_id = %d", $post_id )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertGreaterThan( 0, $count );
	}

	/** A post with unchanged content is not re-indexed. */
	public function test_index_post_skips_unchanged_content(): void {
		global $wpdb;
		$table = Schema::table_name();

		$post_id = $this->factory->post->create(
			array(
				'post_title'   => 'Stable',
				'post_content' => 'Stable content.',
				'post_status'  => 'publish',
			)
		);

		$this->indexer->index_post( $post_id );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$first_updated = $wpdb->get_var(
			$wpdb->prepare( "SELECT updated_at FROM `{$table}` WHERE post_id = %d LIMIT 1", $post_id )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		// A tiny sleep ensures the timestamp would differ if re-indexed.
		sleep( 1 );
		$this->indexer->index_post( $post_id );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$second_updated = $wpdb->get_var(
			$wpdb->prepare( "SELECT updated_at FROM `{$table}` WHERE post_id = %d LIMIT 1", $post_id )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$this->assertSame( $first_updated, $second_updated );
	}

	/** Switching the embedding model re-indexes an unchanged post, even at the same dimensions. */
	public function test_index_post_reindexes_after_model_change(): void {
		$post_id = $this->factory->post->create(
			array(
				'post_title'   => 'Stable',
				'post_content' => 'Content that does not change.',
				'post_status'  => 'publish',
			)
		);
		$this->indexer->index_post( $post_id );
		$old_hash = $this->repository->get_content_hash( $post_id );

		update_option(
			Settings::OPTION_NAME,
			array(
				'provider' => Fake_Embedding_Provider::ID,
				'model'    => 'another-fake-embedding',
			)
		);
		$this->indexer->index_post( $post_id );

		$this->assertNotSame( $old_hash, $this->repository->get_content_hash( $post_id ) );
	}

	/** Chunks are embedded with the document prefix but stored without it. */
	public function test_index_post_embeds_chunks_with_document_prefix(): void {
		global $wpdb;
		Settings::update( array( 'document_prefix' => 'passage: ' ) );
		Fake_Embedding_Provider::$inputs = array();

		// Saving the post may already index it.
		$post_id = $this->factory->post->create(
			array(
				'post_title'   => 'Cats',
				'post_content' => 'Cats are small animals.',
				'post_status'  => 'publish',
			)
		);
		$this->indexer->index_post( $post_id );

		$this->assertSame( array( "passage: Cats\n\nCats are small animals." ), Fake_Embedding_Provider::$inputs );
		$table = Schema::table_name();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$chunk_text = $wpdb->get_var( $wpdb->prepare( "SELECT chunk_text FROM `{$table}` WHERE post_id = %d", $post_id ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertSame( "Cats\n\nCats are small animals.", $chunk_text );
	}

	/** Changing the document prefix re-embeds unchanged posts. */
	public function test_index_post_reindexes_after_document_prefix_change(): void {
		$post_id = $this->factory->post->create(
			array(
				'post_title'   => 'Stable',
				'post_content' => 'Content that does not change.',
				'post_status'  => 'publish',
			)
		);
		$this->indexer->index_post( $post_id );
		$old_hash = $this->repository->get_content_hash( $post_id );

		Settings::update( array( 'document_prefix' => 'passage: ' ) );
		$this->indexer->index_post( $post_id );

		$this->assertNotSame( $old_hash, $this->repository->get_content_hash( $post_id ) );
	}

	/** A post with changed content is re-indexed with a new hash. */
	public function test_index_post_reindexes_changed_content(): void {
		global $wpdb;
		$table = Schema::table_name();

		$post_id = $this->factory->post->create(
			array(
				'post_title'   => 'Draft',
				'post_content' => 'Old content.',
				'post_status'  => 'publish',
			)
		);

		$this->indexer->index_post( $post_id );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$old_hash = $wpdb->get_var(
			$wpdb->prepare( "SELECT content_hash FROM `{$table}` WHERE post_id = %d LIMIT 1", $post_id )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'New content that has changed.',
			)
		);

		$this->indexer->index_post( $post_id );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$new_hash = $wpdb->get_var(
			$wpdb->prepare( "SELECT content_hash FROM `{$table}` WHERE post_id = %d LIMIT 1", $post_id )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$this->assertNotSame( $old_hash, $new_hash );
	}

	/** All rows are removed after delete_post(). */
	public function test_delete_post_removes_rows(): void {
		global $wpdb;
		$table = Schema::table_name();

		$post_id = $this->factory->post->create(
			array(
				'post_title'   => 'To Delete',
				'post_content' => 'Some content.',
				'post_status'  => 'publish',
			)
		);

		$this->indexer->index_post( $post_id );
		$this->indexer->delete_post( $post_id );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE post_id = %d", $post_id )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertSame( 0, $count );
	}

	/** Draft posts are not indexed. */
	public function test_index_post_skips_non_published(): void {
		global $wpdb;
		$table = Schema::table_name();

		$post_id = $this->factory->post->create(
			array(
				'post_title'   => 'Draft post',
				'post_content' => 'Draft content.',
				'post_status'  => 'draft',
			)
		);

		$this->indexer->index_post( $post_id );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE post_id = %d", $post_id )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertSame( 0, $count );
	}

	/** Password-protected posts are not indexed by default. */
	public function test_index_post_skips_password_protected(): void {
		$post_id = $this->factory->post->create(
			array(
				'post_title'    => 'Protected',
				'post_content'  => 'Secret content.',
				'post_status'   => 'publish',
				'post_password' => 'secret',
			)
		);

		$this->indexer->index_post( $post_id );

		$this->assertNull( $this->repository->get_content_hash( $post_id ) );
	}

	/** Password-protected posts are indexed when the exclusion is turned off. */
	public function test_index_post_indexes_password_protected_when_allowed(): void {
		Settings::update( array( 'exclude_password_protected' => false ) );
		$post_id = $this->factory->post->create(
			array(
				'post_title'    => 'Protected',
				'post_content'  => 'Secret content.',
				'post_status'   => 'publish',
				'post_password' => 'secret',
			)
		);

		$this->indexer->index_post( $post_id );

		$this->assertNotNull( $this->repository->get_content_hash( $post_id ) );
	}

	/** Adding a password to an indexed post removes its rows. */
	public function test_index_post_removes_post_given_a_password(): void {
		$post_id = $this->factory->post->create(
			array(
				'post_title'   => 'Soon protected',
				'post_content' => 'Content.',
				'post_status'  => 'publish',
			)
		);
		$this->indexer->index_post( $post_id );

		wp_update_post(
			array(
				'ID'            => $post_id,
				'post_password' => 'secret',
			)
		);
		$this->indexer->index_post( $post_id );

		$this->assertNull( $this->repository->get_content_hash( $post_id ) );
	}

	/** Post types left out of the saved list are not indexed. */
	public function test_index_post_skips_post_type_not_in_settings(): void {
		Settings::update( array( 'post_types' => array( 'page' ) ) );
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->indexer->index_post( $post_id );

		$this->assertNull( $this->repository->get_content_hash( $post_id ) );
	}

	/** Non-indexable post types (e.g. nav_menu_item) are not indexed. */
	public function test_index_post_skips_non_indexable_post_type(): void {
		global $wpdb;
		$table = Schema::table_name();

		$post_id = wp_insert_post(
			array(
				'post_type'    => 'nav_menu_item',
				'post_title'   => 'Menu item',
				'post_content' => 'Menu content.',
				'post_status'  => 'publish',
			),
			true
		);

		$this->assertIsInt( $post_id );
		$this->assertGreaterThan( 0, $post_id );

		$this->indexer->index_post( $post_id );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE post_id = %d", $post_id )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertSame( 0, $count );
	}
}
