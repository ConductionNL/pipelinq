<?php

/**
 * Unit tests for AnalyticsController.
 *
 * Verifies HTTP shaping and error handling: the controller is a thin
 * pass-through to AnalyticsService, returns 401 when unauthenticated, 400 for
 * an unsupported metric, and a static-message 500 on service failure.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
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

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\AnalyticsController;
use OCA\Pipelinq\Service\AnalyticsService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for AnalyticsController.
 */
class AnalyticsControllerTest extends TestCase
{
    /**
     * Build a controller with an authenticated session by default.
     *
     * @param AnalyticsService $service        The (mocked) analytics service.
     * @param bool             $authenticated  Whether a user is logged in.
     * @param array            $params         Request params (key => value).
     *
     * @return AnalyticsController The controller under test.
     */
    private function controller(AnalyticsService $service, bool $authenticated = true, array $params = []): AnalyticsController
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(
            static function (string $key, $default = null) use ($params) {
                return ($params[$key] ?? $default);
            }
        );

        $userSession = $this->createMock(IUserSession::class);
        if ($authenticated === true) {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn('test-user');
            $userSession->method('getUser')->willReturn($user);
        } else {
            $userSession->method('getUser')->willReturn(null);
        }

        $logger = $this->createMock(LoggerInterface::class);

        return new AnalyticsController($request, $service, $userSession, $logger);
    }//end controller()

    /**
     * overview() returns 200 with the service payload.
     *
     * @return void
     */
    public function testOverviewReturnsOk(): void
    {
        $service = $this->createMock(AnalyticsService::class);
        $service->method('getOverview')->willReturn([
            'leadConversionRate'        => 50.0,
            'avgRequestResolutionTime'  => 2.0,
            'contactMomentVolume'       => 3,
            'customerSatisfactionScore' => 4.0,
            'period'                    => 'month',
            'previousPeriod'            => [],
        ]);

        $response = $this->controller($service, true, ['period' => 'month'])->overview();
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(50.0, $response->getData()['leadConversionRate']);
    }//end testOverviewReturnsOk()

    /**
     * overview() returns 401 when unauthenticated.
     *
     * @return void
     */
    public function testOverviewUnauthenticated(): void
    {
        $service  = $this->createMock(AnalyticsService::class);
        $response = $this->controller($service, false)->overview();
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
        $this->assertSame('Unauthorized', $response->getData()['message']);
    }//end testOverviewUnauthenticated()

    /**
     * trends() returns 400 for an unsupported metric (static message).
     *
     * @return void
     */
    public function testTrendsUnsupportedMetricReturns400(): void
    {
        $service = $this->createMock(AnalyticsService::class);
        $service->method('getTrends')->willThrowException(new \InvalidArgumentException('Unsupported metric'));

        $response = $this->controller($service, true, ['metric' => 'nope', 'period' => 'month'])->trends();
        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('Unsupported metric', $response->getData()['message']);
    }//end testTrendsUnsupportedMetricReturns400()

    /**
     * trends() returns 200 with a series array.
     *
     * @return void
     */
    public function testTrendsReturnsSeries(): void
    {
        $service = $this->createMock(AnalyticsService::class);
        $service->method('getTrends')->willReturn([
            'metric' => 'leads',
            'period' => 'month',
            'series' => [['date' => '2026-06-01', 'value' => 1.0]],
        ]);

        $response = $this->controller($service, true, ['metric' => 'leads', 'period' => 'month'])->trends();
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertCount(1, $response->getData()['series']);
    }//end testTrendsReturnsSeries()

    /**
     * overview() returns a static-message 500 when the service throws — the
     * raw exception message is never leaked to the caller.
     *
     * @return void
     */
    public function testOverviewServiceFailureReturnsStatic500(): void
    {
        $service = $this->createMock(AnalyticsService::class);
        $service->method('getOverview')->willThrowException(new \RuntimeException('secret internal detail'));

        $response = $this->controller($service, true, ['period' => 'month'])->overview();
        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
        $this->assertSame('Could not load analytics overview', $response->getData()['message']);
    }//end testOverviewServiceFailureReturnsStatic500()
}//end class
