<?php
/**
 * Search hook — replaces the default WP search with vector similarity.
 *
 * @package WP_MariaDB_Vector_Search
 */

declare(strict_types=1);

namespace WP_MariaDB_Vector_Search;

/**
 * Hooks into pre_get_posts to rewrite the main search query with vector similarity.
 *
 * Only fires when:
 * - is_main_query() is true, AND
 * - is_search() is true, AND
 * - the 's' parameter is non-empty.
 *
 * On embedding failure the query falls through to default WP search.
 * Vector results are filtered by cosine distance (wp_mariadb_vector_search_max_distance)
 * and by distance behind the best match (wp_mariadb_vector_search_max_relative_distance),
 * both defaulting to the saved settings (Settings::get_thresholds()), then fused with
 * WordPress's LIKE search results via Reciprocal Rank Fusion
 * (opt out with wp_mariadb_vector_search_hybrid).
 */
class Search {

	/**
	 * Query var that marks a query rewritten to post__in.
	 *
	 * @since 0.2.0
	 */
	const QUERY_VAR = 'wp_mariadb_vector_search';

	/**
	 * Constructor.
	 *
	 * @param Repository $repository Embeddings table wrapper.
	 */
	public function __construct(
		private Repository $repository,
	) {}

	/**
	 * Attach the query hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'pre_get_posts', array( $this, 'rewrite_search_query' ) );
		add_filter( 'posts_search', array( $this, 'remove_like_clause' ), 99, 2 );
	}

	/**
	 * Rewrite a search WP_Query to use vector similarity results.
	 *
	 * @param \WP_Query $query The query object, modified in place.
	 * @return void
	 */
	public function rewrite_search_query( \WP_Query $query ): void {
		if ( ! $query->is_main_query() || ! $query->is_search() ) {
			return;
		}

		$search_term = $query->get( 's' );
		if ( empty( $search_term ) ) {
			return;
		}

		$post_types = $this->get_post_types( $query );
		$result     = wp_mariadb_vector_search_embedding( $search_term )->generate_embeddings();

		if ( is_wp_error( $result ) || empty( $result[0] ) ) {
			return;
		}

		$thresholds = Settings::get_thresholds();

		/**
		 * Filters the maximum cosine distance of posts included in search results.
		 *
		 * 0 is identical; smaller is more similar. The best value depends on the model.
		 *
		 * @since 0.1.0
		 * @since 0.2.0 Defaults to the saved setting.
		 *
		 * @param float $max_distance Maximum cosine distance (0–2). Default the saved setting, else 0.65.
		 */
		$max_distance = (float) apply_filters( 'wp_mariadb_vector_search_max_distance', $thresholds['max_distance'] );

		/**
		 * Filters the maximum number of posts returned by the vector search.
		 *
		 * Also the basis for the inner LIMIT, so the VECTOR INDEX is used, and
		 * the limit of the LIKE search fused in hybrid mode.
		 *
		 * @since 0.1.0
		 *
		 * @param int $max_results Maximum number of posts. Default 200.
		 */
		$max_results = (int) apply_filters( 'wp_mariadb_vector_search_max_results', 200 );

		/**
		 * Filters the maximum distance behind the best vector match.
		 *
		 * Posts farther than this from the closest post are excluded.
		 *
		 * @since 0.2.0
		 *
		 * @param float $max_relative Maximum distance behind the best match. INF disables.
		 *                            Default the saved setting, else 0.25.
		 */
		$max_relative = (float) apply_filters( 'wp_mariadb_vector_search_max_relative_distance', $thresholds['max_relative_distance'] );

		$ids = $this->repository->search_similar( $result[0]->getValues(), $post_types, $max_distance, $max_results, $max_relative );

		/**
		 * Filters whether vector results are fused with WordPress's LIKE search results.
		 *
		 * @since 0.2.0
		 *
		 * @param bool $hybrid True to fuse via Reciprocal Rank Fusion, false for vector-only. Default true.
		 */
		if ( apply_filters( 'wp_mariadb_vector_search_hybrid', true ) ) {
			/**
			 * Filters the Reciprocal Rank Fusion constant.
			 *
			 * Higher values flatten the weight of top ranks.
			 *
			 * @since 0.2.0
			 *
			 * @param int $rrf_k RRF constant. Negative values are treated as 0. Default 60.
			 */
			$rrf_k = (int) apply_filters( 'wp_mariadb_vector_search_rrf_k', 60 );
			$ids   = Rank_Fusion::fuse( array( $ids, $this->search_like( $search_term, $post_types, $max_results ) ), $rrf_k );
		}

		if ( empty( $ids ) ) {
			// Match nothing rather than fall through to the LIKE search.
			$ids = array( 0 );
		}

		$query->set( 'post__in', $ids );
		$query->set( 'orderby', 'post__in' );
		$query->set( self::QUERY_VAR, true );
	}

	/**
	 * Drop the LIKE search clause from queries rewritten to post__in.
	 *
	 * Scoped by query var rather than added and removed around the query:
	 * fields=ids queries return before the_posts, so a one-shot filter would
	 * leak into later searches.
	 *
	 * @since 0.2.0
	 *
	 * @param string    $search Search SQL for the WHERE clause.
	 * @param \WP_Query $query  The query.
	 * @return string Empty for rewritten queries; otherwise unchanged.
	 */
	public function remove_like_clause( string $search, \WP_Query $query ): string {
		return $query->get( self::QUERY_VAR ) ? '' : $search;
	}

	/**
	 * Run WordPress's default LIKE search.
	 *
	 * This is not the main query, so it does not re-enter rewrite_search_query().
	 *
	 * @since 0.2.0
	 *
	 * @param string   $search_term Search term.
	 * @param string[] $post_types  Post types to include.
	 * @param int      $limit       Maximum number of results.
	 * @return int[] Post IDs ordered by WordPress's search relevance.
	 */
	private function search_like( string $search_term, array $post_types, int $limit ): array {
		$like_query = new \WP_Query(
			array(
				's'              => $search_term,
				'post_type'      => $post_types,
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'orderby'        => 'relevance',
				'no_found_rows'  => true,
			)
		);

		return array_map( 'intval', $like_query->posts );
	}

	/**
	 * Determine the post types to search.
	 *
	 * Uses the query's post_type parameter when set; otherwise falls back to
	 * default_post_types().
	 *
	 * @param \WP_Query $query The query.
	 * @return string[] Post type slugs.
	 */
	private function get_post_types( \WP_Query $query ): array {
		$from_query = $query->get( 'post_type' );

		if ( ! empty( $from_query ) && 'any' !== $from_query ) {
			return (array) $from_query;
		}

		return self::default_post_types();
	}

	/**
	 * All public, searchable post types, filtered by
	 * wp_mariadb_vector_search_post_types.
	 *
	 * @since 0.2.0
	 *
	 * @return string[] Post type slugs.
	 */
	public static function default_post_types(): array {
		$types = array_keys(
			get_post_types(
				array(
					'public'              => true,
					'exclude_from_search' => false,
				)
			)
		);

		/**
		 * Filters the post types that are indexed and searched.
		 *
		 * @since 0.1.0
		 *
		 * @param string[] $types Post type slugs. Default public, searchable post types.
		 */
		return apply_filters( 'wp_mariadb_vector_search_post_types', $types );
	}
}
