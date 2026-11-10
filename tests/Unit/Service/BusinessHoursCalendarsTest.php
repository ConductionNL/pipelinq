<?php

/**
 * Unit tests for the calendar walks of BusinessHoursCalculator.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/sla-engine-and-escalation/spec.md#requirement-holiday-aware-deadline-calculation
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use DateInterval;
use DateTimeImmutable;
use OCA\Pipelinq\Service\BusinessHoursCalculator;
use OCA\Pipelinq\Service\HolidayCalendarService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the deadline and elapsed-time walks of every calendar mode on fixed dates.
 */
final class BusinessHoursCalendarsTest extends TestCase {
	/**
	 * Every error logged, as [message, context].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $errors = [];

	/**
	 * A calculator over a configured window and a set of holiday dates.
	 *
	 * @param array<string, string> $config   App config values.
	 * @param array<int, string>    $holidays Holiday dates (Y-m-d) of calendar `nl`.
	 *
	 * @return BusinessHoursCalculator The calculator.
	 */
	private function calculator(array $config = [], array $holidays = []): BusinessHoursCalculator {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default)
		);
		$calendar = $this->createMock(HolidayCalendarService::class);
		$calendar->method('isHoliday')->willReturnCallback(
			static fn (string $slug, \DateTimeInterface $day): bool => $slug === 'nl' && in_array($day->format('Y-m-d'), $holidays, true)
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->errors[] = [$message, $context];
			}
		);

		return new BusinessHoursCalculator($calendar, $appConfig, $logger);
	}//end calculator()

	/**
	 * Add a duration and format the deadline.
	 *
	 * @param BusinessHoursCalculator $calc     The calculator.
	 * @param string                  $type     The calendar type.
	 * @param string                  $start    The start (ISO, UTC).
	 * @param string                  $duration The ISO 8601 duration.
	 *
	 * @return string The deadline (Y-m-d H:i, UTC).
	 */
	private function deadline(BusinessHoursCalculator $calc, string $type, string $start, string $duration): string {
		return $calc->addDuration($type, new DateTimeImmutable($start), new DateInterval($duration), 'nl')->format('Y-m-d H:i');
	}//end deadline()

	/**
	 * Deadlines in every calendar mode, from before, inside and after the day's window.
	 *
	 * 2026-11-06 is a Friday, 2026-11-07 a Saturday, 2026-11-08 a Sunday.
	 *
	 * @return void
	 */
	public function testDeadlinesInEveryCalendarMode(): void {
		$calc = $this->calculator(holidays: ['2026-11-09']);
		$cases = [
			['business-hours', '2026-11-06T07:00:00Z', 'PT2H', '2026-11-06 11:00'],
			['business-hours', '2026-11-06T16:00:00Z', 'PT2H', '2026-11-10 10:00'],
			['business-hours', '2026-11-06T18:00:00Z', 'PT30M', '2026-11-10 09:30'],
			['business-hours', '2026-11-06T09:00:00Z', 'PT8H', '2026-11-06 17:00'],
			['business-hours', '2026-11-06T09:00:00Z', 'PT0M', '2026-11-06 09:00'],
			['extended-business-hours', '2026-11-06T17:00:00Z', 'PT3H', '2026-11-07 11:00'],
			['extended-business-hours', '2026-11-07T12:00:00Z', 'PT2H', '2026-11-10 09:00'],
			['extended-business-hours', '2026-11-08T10:00:00Z', 'PT1H', '2026-11-10 09:00'],
			['some-other-mode', '2026-11-06T16:00:00Z', 'PT2H', '2026-11-10 10:00'],
			['24x7', '2026-11-06T16:00:00Z', 'PT10H', '2026-11-07 02:00'],
		];
		foreach ($cases as [$type, $start, $duration, $expected]) {
			$this->assertSame($expected, $this->deadline($calc, $type, $start, $duration), $type.' from '.$start.' + '.$duration);
		}

		$this->assertSame([], $this->errors);
	}//end testDeadlinesInEveryCalendarMode()

	/**
	 * A configured window applies to business-hours only; a malformed one falls back to 09:00-17:00.
	 *
	 * @return void
	 */
	public function testConfiguredWindow(): void {
		$calc = $this->calculator(config: ['sla_business_hours_start' => '10:00', 'sla_business_hours_end' => '12:00']);
		$this->assertSame('2026-11-09 11:00', $this->deadline($calc, 'business-hours', '2026-11-06T11:00:00Z', 'PT2H'));
		$this->assertSame('2026-11-06 10:00', $this->deadline($calc, 'extended-business-hours', '2026-11-06T08:00:00Z', 'PT2H'));

		$calc = $this->calculator(config: ['sla_business_hours_start' => '9am', 'sla_business_hours_end' => '25:61']);
		$this->assertSame(['start' => '09:00', 'end' => '25:61'], $calc->getBusinessHoursWindow());
	}//end testConfiguredWindow()

	/**
	 * An all-holiday calendar hits the safety bound, logs it and answers where the walk stopped.
	 *
	 * @return void
	 */
	public function testAllHolidayCalendarHitsTheSafetyBound(): void {
		$days = [];
		for ($day = new DateTimeImmutable('2026-11-06'); $day < new DateTimeImmutable('2029-01-01'); $day = $day->modify('+1 day')) {
			$days[] = $day->format('Y-m-d');
		}

		$calc = $this->calculator(holidays: $days);

		$this->assertSame('2028-11-05 00:00', $this->deadline($calc, 'business-hours', '2026-11-06T10:00:00Z', 'PT1H'));
		$this->assertSame(
			[[
				'BusinessHoursCalculator: hit safety bound advancing deadline; calendar likely misconfigured',
				['calendarType' => 'business-hours', 'holidayCalendar' => 'nl'],
			]],
			$this->errors
		);
	}//end testAllHolidayCalendarHitsTheSafetyBound()

	/**
	 * Elapsed minutes count only open time, per calendar mode.
	 *
	 * @return void
	 */
	public function testElapsedMinutesPerCalendarMode(): void {
		$calc = $this->calculator(holidays: ['2026-11-09']);
		$cases = [
			['business-hours', '2026-11-06T16:00:00Z', '2026-11-10T10:00:00Z', 120],
			['business-hours', '2026-11-06T07:00:00Z', '2026-11-06T08:00:00Z', 0],
			['business-hours', '2026-11-06T12:00:00Z', '2026-11-06T12:30:59Z', 30],
			['extended-business-hours', '2026-11-06T17:00:00Z', '2026-11-10T09:00:00Z', 360],
			['some-other-mode', '2026-11-06T16:00:00Z', '2026-11-10T10:00:00Z', 120],
			['24x7', '2026-11-06T16:00:00Z', '2026-11-06T17:30:00Z', 90],
			['24x7', '2026-11-06T17:30:00Z', '2026-11-06T16:00:00Z', 0],
			['business-hours', '2026-11-06T12:00:00Z', '2026-11-06T12:00:00Z', 0],
		];
		foreach ($cases as [$type, $start, $end, $expected]) {
			$this->assertSame(
				$expected,
				$calc->elapsedBusinessMinutes(new DateTimeImmutable($start), new DateTimeImmutable($end), 'nl', $type),
				$type.' '.$start.' -> '.$end
			);
		}
	}//end testElapsedMinutesPerCalendarMode()
}//end class
