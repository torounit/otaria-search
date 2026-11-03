<?php
/**
 * Unit tests for the Model_Catalog class.
 *
 * @package Squirrel_Search
 */

declare(strict_types=1);

namespace Squirrel_Search\Tests\Unit;

use WordPress\AiClient\Providers\DTO\ProviderModelsMetadata;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\ProviderRegistry;
use Squirrel_Search\Model_Catalog;

/**
 * Class Model_Catalog_Test
 */
class Model_Catalog_Test extends \WP_UnitTestCase {

	/**
	 * Build a ProviderRegistry mock whose capability search returns the given models.
	 *
	 * @param array<int, array{string, string, string}> $models [provider_id, model_id, model_name] triples.
	 * @return ProviderRegistry&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function make_registry( array $models = array() ): ProviderRegistry {
		$by_provider = array();
		foreach ( $models as [ $provider_id, $model_id, $model_name ] ) {
			$by_provider[ $provider_id ][] = new ModelMetadata( $model_id, $model_name, array( CapabilityEnum::embeddingGeneration() ), array() );
		}
		$candidates = array();
		foreach ( $by_provider as $provider_id => $model_metas ) {
			$provider_meta = new ProviderMetadata( $provider_id, $provider_id, ProviderTypeEnum::cloud() );
			$candidates[]  = new ProviderModelsMetadata( $provider_meta, $model_metas );
		}

		$registry = $this->createMock( ProviderRegistry::class );
		$registry->method( 'findModelsMetadataForSupport' )->willReturn( $candidates );

		return $registry;
	}

	/** Embedding-capable models from the registry are listed with their name as label. */
	public function test_registry_models_are_returned(): void {
		$catalog = new Model_Catalog(
			$this->make_registry( array( array( 'openai', 'text-embedding-3-small', 'Text Embedding 3 Small' ) ) )
		);

		$this->assertSame(
			array(
				array(
					'provider' => 'openai',
					'model'    => 'text-embedding-3-small',
					'label'    => 'Text Embedding 3 Small',
				),
			),
			$catalog->get_available_models()
		);
	}

	/** Multiple providers/models all appear. */
	public function test_multiple_registry_models_are_returned(): void {
		$catalog = new Model_Catalog(
			$this->make_registry(
				array(
					array( 'lm_studio', 'text-embedding-nomic-embed-text-v1.5', 'text-embedding-nomic-embed-text-v1.5' ),
					array( 'openai', 'text-embedding-3-small', 'Text Embedding 3 Small' ),
					array( 'openai', 'text-embedding-3-large', 'Text Embedding 3 Large' ),
				)
			)
		);

		$this->assertCount( 3, $catalog->get_available_models() );
	}

	/** An empty model name falls back to the model id. */
	public function test_label_falls_back_to_model_id(): void {
		$catalog = new Model_Catalog( $this->make_registry( array( array( 'lm_studio', 'nomic', '' ) ) ) );

		$this->assertSame( 'nomic', $catalog->get_available_models()[0]['label'] );
	}

	/** No embedding-capable provider → empty list. */
	public function test_empty_when_no_models(): void {
		$catalog = new Model_Catalog( $this->make_registry() );

		$this->assertSame( array(), $catalog->get_available_models() );
	}
}
