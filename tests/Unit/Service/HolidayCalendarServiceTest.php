<?php

/**
 * Unit tests for HolidayCalendarService.
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
 * @spec openspec/specs/sla-engine-and-escalation/spec.md#requirement-holiday-calendar-pluggability
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Pipelinq\Service\HolidayCalendarService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests covering holiday loading, Easter math and lustrum handling.
 */
class HolidayCalendarServiceTest extends TestCase {
	private IAppConfig $appConfig;

	private LoggerInterface $logger;

	private HolidayCalendarService $service;

	/**
	 * Build mocks + service under test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				return $default;
			}
		);
		$this->service = new HolidayCalendarService($this->appConfig, $this->logger);
	}//end setUp()

	/**
	 * Koningsdag is recognised in the NL calendar.
	 *
	 * @return void
	 */
	public function testKoningsdagIsHoliday(): void {
		$this->assertTrue($this->service->isHoliday('nl-feestdagen-rijksoverheid', new DateTimeImmutable('2026-04-27'))
		);
	}//end testKoningsdagIsHoliday()

	/**
	 * Random working day is not a holiday.
	 *
	 * @return void
	 */
	public function testRegularDayIsNotHoliday(): void {
		$this->assertFalse($this->service->isHoliday('nl-feestdagen-rijksoverheid', new DateTimeImmutable('2026-05-13'))
		);
	}//end testRegularDayIsNotHoliday()

	/**
	 * Easter date is computed correctly (Meeus algorithm).
	 *
	 * @return void
	 */
	public function testEasterDate2026(): void {
		$this->assertEquals(
			'2026-04-05',
			$this->service->easterDate(2026)->format('Y-m-d')
		);
	}//end testEasterDate2026()

	/**
	 * Tweede Paasdag (Easter+1) is a holiday in 2026.
	 *
	 * @return void
	 */
	public function testTweedePaasdag2026(): void {
		$this->assertTrue($this->service->isHoliday('nl-feestdagen-rijksoverheid', new DateTimeImmutable('2026-04-06'))
		);
	}//end testTweedePaasdag2026()

	/**
	 * Lustrum-only Bevrijdingsdag: only in years divisible by 5.
	 *
	 * @return void
	 */
	public function testBevrijdingsdagLustrum(): void {
		// 2025 is divisible by 5 → holiday.
		$this->assertTrue($this->service->isHoliday('nl-feestdagen-rijksoverheid', new DateTimeImmutable('2025-05-05'))
		);
		// 2026 is NOT divisible by 5 → not a holiday by default.
		$this->assertFalse($this->service->isHoliday('nl-feestdagen-rijksoverheid', new DateTimeImmutable('2026-05-05'))
		);
	}//end testBevrijdingsdagLustrum()

	/**
	 * `none` and empty calendar names return false for any date.
	 *
	 * @return void
	 */
	public function testNoneCalendarReturnsFalse(): void {
		$this->assertFalse($this->service->isHoliday('none', new DateTimeImmutable('2026-12-25')));
		$this->assertFalse($this->service->isHoliday('', new DateTimeImmutable('2026-12-25')));
	}//end testNoneCalendarReturnsFalse()

	/**
	 * Composite calendar union: NL + BE July 21 (Belgian national day).
	 *
	 * @return void
	 */
	public function testCompositeCalendarUnion(): void {
		$this->assertTrue($this->service->isHoliday('nl-feestdagen-rijksoverheid,be-feestdagen', new DateTimeImmutable('2026-07-21'))
		);
	}//end testCompositeCalendarUnion()

	/**
	 * Tenant override flag forces lustrum holiday on every year.
	 *
	 * @return void
	 */
	public function testLustrumTenantOverride(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$logger = $this->createMock(LoggerInterface::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				if ($key === 'sla_bevrijdingsdag_yearly') {
					return 'true';
				}

				return $default;
			}
		);

		$service = new HolidayCalendarService($appConfig, $logger);
		$this->assertTrue($service->isHoliday('nl-feestdagen-rijksoverheid', new DateTimeImmutable('2026-05-05'))
		);
	}//end testLustrumTenantOverride()
	/**
	 * Build a service whose app config answers from a map and records every
	 * key it is asked for, in order.
	 *
	 * @param array<string, string> $values The configured values.
	 * @param \ArrayObject<int, string> $reads The keys read, appended in order.
	 *
	 * @return HolidayCalendarService The service.
	 */
	private function serviceWithConfig(array $values, \ArrayObject $reads): HolidayCalendarService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($values, $reads): string {
				$reads[] = $key;
				return $values[$key] ?? $default;
			}
		);

		return new HolidayCalendarService($appConfig, $this->createMock(LoggerInterface::class));
	}//end serviceWithConfig()

	/**
	 * The resolved holiday set of a year.
	 *
	 * @param HolidayCalendarService $service      The service.
	 * @param string                 $calendarName The calendar name.
	 * @param int                    $year         The year.
	 *
	 * @return array<string, bool> The set, in build order.
	 */
	private function yearSet(HolidayCalendarService $service, string $calendarName, int $year): array {
		$method = new \ReflectionMethod(HolidayCalendarService::class, 'resolveYearSet');
		$method->setAccessible(true);
		return $method->invoke($service, $calendarName, $year);
	}//end yearSet()

	/**
	 * The whole set of a non-lustrum year: fixed rules first, then the Easter
	 * based dates, then the tenant overrides of each named calendar (a
	 * month-day override gets the year, a full date stays as it is, an entry
	 * without a date is ignored). The lustrum check reads its flag per
	 * lustrum rule before the overrides are read, and a second call is cached.
	 *
	 * @return void
	 */
	public function testYearSetInBuildOrderWithOverrides(): void {
		$reads = new \ArrayObject();
		$overrides = json_encode(
			[
				'nl-feestdagen-rijksoverheid' => [['date' => '12-24'], ['date' => '2027-01-02'], ['name' => 'no date'], ['date' => '04-27']],
				'other' => [['date' => '01-02']],
			]
		);
		$service = $this->serviceWithConfig(['sla_tenant_holiday_overrides' => (string)$overrides], $reads);

		$set = $this->yearSet($service, 'nl-feestdagen-rijksoverheid', 2026);
		$this->assertSame(
			[
				'2026-01-01' => true,
				'2026-04-27' => true,
				'2026-12-25' => true,
				'2026-12-26' => true,
				'2026-04-03' => true,
				'2026-04-05' => true,
				'2026-04-06' => true,
				'2026-05-14' => true,
				'2026-05-24' => true,
				'2026-05-25' => true,
				'2026-12-24' => true,
				'2027-01-02' => true,
			],
			$set
		);
		$this->assertSame(['sla_bevrijdingsdag_yearly', 'sla_tenant_holiday_overrides'], $reads->getArrayCopy());

		$this->assertSame($set, $this->yearSet($service, 'nl-feestdagen-rijksoverheid', 2026));
		$this->assertCount(2, $reads);
	}//end testYearSetInBuildOrderWithOverrides()

	/**
	 * A lustrum year keeps Bevrijdingsdag without reading the flag, and a
	 * composite name is trimmed and reads the overrides of each part.
	 *
	 * @return void
	 */
	public function testCompositeLustrumYearSet(): void {
		$reads = new \ArrayObject();
		$overrides = json_encode(['be-feestdagen' => [['date' => '03-03']], 'nl-feestdagen-rijksoverheid' => [['date' => '03-02']]]);
		$service = $this->serviceWithConfig(['sla_tenant_holiday_overrides' => (string)$overrides], $reads);

		$set = $this->yearSet($service, ' nl-feestdagen-rijksoverheid , be-feestdagen ,', 2025);

		$this->assertArrayHasKey('2025-05-05', $set);
		$this->assertArrayHasKey('2025-07-21', $set);
		$this->assertSame(['2025-03-02', '2025-03-03'], array_slice(array_keys($set), -2));
		$this->assertSame(['sla_tenant_holiday_overrides'], $reads->getArrayCopy());
	}//end testCompositeLustrumYearSet()
	/**
	 * A missing calendar file logs one warning with its path, falls back to
	 * an empty calendar of that name and is cached; 'none' and '' are empty
	 * without a lookup.
	 *
	 * @return void
	 */
	public function testMissingCalendarFallsBackOnceAndIsCached(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'HolidayCalendarService: calendar file not found, falling back to empty',
			$this->callback(
				static fn (array $context): bool => $context['calendar'] === 'xx-missing'
					&& str_ends_with($context['path'], '/../Resources/holidays/nl/xx-missing.json')
			)
		);
		$logger->expects($this->never())->method('error');
		$service = new HolidayCalendarService($this->appConfig, $logger);

		$empty = ['name' => 'xx-missing', 'rules' => [], 'computed' => []];
		$this->assertSame($empty, $service->loadCalendar('xx-missing'));
		$this->assertSame($empty, $service->loadCalendar('xx-missing'));
		$this->assertSame(['name' => 'none', 'rules' => [], 'computed' => []], $service->loadCalendar(''));
		$this->assertSame(['name' => 'none', 'rules' => [], 'computed' => []], $service->loadCalendar('none'));

		$calendar = $service->loadCalendar('nl-feestdagen-rijksoverheid');
		$this->assertSame('Nederlandse Rijksoverheid feestdagen', $calendar['name']);
		$this->assertCount(5, $calendar['rules']);
	}//end testMissingCalendarFallsBackOnceAndIsCached()
}//end class
