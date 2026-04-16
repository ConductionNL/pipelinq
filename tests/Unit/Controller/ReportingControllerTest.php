<?php

/**
 * Unit tests for ReportingController.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\ReportingController;
use OCA\Pipelinq\Service\ReportingService;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ReportingController.
 *
 * @spec openspec/changes/2026-03-20-contactmomenten-rapportage/tasks.md#task-1.2
 */
class ReportingControllerTest extends TestCase
{
    /**
     * The controller under test.
     *
     * @var ReportingController
     */
    private ReportingController $controller;

    /**
     * Mock reporting service.
     *
     * @var ReportingService
     */
    private ReportingService $reportingService;

    /**
     * Mock l10n service.
     *
     * @var IL10N
     */
    private IL10N $l10n;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $request              = $this->createMock(IRequest::class);
        $this->reportingService = $this->createMock(ReportingService::class);
        $this->l10n             = $this->createMock(IL10N::class);
        $this->l10n->method('t')->willReturnArgument(0);

        $this->controller = new ReportingController(
            $request,
            $this->reportingService,
            $this->l10n,
        );
    }//end setUp()

    /**
     * Test getSla returns SLA targets.
     *
     * @return void
     */
    public function testGetSlaReturnsTargets(): void
    {
        $targets = [
            'telefoon' => ['target_percent' => '90', 'wait_seconds' => '30'],
            'email'    => ['target_percent' => '90', 'response_hours' => '8'],
        ];

        $this->reportingService
            ->method('getAllSlaTargets')
            ->willReturn($targets);

        $response = $this->controller->getSla();

        $this->assertSame(200, $response->getStatus());
        $this->assertArrayHasKey('targets', $response->getData());
    }//end testGetSlaReturnsTargets()

    /**
     * Test getSla handles exceptions.
     *
     * @return void
     */
    public function testGetSlaHandlesException(): void
    {
        $this->reportingService
            ->method('getAllSlaTargets')
            ->willThrowException(new \Exception('Test exception'));

        $response = $this->controller->getSla();

        $this->assertSame(500, $response->getStatus());
        $this->assertArrayHasKey('error', $response->getData());
    }//end testGetSlaHandlesException()

    /**
     * Test updateSla updates targets.
     *
     * @return void
     */
    public function testUpdateSlaUpdatesTargets(): void
    {
        $targets = [
            'telefoon' => ['target_percent' => '95'],
        ];

        $this->reportingService
            ->method('getAllSlaTargets')
            ->willReturn($targets);

        $response = $this->controller->updateSla();

        $this->assertSame(200, $response->getStatus());
        $this->assertArrayHasKey('success', $response->getData());
    }//end testUpdateSlaUpdatesTargets()

    /**
     * Test exportCsv returns CSV file.
     *
     * @return void
     */
    public function testExportCsvReturnsFile(): void
    {
        $csv = "Date;Channel;Agent\n2024-01-01;telefoon;John\n";

        $this->reportingService
            ->method('generateCsv')
            ->willReturn($csv);

        $response = $this->controller->exportCsv();

        $this->assertSame(200, $response->getStatus());
    }//end testExportCsvReturnsFile()

    /**
     * Test exportCsv handles exceptions.
     *
     * @return void
     */
    public function testExportCsvHandlesException(): void
    {
        $this->reportingService
            ->method('generateCsv')
            ->willThrowException(new \Exception('Test exception'));

        $response = $this->controller->exportCsv();

        $this->assertSame(500, $response->getStatus());
        $this->assertArrayHasKey('error', $response->getData());
    }//end testExportCsvHandlesException()
}//end class
