<?php

/**
 * Unit tests for the Pipelinq AnalyticsController.
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
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/dashboard/tasks.md#task-2.4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Pipelinq\Controller\AnalyticsController;
use OCA\Pipelinq\Service\AnalyticsService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Asserts the new overview/trends/funnels surface — happy path, bad metric,
 * missing auth, server failure — all return the documented HTTP status codes
 * and static error envelopes (no `getMessage()` leak).
 */
class AnalyticsControllerTest extends TestCase
{
    /**
     * Mock request.
     *
     * @var IRequest&MockObject
     */
    private IRequest $request;

    /**
     * Mock service.
     *
     * @var AnalyticsService&MockObject
     */
    private AnalyticsService $service;

    /**
     * Mock user session.
     *
     * @var IUserSession&MockObject
     */
    private IUserSession $userSession;

    /**
     * Mock logger.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface $logger;

    /**
     * Set up the test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->request     = $this->createMock(IRequest::class);
        $this->service     = $this->createMock(AnalyticsService::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->logger      = $this->createMock(LoggerInterface::class);
    }

    /**
     * Build a controller with the standard mocks.
     *
     * @return AnalyticsController
     */
    private function buildController(): AnalyticsController
    {
        return new AnalyticsController(
            request: $this->request,
            analyticsService: $this->service,
            userSession: $this->userSession,
            logger: $this->logger,
        );
    }

    /**
     * GET /api/analytics/overview returns 200 with the documented JSON shape.
     *
     * @return void
     */
    public function testOverviewReturnsOkWithKpiShape(): void
    {
        $user = $this->createMock(IUser::class);
        $this->userSession->method('getUser')->willReturn($user);
        $this->request->method('getParam')->willReturn('month');

        $this->service->method('getOverview')->willReturn([
            'leadConversionRate'        => 20.0,
            'avgRequestResolutionTime'  => 4.0,
            'contactMomentVolume'       => 12,
            'customerSatisfactionScore' => 4.5,
            'period'                    => 'month',
            'previousPeriod'            => [],
        ]);

        $response = $this->buildController()->overview();
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertSame(20.0, $data['leadConversionRate']);
        $this->assertSame('month', $data['period']);
    }

    /**
     * Unauthenticated request returns 401 with a static message.
     *
     * @return void
     */
    public function testOverviewReturnsUnauthorizedWithoutSession(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $response = $this->buildController()->overview();
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
        $this->assertSame('Unauthorized', $response->getData()['message']);
    }

    /**
     * trends with unsupported metric returns 400 with `Unsupported metric`.
     *
     * @return void
     */
    public function testTrendsRejectsUnsupportedMetric(): void
    {
        $user = $this->createMock(IUser::class);
        $this->userSession->method('getUser')->willReturn($user);
        $this->request->method('getParam')->willReturn('unknown');

        $this->service->method('getTrends')->willThrowException(new InvalidArgumentException('Unsupported metric'));

        $response = $this->buildController()->trends();
        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('Unsupported metric', $response->getData()['message']);
    }

    /**
     * trends returns 200 with a `series` array.
     *
     * @return void
     */
    public function testTrendsReturnsOkWithSeries(): void
    {
        $user = $this->createMock(IUser::class);
        $this->userSession->method('getUser')->willReturn($user);
        $this->request->method('getParam')->willReturn('leads');

        $this->service->method('getTrends')->willReturn([
            'metric' => 'leads',
            'period' => 'month',
            'series' => [['date' => '2026-04-01', 'value' => 2]],
        ]);

        $response = $this->buildController()->trends();
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertArrayHasKey('series', $response->getData());
    }

    /**
     * Backend failure returns 500 with `Analytics unavailable`, never the
     * underlying exception text.
     *
     * @return void
     */
    public function testOverviewReturnsServerErrorOnFailure(): void
    {
        $user = $this->createMock(IUser::class);
        $this->userSession->method('getUser')->willReturn($user);
        $this->request->method('getParam')->willReturn('month');

        $this->service->method('getOverview')->willThrowException(new \RuntimeException('boom'));

        $response = $this->buildController()->overview();
        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
        $payload = $response->getData();
        $this->assertSame('Analytics unavailable', $payload['message']);
        $this->assertStringNotContainsString('boom', $payload['message']);
    }

    /**
     * funnels returns 200 with both lead and request funnel blocks.
     *
     * @return void
     */
    public function testFunnelsReturnsBothFunnels(): void
    {
        $user = $this->createMock(IUser::class);
        $this->userSession->method('getUser')->willReturn($user);

        $this->service->method('getFunnels')->willReturn([
            'leadFunnel'    => ['open' => 1, 'won' => 1, 'lost' => 0, 'conversionRate' => 50.0],
            'requestFunnel' => ['new' => 0, 'in_progress' => 0, 'completed' => 1, 'rejected' => 0, 'resolutionRate' => 100.0],
        ]);

        $response = $this->buildController()->funnels();
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertArrayHasKey('leadFunnel', $data);
        $this->assertArrayHasKey('requestFunnel', $data);
    }
}
