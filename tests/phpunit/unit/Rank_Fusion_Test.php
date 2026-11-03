<?php
/**
 * Unit tests for reciprocal rank fusion.
 *
 * @package Squirrel_Search
 */

declare(strict_types=1);

namespace Squirrel_Search\Tests\Unit;

use Squirrel_Search\Rank_Fusion;

/**
 * Class Rank_Fusion_Test
 */
class Rank_Fusion_Test extends \WP_UnitTestCase {

	/** No input lists fuse to an empty result. */
	public function test_no_lists_returns_empty_array(): void {
		$this->assertSame( array(), Rank_Fusion::fuse( array() ) );
		$this->assertSame( array(), Rank_Fusion::fuse( array( array(), array() ) ) );
	}

	/** A single ranked list keeps its order. */
	public function test_single_list_preserves_order(): void {
		$this->assertSame( array( 1, 2, 3 ), Rank_Fusion::fuse( array( array( 1, 2, 3 ) ) ) );
	}

	/** The fused result is the union of all input lists. */
	public function test_result_is_union_of_input_lists(): void {
		$result = Rank_Fusion::fuse( array( array( 1, 2 ), array( 3, 4 ) ) );
		sort( $result );
		$this->assertSame( array( 1, 2, 3, 4 ), $result );
	}

	/** An ID found by both lists outranks IDs found by only one. */
	public function test_id_in_both_lists_ranks_first(): void {
		$this->assertSame( array( 1, 2, 3 ), Rank_Fusion::fuse( array( array( 2, 1 ), array( 3, 1 ) ) ) );
	}

	/** Equal scores keep the order of the earlier list. */
	public function test_ties_keep_earlier_list_first(): void {
		$this->assertSame( array( 1, 3, 2, 4 ), Rank_Fusion::fuse( array( array( 1, 2 ), array( 3, 4 ) ) ) );
	}

	/** A duplicate within one list counts once, at its best rank. */
	public function test_duplicate_ids_within_a_list_count_once(): void {
		$scores = Rank_Fusion::scores( array( array( 1, 1, 2 ) ), 60 );
		$this->assertSame(
			array(
				1 => 1 / 61,
				2 => 1 / 62,
			),
			$scores
		);
	}

	/** A smaller k weights the top rank more heavily. */
	public function test_smaller_k_increases_score_for_top_rank(): void {
		$small_k = Rank_Fusion::scores( array( array( 1 ) ), 1 );
		$large_k = Rank_Fusion::scores( array( array( 1 ) ), 100 );
		$this->assertGreaterThan( $large_k[1], $small_k[1] );
	}

	/** Numeric-string IDs are normalized to integers. */
	public function test_returned_ids_are_integers(): void {
		$this->assertSame( array( 1, 2 ), Rank_Fusion::fuse( array( array( '1', '2' ) ) ) );
	}

	/** A negative k is clamped to 0 instead of dividing by zero. */
	public function test_negative_k_is_clamped_to_zero(): void {
		$this->assertSame(
			array(
				1 => 1.0,
				2 => 0.5,
			),
			Rank_Fusion::scores( array( array( 1, 2 ) ), -1 )
		);
	}
}
