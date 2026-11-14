<?php

/**
 * Unit tests for ExpiryWindow.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\BackgroundJob
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

namespace OCA\Pipelinq\Tests\Unit\BackgroundJob;

use DateTimeImmutable;
use OCA\Pipelinq\BackgroundJob\ExpiryWindow;
use PHPUnit\Framework\TestCase;

/**
 * ExpiryWindow turns a loyalty programme's expiry policy into cut-off moments.
 */
class ExpiryWindowTest extends TestCase {

	/**
	 * The moment the job runs.
	 *
	 * @return DateTimeImmutable
	 */
	private static function now(): DateTimeImmutable {
		return new DateTimeImmutable('2026-11-14T03:00:00+00:00');
	}//end now()

	/**
	 * A programme without a policy does not expire points, and the default notice is 30 days.
	 *
	 * @return void
	 */
	public function testNoPolicyMeansNoExpiry(): void {
		$window = new ExpiryWindow(programme: [], now: self::now());

		self::assertFalse($window->applies);
		self::assertSame(30, $window->notice);
		self::assertEquals(self::now(), $window->cutoff);
		self::assertEquals(self::now(), $window->noticeCutoff);
	}//end testNoPolicyMeansNoExpiry()

	/**
	 * A policy that is not a list counts as none.
	 *
	 * @return void
	 */
	public function testAMalformedPolicyCountsAsNone(): void {
		self::assertFalse((new ExpiryWindow(programme: ['expiryPolicy' => 'twelve months'], now: self::now()))->applies);
	}//end testAMalformedPolicyCountsAsNone()

	/**
	 * Another policy type, or no months, does not expire points.
	 *
	 * @return void
	 */
	public function testOnlyInactivityMonthsWithAValueApply(): void {
		self::assertFalse((new ExpiryWindow(programme: ['expiryPolicy' => ['type' => 'fixedDate', 'value' => 12]], now: self::now()))->applies);
		self::assertFalse((new ExpiryWindow(programme: ['expiryPolicy' => ['type' => 'inactivityMonths', 'value' => 0]], now: self::now()))->applies);
	}//end testOnlyInactivityMonthsWithAValueApply()

	/**
	 * Twelve months of inactivity with a 30-day notice: cut-off a year back, notice one month later.
	 *
	 * @return void
	 */
	public function testInactivityMonthsSetBothCutOffs(): void {
		$window = new ExpiryWindow(
			programme: ['expiryPolicy' => ['type' => 'inactivityMonths', 'value' => 12, 'advanceNoticeDays' => 30]],
			now: self::now()
		);

		self::assertTrue($window->applies);
		self::assertSame(30, $window->notice);
		self::assertSame('2025-11-14', $window->cutoff->format('Y-m-d'));
		self::assertSame('2025-12-14', $window->noticeCutoff->format('Y-m-d'));
	}//end testInactivityMonthsSetBothCutOffs()

	/**
	 * The notice is rounded up to whole months.
	 *
	 * @return void
	 */
	public function testTheNoticeRoundsUpToWholeMonths(): void {
		$window = new ExpiryWindow(
			programme: ['expiryPolicy' => ['type' => 'inactivityMonths', 'value' => '6', 'advanceNoticeDays' => '45']],
			now: self::now()
		);

		self::assertSame(45, $window->notice);
		self::assertSame('2026-05-14', $window->cutoff->format('Y-m-d'));
		self::assertSame('2026-07-14', $window->noticeCutoff->format('Y-m-d'));
	}//end testTheNoticeRoundsUpToWholeMonths()

	/**
	 * A notice longer than the policy never moves the notice cut-off past now.
	 *
	 * @return void
	 */
	public function testALongNoticeStopsAtNow(): void {
		$window = new ExpiryWindow(
			programme: ['expiryPolicy' => ['type' => 'inactivityMonths', 'value' => 1, 'advanceNoticeDays' => 90]],
			now: self::now()
		);

		self::assertSame('2026-10-14', $window->cutoff->format('Y-m-d'));
		self::assertSame('2026-11-14', $window->noticeCutoff->format('Y-m-d'));
	}//end testALongNoticeStopsAtNow()
}//end class
