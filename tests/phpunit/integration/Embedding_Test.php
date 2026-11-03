<?php
/**
 * Integration tests for otaria_search_embedding().
 *
 * Runs through the real core embedding builder against Fake_Embedding_Provider.
 *
 * @package Otaria_Search
 */

declare(strict_types=1);

namespace Otaria_Search\Tests\Integration;

use WordPress\AiClient\Results\DTO\Embedding;
use Otaria_Search\Settings;

/**
 * Class Embedding_Test
 */
class Embedding_Test extends \WP_UnitTestCase {

	/**
	 * Select the fake model.
	 */
	public function set_up(): void {
		parent::set_up();
		Fake_Embedding_Provider::select();
	}

	/**
	 * Restore defaults.
	 */
	public function tear_down(): void {
		Fake_Embedding_Provider::reset();
		parent::tear_down();
	}

	/** Returns the core embedding builder. */
	public function test_returns_core_builder(): void {
		$this->assertInstanceOf( \WP_AI_Client_Embedding_Builder::class, otaria_search_embedding( 'hello' ) );
	}

	/** Uses the model selected in settings. */
	public function test_uses_selected_model(): void {
		Fake_Embedding_Provider::$vector = array( 0.1, 0.2, 0.3 );

		$result = otaria_search_embedding( 'hello' )->generate_embeddings();

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result );
		$this->assertInstanceOf( Embedding::class, $result[0] );
		$this->assertSame( array( 0.1, 0.2, 0.3 ), $result[0]->getValues() );
	}

	/** A list of texts produces one embedding per text. */
	public function test_accepts_list_of_texts(): void {
		$result = otaria_search_embedding( array( 'hello', 'world' ) )->generate_embeddings();

		$this->assertCount( 2, $result );
	}

	/** Without a selected model, core returns a WP_Error instead of guessing one. */
	public function test_returns_wp_error_without_selected_model(): void {
		delete_option( Settings::OPTION_NAME );

		$this->assertWPError( otaria_search_embedding( 'hello' )->generate_embeddings() );
	}

	/** Core's wp_ai_client_prevent_embedding filter is honored. */
	public function test_respects_core_prevent_filter(): void {
		add_filter( 'wp_ai_client_prevent_embedding', '__return_true' );

		$result = otaria_search_embedding( 'hello' )->generate_embeddings();

		$this->assertWPError( $result );
		$this->assertSame( 'embedding_prevented', $result->get_error_code() );
	}
}
