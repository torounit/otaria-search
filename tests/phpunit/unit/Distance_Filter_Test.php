<?php
/**
 * Unit tests for the distance threshold filter.
 *
 * @package Squirrel_Search
 */

declare(strict_types=1);

namespace Squirrel_Search\Tests\Unit;

use Squirrel_Search\Repository;

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

	/** Posts more than max_relative_distance behind the best are dropped. */
	public function test_max_relative_distance_drops_posts_behind_best(): void {
		$this->assertSame(
			array(
				7 => 0.2,
				3 => 0.35,
			),
			Repository::filter_by_distance( self::DISTANCES, INF, 0.3 )
		);
	}

	/** Nothing within max_distance leaves nothing for the relative filter. */
	public function test_no_post_within_max_distance_returns_empty(): void {
		$this->assertSame( array(), Repository::filter_by_distance( self::DISTANCES, 0.1, 0.3 ) );
	}
}
