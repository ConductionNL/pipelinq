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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests covering holiday loading, Easter math and lustrum handling.
 */
class HolidayCalendarServiceTest extends TestCase
{
    private IAppConfig $appConfig;

    private LoggerInterface $logger;

    private HolidayCalendarService $service;

    /**
     * Build mocks + service under test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->logger    = $this->createMock(LoggerInterface::class);
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
    public function testKoningsdagIsHoliday(): void
    {
        $this->assertTrue(
            $this->service->isHoliday('nl-feestdagen-rijksoverheid', new DateTimeImmutable('2026-04-27'))
        );
    }//end testKoningsdagIsHoliday()

    /**
     * Random working day is not a holiday.
     *
     * @return void
     */
    public function testRegularDayIsNotHoliday(): void
    {
        $this->assertFalse(
            $this->service->isHoliday('nl-feestdagen-rijksoverheid', new DateTimeImmutable('2026-05-13'))
        );
    }//end testRegularDayIsNotHoliday()

    /**
     * Easter date is computed correctly (Meeus algorithm).
     *
     * @return void
     */
    public function testEasterDate2026(): void
    {
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
    public function testTweedePaasdag2026(): void
    {
        $this->assertTrue(
            $this->service->isHoliday('nl-feestdagen-rijksoverheid', new DateTimeImmutable('2026-04-06'))
        );
    }//end testTweedePaasdag2026()

    /**
     * Lustrum-only Bevrijdingsdag: only in years divisible by 5.
     *
     * @return void
     */
    public function testBevrijdingsdagLustrum(): void
    {
        // 2025 is divisible by 5 → holiday.
        $this->assertTrue(
            $this->service->isHoliday('nl-feestdagen-rijksoverheid', new DateTimeImmutable('2025-05-05'))
        );
        // 2026 is NOT divisible by 5 → not a holiday by default.
        $this->assertFalse(
            $this->service->isHoliday('nl-feestdagen-rijksoverheid', new DateTimeImmutable('2026-05-05'))
        );
    }//end testBevrijdingsdagLustrum()

    /**
     * `none` and empty calendar names return false for any date.
     *
     * @return void
     */
    public function testNoneCalendarReturnsFalse(): void
    {
        $this->assertFalse($this->service->isHoliday('none', new DateTimeImmutable('2026-12-25')));
        $this->assertFalse($this->service->isHoliday('', new DateTimeImmutable('2026-12-25')));
    }//end testNoneCalendarReturnsFalse()

    /**
     * Composite calendar union: NL + BE July 21 (Belgian national day).
     *
     * @return void
     */
    public function testCompositeCalendarUnion(): void
    {
        $this->assertTrue(
            $this->service->isHoliday('nl-feestdagen-rijksoverheid,be-feestdagen', new DateTimeImmutable('2026-07-21'))
        );
    }//end testCompositeCalendarUnion()

    /**
     * Tenant override flag forces lustrum holiday on every year.
     *
     * @return void
     */
    public function testLustrumTenantOverride(): void
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $logger    = $this->createMock(LoggerInterface::class);
        $appConfig->method('getValueString')->willReturnCallback(
            static function (string $app, string $key, string $default = ''): string {
                if ($key === 'sla_bevrijdingsdag_yearly') {
                    return 'true';
                }

                return $default;
            }
        );

        $service = new HolidayCalendarService($appConfig, $logger);
        $this->assertTrue(
            $service->isHoliday('nl-feestdagen-rijksoverheid', new DateTimeImmutable('2026-05-05'))
        );
    }//end testLustrumTenantOverride()
}//end class
