<?php

/**
 * Unit tests for RapportageController.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/lead-management/tasks.md#9.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\RapportageController;
use OCA\Pipelinq\Service\RapportageService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for RapportageController.
 */
class RapportageControllerTest extends TestCase
{

    /**
     * Mock request.
     *
     * @var IRequest
     */
    private IRequest $request;

    /**
     * Mock rapportage service.
     *
     * @var RapportageService
     */
    private RapportageService $service;

    /**
     * Mock user session.
     *
     * @var IUserSession
     */
    private IUserSession $userSession;

    /**
     * Mock logger.
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->request     = $this->createMock(IRequest::class);
        $this->service     = $this->createMock(RapportageService::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->logger      = $this->createMock(LoggerInterface::class);
    }//end setUp()

    /**
     * Build the controller under test.
     *
     * @return RapportageController The controller.
     */
    private function controller(): RapportageController
    {
        return new RapportageController(
            $this->request,
            $this->service,
            $this->userSession,
            $this->logger,
        );
    }//end controller()

    /**
     * An authenticated request returns the aggregated stats with HTTP 200.
     *
     * @return void
     */
    public function testGetPipelineStatsReturnsStatsForAuthenticatedUser(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('sales-rep');
        $this->userSession->method('getUser')->willReturn($user);
        $this->request->method('getParam')->willReturn(null);

        $payload = [
            'stageValues'       => [['stage' => 'Nieuw', 'count' => 1, 'totalValue' => 100.0, 'weightedValue' => 50.0]],
            'sourcePerformance' => [],
            'agingBuckets'      => [],
            'winLoss'           => ['wonCount' => 0, 'lostCount' => 0, 'winRate' => 0.0],
        ];
        $this->service->expects($this->once())
            ->method('getPipelineStats')
            ->with(null)
            ->willReturn($payload);

        $response = $this->controller()->getPipelineStats();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame($payload, $response->getData());
    }//end testGetPipelineStatsReturnsStatsForAuthenticatedUser()

    /**
     * The optional pipeline filter is forwarded to the service.
     *
     * @return void
     */
    public function testGetPipelineStatsForwardsPipelineFilter(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('sales-rep');
        $this->userSession->method('getUser')->willReturn($user);
        $this->request->method('getParam')->willReturn('pipeline-123');

        $this->service->expects($this->once())
            ->method('getPipelineStats')
            ->with('pipeline-123')
            ->willReturn([]);

        $response = $this->controller()->getPipelineStats();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }//end testGetPipelineStatsForwardsPipelineFilter()

    /**
     * An unauthenticated request returns HTTP 401.
     *
     * @return void
     */
    public function testGetPipelineStatsRejectsUnauthenticated(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $this->service->expects($this->never())->method('getPipelineStats');

        $response = $this->controller()->getPipelineStats();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testGetPipelineStatsRejectsUnauthenticated()

    /**
     * A service failure returns a generic HTTP 500 (no exception details leaked).
     *
     * @return void
     */
    public function testGetPipelineStatsReturnsGenericErrorOnFailure(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('sales-rep');
        $this->userSession->method('getUser')->willReturn($user);
        $this->request->method('getParam')->willReturn(null);

        $this->service->method('getPipelineStats')
            ->willThrowException(new \RuntimeException('boom: internal secret detail'));

        $response = $this->controller()->getPipelineStats();

        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
        $data = $response->getData();
        $this->assertSame('Failed to load pipeline statistics', $data['message']);
        $this->assertStringNotContainsString('secret', json_encode($data));
    }//end testGetPipelineStatsReturnsGenericErrorOnFailure()
}//end class
