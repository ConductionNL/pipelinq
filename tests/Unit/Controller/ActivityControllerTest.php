<?php

/**
 * Unit tests for ActivityController.
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Pipelinq\Controller\ActivityController;
use OCA\Pipelinq\Service\EntityActivityService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ActivityController.
 */
class ActivityControllerTest extends TestCase
{
    /**
     * Mock request.
     *
     * @var IRequest&MockObject
     */
    private $request;

    /**
     * Mock service.
     *
     * @var EntityActivityService&MockObject
     */
    private $service;

    /**
     * Mock user session.
     *
     * @var IUserSession&MockObject
     */
    private $userSession;

    /**
     * The controller under test.
     *
     * @var ActivityController
     */
    private ActivityController $controller;

    /**
     * Set up the test with an authenticated user by default.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->request     = $this->createMock(IRequest::class);
        $this->service     = $this->createMock(EntityActivityService::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $logger            = $this->createMock(LoggerInterface::class);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('test-user');
        $this->userSession->method('getUser')->willReturn($user);

        $this->controller = new ActivityController(
            $this->request,
            $this->service,
            $this->userSession,
            $logger
        );
    }//end setUp()

    /**
     * Configure the request param defaults.
     *
     * @param string $type  The type param.
     * @param int    $page  The _page param.
     * @param int    $limit The _limit param.
     *
     * @return void
     */
    private function withParams(string $type='all', int $page=1, int $limit=20): void
    {
        $this->request->method('getParam')->willReturnCallback(
            static function (string $key, $default=null) use ($type, $page, $limit) {
                return match ($key) {
                    'type'   => $type,
                    '_page'  => $page,
                    '_limit' => $limit,
                    default  => $default,
                };
            }
        );
    }//end withParams()

    /**
     * A successful request returns the service payload as JSON.
     *
     * @return void
     */
    public function testIndexReturnsPayload(): void
    {
        $this->withParams();
        $payload = [
            'total'   => 1,
            'page'    => 1,
            'pages'   => 1,
            'results' => [['type' => 'contactmoment', 'id' => 'cm-1']],
        ];
        $this->service->method('getActivity')->willReturn($payload);

        $response = $this->controller->index('client', 'client-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame($payload, $response->getData());
    }//end testIndexReturnsPayload()

    /**
     * An invalid entity type yields 400 with the static message and no leak.
     *
     * @return void
     */
    public function testIndexInvalidEntityType(): void
    {
        $this->withParams();
        $this->service->method('getActivity')
            ->willThrowException(new InvalidArgumentException('Invalid entity type'));

        $response = $this->controller->index('unknown', 'uuid-1');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame(['message' => 'Invalid entity type'], $response->getData());
    }//end testIndexInvalidEntityType()

    /**
     * A missing entity id is rejected with 400 before the service is invoked.
     *
     * @return void
     */
    public function testIndexEmptyEntityId(): void
    {
        $this->withParams();
        $this->service->expects($this->never())->method('getActivity');

        $response = $this->controller->index('client', '');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testIndexEmptyEntityId()

    /**
     * An unexpected error returns a generic 500 without exception details.
     *
     * @return void
     */
    public function testIndexInternalErrorIsGeneric(): void
    {
        $this->withParams();
        $this->service->method('getActivity')
            ->willThrowException(new \RuntimeException('boom: /internal/path SELECT *'));

        $response = $this->controller->index('client', 'client-1');

        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
        $data = $response->getData();
        $this->assertSame(['message' => 'Failed to load activity'], $data);
        $this->assertStringNotContainsString('boom', json_encode($data));
    }//end testIndexInternalErrorIsGeneric()

    /**
     * An unauthenticated request is rejected with 401.
     *
     * @return void
     */
    public function testIndexUnauthenticated(): void
    {
        $request     = $this->createMock(IRequest::class);
        $service     = $this->createMock(EntityActivityService::class);
        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn(null);
        $logger = $this->createMock(LoggerInterface::class);

        $service->expects($this->never())->method('getActivity');

        $controller = new ActivityController($request, $service, $userSession, $logger);
        $response   = $controller->index('client', 'client-1');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testIndexUnauthenticated()
}//end class
