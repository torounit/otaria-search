<?php
/**
 * Integration tests for the post types ability.
 *
 * Requires MariaDB 11.7+ with VECTOR support.
 *
 * @package WP_MariaDB_Vector_Search
 */

declare(strict_types=1);

namespace WP_MariaDB_Vector_Search\Tests\Integration;

use WP_MariaDB_Vector_Search\Indexer;
use WP_MariaDB_Vector_Search\Repository;
use WP_MariaDB_Vector_Search\Schema;
use WP_MariaDB_Vector_Search\Settings;

/**
 * Class Post_Types_Abilities_Test
 */
class Post_Types_Abilities_Test extends \WP_UnitTestCase {

	/**
	 * Set up the schema, the fake provider and an administrator.
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
		Schema::install( 4 );
		Fake_Embedding_Provider::select();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Remove settings and the schema.
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

	/**
	 * Create a published post of the given type and index it.
	 *
	 * @param string $post_type Post type.
	 * @return int Post ID.
	 */
	private function create_indexed_post( string $post_type ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => $post_type,
				'post_status' => 'publish',
			)
		);
		( new Indexer( new Repository() ) )->index_post( $post_id );

		return $post_id;
	}

	/**
	 * Run an ability.
	 *
	 * @param string $name  Ability name without the plugin prefix.
	 * @param array  $input Ability input.
	 * @return mixed
	 */
	private function run_ability( string $name, array $input = array() ): mixed {
		return wp_get_ability( "wp-mariadb-vector-search/{$name}" )->execute( empty( $input ) ? null : $input );
	}

	/** The get-status ability reports the indexed post types and the ones to choose from. */
	public function test_get_status_reports_post_types(): void {
		Settings::update( array( 'post_types' => array( 'page' ) ) );

		$status = $this->run_ability( 'get-status' );

		$this->assertSame( array( 'page' ), $status['post_types'] );
		$this->assertContains(
			array(
				'name'  => 'post',
				'label' => 'Posts',
			),
			$status['available_post_types']
		);
	}

	/** Saving post types stores them and removes the index rows of deselected types. */
	public function test_save_post_types_removes_deselected_types(): void {
		$post = $this->create_indexed_post( 'post' );
		$page = $this->create_indexed_post( 'page' );

		$result = $this->run_ability( 'save-post-types', array( 'post_types' => array( 'post' ) ) );

		$this->assertSame(
			array(
				'post_types' => array( 'post' ),
				'added'      => array(),
			),
			$result
		);
		$this->assertSame( array( 'post' ), Settings::get_post_types() );
		$repository = new Repository();
		$this->assertNotNull( $repository->get_content_hash( $post ) );
		$this->assertNull( $repository->get_content_hash( $page ) );
	}

	/** Post types that were not indexed before are reported, since they need a Reindex. */
	public function test_save_post_types_reports_added_types(): void {
		Settings::update( array( 'post_types' => array( 'post' ) ) );

		$result = $this->run_ability( 'save-post-types', array( 'post_types' => array( 'post', 'page' ) ) );

		$this->assertSame( array( 'page' ), $result['added'] );
	}

	/** Post types that are not public and searchable are rejected. */
	public function test_save_post_types_rejects_unavailable_type(): void {
		$this->assertWPError( $this->run_ability( 'save-post-types', array( 'post_types' => array( 'nav_menu_item' ) ) ) );
		$this->assertSame( Settings::available_post_types(), Settings::get_post_types() );
	}

	/** At least one post type is required. */
	public function test_save_post_types_rejects_empty_list(): void {
		$this->assertWPError( $this->run_ability( 'save-post-types', array( 'post_types' => array() ) ) );
	}

	/** Editors cannot change the post types. */
	public function test_save_post_types_requires_manage_options(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertWPError( $this->run_ability( 'save-post-types', array( 'post_types' => array( 'post' ) ) ) );
	}
}
