<?php
/**
 * In-memory embedding model for tests.
 *
 * @package WP_MariaDB_Vector_Search
 */

declare(strict_types=1);

namespace WP_MariaDB_Vector_Search\Tests\Integration;

use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\EmbeddingGeneration\Contracts\EmbeddingGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Embedding;
use WordPress\AiClient\Results\DTO\EmbeddingResult;
use WordPress\AiClient\Results\DTO\TokenUsage;

/**
 * Returns Fake_Embedding_Provider::$vector for every input.
 */
class Fake_Embedding_Model implements ModelInterface, EmbeddingGenerationModelInterface {

	/**
	 * Model config.
	 *
	 * @var ModelConfig
	 */
	private ModelConfig $config;

	/**
	 * Constructor.
	 *
	 * @param ModelMetadata    $metadata          Model metadata.
	 * @param ProviderMetadata $provider_metadata Provider metadata.
	 */
	public function __construct(
		private ModelMetadata $metadata,
		private ProviderMetadata $provider_metadata,
	) {
		$this->config = new ModelConfig();
	}

	/** {@inheritDoc} */
	public function metadata(): ModelMetadata {
		return $this->metadata;
	}

	/** {@inheritDoc} */
	public function providerMetadata(): ProviderMetadata {
		return $this->provider_metadata;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param ModelConfig $config Model config.
	 */
	public function setConfig( ModelConfig $config ): void {
		$this->config = $config;
	}

	/** {@inheritDoc} */
	public function getConfig(): ModelConfig {
		return $this->config;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $inputs Message parts to embed.
	 */
	public function generateEmbeddingResult( array $inputs ): EmbeddingResult {
		$vector     = Fake_Embedding_Provider::$vector;
		$dimensions = count( $vector );
		$embeddings = array_map( static fn() => new Embedding( $vector, $dimensions ), $inputs );

		return new EmbeddingResult(
			uniqid( 'fake_', true ),
			$embeddings,
			$dimensions,
			new TokenUsage( 0, 0, 0 ),
			$this->provider_metadata,
			$this->metadata
		);
	}
}
