<?php

/**
 * Unit tests for NaviController.
 *
 * Verifies HTTP shaping: 200 on success, 401 unauthenticated, 400 on a missing
 * query field, and a static-message 500 when NaviService throws.
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

use OCA\Pipelinq\Controller\NaviController;
use OCA\Pipelinq\Service\NaviService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for NaviController.
 */
class NaviControllerTest extends TestCase
{
    /**
     * Build a controller with configurable auth + request params.
     *
     * @param NaviService $service       The (mocked) Navi service.
     * @param bool        $authenticated Whether a user is logged in.
     * @param array       $params        Request params (key => value).
     *
     * @return NaviController The controller under test.
     */
    private function controller(NaviService $service, bool $authenticated = true, array $params = []): NaviController
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(
            static function (string $key, $default = null) use ($params) {
                return array_key_exists($key, $params) === true ? $params[$key] : $default;
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

        return new NaviController($request, $service, $userSession, $logger);
    }//end controller()

    /**
     * query() returns 200 with the service payload on success.
     *
     * @return void
     */
    public function testQueryReturnsOk(): void
    {
        $service = $this->createMock(NaviService::class);
        $service->method('processQuery')->willReturn([
            'resultType'         => 'text',
            'textResponse'       => 'Antwoord',
            'suggestedFollowUps' => [],
        ]);

        $response = $this->controller($service, true, ['query' => 'Hoeveel leads?'])->query();
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('text', $response->getData()['resultType']);
    }//end testQueryReturnsOk()

    /**
     * query() returns 401 when unauthenticated.
     *
     * @return void
     */
    public function testQueryUnauthenticated(): void
    {
        $service  = $this->createMock(NaviService::class);
        $response = $this->controller($service, false, ['query' => 'x'])->query();
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
        $this->assertSame('Unauthorized', $response->getData()['message']);
    }//end testQueryUnauthenticated()

    /**
     * query() returns 400 when the query field is missing or blank.
     *
     * @return void
     */
    public function testQueryMissingFieldReturns400(): void
    {
        $service = $this->createMock(NaviService::class);

        $missing = $this->controller($service, true, [])->query();
        $this->assertSame(Http::STATUS_BAD_REQUEST, $missing->getStatus());

        $blank = $this->controller($service, true, ['query' => '   '])->query();
        $this->assertSame(Http::STATUS_BAD_REQUEST, $blank->getStatus());
    }//end testQueryMissingFieldReturns400()

    /**
     * query() returns a static-message 500 when the service throws — the raw
     * exception detail is never leaked.
     *
     * @return void
     */
    public function testQueryServiceFailureReturnsStatic500(): void
    {
        $service = $this->createMock(NaviService::class);
        $service->method('processQuery')->willThrowException(new \RuntimeException('secret internal detail'));

        $response = $this->controller($service, true, ['query' => 'Hoeveel leads?'])->query();
        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
        $this->assertSame('Could not process query', $response->getData()['message']);
    }//end testQueryServiceFailureReturnsStatic500()
}//end class
