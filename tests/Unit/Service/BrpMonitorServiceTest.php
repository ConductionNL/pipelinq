<?php

/**
 * Unit tests for BrpMonitorService aggregation.
 *
 * Verifies the 24-hour BRP report maths (lookup count, cache-hit ratio, error
 * rate, average response time, refusals) over a set of audit records — the
 * REQ-BSN-010 service-monitor figures — independent of persistence.
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
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\BrpMonitorService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for BrpMonitorService::aggregate (REQ-BSN-010).
 */
class BrpMonitorServiceTest extends TestCase
{
    /**
     * Build the service (aggregate() needs no live collaborators).
     *
     * @return BrpMonitorService The service.
     */
    private function makeService(): BrpMonitorService
    {
        return new BrpMonitorService(
            $this->createStub(ContainerInterface::class),
            $this->createStub(IAppConfig::class),
            $this->createStub(LoggerInterface::class)
        );
    }

    /**
     * Counts, ratios and averages are computed correctly; refusals excluded.
     *
     * @return void
     */
    public function testAggregateComputesReport(): void
    {
        $records = [
            ['uitkomst' => 'geslaagd', 'responseInCache' => true,  'responseDuurMs' => 10],
            ['uitkomst' => 'geslaagd', 'responseInCache' => false, 'responseDuurMs' => 400],
            ['uitkomst' => 'fout',     'responseInCache' => false, 'responseDuurMs' => 5000],
            ['uitkomst' => 'niet-gevonden', 'responseInCache' => false, 'responseDuurMs' => 390],
            ['actie' => 'brp-lookup-geweigerd', 'uitkomst' => 'geweigerd-onbevoegd'],
        ];

        $report = $this->makeService()->aggregate($records);

        $this->assertSame(4, $report['lookups']);
        $this->assertSame(1, $report['refusals']);
        $this->assertSame(25.0, $report['cacheHitRatio']);
        $this->assertSame(25.0, $report['errorRate']);
        $this->assertSame(1450.0, $report['avgResponseMs']);
    }

    /**
     * An empty set yields zeroed metrics with no division by zero.
     *
     * @return void
     */
    public function testAggregateEmpty(): void
    {
        $report = $this->makeService()->aggregate([]);

        $this->assertSame(0, $report['lookups']);
        $this->assertSame(0.0, $report['cacheHitRatio']);
        $this->assertSame(0.0, $report['errorRate']);
        $this->assertSame(0.0, $report['avgResponseMs']);
    }
}//end class
