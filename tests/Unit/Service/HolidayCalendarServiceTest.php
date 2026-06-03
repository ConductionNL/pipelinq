<?php

/**
 * Unit tests for HolidayCalendarService.
 *
 * Exercises bundled-calendar loading, lustrum handling, composite (NL+BE)
 * unioning, and per-tenant overrides (REQ-010).
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
 * @link https://codeberg.org/Conduction/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Pipelinq\Service\HolidayCalendarService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for HolidayCalendarService.
 */
class HolidayCalendarServiceTest extends TestCase
{
    /**
     * Build a service whose tenant-override config returns the given JSON.
     *
     * @param string $overrideJson The override JSON (default empty).
     *
     * @return HolidayCalendarService The service under test.
     */
    private function makeService(string $overrideJson = ''): HolidayCalendarService
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            static function (string $app, string $key, string $default = '') use ($overrideJson): string {
                if ($key === HolidayCalendarService::OVERRIDES_KEY) {
                    return $overrideJson;
                }

                return $default;
            }
        );

        return new HolidayCalendarService($appConfig, $this->createMock(LoggerInterface::class));
    }//end makeService()

    /**
     * Koningsdag (2026-04-27) is a recognised NL holiday.
     *
     * @return void
     */
    public function testKoningsdagIsHoliday(): void
    {
        $service = $this->makeService();
        $this->assertTrue(
            $service->isHoliday('nl-feestdagen-rijksoverheid', new DateTimeImmutable('2026-04-27'))
        );
    }//end testKoningsdagIsHoliday()

    /**
     * A normal working day is not a holiday.
     *
     * @return void
     */
    public function testRegularDayIsNotHoliday(): void
    {
        $service = $this->makeService();
        $this->assertFalse(
            $service->isHoliday('nl-feestdagen-rijksoverheid', new DateTimeImmutable('2026-05-12'))
        );
    }//end testRegularDayIsNotHoliday()

    /**
     * The recurring fixed Nieuwjaarsdag applies every year.
     *
     * @return void
     */
    public function testRecurringFixedHoliday(): void
    {
        $service = $this->makeService();
        $this->assertTrue($service->isHoliday('nl-feestdagen-rijksoverheid', new DateTimeImmutable('2027-01-01')));
        $this->assertTrue($service->isHoliday('nl-feestdagen-rijksoverheid', new DateTimeImmutable('2031-12-25')));
    }//end testRecurringFixedHoliday()

    /**
     * Bevrijdingsdag only counts in lustrum years (divisible by 5).
     *
     * @return void
     */
    public function testLustrumHolidayOnlyInLustrumYears(): void
    {
        $service = $this->makeService();
        // 2025 is a lustrum year.
        $this->assertTrue($service->isHoliday('nl-feestdagen-rijksoverheid', new DateTimeImmutable('2025-05-05')));
        // 2026 is not.
        $this->assertFalse($service->isHoliday('nl-feestdagen-rijksoverheid', new DateTimeImmutable('2026-05-05')));
    }//end testLustrumHolidayOnlyInLustrumYears()

    /**
     * A composite NL+BE spec unions both calendars (REQ-010 OR logic).
     *
     * The Belgian national day (21 July) is not an NL holiday but IS recognised
     * in the composite.
     *
     * @return void
     */
    public function testCompositeCalendarUnion(): void
    {
        $service = $this->makeService();
        $spec    = 'nl-feestdagen-rijksoverheid,be-feestdagen';

        $this->assertFalse($service->isHoliday('nl-feestdagen-rijksoverheid', new DateTimeImmutable('2026-07-21')));
        $this->assertTrue($service->isHoliday($spec, new DateTimeImmutable('2026-07-21')));
        // And NL Koningsdag still holds in the composite.
        $this->assertTrue($service->isHoliday($spec, new DateTimeImmutable('2026-04-27')));
    }//end testCompositeCalendarUnion()

    /**
     * A tenant closure range is observed (REQ-010 closure range).
     *
     * @return void
     */
    public function testTenantClosureRange(): void
    {
        $override = json_encode(
            [
                'nl-feestdagen-rijksoverheid' => [
                    'ranges' => [['from' => '2026-12-24', 'to' => '2027-01-01', 'name' => 'bedrijfssluiting kerst']],
                ],
            ]
        );
        $service = $this->makeService((string) $override);

        $this->assertTrue($service->isHoliday('nl-feestdagen-rijksoverheid', new DateTimeImmutable('2026-12-28')));
        // Jan 2 is back to a working day.
        $this->assertFalse($service->isHoliday('nl-feestdagen-rijksoverheid', new DateTimeImmutable('2027-01-02')));
    }//end testTenantClosureRange()

    /**
     * Forcing lustrum makes Bevrijdingsdag apply every year (REQ-010 override).
     *
     * @return void
     */
    public function testTenantForceLustrum(): void
    {
        $override = json_encode(['nl-feestdagen-rijksoverheid' => ['forceLustrum' => true]]);
        $service  = $this->makeService((string) $override);

        // 2026 is not a lustrum year, but the override forces it.
        $this->assertTrue($service->isHoliday('nl-feestdagen-rijksoverheid', new DateTimeImmutable('2026-05-05')));
    }//end testTenantForceLustrum()

    /**
     * Calendar "none" never reports a holiday.
     *
     * @return void
     */
    public function testNoneCalendar(): void
    {
        $service = $this->makeService();
        $this->assertFalse($service->isHoliday('none', new DateTimeImmutable('2026-04-27')));
        $this->assertFalse($service->isHoliday('', new DateTimeImmutable('2026-04-27')));
    }//end testNoneCalendar()

    /**
     * An unknown calendar degrades to no-holidays without throwing.
     *
     * @return void
     */
    public function testUnknownCalendarDegradesSafely(): void
    {
        $service = $this->makeService();
        $this->assertFalse($service->isHoliday('does-not-exist', new DateTimeImmutable('2026-04-27')));
    }//end testUnknownCalendarDegradesSafely()
}//end class
