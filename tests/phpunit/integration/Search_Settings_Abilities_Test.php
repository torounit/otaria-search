<?php
/**
 * Integration tests for the search settings abilities.
 *
 * Requires MariaDB 11.7+ with VECTOR support.
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
 * Class Search_Settings_Abilities_Test
 */
class Search_Settings_Abilities_Test extends \WP_UnitTestCase {

	/**
	 * IDs of posts created by the test.
	 *
	 * @var int[]
	 */
	private array $posts = array();

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
	 * Remove posts, settings and the schema.
	 */
	public function tear_down(): void {
		Fake_Embedding_Provider::reset();
		foreach ( $this->posts as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		// Schema::drop() commits the test transaction (DDL); clean up before it.
		delete_option( Settings::OPTION_NAME );

		Schema::drop();
		delete_option( Schema::DB_VERSION_OPTION );

		add_filter( 'query', array( $this, '_create_temporary_tables' ) );
		add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		parent::tear_down();
	}

	/**
	 * Create a published post indexed with the given embedding.
	 *
	 * @param string  $title  Post title.
	 * @param float[] $vector Embedding.
	 * @return int Post ID.
	 */
	private function create_indexed_post( string $title, array $vector ): int {
		Fake_Embedding_Provider::$vector = $vector;
		$post_id                         = self::factory()->post->create(
			array(
				'post_title'  => $title,
				'post_status' => 'publish',
			)
		);
		( new Indexer( new Repository() ) )->index_post( $post_id );
		Fake_Embedding_Provider::reset();

		$this->posts[] = $post_id;
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
		return wp_get_ability( "otaria-search/{$name}" )->execute( empty( $input ) ? null : $input );
	}

	/** The get-status ability reports the saved thresholds and the defaults. */
	public function test_get_status_reports_thresholds(): void {
		Settings::update( array( 'max_distance' => 0.4 ) );

		$status = $this->run_ability( 'get-status' );

		$this->assertSame(
			array(
				'max_distance'          => 0.4,
				'max_relative_distance' => 0.1,
			),
			$status['thresholds']
		);
		$this->assertSame( Settings::DEFAULT_THRESHOLDS, $status['default_thresholds'] );
	}

	/** The get-status ability reports whether hybrid search is on. */
	public function test_get_status_reports_hybrid(): void {
		$this->assertTrue( $this->run_ability( 'get-status' )['hybrid'] );

		Settings::update( array( 'hybrid' => false ) );

		$this->assertFalse( $this->run_ability( 'get-status' )['hybrid'] );
	}

	/** Saving thresholds stores the values and keeps the model settings. */
	public function test_save_thresholds_stores_values(): void {
		$result = $this->run_ability(
			'update-settings',
			array(
				'max_distance'          => 0.4,
				'max_relative_distance' => 0.1,
			)
		);

		$expected = array(
			'max_distance'          => 0.4,
			'max_relative_distance' => 0.1,
		);
		$this->assertSame( $expected, array_intersect_key( $result, $expected ) );
		$this->assertTrue( $result['hybrid'] );
		$this->assertFalse( $result['reindex_required'] );
		$this->assertSame( $expected, Settings::get_thresholds() );
		$this->assertSame( Fake_Embedding_Provider::MODEL, get_option( Settings::OPTION_NAME )['model'] );
	}

	/** Saving hybrid stores it and keeps the saved thresholds. */
	public function test_save_hybrid_keeps_thresholds(): void {
		Settings::update( array( 'max_distance' => 0.4 ) );

		$result = $this->run_ability( 'update-settings', array( 'hybrid' => false ) );

		$this->assertFalse( $result['hybrid'] );
		$this->assertFalse( Settings::is_hybrid() );
		$this->assertSame( 0.4, $result['max_distance'] );
	}

	/** Null resets hybrid search to its default, on. */
	public function test_update_settings_null_resets_hybrid(): void {
		Settings::update( array( 'hybrid' => false ) );

		$this->assertTrue( $this->run_ability( 'update-settings', array( 'hybrid' => null ) )['hybrid'] );
	}

	/** Null resets a threshold to its default. */
	public function test_save_thresholds_null_resets_to_default(): void {
		Settings::update( array( 'max_distance' => 0.4 ) );

		$result = $this->run_ability( 'update-settings', array( 'max_distance' => null ) );

		$this->assertSame( 0.65, $result['max_distance'] );
	}

	/** Values outside the cosine distance range are rejected by the input schema. */
	public function test_save_thresholds_rejects_out_of_range(): void {
		$this->assertWPError( $this->run_ability( 'update-settings', array( 'max_distance' => 3 ) ) );
	}

	/** The model cannot be changed here: select-model probes it first. */
	public function test_update_settings_rejects_model(): void {
		$this->assertWPError( $this->run_ability( 'update-settings', array( 'model' => 'another-model' ) ) );
		$this->assertSame( Fake_Embedding_Provider::MODEL, get_option( Settings::OPTION_NAME )['model'] );
	}

	/** Editors cannot change search settings. */
	public function test_save_search_settings_requires_manage_options(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertWPError( $this->run_ability( 'update-settings', array( 'max_distance' => 0.4 ) ) );
	}

	/** The search preview lists the nearest posts with distances and whether the thresholds keep them. */
	public function test_preview_search_marks_posts_kept_by_thresholds(): void {
		$best = $this->create_indexed_post( 'Best', array( 1.0, 0.0, 0.0, 0.0 ) );    // Distance 0.
		$near = $this->create_indexed_post( 'Near', array( 0.8, 0.6, 0.0, 0.0 ) );    // Distance 0.2.
		$far  = $this->create_indexed_post( 'Far', array( 0.0, 1.0, 0.0, 0.0 ) );     // Distance 1.

		$result = $this->run_ability(
			'preview-search',
			array(
				'query'                 => 'anything',
				'max_distance'          => 0.5,
				'max_relative_distance' => 0.3,
			)
		);

		$this->assertSame( array( $best, $near, $far ), array_column( $result['results'], 'post_id' ) );
		$this->assertSame( array( true, true, false ), array_column( $result['results'], 'included' ) );
		$this->assertSame( 'Near', $result['results'][1]['title'] );
		$this->assertEqualsWithDelta( 0.2, $result['results'][1]['distance'], 1e-6 );
	}

	/** Thresholds omitted from the preview input fall back to the saved values. */
	public function test_preview_search_uses_saved_thresholds_by_default(): void {
		$this->create_indexed_post( 'Best', array( 1.0, 0.0, 0.0, 0.0 ) );
		$this->create_indexed_post( 'Near', array( 0.8, 0.6, 0.0, 0.0 ) );
		// Near is 0.2 behind Best: dropped by the default (0.10), kept by the saved value.
		Settings::update( array( 'max_relative_distance' => 0.3 ) );

		$result = $this->run_ability( 'preview-search', array( 'query' => 'anything' ) );

		$this->assertSame( array( true, true ), array_column( $result['results'], 'included' ) );
	}

	/** An embedding failure is returned as an error. */
	public function test_preview_search_returns_embedding_error(): void {
		add_filter( 'wp_ai_client_prevent_embedding', '__return_true' );

		$this->assertWPError( $this->run_ability( 'preview-search', array( 'query' => 'anything' ) ) );
	}
}
