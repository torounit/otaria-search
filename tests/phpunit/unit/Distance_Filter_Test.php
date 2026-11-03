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

	/** Without a threshold every post is kept. */
	public function test_infinite_threshold_keeps_all(): void {
		$this->assertSame( self::DISTANCES, Repository::filter_by_distance( self::DISTANCES, INF ) );
	}

	/** Posts beyond max_distance are dropped; keys are preserved. */
	public function test_max_distance_drops_far_posts(): void {
		$this->assertSame(
			array(
				7 => 0.2,
				3 => 0.35,
			),
			Repository::filter_by_distance( self::DISTANCES, 0.5 )
		);
	}

	/** Posts within max_distance are kept however far behind the best they are. */
	public function test_posts_far_behind_best_within_max_distance_are_kept(): void {
		$this->assertSame( self::DISTANCES, Repository::filter_by_distance( self::DISTANCES, 0.8 ) );
	}

	/** Nothing within max_distance returns nothing. */
	public function test_no_post_within_max_distance_returns_empty(): void {
		$this->assertSame( array(), Repository::filter_by_distance( self::DISTANCES, 0.1 ) );
	}
}
