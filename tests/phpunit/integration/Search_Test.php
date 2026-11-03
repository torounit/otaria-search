<?php
/**
 * Integration tests for the Search class.
 *
 * Requires MariaDB 11.7+ with VECTOR support.
 * Embeddings come from Fake_Embedding_Provider through the core AI Client
 * (no real HTTP calls).
 *
 * @package Squirrel_Search
 */

declare(strict_types=1);

namespace Squirrel_Search\Tests\Integration;

use Squirrel_Search\Indexer;
use Squirrel_Search\Repository;
use Squirrel_Search\Schema;
use Squirrel_Search\Search;
use Squirrel_Search\Settings;

/**
 * Class Search_Test
 */
class Search_Test extends \WP_UnitTestCase {

	/**
	 * Repository instance.
	 *
	 * @var Repository
	 */
	private Repository $repository;

	/**
	 * Search instance under test.
	 *
	 * @var Search
	 */
	private Search $search;

	/**
	 * ID of the first test post.
	 *
	 * @var int
	 */
	private int $post_a;

	/**
	 * ID of the second test post.
	 *
	 * @var int
	 */
	private int $post_b;

	/**
	 * IDs of posts created by individual tests.
	 *
	 * @var int[]
	 */
	private array $extra_posts = array();

	/**
	 * Number of vector dimensions used in this test suite.
	 */
	private const DIMS = 4;

	/**
	 * Saved main WP_Query reference for restoration.
	 *
	 * @var \WP_Query|null
	 */
	private $original_main_query;



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

		// Create and index two posts with the default fake vector (1,0,0,0).
		$indexer = new Indexer( $this->repository );

		$this->post_a = $this->factory->post->create(
			array(
				'post_title'   => 'Cats',
				'post_content' => 'About cats.',
				'post_status'  => 'publish',
			)
		);
		$this->post_b = $this->factory->post->create(
			array(
				'post_title'   => 'Dogs',
				'post_content' => 'About dogs.',
				'post_status'  => 'publish',
			)
		);

		$indexer->index_post( $this->post_a );
		$indexer->index_post( $this->post_b );

		$this->search = new Search( $this->repository );
		$this->search->register_hooks();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down(): void {
		Fake_Embedding_Provider::reset();
		remove_all_filters( 'pre_get_posts' );
		remove_all_filters( 'posts_search' );
		remove_all_actions( 'the_posts' );

		$this->restore_main_query();

		// Delete posts before dropping schema so delete_post hooks don't error.
		wp_delete_post( $this->post_a, true );
		wp_delete_post( $this->post_b, true );
		foreach ( $this->extra_posts as $post_id ) {
			wp_delete_post( $post_id, true );
		}

		// Schema::drop() commits the test transaction (DDL), so saved thresholds
		// would leak into later tests unless removed before it.
		delete_option( Settings::OPTION_NAME );

		Schema::drop();
		delete_option( Schema::DB_VERSION_OPTION );

		add_filter( 'query', array( $this, '_create_temporary_tables' ) );
		add_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		parent::tear_down();
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	/**
	 * Make $query act as the WordPress main query so is_main_query() returns true.
	 *
	 * WP_Query::is_main_query() checks $wp_the_query === $this, so we replace
	 * the global temporarily.
	 *
	 * @param \WP_Query $query Query to promote to main query.
	 * @return \WP_Query The promoted query (for chaining).
	 */
	private function as_main_query( \WP_Query $query ): \WP_Query {
		global $wp_the_query;
		$this->original_main_query = $wp_the_query;
		$wp_the_query              = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		return $query;
	}

	/**
	 * Restore the original main query.
	 *
	 * @return void
	 */
	private function restore_main_query(): void {
		if ( isset( $this->original_main_query ) ) {
			global $wp_the_query;
			$wp_the_query              = $this->original_main_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			$this->original_main_query = null;
		}
	}

	/**
	 * Create a published post and index it with the given embedding.
	 *
	 * @param string  $title     Post title.
	 * @param float[] $vector    Embedding the fake provider returns while indexing.
	 * @param array   $post_args Extra post fields.
	 * @return int Post ID.
	 */
	private function create_indexed_post( string $title, array $vector, array $post_args = array() ): int {
		// Set the vector first: saving the post may already index it.
		Fake_Embedding_Provider::$vector = $vector;
		$post_id                         = $this->factory->post->create(
			$post_args + array(
				'post_title'   => $title,
				'post_content' => "About {$title}.",
				'post_status'  => 'publish',
			)
		);
		( new Indexer( $this->repository ) )->index_post( $post_id );
		Fake_Embedding_Provider::reset();

		$this->extra_posts[] = $post_id;
		return $post_id;
	}

	/**
	 * Index password-protected posts, which are left out of the index by
	 * default, so the tests reach the search side.
	 *
	 * @return void
	 */
	private function allow_password_protected_in_index(): void {
		Settings::update( array( 'exclude_password_protected' => false ) );
	}

	/**
	 * Run $search_term as the main search query.
	 *
	 * @param string $search_term Search term.
	 * @return int[] Found post IDs, in result order.
	 */
	private function search_ids( string $search_term ): array {
		$query = new \WP_Query(
			array(
				's'              => $search_term,
				'post_type'      => 'post',
				'posts_per_page' => 10,
				'fields'         => 'ids',
			)
		);

		$this->as_main_query( $query );
		$query->get_posts();
		$this->restore_main_query();

		return array_map( 'intval', $query->posts );
	}

	// -----------------------------------------------------------------------
	// Tests
	// -----------------------------------------------------------------------

	/** Suppressing the LIKE clause affects only the rewritten query. */
	public function test_like_suppression_does_not_leak_into_later_queries(): void {
		// fields=ids returns before the_posts runs.
		$this->search_ids( 'animals' );

		$later = new \WP_Query(
			array(
				's'         => 'Zorbex',
				'post_type' => 'post',
				'fields'    => 'ids',
			)
		);

		$this->assertSame( array(), $later->posts );
	}

	/** Like core search, password-protected posts are hidden from logged-out visitors. */
	public function test_search_excludes_password_protected_post_when_logged_out(): void {
		$this->allow_password_protected_in_index();
		$protected = $this->create_indexed_post( 'Protected', array( 1.0, 0.0, 0.0, 0.0 ), array( 'post_password' => 'secret' ) );
		wp_set_current_user( 0 );

		$found_ids = $this->search_ids( 'animals' );

		$this->assertNotContains( $protected, $found_ids );
		$this->assertContains( $this->post_a, $found_ids );
	}

	/** Like core search, logged-in users find password-protected posts. */
	public function test_search_includes_password_protected_post_when_logged_in(): void {
		$this->allow_password_protected_in_index();
		$protected = $this->create_indexed_post( 'Protected', array( 1.0, 0.0, 0.0, 0.0 ), array( 'post_password' => 'secret' ) );
		wp_set_current_user( $this->factory->user->create() );

		$this->assertContains( $protected, $this->search_ids( 'animals' ) );
	}

	/** A post far behind the best vector match is excluded by default. */
	public function test_search_excludes_post_far_behind_best_match(): void {
		// Cosine distance 0.5 from the query: within max_distance (0.65), but
		// more than the default max_relative_distance (0.25) behind posts A and B.
		$far = $this->create_indexed_post( 'Far', array( 0.5, 0.8660254038, 0.0, 0.0 ) );

		$found_ids = $this->search_ids( 'animals' );

		$this->assertContains( $this->post_a, $found_ids );
		$this->assertNotContains( $far, $found_ids );
	}

	/** A saved max_relative_distance replaces the default. */
	public function test_search_uses_saved_max_relative_distance(): void {
		$far = $this->create_indexed_post( 'Far', array( 0.5, 0.8660254038, 0.0, 0.0 ) );
		Settings::update( array( 'max_relative_distance' => 0.6 ) );

		$this->assertContains( $far, $this->search_ids( 'animals' ) );
	}

	/** A saved max_distance replaces the default. */
	public function test_search_uses_saved_max_distance(): void {
		// Cosine distance 0.1 from the query; posts A and B are at 0.
		$near = $this->create_indexed_post( 'Near', array( 0.9, 0.4358898944, 0.0, 0.0 ) );
		Settings::update(
			array(
				'max_distance' => 0.05,
				'hybrid'       => false,
			)
		);

		$found_ids = $this->search_ids( 'animals' );

		$this->assertNotContains( $near, $found_ids );
		$this->assertContains( $this->post_a, $found_ids );
	}

	/** Hybrid search adds posts that only the LIKE search finds. */
	public function test_hybrid_includes_lexical_only_match(): void {
		// Orthogonal to the query vector, so vector search never finds it.
		$lexical = $this->create_indexed_post( 'Zorbex Gadget Guide', array( 0.0, 0.0, 0.0, 1.0 ) );

		$found_ids = $this->search_ids( 'Zorbex' );

		$this->assertContains( $lexical, $found_ids );
		$this->assertContains( $this->post_a, $found_ids );
	}

	/** A post found by both vector and LIKE search ranks first. */
	public function test_hybrid_ranks_post_found_by_both_searches_first(): void {
		// Third on the vector side (distance 0.1, behind posts A and B), but
		// the only post that matches "Hounds" lexically.
		$both = $this->create_indexed_post( 'Hounds', array( 0.9, 0.4358898944, 0.0, 0.0 ) );

		$found_ids = $this->search_ids( 'Hounds' );

		$this->assertSame( $both, $found_ids[0] );
	}

	/** The query prefix is added to the search term before embedding it. */
	public function test_search_embeds_query_with_prefix(): void {
		Settings::update( array( 'query_prefix' => 'query: ' ) );
		Fake_Embedding_Provider::$inputs = array();

		$this->search_ids( 'animals' );

		// The plugin's own Search instance runs too, so the term is embedded twice.
		$this->assertSame( array( 'query: animals' ), array_values( array_unique( Fake_Embedding_Provider::$inputs ) ) );
	}

	/** Turning hybrid search off leaves vector results only. */
	public function test_hybrid_off_excludes_lexical_only_match(): void {
		$lexical = $this->create_indexed_post( 'Zorbex Gadget Guide', array( 0.0, 0.0, 0.0, 1.0 ) );
		Settings::update( array( 'hybrid' => false ) );

		$found_ids = $this->search_ids( 'Zorbex' );

		$this->assertNotContains( $lexical, $found_ids );
		$this->assertContains( $this->post_a, $found_ids );
	}

	/** Without matches, the search returns nothing rather than falling back to LIKE. */
	public function test_no_vector_match_does_not_fall_back_to_like(): void {
		Settings::update( array( 'hybrid' => false ) );
		// Orthogonal to every indexed post; "Cats" still matches post A lexically.
		Fake_Embedding_Provider::$vector = array( 0.0, 0.0, 0.0, 1.0 );

		$this->assertSame( array(), $this->search_ids( 'Cats' ) );
	}

	/** Vector search returns at least one post. */
	public function test_search_returns_indexed_posts(): void {
		$query = new \WP_Query(
			array(
				's'              => 'cats',
				'post_type'      => 'post',
				'posts_per_page' => 10,
				'fields'         => 'ids',
			)
		);

		$this->as_main_query( $query );
		$query->get_posts();
		$this->restore_main_query();

		$this->assertNotEmpty( $query->posts );
	}

	/** Both indexed posts appear when all stub vectors are identical. */
	public function test_search_result_contains_both_posts(): void {
		// Both posts have identical stub vectors, so both appear in results.
		$query = new \WP_Query(
			array(
				's'              => 'animals',
				'post_type'      => 'post',
				'posts_per_page' => 10,
				'fields'         => 'ids',
			)
		);

		$this->as_main_query( $query );
		$query->get_posts();
		$this->restore_main_query();

		// posts contain raw IDs when fields='ids'.
		$found_ids = array_map( 'intval', $query->posts );
		$this->assertContains( $this->post_a, $found_ids );
		$this->assertContains( $this->post_b, $found_ids );
	}

	/** Search falls back gracefully when the embedding provider returns an error. */
	public function test_search_falls_back_on_provider_error(): void {
		// Make every embedding request fail via core's prevent filter.
		remove_all_filters( 'pre_get_posts' );
		remove_all_filters( 'posts_search' );
		remove_all_actions( 'the_posts' );

		add_filter( 'wp_ai_client_prevent_embedding', '__return_true' );
		$error_search = new Search( $this->repository );
		$error_search->register_hooks();

		$query = new \WP_Query(
			array(
				's'              => 'cats',
				'post_type'      => 'post',
				'posts_per_page' => 10,
				'fields'         => 'ids',
			)
		);

		$this->as_main_query( $query );
		$query->get_posts();
		$this->restore_main_query();

		// Should not throw. Posts may or may not appear (LIKE fallback).
		$this->assertIsArray( $query->posts );
	}

	/** The 's' query var is preserved so templates can display the search term. */
	public function test_search_preserves_search_query_var(): void {
		$query = new \WP_Query(
			array(
				's'              => 'cats',
				'post_type'      => 'post',
				'posts_per_page' => 10,
				'fields'         => 'ids',
			)
		);

		$this->as_main_query( $query );
		$query->get_posts();
		$this->restore_main_query();

		$this->assertSame( 'cats', $query->get( 's' ) );
	}

	/** A non-search query is not modified by the Search class. */
	public function test_non_search_query_is_not_modified(): void {
		$query = new \WP_Query(
			array(
				'post_type'      => 'post',
				'posts_per_page' => 10,
			)
		);

		$this->as_main_query( $query );
		$query->get_posts();
		$this->restore_main_query();

		// post__in should NOT be set by the Search class (no 's' param).
		$this->assertEmpty( $query->get( 'post__in' ) );
	}
}
