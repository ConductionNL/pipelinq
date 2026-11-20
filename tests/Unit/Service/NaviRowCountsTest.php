<?php

/**
 * Unit tests for NaviRowCounts.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\NaviRowCounts;
use PHPUnit\Framework\TestCase;

/**
 * Tests for NaviRowCounts: ISO-week buckets, the won rate, and status and category tallies.
 *
 * @covers \OCA\Pipelinq\Service\NaviRowCounts
 */
class NaviRowCountsTest extends TestCase {

	/**
	 * Rows are bucketed by ISO week of createdAt (else created), sorted, and unparsable dates dropped.
	 *
	 * @return void
	 */
	public function testWeeklyCountsBucketsByIsoWeek(): void {
		$rows = [
			['createdAt' => '2026-01-05T10:00:00Z'],
			['created' => '2026-01-07'],
			['createdAt' => '2025-12-29'],
			['createdAt' => 'not a date'],
			[],
		];

		self::assertSame(expected: ['2026-W01' => 1, '2026-W02' => 2], actual: (new NaviRowCounts())->weeklyCounts(rows: $rows));
	}//end testWeeklyCountsBucketsByIsoWeek()

	/**
	 * The won rate is a percentage rounded to one decimal, and zero with no total.
	 *
	 * @return void
	 */
	public function testWonRate(): void {
		$counts = new NaviRowCounts();

		self::assertSame(expected: 0.0, actual: $counts->wonRate(won: 3, total: 0));
		self::assertSame(expected: 33.3, actual: $counts->wonRate(won: 1, total: 3));
		self::assertSame(expected: 100.0, actual: $counts->wonRate(won: 4, total: 4));
	}//end testWonRate()

	/**
	 * Only the open, won and lost statuses are counted, each starting at zero.
	 *
	 * @return void
	 */
	public function testStatusCountsCountsTheThreeKnownStatuses(): void {
		$rows = [['status' => 'won'], ['status' => 'open'], ['status' => 'won'], ['status' => 'archived'], []];

		self::assertSame(expected: ['open' => 1, 'won' => 2, 'lost' => 0], actual: (new NaviRowCounts())->statusCounts(rows: $rows));
	}//end testStatusCountsCountsTheThreeKnownStatuses()

	/**
	 * Categories are tallied, empty ones skipped, and sorted by count descending.
	 *
	 * @return void
	 */
	public function testCategoryCountsSortsByCountDescending(): void {
		$rows = [['category' => 'b'], ['category' => 'a'], ['category' => 'a'], ['category' => ''], []];

		self::assertSame(expected: ['a' => 2, 'b' => 1], actual: (new NaviRowCounts())->categoryCounts(rows: $rows));
	}//end testCategoryCountsSortsByCountDescending()
}//end class
