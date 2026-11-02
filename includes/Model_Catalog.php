<?php
/**
 * Embedding model catalog — enumerates available embedding models.
 *
 * @package WP_MariaDB_Vector_Search
 */

declare(strict_types=1);

namespace WP_MariaDB_Vector_Search;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Models\DTO\ModelRequirements;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\ProviderRegistry;

/**
 * Enumerates embedding models available for selection in the admin UI.
 *
 * Lists the models that registered, configured providers report as
 * embedding-capable. A filterable known list supplies their dimensions,
 * since providers do not expose them in model metadata.
 */
class Model_Catalog {

	/**
	 * Constructor.
	 *
	 * @param ProviderRegistry $registry Provider registry.
	 */
	public function __construct(
		private ProviderRegistry $registry,
	) {}

	/**
	 * Factory: create using the default AI Client registry.
	 *
	 * @return static
	 */
	public static function create(): static {
		return new static( AiClient::defaultRegistry() );
	}

	/**
	 * Return all available embedding models.
	 *
	 * Each element is an array with keys:
	 *  - provider   (string):   Provider id, e.g. "openai".
	 *  - model      (string):   Model id, e.g. "text-embedding-3-small".
	 *  - label      (string):   Human-readable label for the select option.
	 *  - dimensions (int|null): Known embedding dimensions, or null if unknown.
	 *
	 * @return array<int, array{provider: string, model: string, label: string, dimensions: int|null}>
	 */
	public function get_available_models(): array {
		$models = array(); // Keyed by "provider:model" for deduplication.

		$requirements = new ModelRequirements(
			array( CapabilityEnum::embeddingGeneration() ),
			array()
		);
		$candidates   = $this->registry->findModelsMetadataForSupport( $requirements );
		foreach ( $candidates as $pmc ) {
			$provider_id = $pmc->getProvider()->getId();
			foreach ( $pmc->getModels() as $model_metadata ) {
				$model_id         = $model_metadata->getId();
				$key              = $provider_id . ':' . $model_id;
				$models[ $key ] ??= array(
					'provider' => $provider_id,
					'model'    => $model_id,
					'label'    => '' !== $model_metadata->getName() ? $model_metadata->getName() : $model_id,
				);
			}
		}

		// Known dimensions, filterable via wp_mariadb_vector_search_known_embedding_models.
		$default_known = array(
			array(
				'provider'   => 'openai',
				'model'      => 'text-embedding-3-small',
				'dimensions' => 1536,
			),
			array(
				'provider'   => 'openai',
				'model'      => 'text-embedding-3-large',
				'dimensions' => 3072,
			),
			array(
				'provider'   => 'openai',
				'model'      => 'text-embedding-ada-002',
				'dimensions' => 1536,
			),
			array(
				'provider'   => 'google',
				'model'      => 'text-embedding-004',
				'dimensions' => 768,
			),
		);

		/**
		 * Filter the known embedding model dimensions.
		 *
		 * Each element has 'provider' (string), 'model' (string) and
		 * 'dimensions' (int) keys. Entries only supply dimensions for models
		 * the registry reports; they never add models to the list.
		 *
		 * @param array<int, array{provider: string, model: string, dimensions?: int}> $known_list Default known models.
		 */
		$known_list = apply_filters( 'wp_mariadb_vector_search_known_embedding_models', $default_known );

		// Build a provider:model => dimensions lookup.
		$known_dims = array();
		foreach ( (array) $known_list as $item ) {
			if ( is_array( $item ) && isset( $item['provider'], $item['model'], $item['dimensions'] )
				&& is_int( $item['dimensions'] ) ) {
				$known_dims[ $item['provider'] . ':' . $item['model'] ] = $item['dimensions'];
			}
		}

		$result = array();
		foreach ( $models as $key => $m ) {
			$result[] = array(
				'provider'   => $m['provider'],
				'model'      => $m['model'],
				'label'      => $m['label'],
				'dimensions' => $known_dims[ $key ] ?? null,
			);
		}
		return $result;
	}
}
