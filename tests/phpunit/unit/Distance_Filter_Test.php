<?php
/**
 * Unit tests for the distance threshold filter.
 *
 * @package Otaria_Search
 */

declare(strict_types=1);

namespace Otaria_Search\Tests\Unit;

use Otaria_Search\Repository;

/**
 * Class Distance_Filter_Test
 */
class Distance_Filter_Test extends \WP_UnitTestCase {

	/** Distances by post ID, closest first. */
	private const DISTANCES = array(
		7 => 0.2,
		3 => 0.35,
		9 => 0.6,
		4 => 0.8,
	);

	/** Without thresholds every post is kept. */
	public function test_infinite_thresholds_keep_all(): void {
		$this->assertSame( self::DISTANCES, Repository::filter_by_distance( self::DISTANCES, INF, INF ) );
	}

	/** Posts beyond max_distance are dropped; keys are preserved. */
	public function test_max_distance_drops_far_posts(): void {
		$this->assertSame(
			array(
				7 => 0.2,
				3 => 0.35,
			),
			Repository::filter_by_distance( self::DISTANCES, 0.5, INF )
		);
	}

	/** With fewer than 50 posts, the window reaches toward max_distance. */
	public function test_relative_cutoff_uses_max_distance_below_reference_rank(): void {
		// Best 0.2, reference max_distance 0.7: 0.5 keeps up to 0.2 + 0.5 * 0.5 = 0.45.
		$this->assertSame(
			array(
				7 => 0.2,
				3 => 0.35,
			),
			Repository::filter_by_distance( self::DISTANCES, 0.7, 0.5 )
		);
	}

	/** Without max_distance, the farthest post is the reference below rank 50. */
	public function test_relative_cutoff_uses_farthest_post_without_max_distance(): void {
		// Best 0.2, reference 0.8: 0.5 keeps up to 0.5.
		$this->assertSame(
			array(
				7 => 0.2,
				3 => 0.35,
			),
			Repository::filter_by_distance( self::DISTANCES, INF, 0.5 )
		);
	}

	/** The window is a fraction of the gap between the best and the 50th post. */
	public function test_relative_cutoff_uses_fiftieth_post_as_reference(): void {
		$distances = array();
		for ( $i = 0; $i < 60; $i++ ) {
			$distances[ 100 + $i ] = 0.1 + 0.01 * $i;
		}

		// The 50th is at 0.59: 0.5 keeps up to 0.1 + 0.5 * 0.49 = 0.345, the first 25.
		$kept = Repository::filter_by_distance( $distances, INF, 0.5 );

		$this->assertSame( array_slice( $distances, 0, 25, true ), $kept );
	}

	/** Nothing within max_distance leaves nothing for the relative cutoff. */
	public function test_no_post_within_max_distance_returns_empty(): void {
		$this->assertSame( array(), Repository::filter_by_distance( self::DISTANCES, 0.1, 0.5 ) );
	}
}
