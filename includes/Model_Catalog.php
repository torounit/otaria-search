<?php
/**
 * Embedding model catalog — enumerates available embedding models.
 *
 * @package Squirrel_Search
 */

declare(strict_types=1);

namespace Squirrel_Search;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Models\DTO\ModelRequirements;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\ProviderRegistry;

/**
 * Enumerates embedding models available for selection in the admin UI.
 *
 * Lists the models that registered, configured providers report as
 * embedding-capable. Dimensions are not listed: they are detected by probing
 * the model when it is selected (see Abilities::execute_select_model()).
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
	 *  - provider (string): Provider id, e.g. "openai".
	 *  - model    (string): Model id, e.g. "text-embedding-3-small".
	 *  - label    (string): Human-readable label for the select option.
	 *
	 * @return array<int, array{provider: string, model: string, label: string}>
	 */
	public function get_available_models(): array {
		$requirements = new ModelRequirements(
			array( CapabilityEnum::embeddingGeneration() ),
			array()
		);

		$models = array();
		foreach ( $this->registry->findModelsMetadataForSupport( $requirements ) as $pmc ) {
			$provider_id = $pmc->getProvider()->getId();
			foreach ( $pmc->getModels() as $model_metadata ) {
				$models[] = array(
					'provider' => $provider_id,
					'model'    => $model_metadata->getId(),
					'label'    => '' !== $model_metadata->getName() ? $model_metadata->getName() : $model_metadata->getId(),
				);
			}
		}

		return $models;
	}
}
