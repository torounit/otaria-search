<?php
/**
 * Integration tests for the index settings abilities.
 *
 * @package WP_MariaDB_Vector_Search
 */

declare(strict_types=1);

namespace WP_MariaDB_Vector_Search\Tests\Integration;

use WP_MariaDB_Vector_Search\Settings;

/**
 * Class Index_Settings_Abilities_Test
 */
class Index_Settings_Abilities_Test extends \WP_UnitTestCase {

	/**
	 * Log in as an administrator with no saved settings.
	 */
	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION_NAME );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
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

	/** The get-status ability reports whether password-protected posts are excluded. */
	public function test_get_status_reports_exclude_password_protected(): void {
		$this->assertTrue( $this->run_ability( 'get-status' )['exclude_password_protected'] );
	}

	/** Saving the index settings stores the flag and keeps the model settings. */
	public function test_save_index_settings_stores_flag(): void {
		update_option( Settings::OPTION_NAME, array( 'model' => 'm' ) );

		$result = $this->run_ability( 'update-settings', array( 'exclude_password_protected' => false ) );

		$this->assertFalse( $result['exclude_password_protected'] );
		$this->assertTrue( $result['reindex_required'] );
		$this->assertFalse( Settings::excludes_password_protected() );
		$this->assertSame( 'm', get_option( Settings::OPTION_NAME )['model'] );
	}

	/** Editors cannot change the index settings. */
	public function test_save_index_settings_requires_manage_options(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertWPError( $this->run_ability( 'update-settings', array( 'exclude_password_protected' => false ) ) );
	}
}
