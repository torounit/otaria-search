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

	/** Posts farther than max_distance_ratio times the best distance are dropped. */
	public function test_max_distance_ratio_drops_posts_beyond_multiple_of_best(): void {
		// The best is 0.2, so a ratio of 2 keeps distances up to 0.4.
		$this->assertSame(
			array(
				7 => 0.2,
				3 => 0.35,
			),
			Repository::filter_by_distance( self::DISTANCES, INF, 2.0 )
		);
	}

	/** The ratio applies to the best post kept by max_distance. */
	public function test_max_distance_ratio_applies_after_max_distance(): void {
		$this->assertSame( array( 7 => 0.2 ), Repository::filter_by_distance( self::DISTANCES, 0.3, 4.0 ) );
	}

	/** Nothing within max_distance leaves nothing for the ratio filter. */
	public function test_no_post_within_max_distance_returns_empty(): void {
		$this->assertSame( array(), Repository::filter_by_distance( self::DISTANCES, 0.1, 2.0 ) );
	}
}
