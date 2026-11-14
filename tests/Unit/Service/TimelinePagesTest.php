<?php

/**
 * Unit tests for TimelinePages.
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

use OCA\Pipelinq\Service\PageRequest;
use OCA\Pipelinq\Service\TimelinePages;
use PHPUnit\Framework\TestCase;

/**
 * TimelinePages slices a merged timeline into pages.
 */
class TimelinePagesTest extends TestCase {

	/**
	 * Items 1 to n.
	 *
	 * @param int $count How many.
	 *
	 * @return list<array{n: int}>
	 */
	private static function items(int $count): array {
		return array_map(static fn (int $n): array => ['n' => $n], range(1, $count));
	}//end items()

	/**
	 * A page slices its window and reports the totals.
	 *
	 * @return void
	 */
	public function testAPage(): void {
		$page = (new TimelinePages())->timelinePage(merged: self::items(25), pageRequest: new PageRequest(page: 2, limit: 10));

		self::assertSame(range(11, 20), array_column($page['items'], 'n'));
		self::assertSame(25, $page['total']);
		self::assertSame(2, $page['page']);
		self::assertSame(3, $page['pages']);
	}//end testAPage()

	/**
	 * The last page holds the rest.
	 *
	 * @return void
	 */
	public function testTheLastPage(): void {
		$page = (new TimelinePages())->timelinePage(merged: self::items(25), pageRequest: new PageRequest(page: 3, limit: 10));

		self::assertSame(range(21, 25), array_column($page['items'], 'n'));
	}//end testTheLastPage()

	/**
	 * A timeline page past the end is empty and reports the last page number.
	 *
	 * @return void
	 */
	public function testAPagePastTheEnd(): void {
		$page = (new TimelinePages())->timelinePage(merged: self::items(5), pageRequest: new PageRequest(page: 4, limit: 10));

		self::assertSame([], $page['items']);
		self::assertSame(1, $page['page']);
		self::assertSame(1, $page['pages']);
	}//end testAPagePastTheEnd()

	/**
	 * An empty timeline has one empty page.
	 *
	 * @return void
	 */
	public function testAnEmptyTimeline(): void {
		self::assertSame(
			['items' => [], 'total' => 0, 'page' => 1, 'pages' => 1],
			(new TimelinePages())->timelinePage(merged: [], pageRequest: new PageRequest(page: 1, limit: 10))
		);
	}//end testAnEmptyTimeline()

	/**
	 * A worklog page past the end shows the last page instead of nothing.
	 *
	 * @return void
	 */
	public function testAWorklogPagePastTheEndShowsTheLastPage(): void {
		$page = (new TimelinePages())->worklogPage(items: self::items(12), pageRequest: new PageRequest(page: 9, limit: 5));

		self::assertSame([11, 12], array_column($page['items'], 'n'));
		self::assertSame(3, $page['page']);
		self::assertSame(3, $page['pages']);
	}//end testAWorklogPagePastTheEndShowsTheLastPage()

	/**
	 * Keys are renumbered from zero.
	 *
	 * @return void
	 */
	public function testItemsAreRenumbered(): void {
		$page = (new TimelinePages())->timelinePage(merged: ['a' => ['n' => 1], 'b' => ['n' => 2]], pageRequest: new PageRequest(page: 1, limit: 10));

		self::assertSame([0, 1], array_keys($page['items']));
	}//end testItemsAreRenumbered()
}//end class
