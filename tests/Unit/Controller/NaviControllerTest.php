<?php

/**
 * Unit tests for the Pipelinq NaviController.
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
 * @spec openspec/changes/dashboard/tasks.md#task-1.4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\NaviController;
use OCA\Pipelinq\Service\NaviService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Asserts the controller is a thin pass-through (success / unauthenticated /
 * missing query / service failure) and that error envelopes are static
 * strings, never `getMessage()` of the underlying exception.
 */
class NaviControllerTest extends TestCase
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
     * @var NaviService&MockObject
     */
    private NaviService $service;

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
        $this->service     = $this->createMock(NaviService::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->logger      = $this->createMock(LoggerInterface::class);
    }

    /**
     * Successful query returns 200 with the service envelope echoed.
     *
     * @return void
     */
    public function testQueryReturnsOkOnSuccess(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);

        $this->request->method('getParam')->willReturnMap([
            ['query', '', 'How many leads are open?'],
            ['conversationId', '', 'conv-1'],
        ]);

        $this->service->expects($this->once())
            ->method('processQuery')
            ->with('How many leads are open?', 'alice')
            ->willReturn([
                'query'              => 'How many leads are open?',
                'resultType'         => 'text',
                'textResponse'       => 'Found 3 records.',
                'suggestedFollowUps' => ['Trend?'],
            ]);

        $controller = new NaviController($this->request, $this->service, $this->userSession, $this->logger);
        $response   = $controller->query();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $payload = $response->getData();
        $this->assertSame('text', $payload['resultType']);
        $this->assertSame('conv-1', $payload['conversationId']);
    }

    /**
     * Missing query parameter yields 400.
     *
     * @return void
     */
    public function testQueryReturnsBadRequestOnMissingQuery(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);

        $this->request->method('getParam')->willReturn('');

        $controller = new NaviController($this->request, $this->service, $this->userSession, $this->logger);
        $response   = $controller->query();

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('Missing query', $response->getData()['message']);
    }

    /**
     * Unauthenticated request returns 401 with a static message.
     *
     * @return void
     */
    public function testQueryReturnsUnauthorizedWithoutSession(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $controller = new NaviController($this->request, $this->service, $this->userSession, $this->logger);
        $response   = $controller->query();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
        $this->assertSame('Unauthorized', $response->getData()['message']);
    }

    /**
     * NaviService throwing returns a 500 with a static envelope, never the
     * `getMessage()` of the underlying exception.
     *
     * @return void
     */
    public function testQueryReturnsServerErrorOnServiceFailure(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);

        $this->request->method('getParam')->willReturnMap([
            ['query', '', 'How many leads?'],
            ['conversationId', '', ''],
        ]);

        $this->service->method('processQuery')->willThrowException(new \RuntimeException('boom'));

        $controller = new NaviController($this->request, $this->service, $this->userSession, $this->logger);
        $response   = $controller->query();

        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
        $payload = $response->getData();
        $this->assertSame('Navi unavailable', $payload['message']);
        $this->assertStringNotContainsString('boom', $payload['message']);
    }
}
