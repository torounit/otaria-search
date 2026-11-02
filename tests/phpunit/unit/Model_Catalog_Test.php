<?php
/**
 * Unit tests for the Model_Catalog class.
 *
 * @package WP_MariaDB_Vector_Search
 */

declare(strict_types=1);

namespace WP_MariaDB_Vector_Search\Tests\Unit;

use WordPress\AiClient\Providers\DTO\ProviderModelsMetadata;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\ProviderRegistry;
use WP_MariaDB_Vector_Search\Model_Catalog;

/**
 * Class Model_Catalog_Test
 */
class Model_Catalog_Test extends \WP_UnitTestCase {

	/**
	 * Tear down registered filters.
	 */
	public function tear_down(): void {
		remove_all_filters( 'wp_mariadb_vector_search_known_embedding_models' );
		parent::tear_down();
	}

	/**
	 * Build a ProviderRegistry mock whose capability search returns the given models.
	 *
	 * @param array<int, array{string, string}> $models [provider_id, model_id] pairs.
	 * @return ProviderRegistry&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function make_registry( array $models = array() ): ProviderRegistry {
		$by_provider = array();
		foreach ( $models as [ $provider_id, $model_id ] ) {
			$by_provider[ $provider_id ][] = $model_id;
		}
		$candidates = array();
		foreach ( $by_provider as $provider_id => $model_ids ) {
			$provider_meta = new ProviderMetadata( $provider_id, $provider_id, ProviderTypeEnum::cloud() );
			$model_metas   = array_map(
				static fn( $m ) => new ModelMetadata( $m, $m, array( CapabilityEnum::embeddingGeneration() ), array() ),
				$model_ids
			);
			$candidates[]  = new ProviderModelsMetadata( $provider_meta, $model_metas );
		}

		$registry = $this->createMock( ProviderRegistry::class );
		$registry->method( 'findModelsMetadataForSupport' )->willReturn( $candidates );
		$registry->method( 'hasProvider' )->willReturn( true );
		$registry->method( 'isProviderConfigured' )->willReturn( true );

		return $registry;
	}

	/** Embedding-capable models from the registry appear in the result. */
	public function test_registry_models_are_returned(): void {
		$catalog = new Model_Catalog( $this->make_registry( array( array( 'lm_studio', 'nomic-embed-text-v1.5' ) ) ) );

		$models = $catalog->get_available_models();

		$this->assertCount( 1, $models );
		$this->assertSame( 'lm_studio', $models[0]['provider'] );
		$this->assertSame( 'nomic-embed-text-v1.5', $models[0]['model'] );
	}

	/** Multiple providers/models all appear. */
	public function test_multiple_registry_models_are_returned(): void {
		$catalog = new Model_Catalog(
			$this->make_registry(
				array(
					array( 'lm_studio', 'nomic-embed-text-v1.5' ),
					array( 'openai', 'text-embedding-3-small' ),
				)
			)
		);

		$this->assertCount( 2, $catalog->get_available_models() );
	}

	/**
	 * Known-list entries are not listed unless the registry reports them as
	 * embedding-capable: the core builder would reject them anyway.
	 */
	public function test_known_list_does_not_add_models(): void {
		$catalog = new Model_Catalog( $this->make_registry() );

		$this->assertSame( array(), $catalog->get_available_models() );
	}

	/** Known dimensions are applied to registry models. */
	public function test_known_dimensions_applied_to_registry_models(): void {
		$catalog = new Model_Catalog( $this->make_registry( array( array( 'openai', 'text-embedding-3-small' ) ) ) );

		$this->assertSame( 1536, $catalog->get_available_models()[0]['dimensions'] );
	}

	/** Unknown models report null dimensions. */
	public function test_unknown_model_has_null_dimensions(): void {
		$catalog = new Model_Catalog( $this->make_registry( array( array( 'lm_studio', 'nomic-embed-text-v1.5' ) ) ) );

		$this->assertNull( $catalog->get_available_models()[0]['dimensions'] );
	}

	/** The filter can add dimension hints for additional models. */
	public function test_filter_can_add_dimensions(): void {
		add_filter(
			'wp_mariadb_vector_search_known_embedding_models',
			static fn( array $known ) => array_merge(
				$known,
				array(
					array(
						'provider'   => 'lm_studio',
						'model'      => 'nomic-embed-text-v1.5',
						'dimensions' => 768,
					),
				)
			)
		);
		$catalog = new Model_Catalog( $this->make_registry( array( array( 'lm_studio', 'nomic-embed-text-v1.5' ) ) ) );

		$this->assertSame( 768, $catalog->get_available_models()[0]['dimensions'] );
	}

	/** Invalid entries in the known list are silently ignored. */
	public function test_invalid_known_list_entries_ignored(): void {
		add_filter(
			'wp_mariadb_vector_search_known_embedding_models',
			static fn() => array(
				'not-an-array',
				array(),
				array( 'provider' => '' ),
				array(
					'provider'   => 'openai',
					'model'      => 'text-embedding-3-small',
					'dimensions' => 'large',
				),
			)
		);
		$catalog = new Model_Catalog( $this->make_registry( array( array( 'openai', 'text-embedding-3-small' ) ) ) );

		$models = $catalog->get_available_models();

		$this->assertCount( 1, $models );
		$this->assertNull( $models[0]['dimensions'] );
	}
}
