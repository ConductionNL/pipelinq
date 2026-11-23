<?php

/**
 * Unit tests for TaskService deadlines.
 *
 * A task deadline counts business hours only: hours outside the configured
 * business day and whole weekends do not count. The default deadline is the
 * end of the next working day.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use DateTime;
use OCA\Pipelinq\Service\TaskService;
use OCP\IAppConfig;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for TaskService.
 */
class TaskServiceDeadlineTest extends TestCase {

	/**
	 * Build the service with the given business day.
	 *
	 * @param int $start First business hour.
	 * @param int $end Hour the business day ends.
	 *
	 * @return TaskService
	 */
	private function service(int $start, int $end): TaskService {
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueInt')->willReturnCallback(
			static fn (string $app, string $key): int => match ($key) {
				'task.business_hour_start' => $start,
				'task.business_hour_end' => $end,
			}
		);

		return new TaskService(
			userSession: $this->createMock(originalClassName: IUserSession::class),
			appConfig: $config,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
	}//end service()

	/**
	 * Hours inside one business day count one by one.
	 *
	 * @return void
	 */
	public function testCountsHoursWithinTheBusinessDay(): void {
		self::assertSame(
			expected: '2026-11-11T12:00:00+00:00',
			actual: $this->service(start: 8, end: 17)->calculateDeadline(createdAt: '2026-11-11T09:00:00+00:00', businessHours: 3)
		);
	}//end testCountsHoursWithinTheBusinessDay()

	/**
	 * Evenings and the weekend are skipped.
	 *
	 * @return void
	 */
	public function testSkipsEveningsAndTheWeekend(): void {
		self::assertSame(
			expected: '2026-11-16T10:00:00+00:00',
			actual: $this->service(start: 8, end: 17)->calculateDeadline(createdAt: '2026-11-13T16:00:00+00:00', businessHours: 3)
		);
	}//end testSkipsEveningsAndTheWeekend()

	/**
	 * The configured business day is used instead of the default.
	 *
	 * @return void
	 */
	public function testUsesTheConfiguredBusinessDay(): void {
		self::assertSame(
			expected: '2026-11-12T11:30:00+00:00',
			actual: $this->service(start: 10, end: 12)->calculateDeadline(createdAt: '2026-11-11T11:30:00+00:00', businessHours: 2)
		);
	}//end testUsesTheConfiguredBusinessDay()

	/**
	 * Zero business hours leaves the creation time unchanged.
	 *
	 * @return void
	 */
	public function testZeroHoursIsTheCreationTime(): void {
		self::assertSame(
			expected: '2026-11-14T03:00:00+00:00',
			actual: $this->service(start: 8, end: 17)->calculateDeadline(createdAt: '2026-11-14T03:00:00+00:00', businessHours: 0)
		);
	}//end testZeroHoursIsTheCreationTime()

	/**
	 * The default deadline is the end of the business day on a later weekday.
	 *
	 * @return void
	 */
	public function testDefaultDeadlineIsTheEndOfTheNextWorkingDay(): void {
		$deadline = new DateTime($this->service(start: 8, end: 16)->getDefaultDeadline());

		self::assertSame(expected: '16:00:00', actual: $deadline->format('H:i:s'));
		self::assertLessThanOrEqual(expected: 5, actual: (int)$deadline->format('N'));
		self::assertGreaterThan(expected: new DateTime(), actual: $deadline);
	}//end testDefaultDeadlineIsTheEndOfTheNextWorkingDay()
}//end class
