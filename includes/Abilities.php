<?php
/**
 * Abilities registration for the plugin.
 *
 * @package Otaria_Search
 */

declare(strict_types=1);

namespace Otaria_Search;

/**
 * Handles registration of Abilities and Categories.
 */
class Abilities {

	const CATEGORY_SLUG      = 'otaria-search';
	const ABILITY_GET_STATUS = 'otaria-search/get-status';
	const ABILITY_REINDEX    = 'otaria-search/reindex';

	/**
	 * Ability that probes a model's dimensions and saves it as the embedding model.
	 *
	 * @since 0.2.0
	 */
	const ABILITY_SELECT = 'otaria-search/select-model';

	/**
	 * Ability that changes any settings except the model: search thresholds,
	 * hybrid search, post types, prefixes and the password-protected exclusion.
	 *
	 * @since 0.3.0
	 */
	const ABILITY_UPDATE_SETTINGS = 'otaria-search/update-settings';

	/**
	 * Ability that lists the posts nearest to a query with their distances.
	 *
	 * @since 0.2.0
	 */
	const ABILITY_PREVIEW = 'otaria-search/preview-search';

	/**
	 * Number of posts listed by the search preview.
	 *
	 * @since 0.2.0
	 */
	const PREVIEW_LIMIT = 20;

	/**
	 * Register abilities and categories.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_categories' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
	}

	/**
	 * Register ability categories.
	 *
	 * @return void
	 */
	public static function register_categories(): void {
		wp_register_ability_category(
			self::CATEGORY_SLUG,
			array(
				'label'       => __( 'Otaria Search', 'otaria-search' ),
				'description' => __( 'Abilities for Otaria Search.', 'otaria-search' ),
			)
		);
	}

	/**
	 * Register abilities.
	 *
	 * @return void
	 */
	public static function register_abilities(): void {
		// Register get-status ability.
		wp_register_ability(
			self::ABILITY_GET_STATUS,
			array(
				'label'               => __( 'Get Status', 'otaria-search' ),
				'description'         => __( 'Retrieves the current plugin status.', 'otaria-search' ),
				'category'            => self::CATEGORY_SLUG,
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_get_status' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		// Register reindex ability.
		wp_register_ability(
			self::ABILITY_REINDEX,
			array(
				'label'               => __( 'Reindex', 'otaria-search' ),
				'description'         => __( 'Triggers a full reindex of all posts.', 'otaria-search' ),
				'category'            => self::CATEGORY_SLUG,
				'meta'                => array( 'show_in_rest' => true ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'force'           => array(
							'type'        => 'boolean',
							'description' => __( 'Force a full rebuild.', 'otaria-search' ),
							'default'     => false,
						),
						'confirm_rebuild' => array(
							'type'        => 'boolean',
							'description' => __( 'Confirm the rebuild process.', 'otaria-search' ),
							'default'     => false,
						),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_reindex' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		// Register select-model ability.
		wp_register_ability(
			self::ABILITY_SELECT,
			array(
				'label'               => __( 'Select Model', 'otaria-search' ),
				'description'         => __( 'Selects the embedding model, detecting its dimensions with a probe request.', 'otaria-search' ),
				'category'            => self::CATEGORY_SLUG,
				'meta'                => array( 'show_in_rest' => true ),
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'provider', 'model' ),
					'properties' => array(
						'provider' => array(
							'type'        => 'string',
							'description' => __( 'AI provider id.', 'otaria-search' ),
						),
						'model'    => array(
							'type'        => 'string',
							'description' => __( 'Embedding model id.', 'otaria-search' ),
						),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_select_model' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		// Register update-settings ability.
		wp_register_ability(
			self::ABILITY_UPDATE_SETTINGS,
			array(
				'label'               => __( 'Update Settings', 'otaria-search' ),
				'description'         => __( 'Changes the given settings and keeps the others: search thresholds, hybrid search, indexed post types, embedding prefixes and whether password-protected posts are indexed. Null resets a setting to its default. Deselected post types are removed from the index. Returns the settings in effect, the added post types, and reindex_required when a Reindex is needed to apply the change.', 'otaria-search' ),
				'category'            => self::CATEGORY_SLUG,
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => false,
						// Deselecting post types deletes their embeddings.
						'destructive' => true,
						'idempotent'  => true,
					),
				),
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => self::update_settings_properties(),
				),
				'execute_callback'    => array( __CLASS__, 'execute_update_settings' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		// Register preview-search ability.
		wp_register_ability(
			self::ABILITY_PREVIEW,
			array(
				'label'               => __( 'Preview Search', 'otaria-search' ),
				'description'         => __( 'Lists the posts nearest to a query with their cosine distances and whether the thresholds keep them.', 'otaria-search' ),
				'category'            => self::CATEGORY_SLUG,
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
					),
				),
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'query' ),
					'properties' => array(
						'query'           => array(
							'type'        => 'string',
							'minLength'   => 1,
							'description' => __( 'Search query.', 'otaria-search' ),
						),
						'max_distance'    => self::distance_schema() + array(
							'description' => __( 'Threshold to preview; defaults to the saved value.', 'otaria-search' ),
						),
						'relative_cutoff' => self::fraction_schema() + array(
							'description' => __( 'Threshold to preview; defaults to the saved value.', 'otaria-search' ),
						),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_preview_search' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	/**
	 * Input schema of a cosine distance threshold: 0–2, or null for the default.
	 *
	 * @since 0.3.0
	 *
	 * @return array
	 */
	private static function distance_schema(): array {
		return array(
			'type'    => array( 'number', 'null' ),
			'minimum' => 0,
			'maximum' => 2,
		);
	}

	/**
	 * Input schema of a fraction: 0–1, or null for the default.
	 *
	 * @return array
	 */
	private static function fraction_schema(): array {
		return array(
			'type'    => array( 'number', 'null' ),
			'minimum' => 0,
			'maximum' => 1,
		);
	}

	/**
	 * Input schema properties of update-settings: every setting but the
	 * model, which select-model changes after probing it.
	 *
	 * The keys are also the ones execute_update_settings() accepts. A new
	 * setting is added here.
	 *
	 * @since 0.3.0
	 *
	 * @return array<string, array>
	 */
	private static function update_settings_properties(): array {
		return array(
			'max_distance'               => self::distance_schema() + array(
				'description' => __( 'Maximum cosine distance from the query.', 'otaria-search' ),
			),
			'relative_cutoff'            => self::fraction_schema() + array(
				'description' => __( 'Fraction of the way from the best match to a typical unrelated post (the 50th nearest) within which posts are kept.', 'otaria-search' ),
			),
			'hybrid'                     => array(
				'type'        => array( 'boolean', 'null' ),
				'description' => __( 'Fuse vector results with the LIKE search results.', 'otaria-search' ),
			),
			'post_types'                 => array(
				'type'        => array( 'array', 'null' ),
				'minItems'    => 1,
				'items'       => array( 'type' => 'string' ),
				'description' => __( 'Public, searchable post types to index. Null indexes all of them, including ones registered later.', 'otaria-search' ),
			),
			'query_prefix'               => array(
				'type'        => array( 'string', 'null' ),
				'description' => __( 'Prepended to search terms before embedding.', 'otaria-search' ),
			),
			'document_prefix'            => array(
				'type'        => array( 'string', 'null' ),
				'description' => __( 'Prepended to post chunks before embedding. Changing it requires a Reindex.', 'otaria-search' ),
			),
			'exclude_password_protected' => array(
				'type'        => array( 'boolean', 'null' ),
				'description' => __( 'Leave password-protected posts out of the index. Changing it requires a Reindex.', 'otaria-search' ),
			),
		);
	}

	/**
	 * Callback for get-status ability.
	 *
	 * @param array|null $input Ability input (unused).
	 * @return array
	 */
	public static function execute_get_status( ?array $input = null ): array {
		unset( $input );

		$repository   = new Repository();
		$catalog      = Model_Catalog::create();
		$is_supported = Schema::is_vector_supported();
		$installed    = Schema::is_installed();
		$schema_ready = $is_supported && $installed;

		$settings     = get_option( Settings::OPTION_NAME, array() );
		$cur_provider = is_array( $settings ) ? (string) ( $settings['provider'] ?? '' ) : '';
		$cur_model    = is_array( $settings ) ? (string) ( $settings['model'] ?? '' ) : '';
		$cur_dims     = is_array( $settings ) && isset( $settings['dimensions'] ) ? (int) $settings['dimensions'] : null;

		$table_dims  = $schema_ready ? $repository->get_column_dimensions() : null;
		$dim_changed = $installed && null !== $table_dims && null !== $cur_dims && $table_dims !== $cur_dims;
		$progress    = get_transient( Cron_Backfill::PROGRESS_KEY );

		return array(
			'is_supported'               => $is_supported,
			'installed'                  => $installed,
			'indexed'                    => $schema_ready ? $repository->count_indexed() : 0,
			'table_dims'                 => $table_dims,
			'progress'                   => $progress ? $progress : null,
			'settings'                   => array(
				'provider'   => $cur_provider,
				'model'      => $cur_model,
				'dimensions' => $cur_dims,
			),
			'available_models'           => $catalog->get_available_models(),
			'dim_changed'                => $dim_changed,
			'thresholds'                 => Settings::get_thresholds(),
			'default_thresholds'         => Settings::DEFAULT_THRESHOLDS,
			'hybrid'                     => Settings::is_hybrid(),
			'exclude_password_protected' => Settings::excludes_password_protected(),
			'post_types'                 => Settings::get_post_types(),
			'prefixes'                   => Settings::get_prefixes(),
			'available_post_types'       => array_map(
				static fn( string $name ): array => array(
					'name'  => $name,
					'label' => (string) get_post_type_object( $name )->labels->name,
				),
				Settings::available_post_types()
			),
		);
	}

	/**
	 * Callback for update-settings ability.
	 *
	 * Deselected post types are removed from the index by the option update
	 * (Indexer::remove_deselected_post_types()). A Reindex is required, and
	 * enough without forcing it, when post types are added, when the
	 * embedding key in the content hash changes (e.g. the document prefix),
	 * or when the password-protected exclusion changes.
	 *
	 * @since 0.3.0
	 *
	 * @param array|null $input Settings to change; see update_settings_properties().
	 * @return array|\WP_Error The settings now in effect plus added_post_types and
	 *                         reindex_required, or an error for unavailable post types.
	 */
	public static function execute_update_settings( ?array $input = null ): array|\WP_Error {
		$changes = array_intersect_key( (array) $input, self::update_settings_properties() );

		if ( isset( $changes['post_types'] ) ) {
			$post_types = Settings::sanitize_settings( array( 'post_types' => $changes['post_types'] ) )['post_types'] ?? array();
			$invalid    = array_diff( $post_types, Settings::available_post_types() );
			if ( empty( $post_types ) || ! empty( $invalid ) ) {
				return new \WP_Error(
					'invalid_post_types',
					/* translators: %s: Comma-separated post type names. */
					sprintf( __( 'These post types cannot be indexed: %s', 'otaria-search' ), implode( ', ', $invalid ) ),
					array( 'status' => 400 )
				);
			}
			$changes['post_types'] = $post_types;
		}

		$post_types_before    = Settings::get_post_types();
		$embedding_key_before = Settings::get_embedding_key();
		$exclude_before       = Settings::excludes_password_protected();

		Settings::update( $changes );

		$post_types = Settings::get_post_types();
		$added      = array_values( array_diff( $post_types, $post_types_before ) );
		$exclude    = Settings::excludes_password_protected();

		return Settings::get_thresholds() + Settings::get_prefixes() + array(
			'hybrid'                     => Settings::is_hybrid(),
			'post_types'                 => $post_types,
			'exclude_password_protected' => $exclude,
			'added_post_types'           => $added,
			'reindex_required'           => array() !== $added
				|| Settings::get_embedding_key() !== $embedding_key_before
				|| $exclude !== $exclude_before,
		);
	}

	/**
	 * Callback for preview-search ability.
	 *
	 * @since 0.2.0
	 *
	 * @param array|null $input Ability input with 'query' and optional thresholds.
	 * @return array|\WP_Error Nearest posts, or the embedding error.
	 */
	public static function execute_preview_search( ?array $input = null ): array|\WP_Error {
		$result = otaria_search_embedding( Settings::get_prefixes()['query_prefix'] . ( $input['query'] ?? '' ) )->generate_embeddings();
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$thresholds = Settings::get_thresholds();
		foreach ( Settings::THRESHOLD_KEYS as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$thresholds[ $key ] = (float) $input[ $key ];
			}
		}

		// Judge as the search does, on its full candidate list: the relative
		// cutoff measures against the 50th post. Then list the nearest only.
		$distances = ( new Repository() )->nearest_posts( $result[0]->getValues(), Settings::get_post_types(), Search::MAX_RESULTS );
		$kept      = Repository::filter_by_distance( $distances, $thresholds['max_distance'], $thresholds['relative_cutoff'] );

		$results = array();
		foreach ( array_slice( $distances, 0, self::PREVIEW_LIMIT, true ) as $post_id => $distance ) {
			$results[] = array(
				'post_id'  => $post_id,
				'title'    => get_the_title( $post_id ),
				'distance' => $distance,
				'included' => isset( $kept[ $post_id ] ),
			);
		}

		return array( 'results' => $results );
	}

	/**
	 * Callback for reindex ability.
	 *
	 * @param array|null $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function execute_reindex( ?array $input = null ): array|\WP_Error {
		$force = is_array( $input ) && isset( $input['force'] )
			? (bool) $input['force']
			: false;

		$confirm_rebuild = is_array( $input ) && isset( $input['confirm_rebuild'] )
			? (bool) $input['confirm_rebuild']
			: false;

		$settings   = get_option( Settings::OPTION_NAME, array() );
		$cur_dims   = is_array( $settings ) && isset( $settings['dimensions'] ) ? (int) $settings['dimensions'] : null;
		$saved_dims = $cur_dims ?? Plugin::DEFAULT_DIMENSIONS;

		$repository  = new Repository();
		$installed   = Schema::is_installed();
		$table_dims  = $installed ? $repository->get_column_dimensions() : null;
		$dim_changed = $installed && null !== $table_dims && null !== $cur_dims && $table_dims !== $cur_dims;

		$indexer  = new Indexer( $repository );
		$backfill = new Cron_Backfill( $indexer );

		if ( $dim_changed ) {
			if ( ! $confirm_rebuild ) {
				return new \WP_Error(
					'confirm_required',
					__( 'Please confirm before rebuilding.', 'otaria-search' ),
					array( 'status' => 400 )
				);
			}

			Schema::drop();
			delete_option( Schema::DB_VERSION_OPTION );
			Schema::install( $saved_dims );
			$backfill->schedule( true );

			return array( 'rebuilt' => true );
		}

		if ( ! $installed ) {
			Schema::install( $saved_dims );
			$backfill->schedule( true );
		} else {
			$backfill->schedule( $force );
		}

		return array( 'rebuilt' => false );
	}

	/**
	 * Callback for select-model ability.
	 *
	 * Embeds a probe text with the requested model to detect its native
	 * dimensions, then saves provider, model and dimensions together. When the
	 * probe fails the error is returned and the saved settings are untouched.
	 *
	 * @since 0.2.0
	 *
	 * @param array|null $input Ability input with 'provider' and 'model'.
	 * @return array|\WP_Error The stored settings, or the probe error.
	 */
	public static function execute_select_model( ?array $input = null ): array|\WP_Error {
		$provider = sanitize_text_field( (string) ( $input['provider'] ?? '' ) );
		$model    = sanitize_text_field( (string) ( $input['model'] ?? '' ) );

		$result = wp_ai_client_embedding( 'dimension probe' )
			->using_provider_model( $provider, $model )
			->generate_embedding_result();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$settings = array(
			'provider'   => $provider,
			'model'      => $model,
			'dimensions' => $result->getDimensions(),
		);

		// Distances and prefixes are model-specific: those set for another
		// model are cleared back to the defaults.
		$saved = get_option( Settings::OPTION_NAME, array() );
		if ( ! is_array( $saved ) || ( $saved['provider'] ?? '' ) !== $provider || ( $saved['model'] ?? '' ) !== $model ) {
			$settings += array_fill_keys( array( ...Settings::THRESHOLD_KEYS, ...Settings::PREFIX_KEYS ), null );
		}

		Settings::update( $settings );

		return get_option( Settings::OPTION_NAME );
	}
}
