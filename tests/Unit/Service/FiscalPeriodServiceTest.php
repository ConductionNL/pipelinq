<?php

/**
 * Unit tests for FiscalPeriodService.
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
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Pipelinq\Service\FiscalPeriodService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for calendar-quarter period resolution.
 */
class FiscalPeriodServiceTest extends TestCase
{
    /**
     * The service under test.
     *
     * @var FiscalPeriodService
     */
    private FiscalPeriodService $service;

    /**
     * Set up the fixture.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->service = new FiscalPeriodService();
    }//end setUp()

    /**
     * A May date resolves to Q2.
     *
     * @return void
     */
    public function testPeriodIdForQ2(): void
    {
        $this->assertSame('Q2-2026', $this->service->periodIdFor(new DateTimeImmutable('2026-05-20')));
        $this->assertSame('Q1-2026', $this->service->periodIdFor(new DateTimeImmutable('2026-01-01')));
        $this->assertSame('Q4-2026', $this->service->periodIdFor(new DateTimeImmutable('2026-12-31')));
    }//end testPeriodIdForQ2()

    /**
     * The period end is the last day of the quarter.
     *
     * @return void
     */
    public function testPeriodEnd(): void
    {
        $end = $this->service->periodEnd('Q2-2026');
        $this->assertNotNull($end);
        $this->assertSame('2026-06-30', $end->format('Y-m-d'));
    }//end testPeriodEnd()

    /**
     * Days remaining counts down within the period and floors at zero.
     *
     * @return void
     */
    public function testDaysRemaining(): void
    {
        $this->assertSame(20, $this->service->daysRemaining('Q2-2026', new DateTimeImmutable('2026-06-10 00:00:00')));
        $this->assertSame(0, $this->service->daysRemaining('Q2-2026', new DateTimeImmutable('2026-07-15')));
    }//end testDaysRemaining()

    /**
     * A period is closed once its end is in the past.
     *
     * @return void
     */
    public function testIsClosed(): void
    {
        $this->assertTrue($this->service->isClosed('Q2-2026', new DateTimeImmutable('2026-07-01')));
        $this->assertFalse($this->service->isClosed('Q2-2026', new DateTimeImmutable('2026-05-20')));
    }//end testIsClosed()

    /**
     * A malformed period id degrades safely.
     *
     * @return void
     */
    public function testMalformedPeriod(): void
    {
        $this->assertNull($this->service->periodEnd('not-a-period'));
        $this->assertSame(0, $this->service->daysRemaining('bad'));
        $this->assertFalse($this->service->isClosed('bad'));
    }//end testMalformedPeriod()
}//end class
