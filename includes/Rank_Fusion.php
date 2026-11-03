<?php
/**
 * Reciprocal rank fusion of ranked post ID lists.
 *
 * @package Squirrel_Search
 */

declare(strict_types=1);

namespace Squirrel_Search;

/**
 * Merges ranked ID lists without needing comparable scores from each source.
 *
 * Used to combine vector similarity results with WordPress's LIKE search
 * results: cosine distances and LIKE relevance are on different scales, so
 * only the ranks are fused.
 *
 * @since 0.2.0
 */
class Rank_Fusion {

	/**
	 * Fuse ranked lists with Reciprocal Rank Fusion (RRF).
	 *
	 * Equal scores keep first-seen order, so earlier lists win ties.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, array<int, int|string>> $ranked_lists Ranked lists of post IDs, best first.
	 * @param int                                $k            RRF constant. Higher values flatten the
	 *                                                          weight of top ranks. Default 60.
	 * @return int[] Union of all input IDs, ordered by descending fused score.
	 */
	public static function fuse( array $ranked_lists, int $k = 60 ): array {
		$scores = self::scores( $ranked_lists, $k );
		arsort( $scores );
		return array_keys( $scores );
	}

	/**
	 * Compute each ID's RRF score: the sum of 1 / ( k + rank ) over the lists.
	 *
	 * A duplicate ID within one list counts once, at its best rank.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, array<int, int|string>> $ranked_lists Ranked lists of post IDs, best first.
	 * @param int                                $k            RRF constant. Negative values are treated as 0.
	 * @return array<int, float> Map of post ID to fused score, in first-seen order.
	 */
	public static function scores( array $ranked_lists, int $k = 60 ): array {
		$k      = max( 0, $k );
		$scores = array();

		foreach ( $ranked_lists as $list ) {
			$ids = array_values( array_unique( array_map( 'intval', $list ) ) );
			foreach ( $ids as $index => $id ) {
				$scores[ $id ] = ( $scores[ $id ] ?? 0.0 ) + 1.0 / ( $k + $index + 1 );
			}
		}

		return $scores;
	}
}
