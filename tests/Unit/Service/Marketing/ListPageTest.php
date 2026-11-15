<?php

/**
 * Unit tests for ListPage.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Marketing
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

namespace OCA\Pipelinq\Tests\Unit\Service\Marketing;

use OCA\Pipelinq\Service\Marketing\ListPage;
use PHPUnit\Framework\TestCase;

/**
 * ListPage clamps a page request and wraps a page of results.
 */
class ListPageTest extends TestCase {

	/**
	 * Page and limit are clamped to at least 1 and a limit of at most 100.
	 *
	 * @return void
	 */
	public function testThePageIsClamped(): void {
		$low = ListPage::clamped(page: 0, limit: 0);
		self::assertSame(1, $low->page);
		self::assertSame(1, $low->limit);

		$high = ListPage::clamped(page: 7, limit: 500);
		self::assertSame(7, $high->page);
		self::assertSame(100, $high->limit);

		self::assertSame(25, ListPage::clamped(page: -3, limit: 25)->limit);
	}//end testThePageIsClamped()

	/**
	 * The window skips the earlier pages.
	 *
	 * @return void
	 */
	public function testTheWindow(): void {
		self::assertSame(['limit' => 20, 'offset' => 0], (new ListPage(page: 1, limit: 20))->window());
		self::assertSame(['limit' => 20, 'offset' => 40], (new ListPage(page: 3, limit: 20))->window());
	}//end testTheWindow()

	/**
	 * A newest-first query carries the filters, the window and the sort.
	 *
	 * @return void
	 */
	public function testANewestFirstQuery(): void {
		self::assertSame(
			['filters' => ['listId' => 'l-1'], 'limit' => 10, 'offset' => 10, 'sort' => ['createdAt' => 'desc']],
			(new ListPage(page: 2, limit: 10))->newestFirst(filters: ['listId' => 'l-1'])
		);
	}//end testANewestFirstQuery()

	/**
	 * The envelope counts pages, rounding up, and no pages without results.
	 *
	 * @return void
	 */
	public function testTheEnvelope(): void {
		$page = new ListPage(page: 2, limit: 10);

		self::assertSame(
			['data' => [['id' => 'a']], 'pagination' => ['page' => 2, 'limit' => 10, 'total' => 11, 'pages' => 2]],
			$page->envelope(data: [['id' => 'a']], total: 11)
		);
		self::assertSame(0, $page->envelope(data: [], total: 0)['pagination']['pages']);
		self::assertSame(1, $page->envelope(data: [], total: 10)['pagination']['pages']);
		self::assertSame(0, (new ListPage(page: 1, limit: 0))->envelope(data: [], total: 5)['pagination']['pages']);
	}//end testTheEnvelope()
}//end class
