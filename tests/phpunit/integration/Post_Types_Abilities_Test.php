<?php
/**
 * Integration tests for the post types ability.
 *
 * Requires MariaDB 11.7+ with VECTOR support.
 *
 * @package Squirrel_Search
 */

declare(strict_types=1);

namespace Squirrel_Search\Tests\Integration;

use Squirrel_Search\Indexer;
use Squirrel_Search\Repository;
use Squirrel_Search\Schema;
use Squirrel_Search\Settings;

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
		return wp_get_ability( "squirrel-search/{$name}" )->execute( empty( $input ) ? null : $input );
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

		$result = $this->run_ability( 'update-settings', array( 'post_types' => array( 'post' ) ) );

		$this->assertSame( array( 'post' ), $result['post_types'] );
		$this->assertSame( array(), $result['added_post_types'] );
		$this->assertFalse( $result['reindex_required'] );
		$this->assertSame( array( 'post' ), Settings::get_post_types() );
		$repository = new Repository();
		$this->assertNotNull( $repository->get_content_hash( $post ) );
		$this->assertNull( $repository->get_content_hash( $page ) );
	}

	/** Adding post types that were not indexed before requires a Reindex. */
	public function test_save_post_types_requires_reindex_for_added_types(): void {
		Settings::update( array( 'post_types' => array( 'post' ) ) );

		$result = $this->run_ability( 'update-settings', array( 'post_types' => array( 'post', 'page' ) ) );

		$this->assertTrue( $result['reindex_required'] );
		$this->assertSame( array( 'page' ), $result['added_post_types'] );
	}

	/** Post types that are not public and searchable are rejected. */
	public function test_save_post_types_rejects_unavailable_type(): void {
		$this->assertWPError( $this->run_ability( 'update-settings', array( 'post_types' => array( 'nav_menu_item' ) ) ) );
		$this->assertSame( Settings::available_post_types(), Settings::get_post_types() );
	}

	/** Null resets the post types to all available ones, picking up post types registered later. */
	public function test_update_settings_null_resets_post_types(): void {
		Settings::update( array( 'post_types' => array( 'post' ) ) );

		$result = $this->run_ability( 'update-settings', array( 'post_types' => null ) );

		$this->assertSame( Settings::available_post_types(), $result['post_types'] );
		$this->assertContains( 'page', $result['added_post_types'] );
		$this->assertTrue( $result['reindex_required'] );
	}

	/** An invalid post type rejects the whole call, including the other settings. */
	public function test_update_settings_saves_nothing_on_invalid_post_types(): void {
		$result = $this->run_ability(
			'update-settings',
			array(
				'max_distance' => 0.4,
				'post_types'   => array( 'nav_menu_item' ),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( Settings::DEFAULT_THRESHOLDS['max_distance'], Settings::get_thresholds()['max_distance'] );
	}

	/** Saving other settings leaves the index rows alone. */
	public function test_update_settings_without_post_types_keeps_rows(): void {
		Settings::update( array( 'post_types' => array( 'post' ) ) );
		// A row outside the saved post types, e.g. left over from before.
		( new Repository() )->replace_post_chunks(
			999,
			'page',
			'h',
			array(
				array(
					'chunk_index' => 0,
					'chunk_text'  => 'A',
					'vector'      => array( 1.0, 0.0, 0.0, 0.0 ),
				),
			)
		);

		$this->run_ability( 'update-settings', array( 'max_distance' => 0.4 ) );

		$this->assertSame( 'h', ( new Repository() )->get_content_hash( 999 ) );
	}

	/** Deselecting post types removes their rows however the settings are written. */
	public function test_settings_update_removes_deselected_post_types(): void {
		$page = $this->create_indexed_post( 'page' );

		Settings::update( array( 'post_types' => array( 'post' ) ) );

		$this->assertNull( ( new Repository() )->get_content_hash( $page ) );
	}

	/** Each reason for a Reindex is reported when several settings change at once. */
	public function test_update_settings_reports_added_post_types_with_prefix_change(): void {
		Settings::update( array( 'post_types' => array( 'post' ) ) );

		$result = $this->run_ability(
			'update-settings',
			array(
				'post_types'      => array( 'post', 'page' ),
				'document_prefix' => 'passage: ',
			)
		);

		$this->assertSame( array( 'page' ), $result['added_post_types'] );
		$this->assertTrue( $result['reindex_required'] );
	}

	/** The ability is marked destructive, since it can delete index rows. */
	public function test_update_settings_is_annotated_destructive(): void {
		$annotations = wp_get_ability( 'squirrel-search/update-settings' )->get_meta_item( 'annotations' );

		$this->assertTrue( $annotations['destructive'] );
		$this->assertTrue( $annotations['idempotent'] );
		$this->assertFalse( $annotations['readonly'] );
	}

	/** At least one post type is required. */
	public function test_save_post_types_rejects_empty_list(): void {
		$this->assertWPError( $this->run_ability( 'update-settings', array( 'post_types' => array() ) ) );
	}

	/** Editors cannot change the post types. */
	public function test_save_post_types_requires_manage_options(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertWPError( $this->run_ability( 'update-settings', array( 'post_types' => array( 'post' ) ) ) );
	}
}
