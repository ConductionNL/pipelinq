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
     * An identifier of the shape the controller itself mints.
     *
     * @var string
     */
    private const VALID_CONVERSATION_ID = '0123456789abcdef0123456789abcdef';

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
     * A well-formed conversation identifier is accepted: it is handed to the
     * service so the turn joins that conversation, and echoed in the payload.
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
            ['conversationId', '', self::VALID_CONVERSATION_ID],
        ]);

        $this->service->expects($this->once())
            ->method('processQuery')
            ->with('How many leads are open?', 'alice', self::VALID_CONVERSATION_ID)
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
        $this->assertSame(self::VALID_CONVERSATION_ID, $payload['conversationId']);
    }

    /**
     * A first turn sends no identifier, so the controller mints one: the
     * payload carries a usable identifier rather than null, and the service is
     * given the same value so the turn is recorded under it.
     *
     * @return void
     */
    public function testQueryMintsConversationIdWhenClientSendsNone(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);

        $this->request->method('getParam')->willReturnMap([
            ['query', '', 'How many leads are open?'],
            ['conversationId', '', ''],
        ]);

        $seen = null;
        $this->service->expects($this->once())
            ->method('processQuery')
            ->willReturnCallback(
                static function (string $query, string $userId, ?string $conversationId = null) use (&$seen): array {
                    $seen = $conversationId;
                    return [
                        'query'              => $query,
                        'resultType'         => 'text',
                        'textResponse'       => 'Found 3 records.',
                        'suggestedFollowUps' => [],
                    ];
                }
            );

        $controller = new NaviController($this->request, $this->service, $this->userSession, $this->logger);
        $payload    = $controller->query()->getData();

        $this->assertNotNull($payload['conversationId']);
        $this->assertMatchesRegularExpression(
            NaviController::CONVERSATION_ID_PATTERN,
            $payload['conversationId']
        );
        $this->assertSame($payload['conversationId'], $seen, 'the minted id must reach the service');
    }

    /**
     * A client-supplied identifier that does not match the minted shape is
     * treated as absent. It must never be handed on as part of a store key, so
     * a fresh identifier is minted and the caller's string is discarded.
     *
     * @return void
     */
    public function testQueryRejectsMalformedConversationId(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);

        $hostile = '../../etc/passwd';
        $this->request->method('getParam')->willReturnMap([
            ['query', '', 'How many leads are open?'],
            ['conversationId', '', $hostile],
        ]);

        $seen = null;
        $this->service->method('processQuery')->willReturnCallback(
            static function (string $query, string $userId, ?string $conversationId = null) use (&$seen): array {
                $seen = $conversationId;
                return [
                    'query'              => $query,
                    'resultType'         => 'text',
                    'textResponse'       => 'Found 3 records.',
                    'suggestedFollowUps' => [],
                ];
            }
        );

        $controller = new NaviController($this->request, $this->service, $this->userSession, $this->logger);
        $payload    = $controller->query()->getData();

        $this->assertNotSame($hostile, $seen);
        $this->assertNotSame($hostile, $payload['conversationId']);
        $this->assertMatchesRegularExpression(
            NaviController::CONVERSATION_ID_PATTERN,
            $payload['conversationId']
        );
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
