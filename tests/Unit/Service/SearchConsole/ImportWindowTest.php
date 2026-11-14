<?php

/**
 * Unit tests for the Search Console ImportWindow.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\SearchConsole
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\SearchConsole;

use OCA\Pipelinq\Service\SearchConsole\ImportWindow;
use PHPUnit\Framework\TestCase;

/**
 * Pins the UTC day the window ends on and how far back it starts.
 *
 * @covers \OCA\Pipelinq\Service\SearchConsole\ImportWindow
 */
class ImportWindowTest extends TestCase {

	/**
	 * The window ends on the UTC day of the timestamp and starts the given
	 * number of days earlier.
	 *
	 * @return void
	 */
	public function testTheWindowEndsTodayInUtcAndStartsDaysBack(): void {
		// 2026-03-01 23:30 UTC; in Amsterdam it is already 2 March.
		$window = new ImportWindow(1772407800, 3);

		$this->assertSame('UTC', $window->today()->getTimezone()->getName());
		$this->assertSame('2026-03-01', $window->lastDay());
		$this->assertSame('2026-02-26', $window->firstDay());
	}//end testTheWindowEndsTodayInUtcAndStartsDaysBack()

	/**
	 * A negative day count is a window of one day.
	 *
	 * @return void
	 */
	public function testANegativeDayCountIsOneDay(): void {
		$window = new ImportWindow(1772407800, -5);

		$this->assertSame($window->lastDay(), $window->firstDay());
	}//end testANegativeDayCountIsOneDay()
}//end class
