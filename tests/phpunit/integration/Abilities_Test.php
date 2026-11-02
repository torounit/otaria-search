<?php
/**
 * Integration tests for Abilities API.
 *
 * @package WP_MariaDB_Vector_Search
 */

declare(strict_types=1);

namespace WP_MariaDB_Vector_Search\Tests\Integration;

use WP_MariaDB_Vector_Search\Settings;
use WP_UnitTestCase;

/**
 * Tests for the Abilities API implementation.
 */
class Abilities_Test extends WP_UnitTestCase {

	/**
	 * Test that the 'get-status' ability is registered and returns correct data.
	 */
	public function test_get_status_ability(): void {
		// Check if the ability is registered.
		$this->assertTrue( function_exists( 'wp_has_ability' ) );
		$this->assertTrue( wp_has_ability( 'wp-mariadb-vector-search/get-status' ) );

		// Set up a user with manage_options capability.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// Simulate a REST API request to the ability endpoint.
		$ability = wp_get_ability( 'wp-mariadb-vector-search/get-status' );
		$this->assertNotNull( $ability );

		// Execute the ability.
		$result = $ability->execute();

		// Verify the structure of the result (based on StatusResponse).
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'is_supported', $result );
		$this->assertArrayHasKey( 'installed', $result );
		$this->assertArrayHasKey( 'indexed', $result );
		$this->assertArrayHasKey( 'table_dims', $result );
		$this->assertArrayHasKey( 'progress', $result );
		$this->assertArrayHasKey( 'settings', $result );
		$this->assertArrayHasKey( 'available_models', $result );
		$this->assertArrayHasKey( 'dim_changed', $result );
	}

	/**
	 * Test that the 'reindex' ability is registered and can be executed.
	 */
	public function test_reindex_ability(): void {
		// Check if the ability is registered.
		$this->assertTrue( wp_has_ability( 'wp-mariadb-vector-search/reindex' ) );

		// Set up a user with manage_options capability.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$ability = wp_get_ability( 'wp-mariadb-vector-search/reindex' );
		$this->assertNotNull( $ability );

		// Execute the ability with some input.
		$input = array(
			'force'           => true,
			'confirm_rebuild' => true,
		);

		$result = $ability->execute( $input );

		// Verify the result.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'rebuilt', $result );
	}

	/**
	 * Run the 'select-model' ability as an administrator.
	 *
	 * @param array $input Ability input.
	 * @return mixed Ability result.
	 */
	private function select_model( array $input ): mixed {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		return wp_get_ability( 'wp-mariadb-vector-search/select-model' )->execute( $input );
	}

	/**
	 * Clean up settings and the fake provider.
	 */
	public function tear_down(): void {
		Fake_Embedding_Provider::reset();
		delete_option( Settings::OPTION_NAME );
		parent::tear_down();
	}

	/** Selecting a model probes it and saves the detected dimensions. */
	public function test_select_model_saves_probed_dimensions(): void {
		Fake_Embedding_Provider::$vector = array( 0.1, 0.2, 0.3 );

		$result = $this->select_model(
			array(
				'provider' => Fake_Embedding_Provider::ID,
				'model'    => Fake_Embedding_Provider::MODEL,
			)
		);

		$expected = array(
			'provider'   => Fake_Embedding_Provider::ID,
			'model'      => Fake_Embedding_Provider::MODEL,
			'dimensions' => 3,
		);
		$this->assertSame( $expected, $result );
		$this->assertSame( $expected, get_option( Settings::OPTION_NAME ) );
	}

	/** Input is sanitized before probing, and the stored settings are returned. */
	public function test_select_model_returns_sanitized_saved_settings(): void {
		$result = $this->select_model(
			array(
				'provider' => ' ' . Fake_Embedding_Provider::ID . ' ',
				'model'    => Fake_Embedding_Provider::MODEL . "\n",
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( Fake_Embedding_Provider::ID, $result['provider'] );
		$this->assertSame( Fake_Embedding_Provider::MODEL, $result['model'] );
		$this->assertSame( get_option( Settings::OPTION_NAME ), $result );
	}

	/** A failed probe returns the error and leaves the saved settings untouched. */
	public function test_select_model_keeps_settings_when_probe_fails(): void {
		$saved = array(
			'provider'   => 'openai',
			'model'      => 'text-embedding-3-small',
			'dimensions' => 1536,
		);
		update_option( Settings::OPTION_NAME, $saved );
		add_filter( 'wp_ai_client_prevent_embedding', '__return_true' );

		$result = $this->select_model(
			array(
				'provider' => Fake_Embedding_Provider::ID,
				'model'    => Fake_Embedding_Provider::MODEL,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( $saved, get_option( Settings::OPTION_NAME ) );
	}

	/** Only administrators may select a model. */
	public function test_select_model_requires_manage_options(): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'editor' ) ) );

		$result = wp_get_ability( 'wp-mariadb-vector-search/select-model' )->execute(
			array(
				'provider' => Fake_Embedding_Provider::ID,
				'model'    => Fake_Embedding_Provider::MODEL,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( array(), get_option( Settings::OPTION_NAME ) );
	}
}
