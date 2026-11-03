<?php
/**
 * Integration tests for the embedding prefix ability.
 *
 * Requires MariaDB 11.7+ with VECTOR support.
 *
 * @package WP_MariaDB_Vector_Search
 */

declare(strict_types=1);

namespace WP_MariaDB_Vector_Search\Tests\Integration;

use WP_MariaDB_Vector_Search\Schema;
use WP_MariaDB_Vector_Search\Settings;

/**
 * Class Prefix_Abilities_Test
 */
class Prefix_Abilities_Test extends \WP_UnitTestCase {

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
	 * Run an ability.
	 *
	 * @param string $name  Ability name without the plugin prefix.
	 * @param array  $input Ability input.
	 * @return mixed
	 */
	private function run_ability( string $name, array $input = array() ): mixed {
		return wp_get_ability( "wp-mariadb-vector-search/{$name}" )->execute( empty( $input ) ? null : $input );
	}

	/** The get-status ability reports the saved prefixes. */
	public function test_get_status_reports_prefixes(): void {
		update_option( Settings::OPTION_NAME, array( 'query_prefix' => 'query: ' ) );

		$this->assertSame(
			array(
				'query_prefix'    => 'query: ',
				'document_prefix' => '',
			),
			$this->run_ability( 'get-status' )['prefixes']
		);
	}

	/** Saving prefixes stores them and keeps the model settings. */
	public function test_save_prefixes_stores_values(): void {
		$result = $this->run_ability(
			'save-prefixes',
			array(
				'query_prefix'    => 'query: ',
				'document_prefix' => 'passage: ',
			)
		);

		$this->assertSame( 'query: ', $result['query_prefix'] );
		$this->assertSame( 'passage: ', $result['document_prefix'] );
		$this->assertSame( $result['query_prefix'], Settings::get_prefixes()['query_prefix'] );
		$this->assertSame( Fake_Embedding_Provider::MODEL, get_option( Settings::OPTION_NAME )['model'] );
	}

	/** A changed document prefix is reported, since posts must be re-embedded. */
	public function test_save_prefixes_reports_document_prefix_change(): void {
		$this->assertTrue( $this->run_ability( 'save-prefixes', array( 'document_prefix' => 'passage: ' ) )['document_changed'] );
		$this->assertFalse( $this->run_ability( 'save-prefixes', array( 'query_prefix' => 'query: ' ) )['document_changed'] );
	}

	/** Editors cannot change the prefixes. */
	public function test_save_prefixes_requires_manage_options(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertWPError( $this->run_ability( 'save-prefixes', array( 'query_prefix' => 'query: ' ) ) );
	}

	/** The search preview embeds the query with the query prefix. */
	public function test_preview_search_embeds_query_with_prefix(): void {
		update_option( Settings::OPTION_NAME, array( 'query_prefix' => 'query: ' ) );

		$this->run_ability( 'preview-search', array( 'query' => 'cats' ) );

		$this->assertSame( array( 'query: cats' ), Fake_Embedding_Provider::$inputs );
	}
}
