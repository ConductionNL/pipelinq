<?php

/**
 * Unit tests for RapportageController.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\RapportageController;
use OCA\Pipelinq\Service\RapportageService;
use OCP\AppFramework\Http;
use OCP\IL10N;
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
     * Mock service.
     *
     * @var RapportageService
     */
    private RapportageService $service;

    /**
     * Mock user session.
     *
     * @var IUserSession
     */
    private IUserSession $session;

    /**
     * The controller under test.
     *
     * @var RapportageController
     */
    private RapportageController $controller;

    /**
     * Set up the controller with mocks.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->request = $this->createMock(IRequest::class);
        $this->service = $this->createMock(RapportageService::class);
        $this->session = $this->createMock(IUserSession::class);
        $l10n          = $this->createMock(IL10N::class);
        $logger        = $this->createMock(LoggerInterface::class);

        $this->controller = new RapportageController(
            $this->request,
            $this->service,
            $this->session,
            $l10n,
            $logger,
        );
    }//end setUp()

    /**
     * An authenticated request returns HTTP 200 with the analytics payload.
     *
     * @return void
     */
    public function testGetPipelineStatsReturnsDataForAuthenticatedUser(): void
    {
        $this->session->method('getUser')->willReturn($this->createMock(IUser::class));
        $this->request->method('getParam')->willReturn(null);

        $payload = [
            'stageValues'       => [],
            'sourcePerformance' => [],
            'agingBuckets'      => [],
            'winLoss'           => ['wonCount' => 0],
        ];
        $this->service->method('getPipelineStats')->willReturn($payload);

        $response = $this->controller->getPipelineStats();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame($payload, $response->getData());
    }//end testGetPipelineStatsReturnsDataForAuthenticatedUser()

    /**
     * An unauthenticated request returns HTTP 401.
     *
     * @return void
     */
    public function testGetPipelineStatsRejectsAnonymous(): void
    {
        $this->session->method('getUser')->willReturn(null);

        $response = $this->controller->getPipelineStats();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testGetPipelineStatsRejectsAnonymous()

    /**
     * A service failure is mapped to HTTP 500 without leaking the message.
     *
     * @return void
     */
    public function testGetPipelineStatsHandlesServiceError(): void
    {
        $this->session->method('getUser')->willReturn($this->createMock(IUser::class));
        $this->request->method('getParam')->willReturn(null);
        $this->service->method('getPipelineStats')->willThrowException(new \RuntimeException('boom'));

        $response = $this->controller->getPipelineStats();

        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
        $data = $response->getData();
        $this->assertArrayHasKey('error', $data);
        $this->assertStringNotContainsStringIgnoringCase('boom', $data['error']);
    }//end testGetPipelineStatsHandlesServiceError()

    /**
     * Query parameters are forwarded to the service.
     *
     * @return void
     */
    public function testGetPipelineStatsForwardsFilters(): void
    {
        $this->session->method('getUser')->willReturn($this->createMock(IUser::class));
        $this->request->method('getParam')->willReturnMap(
            [
                ['pipeline', null, 'pipe-7'],
                ['dateFrom', null, '2026-01-01'],
                ['dateTo', null, null],
            ]
        );

        $this->service->expects($this->once())
            ->method('getPipelineStats')
            ->with('pipe-7', '2026-01-01', null)
            ->willReturn(['stageValues' => [], 'sourcePerformance' => [], 'agingBuckets' => [], 'winLoss' => []]);

        $response = $this->controller->getPipelineStats();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }//end testGetPipelineStatsForwardsFilters()
}//end class
