<?php
/**
 * Core's AI Client embedding API (WordPress 7.2+), for PHPStan only.
 *
 * Remove once php-stubs/wordpress-stubs covers 7.2.
 *
 * @package Otaria_Search
 */

namespace WordPress\AiClient\Results\DTO {

	final class Embedding implements \Countable {
		/**
		 * @return list<float|int>
		 */
		public function getValues(): array {}

		public function getDimensions(): int {}

		public function count(): int {}
	}

	class EmbeddingResult {
		public function getEmbedding(): Embedding {}

		public function getDimensions(): int {}
	}
}

namespace {

	use WordPress\AiClient\Results\DTO\Embedding;
	use WordPress\AiClient\Results\DTO\EmbeddingResult;

	/**
	 * @method self using_provider_model(string $providerIdOrClassName, string $modelId)
	 * @method EmbeddingResult|WP_Error generate_embedding_result()
	 * @method list<Embedding>|WP_Error generate_embeddings()
	 */
	class WP_AI_Client_Embedding_Builder {
		/**
		 * @param array<mixed> $arguments
		 * @return mixed
		 */
		public function __call( string $name, array $arguments ) {}
	}

	/**
	 * @param mixed ...$input
	 */
	function wp_ai_client_embedding( ...$input ): WP_AI_Client_Embedding_Builder {}
}
