<?php

/**
 * Characterisation tests for SlaEngineService target evaluation and timer
 * resumption.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use OCA\Pipelinq\Service\BusinessHoursCalculator;
use OCA\Pipelinq\Service\HolidayCalendarService;
use OCA\Pipelinq\Service\SlaEngineService;
use OCP\IAppConfig;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Pins evaluateTargets() and resumeTimer() over a scripted business-hours
 * calculator: elapsed minutes are the wall-clock minutes, and every call
 * is recorded with its calendar arguments.
 */
class SlaEngineTimerGoldenTest extends TestCase {

	/**
	 * The calculator mock.
	 *
	 * @var BusinessHoursCalculator&MockObject
	 */
	private BusinessHoursCalculator&MockObject $hours;

	/**
	 * Calls made to the calculator.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * Build the engine.
	 *
	 * @return SlaEngineService
	 */
	private function engine(): SlaEngineService {
		$this->hours = $this->createMock(BusinessHoursCalculator::class);
		$this->hours->method('elapsedBusinessMinutes')->willReturnCallback(
			function (DateTimeInterface $from, DateTimeInterface $to, string $holidays, string $calendar): int {
				$this->calls[] = 'elapsed ' . $from->format('H:i') . '-' . $to->format('H:i') . ' ' . $holidays . ' ' . $calendar;
				return intdiv($to->getTimestamp() - $from->getTimestamp(), 60);
			}
		);
		$this->hours->method('addDuration')->willReturnCallback(
			function (string $calendarType, DateTimeInterface $startTime, DateInterval $duration, string $holidayCalendar): DateTimeImmutable {
				$this->calls[] = 'add ' . $startTime->format('H:i') . ' +' . $duration->i . 'm ' . $holidayCalendar . ' ' . $calendarType;
				return DateTimeImmutable::createFromInterface($startTime)->add($duration);
			}
		);

		return new SlaEngineService(
			$this->createMock(HolidayCalendarService::class),
			$this->hours,
			$this->createMock(ContainerInterface::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(INotificationManager::class),
			$this->createMock(LoggerInterface::class)
		);
	}//end engine()

	/**
	 * Every target outcome: met, unparseable, zero-length, on track, at
	 * risk, breached, and the default calendars.
	 *
	 * @return void
	 */
	public function testEvaluateTargetsGolden(): void {
		$engine = $this->engine();
		$targets = [
			'met' => ['status' => 'met', 'startedAt' => '2026-01-05T09:00:00+00:00', 'dueAt' => '2026-01-05T10:00:00+00:00'],
			'nostart' => ['status' => 'on-track', 'dueAt' => '2026-01-05T10:00:00+00:00'],
			'baddue' => ['startedAt' => '2026-01-05T09:00:00+00:00', 'dueAt' => 'garbage'],
			'zero' => ['startedAt' => '2026-01-05T09:00:00+00:00', 'dueAt' => '2026-01-05T09:00:00+00:00', 'calendar' => '24x7'],
			'ontrack' => ['startedAt' => '2026-01-05T09:00:00+00:00', 'dueAt' => '2026-01-05T11:00:00+00:00', 'status' => 'breached'],
			'atrisk' => ['startedAt' => '2026-01-05T09:12:00+00:00', 'dueAt' => '2026-01-05T09:57:00+00:00'],
			'exact80' => ['startedAt' => '2026-01-05T09:20:00+00:00', 'dueAt' => '2026-01-05T09:45:00+00:00'],
			'breached' => ['startedAt' => '2026-01-05T08:00:00+00:00', 'dueAt' => '2026-01-05T09:00:00+00:00'],
		];
		$now = new DateTimeImmutable('2026-01-05T09:40:00+00:00');

		$result = $engine->evaluateTargets($targets, ['holidayCalendar' => 'be'], $now);

		$this->assertSame($targets['met'], $result['met']);
		$this->assertSame($targets['nostart'], $result['nostart']);
		$this->assertSame($targets['baddue'], $result['baddue']);
		$this->assertSame(array_merge($targets['zero'], ['consumedPercentage' => 0.0, 'status' => 'on-track']), $result['zero']);
		$this->assertSame(array_merge($targets['ontrack'], ['consumedPercentage' => 0.3333, 'status' => 'on-track']), $result['ontrack']);
		$this->assertSame(array_merge($targets['atrisk'], ['consumedPercentage' => 0.6222, 'status' => 'on-track']), $result['atrisk']);
		$this->assertSame(array_merge($targets['exact80'], ['consumedPercentage' => 0.8, 'status' => 'at-risk']), $result['exact80']);
		$this->assertSame(array_merge($targets['breached'], ['consumedPercentage' => 1.6667, 'status' => 'breached']), $result['breached']);
		$this->assertSame(array_keys($targets), array_keys($result));
		$this->assertSame('elapsed 09:00-09:00 be 24x7', $this->calls[0]);
		$this->assertSame('elapsed 09:00-09:40 be 24x7', $this->calls[1]);
		$this->assertStringEndsWith(' be business-hours', $this->calls[2]);

		$this->calls = [];
		$engine->evaluateTargets([$targets['ontrack']], [], $now);
		$this->assertSame('elapsed 09:00-11:00 nl-feestdagen-rijksoverheid business-hours', $this->calls[0]);
	}//end testEvaluateTargetsGolden()

	/**
	 * Resuming: not paused, unparseable pause, and a real resume that
	 * extends only the targets that carry a dueAt.
	 *
	 * @return void
	 */
	public function testResumeTimerGolden(): void {
		$engine = $this->engine();
		$now = new DateTimeImmutable('2026-01-05T10:30:00+00:00');

		$this->assertSame(['pausedAt' => null, 'x' => 1], $engine->resumeTimer(['pausedAt' => null, 'x' => 1], [], $now));
		$this->assertSame(['pausedAt' => '', 'x' => 1], $engine->resumeTimer(['pausedAt' => '', 'x' => 1], [], $now));
		$this->assertSame(['x' => 1], $engine->resumeTimer(['x' => 1], [], $now));
		$this->assertSame(['pausedAt' => null, 'x' => 1], $engine->resumeTimer(['pausedAt' => 'garbage', 'x' => 1], [], $now));
		$this->assertSame([], $this->calls);

		$status = [
			'pausedAt' => '2026-01-05T10:00:00+00:00',
			'totalPausedMs' => '1000',
			'targets' => [
				['dueAt' => '2026-01-05T12:00:00+00:00', 'calendar' => '24x7'],
				['kind' => 'response'],
				['dueAt' => '', 'calendar' => '24x7'],
				['dueAt' => 'garbage'],
			],
			'other' => 'kept',
		];

		$result = $engine->resumeTimer($status, ['holidayCalendar' => 'de'], $now);

		$this->assertSame(
			[
				'pausedAt' => null,
				'totalPausedMs' => 1801000,
				'targets' => [
					['dueAt' => '2026-01-05T12:30:00+00:00', 'calendar' => '24x7'],
					['kind' => 'response'],
					['dueAt' => '', 'calendar' => '24x7'],
					['dueAt' => 'garbage'],
				],
				'other' => 'kept',
				'lastEvaluatedAt' => '2026-01-05T10:30:00+00:00',
			],
			$result
		);
		$this->assertSame(
			[
				'elapsed 10:00-10:30 de 24x7',
				'add 12:00 +30m de 24x7',
				'elapsed 10:00-10:30 de business-hours',
				'elapsed 10:00-10:30 de 24x7',
				'elapsed 10:00-10:30 de business-hours',
			],
			$this->calls
		);
	}//end testResumeTimerGolden()

	/**
	 * A pause that charged no minutes leaves every dueAt alone.
	 *
	 * @return void
	 */
	public function testResumeTimerWithoutChargedMinutes(): void {
		$engine = $this->engine();
		$now = new DateTimeImmutable('2026-01-05T10:00:30+00:00');

		$result = $engine->resumeTimer(
			['pausedAt' => '2026-01-05T10:00:00+00:00', 'targets' => [['dueAt' => '2026-01-05T12:00:00+00:00']]],
			[],
			$now
		);

		$this->assertSame([['dueAt' => '2026-01-05T12:00:00+00:00']], $result['targets']);
		$this->assertSame(30000, $result['totalPausedMs']);
		$this->assertSame(['elapsed 10:00-10:00 nl-feestdagen-rijksoverheid business-hours'], $this->calls);
	}//end testResumeTimerWithoutChargedMinutes()
}//end class
