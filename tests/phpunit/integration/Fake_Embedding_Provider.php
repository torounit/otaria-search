<?php
/**
 * In-memory AI provider for tests.
 *
 * @package WP_MariaDB_Vector_Search
 */

declare(strict_types=1);

namespace WP_MariaDB_Vector_Search\Tests\Integration;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\Contracts\ProviderInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WP_MariaDB_Vector_Search\Settings;

/**
 * AI provider with a single embedding model that returns a fixed vector.
 *
 * Registered in the AI Client default registry by the test bootstrap, so
 * production code runs through the real core embedding builder without any
 * HTTP calls. Tests opt in with select() and control the output via $vector.
 */
class Fake_Embedding_Provider implements ProviderInterface {

	const ID             = 'fake';
	const MODEL          = 'fake-embedding';
	const DEFAULT_VECTOR = array( 1.0, 0.0, 0.0, 0.0 );

	/**
	 * Vector returned for every input.
	 *
	 * @var float[]
	 */
	public static array $vector = self::DEFAULT_VECTOR;

	/**
	 * Register this provider in the AI Client default registry.
	 *
	 * @return void
	 */
	public static function register(): void {
		$registry = AiClient::defaultRegistry();
		if ( ! $registry->hasProvider( self::ID ) ) {
			$registry->registerProvider( self::class );
		}
	}

	/**
	 * Select the fake model in plugin settings.
	 *
	 * @return void
	 */
	public static function select(): void {
		update_option(
			Settings::OPTION_NAME,
			array(
				'provider' => self::ID,
				'model'    => self::MODEL,
			)
		);
	}

	/**
	 * Restore the default vector.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$vector = self::DEFAULT_VECTOR;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return ProviderMetadata
	 */
	public static function metadata(): ProviderMetadata {
		return new ProviderMetadata( self::ID, 'Fake', ProviderTypeEnum::cloud() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string           $model_id     Model id (only self::MODEL exists).
	 * @param ModelConfig|null $model_config Optional model config.
	 * @return ModelInterface
	 */
	public static function model( string $model_id, ?ModelConfig $model_config = null ): ModelInterface {
		$model = new Fake_Embedding_Model( self::model_metadata(), self::metadata() );
		if ( null !== $model_config ) {
			$model->setConfig( $model_config );
		}
		return $model;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return ProviderAvailabilityInterface
	 */
	public static function availability(): ProviderAvailabilityInterface {
		return new class() implements ProviderAvailabilityInterface {
			/** {@inheritDoc} */
			public function isConfigured(): bool {
				return true;
			}
		};
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return ModelMetadataDirectoryInterface
	 */
	public static function modelMetadataDirectory(): ModelMetadataDirectoryInterface {
		return new class() implements ModelMetadataDirectoryInterface {
			/** {@inheritDoc} */
			public function listModelMetadata(): array {
				return array( Fake_Embedding_Provider::model_metadata() );
			}

			/**
			 * {@inheritDoc}
			 *
			 * @param string $model_id Model id.
			 */
			public function hasModelMetadata( string $model_id ): bool {
				return Fake_Embedding_Provider::MODEL === $model_id;
			}

			/**
			 * {@inheritDoc}
			 *
			 * @param string $model_id Model id.
			 */
			public function getModelMetadata( string $model_id ): ModelMetadata {
				return Fake_Embedding_Provider::model_metadata();
			}
		};
	}

	/**
	 * Metadata of the single embedding model.
	 *
	 * @return ModelMetadata
	 */
	public static function model_metadata(): ModelMetadata {
		return new ModelMetadata(
			self::MODEL,
			'Fake Embedding',
			array( CapabilityEnum::embeddingGeneration() ),
			array( new SupportedOption( OptionEnum::inputModalities() ) )
		);
	}
}
