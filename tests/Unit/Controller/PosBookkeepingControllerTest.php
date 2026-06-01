<?php

/**
 * Unit tests for PosBookkeepingController.
 *
 * Covers authorization enforcement and the HTTP response codes returned for
 * the /api/pos-bookkeeping/post endpoint.
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
 * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#7.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\PosBookkeepingController;
use OCA\Pipelinq\Service\PosBookkeepingService;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IGroupManager;
use OCP\IGroup;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for PosBookkeepingController.
 *
 * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#7.2
 */
class PosBookkeepingControllerTest extends TestCase
{

    /**
     * The controller under test.
     *
     * @var PosBookkeepingController
     */
    private PosBookkeepingController $controller;

    /**
     * Mock PosBookkeepingService.
     *
     * @var PosBookkeepingService&MockObject
     */
    private PosBookkeepingService $service;

    /**
     * Mock IRequest.
     *
     * @var IRequest&MockObject
     */
    private IRequest $request;

    /**
     * Mock IUserSession.
     *
     * @var IUserSession&MockObject
     */
    private IUserSession $userSession;

    /**
     * Mock IGroupManager.
     *
     * @var IGroupManager&MockObject
     */
    private IGroupManager $groupManager;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->service      = $this->createMock(PosBookkeepingService::class);
        $this->request      = $this->createMock(IRequest::class);
        $this->userSession  = $this->createMock(IUserSession::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $logger = $this->createMock(LoggerInterface::class);

        $this->controller = new PosBookkeepingController(
            request: $this->request,
            service: $this->service,
            userSession: $this->userSession,
            groupManager: $this->groupManager,
            logger: $logger,
        );
    }//end setUp()

    /**
     * Unauthenticated requests receive 401 Unauthorized.
     *
     * @return void
     *
     * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#7.2
     */
    public function testPostRequiresAuthentication(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $response = $this->controller->post();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testPostRequiresAuthentication()

    /**
     * Non-accounting users receive 403 Forbidden.
     *
     * @return void
     *
     * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#7.2
     */
    public function testPostForbiddenForNonAccountingUser(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('regular-user');

        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isAdmin')->with('regular-user')->willReturn(false);
        $this->groupManager->method('get')->with('pos-accounting')->willReturn(null);

        $response = $this->controller->post();

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }//end testPostForbiddenForNonAccountingUser()

    /**
     * Admin users can trigger the post action.
     *
     * @return void
     *
     * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#7.2
     */
    public function testPostAllowedForAdmin(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('admin');

        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isAdmin')->with('admin')->willReturn(true);

        $this->request->method('getParam')->with('outboundMessageId', '')->willReturn('test-uuid');

        $this->service
            ->expects($this->once())
            ->method('postToShillinq')
            ->with('test-uuid')
            ->willReturn(['status' => 'posted', 'idempotencyKey' => 'sha256:abc']);

        $response = $this->controller->post();

        $this->assertSame(Http::STATUS_ACCEPTED, $response->getStatus());
    }//end testPostAllowedForAdmin()

    /**
     * Missing outboundMessageId returns 422 Unprocessable Entity.
     *
     * @return void
     *
     * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#7.2
     */
    public function testPostMissingOutboundMessageIdReturns422(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('admin');

        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isAdmin')->with('admin')->willReturn(true);

        $this->request->method('getParam')->with('outboundMessageId', '')->willReturn('');

        $response = $this->controller->post();

        $this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
    }//end testPostMissingOutboundMessageIdReturns422()

    /**
     * OCSNotFoundException is mapped to 404.
     *
     * @return void
     *
     * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#7.2
     */
    public function testPostNotFoundReturns404(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('admin');

        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isAdmin')->with('admin')->willReturn(true);

        $this->request->method('getParam')->with('outboundMessageId', '')->willReturn('missing-uuid');

        $this->service
            ->method('postToShillinq')
            ->willThrowException(new OCSNotFoundException('Not found'));

        $response = $this->controller->post();

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }//end testPostNotFoundReturns404()

    /**
     * OCSBadRequestException is mapped to 422.
     *
     * @return void
     *
     * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#7.2
     */
    public function testPostPreconditionFailureReturns422(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('admin');

        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isAdmin')->with('admin')->willReturn(true);

        $this->request->method('getParam')->with('outboundMessageId', '')->willReturn('bad-status-uuid');

        $this->service
            ->method('postToShillinq')
            ->willThrowException(new OCSBadRequestException('Invalid state'));

        $response = $this->controller->post();

        $this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
    }//end testPostPreconditionFailureReturns422()
}//end class
