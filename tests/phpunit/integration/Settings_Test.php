<?php
/**
 * Integration tests for Settings API.
 *
 * @package Otaria_Search
 */

declare(strict_types=1);

namespace Otaria_Search\Tests\Integration;

use Otaria_Search\Settings;
use WP_UnitTestCase;

/**
 * Tests for the Settings API implementation.
 */
class Settings_Test extends WP_UnitTestCase {

	const SETTINGS_OPTION = Settings::OPTION_NAME;

	/**
	 * Ensure each test starts with no stored settings.
	 */
	public function set_up(): void {
		parent::set_up();
		delete_option( self::SETTINGS_OPTION );
	}

	/**
	 * Clean up stored settings after each test.
	 */
	public function tear_down(): void {
		delete_option( self::SETTINGS_OPTION );
		parent::tear_down();
	}

	/**
	 * Test that settings can be saved and retrieved.
	 */
	public function test_settings_save_and_get(): void {
		$settings = array(
			'provider'   => 'openai',
			'model'      => 'text-embedding-3-small',
			'dimensions' => 1536,
		);

		update_option( self::SETTINGS_OPTION, $settings );

		$retrieved = get_option( self::SETTINGS_OPTION );
		$this->assertEquals( $settings, $retrieved );
	}

	/**
	 * Non-numeric dimensions are rejected; unknown keys are not passed through.
	 */
	public function test_settings_sanitize_rejects_non_numeric_dimensions(): void {
		$result = Settings::sanitize_settings(
			array(
				'provider'   => 'openai',
				'model'      => 'text-embedding-3-small',
				'dimensions' => 'not-a-number',
				'unknown'    => 'should-be-dropped',
			)
		);

		$this->assertSame( 'openai', $result['provider'] );
		$this->assertSame( 'text-embedding-3-small', $result['model'] );
		$this->assertArrayNotHasKey( 'dimensions', $result );
		$this->assertArrayNotHasKey( 'unknown', $result );
	}

	/**
	 * Thresholds are stored as floats: a cosine distance (0–2) and a fraction (0–1).
	 */
	public function test_settings_sanitize_accepts_distance_thresholds(): void {
		$result = Settings::sanitize_settings(
			array(
				'max_distance'    => '0.4',
				'relative_cutoff' => '0.5',
			)
		);

		$this->assertSame( 0.4, $result['max_distance'] );
		$this->assertSame( 0.5, $result['relative_cutoff'] );
	}

	/**
	 * Thresholds out of range or non-numeric are rejected.
	 */
	public function test_settings_sanitize_rejects_invalid_distance_thresholds(): void {
		$this->assertSame( array(), Settings::sanitize_settings( array( 'max_distance' => 2.5 ) ) );
		$this->assertSame( array(), Settings::sanitize_settings( array( 'relative_cutoff' => 'wide' ) ) );
		$this->assertSame( array(), Settings::sanitize_settings( array( 'relative_cutoff' => -0.1 ) ) );
		$this->assertSame( array(), Settings::sanitize_settings( array( 'relative_cutoff' => 1.5 ) ) );
	}

	/**
	 * The former max_relative_distance (a difference, measured differently) is dropped.
	 */
	public function test_settings_sanitize_drops_max_relative_distance(): void {
		$this->assertSame( array(), Settings::sanitize_settings( array( 'max_relative_distance' => 0.25 ) ) );
	}

	/**
	 * Password-protected posts are excluded from the index unless the setting says otherwise.
	 */
	public function test_excludes_password_protected_by_default(): void {
		$this->assertTrue( Settings::excludes_password_protected() );

		update_option( self::SETTINGS_OPTION, array( 'exclude_password_protected' => false ) );

		$this->assertFalse( Settings::excludes_password_protected() );
	}

	/**
	 * The exclusion flag is stored as a boolean.
	 */
	public function test_settings_sanitize_stores_exclude_password_protected_as_bool(): void {
		$this->assertFalse( Settings::sanitize_settings( array( 'exclude_password_protected' => '0' ) )['exclude_password_protected'] );
	}

	/**
	 * Hybrid is stored as a boolean only.
	 */
	public function test_settings_sanitize_stores_hybrid_as_bool(): void {
		$this->assertFalse( Settings::sanitize_settings( array( 'hybrid' => false ) )['hybrid'] );
		$this->assertArrayNotHasKey( 'hybrid', Settings::sanitize_settings( array( 'hybrid' => 'no' ) ) );
	}

	/**
	 * Hybrid search is on unless it has been turned off.
	 */
	public function test_is_hybrid_defaults_to_true(): void {
		$this->assertTrue( Settings::is_hybrid() );

		update_option( self::SETTINGS_OPTION, array( 'hybrid' => false ) );

		$this->assertFalse( Settings::is_hybrid() );
	}

	/**
	 * Without a saved list, all public, searchable post types are indexed.
	 */
	public function test_get_post_types_defaults_to_available_types(): void {
		$this->assertSame( Settings::available_post_types(), Settings::get_post_types() );
		$this->assertContains( 'post', Settings::get_post_types() );
		$this->assertContains( 'page', Settings::get_post_types() );
		$this->assertNotContains( 'nav_menu_item', Settings::get_post_types() );
	}

	/**
	 * Attachments are never published, so they cannot be indexed.
	 */
	public function test_available_post_types_exclude_attachments(): void {
		$this->assertNotContains( 'attachment', Settings::available_post_types() );
	}

	/**
	 * A saved list is used, minus post types that are no longer available.
	 */
	public function test_get_post_types_uses_saved_available_types(): void {
		update_option( self::SETTINGS_OPTION, array( 'post_types' => array( 'page', 'unregistered' ) ) );

		$this->assertSame( array( 'page' ), Settings::get_post_types() );
	}

	/**
	 * Post types are stored as a list of unique keys.
	 */
	public function test_settings_sanitize_stores_post_types_as_keys(): void {
		$this->assertSame(
			array( 'post', 'page' ),
			Settings::sanitize_settings( array( 'post_types' => array( 'post', 'Page', 'post' ) ) )['post_types']
		);
		$this->assertArrayNotHasKey( 'post_types', Settings::sanitize_settings( array( 'post_types' => 'post' ) ) );
	}

	/**
	 * Prefixes keep their spaces; control characters such as line breaks are removed.
	 */
	public function test_settings_sanitize_keeps_prefix_spaces(): void {
		$result = Settings::sanitize_settings(
			array(
				'query_prefix'    => 'query: ',
				'document_prefix' => "passage:\n ",
			)
		);

		$this->assertSame( 'query: ', $result['query_prefix'] );
		$this->assertSame( 'passage: ', $result['document_prefix'] );
		$this->assertArrayNotHasKey( 'query_prefix', Settings::sanitize_settings( array( 'query_prefix' => '' ) ) );
	}

	/**
	 * The embedding key is "provider:model", plus the document prefix when one is set.
	 */
	public function test_get_embedding_key(): void {
		Settings::update(
			array(
				'provider' => 'openai',
				'model'    => 'text-embedding-3-small',
			)
		);
		$this->assertSame( 'openai:text-embedding-3-small', Settings::get_embedding_key() );

		Settings::update( array( 'document_prefix' => 'passage: ' ) );
		$this->assertSame( "openai:text-embedding-3-small\x00passage: ", Settings::get_embedding_key() );
	}

	/**
	 * Prefixes are empty until saved.
	 */
	public function test_get_prefixes_defaults_to_empty(): void {
		$this->assertSame(
			array(
				'query_prefix'    => '',
				'document_prefix' => '',
			),
			Settings::get_prefixes()
		);
	}

	/**
	 * Sanitizing validates the given settings only; it does not fill in saved ones.
	 */
	public function test_settings_sanitize_does_not_read_saved_settings(): void {
		update_option( self::SETTINGS_OPTION, array( 'provider' => 'openai' ) );

		$this->assertSame( array( 'model' => 'm' ), Settings::sanitize_settings( array( 'model' => 'm' ) ) );
	}

	/**
	 * Updating changes the given keys and keeps the others.
	 */
	public function test_update_keeps_other_keys(): void {
		update_option(
			self::SETTINGS_OPTION,
			array(
				'provider'   => 'openai',
				'model'      => 'text-embedding-3-small',
				'dimensions' => 1536,
			)
		);

		Settings::update( array( 'dimensions' => 768 ) );

		$this->assertSame(
			array(
				'provider'   => 'openai',
				'model'      => 'text-embedding-3-small',
				'dimensions' => 768,
			),
			get_option( self::SETTINGS_OPTION )
		);
	}

	/**
	 * Null removes a key, restoring its default.
	 */
	public function test_update_null_removes_key(): void {
		Settings::update(
			array(
				'max_distance'    => 0.4,
				'relative_cutoff' => 0.5,
			)
		);

		Settings::update( array( 'relative_cutoff' => null ) );

		$this->assertSame( array( 'max_distance' => 0.4 ), get_option( self::SETTINGS_OPTION ) );
	}

	/**
	 * Updated values are sanitized; invalid ones are not stored.
	 */
	public function test_update_sanitizes_values(): void {
		Settings::update(
			array(
				'max_distance' => 3,
				'query_prefix' => "query:\n ",
			)
		);

		$this->assertSame( array( 'query_prefix' => 'query: ' ), get_option( self::SETTINGS_OPTION ) );
	}
}
