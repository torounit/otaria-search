<?php
/**
 * Abilities registration for the plugin.
 *
 * @package WP_MariaDB_Vector_Search
 */

declare(strict_types=1);

namespace WP_MariaDB_Vector_Search;

/**
 * Handles registration of Abilities and Categories.
 */
class Abilities {

	const CATEGORY_SLUG      = 'wp-mariadb-vector-search';
	const ABILITY_GET_STATUS = 'wp-mariadb-vector-search/get-status';
	const ABILITY_REINDEX    = 'wp-mariadb-vector-search/reindex';

	/**
	 * Ability that probes a model's dimensions and saves it as the embedding model.
	 *
	 * @since 0.2.0
	 */
	const ABILITY_SELECT = 'wp-mariadb-vector-search/select-model';

	/**
	 * Ability that saves the search distance thresholds.
	 *
	 * @since 0.2.0
	 */
	const ABILITY_THRESHOLDS = 'wp-mariadb-vector-search/save-thresholds';

	/**
	 * Ability that lists the posts nearest to a query with their distances.
	 *
	 * @since 0.2.0
	 */
	const ABILITY_PREVIEW = 'wp-mariadb-vector-search/preview-search';

	/**
	 * Ability that saves which posts are indexed.
	 *
	 * @since 0.3.0
	 */
	const ABILITY_INDEX_SETTINGS = 'wp-mariadb-vector-search/save-index-settings';

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
				'label'       => __( 'Vector Search', 'wp-mariadb-vector-search' ),
				'description' => __( 'Abilities for MariaDB Vector Search.', 'wp-mariadb-vector-search' ),
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
				'label'               => __( 'Get Status', 'wp-mariadb-vector-search' ),
				'description'         => __( 'Retrieves the current plugin status.', 'wp-mariadb-vector-search' ),
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
				'label'               => __( 'Reindex', 'wp-mariadb-vector-search' ),
				'description'         => __( 'Triggers a full reindex of all posts.', 'wp-mariadb-vector-search' ),
				'category'            => self::CATEGORY_SLUG,
				'meta'                => array( 'show_in_rest' => true ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'force'           => array(
							'type'        => 'boolean',
							'description' => __( 'Force a full rebuild.', 'wp-mariadb-vector-search' ),
							'default'     => false,
						),
						'confirm_rebuild' => array(
							'type'        => 'boolean',
							'description' => __( 'Confirm the rebuild process.', 'wp-mariadb-vector-search' ),
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
				'label'               => __( 'Select Model', 'wp-mariadb-vector-search' ),
				'description'         => __( 'Selects the embedding model, detecting its dimensions with a probe request.', 'wp-mariadb-vector-search' ),
				'category'            => self::CATEGORY_SLUG,
				'meta'                => array( 'show_in_rest' => true ),
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'provider', 'model' ),
					'properties' => array(
						'provider' => array(
							'type'        => 'string',
							'description' => __( 'AI provider id.', 'wp-mariadb-vector-search' ),
						),
						'model'    => array(
							'type'        => 'string',
							'description' => __( 'Embedding model id.', 'wp-mariadb-vector-search' ),
						),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_select_model' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		$distance_schema = array(
			'type'    => array( 'number', 'null' ),
			'minimum' => 0,
			'maximum' => 2,
		);

		// Register save-thresholds ability.
		wp_register_ability(
			self::ABILITY_THRESHOLDS,
			array(
				'label'               => __( 'Save Thresholds', 'wp-mariadb-vector-search' ),
				'description'         => __( 'Saves the cosine distance thresholds for search results. Null resets a threshold to its default.', 'wp-mariadb-vector-search' ),
				'category'            => self::CATEGORY_SLUG,
				'meta'                => array( 'show_in_rest' => true ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'max_distance'          => $distance_schema + array(
							'description' => __( 'Maximum cosine distance from the query.', 'wp-mariadb-vector-search' ),
						),
						'max_relative_distance' => $distance_schema + array(
							'description' => __( 'Maximum distance behind the best match.', 'wp-mariadb-vector-search' ),
						),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_save_thresholds' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		// Register preview-search ability.
		wp_register_ability(
			self::ABILITY_PREVIEW,
			array(
				'label'               => __( 'Preview Search', 'wp-mariadb-vector-search' ),
				'description'         => __( 'Lists the posts nearest to a query with their cosine distances and whether the thresholds keep them.', 'wp-mariadb-vector-search' ),
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
						'query'                 => array(
							'type'        => 'string',
							'minLength'   => 1,
							'description' => __( 'Search query.', 'wp-mariadb-vector-search' ),
						),
						'max_distance'          => $distance_schema + array(
							'description' => __( 'Threshold to preview; defaults to the saved value.', 'wp-mariadb-vector-search' ),
						),
						'max_relative_distance' => $distance_schema + array(
							'description' => __( 'Threshold to preview; defaults to the saved value.', 'wp-mariadb-vector-search' ),
						),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_preview_search' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		// Register save-index-settings ability.
		wp_register_ability(
			self::ABILITY_INDEX_SETTINGS,
			array(
				'label'               => __( 'Save Index Settings', 'wp-mariadb-vector-search' ),
				'description'         => __( 'Saves which posts are indexed. Run Reindex to apply it to existing posts.', 'wp-mariadb-vector-search' ),
				'category'            => self::CATEGORY_SLUG,
				'meta'                => array( 'show_in_rest' => true ),
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'exclude_password_protected' ),
					'properties' => array(
						'exclude_password_protected' => array(
							'type'        => 'boolean',
							'description' => __( 'Leave password-protected posts out of the index.', 'wp-mariadb-vector-search' ),
						),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_save_index_settings' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
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

		$settings     = get_option( Admin::SETTINGS_KEY, array() );
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
			'exclude_password_protected' => Settings::excludes_password_protected(),
		);
	}

	/**
	 * Callback for save-index-settings ability.
	 *
	 * @since 0.3.0
	 *
	 * @param array|null $input Ability input with 'exclude_password_protected'.
	 * @return array The index settings now in effect.
	 */
	public static function execute_save_index_settings( ?array $input = null ): array {
		// Settings::sanitize_settings() keeps the other settings.
		update_option(
			Settings::OPTION_NAME,
			array( 'exclude_password_protected' => (bool) ( $input['exclude_password_protected'] ?? true ) )
		);

		return array( 'exclude_password_protected' => Settings::excludes_password_protected() );
	}

	/**
	 * Callback for save-thresholds ability.
	 *
	 * @since 0.2.0
	 *
	 * @param array|null $input Ability input with optional 'max_distance' and 'max_relative_distance'.
	 * @return array The thresholds now in effect.
	 */
	public static function execute_save_thresholds( ?array $input = null ): array {
		// Settings::sanitize_settings() keeps the model settings and any
		// threshold omitted from the input.
		update_option(
			Settings::OPTION_NAME,
			array_intersect_key( (array) $input, array_flip( Settings::THRESHOLD_KEYS ) )
		);

		return Settings::get_thresholds();
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
		$result = wp_mariadb_vector_search_embedding( (string) ( $input['query'] ?? '' ) )->generate_embeddings();
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$thresholds = Settings::get_thresholds();
		foreach ( Settings::THRESHOLD_KEYS as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$thresholds[ $key ] = (float) $input[ $key ];
			}
		}

		$distances = ( new Repository() )->nearest_posts( $result[0]->getValues(), Search::default_post_types(), self::PREVIEW_LIMIT );
		$kept      = Repository::filter_by_distance( $distances, $thresholds['max_distance'], $thresholds['max_relative_distance'] );

		$results = array();
		foreach ( $distances as $post_id => $distance ) {
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

		$settings   = get_option( Admin::SETTINGS_KEY, array() );
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
					__( 'Please confirm before rebuilding.', 'wp-mariadb-vector-search' ),
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

		// Distances are model-specific: thresholds tuned for another model
		// are cleared back to the defaults.
		$saved = get_option( Settings::OPTION_NAME, array() );
		if ( ! is_array( $saved ) || ( $saved['provider'] ?? '' ) !== $provider || ( $saved['model'] ?? '' ) !== $model ) {
			$settings += array_fill_keys( Settings::THRESHOLD_KEYS, null );
		}

		update_option( Settings::OPTION_NAME, $settings );

		return get_option( Settings::OPTION_NAME );
	}
}
