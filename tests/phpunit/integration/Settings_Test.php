<?php
/**
 * Integration tests for Settings API.
 *
 * @package WP_MariaDB_Vector_Search
 */

declare(strict_types=1);

namespace WP_MariaDB_Vector_Search\Tests\Integration;

use WP_MariaDB_Vector_Search\Settings;
use WP_UnitTestCase;

/**
 * Tests for the Settings API implementation.
 */
class Settings_Test extends WP_UnitTestCase {

	const SETTINGS_OPTION = 'wp_mariadb_vector_search_settings';

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
	 * Distance thresholds are stored as floats within the cosine distance range.
	 */
	public function test_settings_sanitize_accepts_distance_thresholds(): void {
		$result = Settings::sanitize_settings(
			array(
				'max_distance'          => '0.4',
				'max_relative_distance' => 0.1,
			)
		);

		$this->assertSame( 0.4, $result['max_distance'] );
		$this->assertSame( 0.1, $result['max_relative_distance'] );
	}

	/**
	 * Thresholds outside 0–2 or non-numeric are rejected.
	 */
	public function test_settings_sanitize_rejects_invalid_distance_thresholds(): void {
		$result = Settings::sanitize_settings(
			array(
				'max_distance'          => 2.5,
				'max_relative_distance' => 'wide',
			)
		);

		$this->assertArrayNotHasKey( 'max_distance', $result );
		$this->assertArrayNotHasKey( 'max_relative_distance', $result );
	}

	/**
	 * Omitted thresholds keep their saved values; null clears them back to the default.
	 */
	public function test_settings_sanitize_preserves_or_clears_distance_thresholds(): void {
		update_option(
			self::SETTINGS_OPTION,
			array(
				'max_distance'          => 0.4,
				'max_relative_distance' => 0.1,
			)
		);

		$result = Settings::sanitize_settings( array( 'max_relative_distance' => null ) );

		$this->assertSame( 0.4, $result['max_distance'] );
		$this->assertArrayNotHasKey( 'max_relative_distance', $result );
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
	 * The exclusion flag is stored as a boolean and kept when omitted.
	 */
	public function test_settings_sanitize_stores_or_preserves_exclude_password_protected(): void {
		$this->assertFalse( Settings::sanitize_settings( array( 'exclude_password_protected' => '0' ) )['exclude_password_protected'] );

		update_option( self::SETTINGS_OPTION, array( 'exclude_password_protected' => false ) );

		$this->assertFalse( Settings::sanitize_settings( array( 'model' => 'm' ) )['exclude_password_protected'] );
	}

	/**
	 * Partial updates preserve existing provider, model, and dimensions.
	 */
	public function test_settings_sanitize_preserves_existing_on_partial_update(): void {
		update_option(
			self::SETTINGS_OPTION,
			array(
				'provider'   => 'openai',
				'model'      => 'text-embedding-3-small',
				'dimensions' => 1536,
			)
		);

		// Only dimensions is in the payload; provider and model should be preserved.
		$result = Settings::sanitize_settings( array( 'dimensions' => 768 ) );

		$this->assertSame( 'openai', $result['provider'] );
		$this->assertSame( 'text-embedding-3-small', $result['model'] );
		$this->assertSame( 768, $result['dimensions'] );
	}
}
