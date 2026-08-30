<?php

/**
 * Unit tests for BusinessHoursCalculator.
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
 * Verify 24x7 / business-hours / extended-business-hours arithmetic.
 */
class BusinessHoursCalculatorTest extends TestCase {
	private IAppConfig $appConfig;

	private HolidayCalendarService $holidays;

	private BusinessHoursCalculator $calc;

	/**
	 * Build the service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->holidays = $this->createMock(HolidayCalendarService::class);
		$logger = $this->createMock(LoggerInterface::class);

		$this->appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				return $default;
			}
		);
		$this->holidays->method('isHoliday')->willReturn(false);

		$this->calc = new BusinessHoursCalculator($this->holidays, $this->appConfig, $logger);
	}//end setUp()

	/**
	 * 24x7 + 4h = simple wall-clock addition.
	 *
	 * @return void
	 */
	public function test24x7AddsWallClockHours(): void {
		$start = new DateTimeImmutable('2026-05-15T10:00:00Z');
		$end = $this->calc->addDuration(
			BusinessHoursCalculator::CALENDAR_24X7,
			$start,
			new DateInterval('PT4H'),
			'none'
		);

		$this->assertSame('2026-05-15T14:00:00+00:00', $end->format(DateTimeImmutable::ATOM));
	}//end test24x7AddsWallClockHours()

	/**
	 * Spec REQ-002 scenario: Friday 17:00 + 4 business-hours = Monday 12:00.
	 *
	 * Note: with the default 09:00-17:00 window, starting AT 17:00 leaves
	 * zero capacity on Friday, so all 4 business hours roll to Monday
	 * (09:00 → 13:00). We assert this exact behaviour.
	 *
	 * @return void
	 */
	public function testBusinessHoursWeekendRollover(): void {
		$start = new DateTimeImmutable('2026-05-15T17:00:00Z'); // Friday.
		$end = $this->calc->addDuration(
			BusinessHoursCalculator::CALENDAR_BUSINESS,
			$start,
			new DateInterval('PT4H'),
			'none'
		);

		// Friday 17:00 → cursor jumps to Monday 09:00; +4h → 13:00.
		$this->assertSame(2026, (int)$end->format('Y'));
		$this->assertSame('05-18', $end->format('m-d'));
		$this->assertSame('13:00', $end->format('H:i'));
	}//end testBusinessHoursWeekendRollover()

	/**
	 * Business-hours add inside the same working day stays put.
	 *
	 * @return void
	 */
	public function testBusinessHoursWithinDay(): void {
		$start = new DateTimeImmutable('2026-05-12T10:00:00Z'); // Tuesday.
		$end = $this->calc->addDuration(
			BusinessHoursCalculator::CALENDAR_BUSINESS,
			$start,
			new DateInterval('PT2H'),
			'none'
		);

		$this->assertSame('2026-05-12T12:00:00+00:00', $end->format(DateTimeImmutable::ATOM));
	}//end testBusinessHoursWithinDay()

	/**
	 * Business-hours skips a holiday day.
	 *
	 * @return void
	 */
	public function testBusinessHoursSkipsHoliday(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$holidays = $this->createMock(HolidayCalendarService::class);
		$logger = $this->createMock(LoggerInterface::class);

		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				return $default;
			}
		);

		// Koningsdag 2026-04-27 (Monday) is a holiday.
		$holidays->method('isHoliday')->willReturnCallback(
			static function (string $cal, $date): bool {
				return $date->format('Y-m-d') === '2026-04-27';
			}
		);

		$calc = new BusinessHoursCalculator($holidays, $appConfig, $logger);
		$start = new DateTimeImmutable('2026-04-24T16:00:00Z'); // Friday.
		$end = $calc->addDuration(
			BusinessHoursCalculator::CALENDAR_BUSINESS,
			$start,
			new DateInterval('PT2H'),
			'nl-feestdagen-rijksoverheid'
		);

		// Friday 16-17 covers 1 hour. Saturday/Sunday skipped, Monday
		// is Koningsdag → also skipped. Tuesday 09:00 + 1 = 10:00.
		$this->assertSame('04-28', $end->format('m-d'));
		$this->assertSame('10:00', $end->format('H:i'));
	}//end testBusinessHoursSkipsHoliday()

	/**
	 * elapsedBusinessMinutes returns 0 when end <= start.
	 *
	 * @return void
	 */
	public function testElapsedZeroWhenInverted(): void {
		$start = new DateTimeImmutable('2026-05-12T10:00:00Z');
		$end = new DateTimeImmutable('2026-05-12T08:00:00Z');
		$this->assertSame(0, $this->calc->elapsedBusinessMinutes($start, $end, 'none'));
	}//end testElapsedZeroWhenInverted()

	/**
	 * elapsedBusinessMinutes within a single workday is exact.
	 *
	 * @return void
	 */
	public function testElapsedWithinDay(): void {
		$start = new DateTimeImmutable('2026-05-12T10:00:00Z');
		$end = new DateTimeImmutable('2026-05-12T12:30:00Z');
		$this->assertSame(150, $this->calc->elapsedBusinessMinutes($start, $end, 'none'));
	}//end testElapsedWithinDay()

	/**
	 * Default business-hours window can be read.
	 *
	 * @return void
	 */
	public function testBusinessHoursWindowDefaults(): void {
		$window = $this->calc->getBusinessHoursWindow();
		$this->assertSame('09:00', $window['start']);
		$this->assertSame('17:00', $window['end']);
	}//end testBusinessHoursWindowDefaults()
}//end class
