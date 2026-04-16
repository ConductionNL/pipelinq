<?php

/**
 * Unit tests for CallbackController.
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

use OCA\Pipelinq\Controller\CallbackController;
use OCA\Pipelinq\Service\CallbackService;
use OCA\Pipelinq\Service\NotificationService;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for CallbackController.
 *
 * @spec openspec/changes/callback-management/tasks.md#task-4.2
 */
class CallbackControllerTest extends TestCase
{
    /**
     * The controller under test.
     *
     * @var CallbackController
     */
    private CallbackController $controller;

    /**
     * Mock callback service.
     *
     * @var CallbackService&MockObject
     */
    private CallbackService $callbackService;

    /**
     * Mock notification service.
     *
     * @var NotificationService&MockObject
     */
    private NotificationService $notificationService;

    /**
     * Mock request.
     *
     * @var IRequest&MockObject
     */
    private IRequest $request;

    /**
     * Mock app config.
     *
     * @var IAppConfig&MockObject
     */
    private IAppConfig $appConfig;

    /**
     * Mock user session.
     *
     * @var IUserSession&MockObject
     */
    private IUserSession $userSession;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->request             = $this->createMock(IRequest::class);
        $this->callbackService     = $this->createMock(CallbackService::class);
        $this->notificationService = $this->createMock(NotificationService::class);
        $this->appConfig           = $this->createMock(IAppConfig::class);
        $this->userSession         = $this->createMock(IUserSession::class);
        $logger                    = $this->createMock(LoggerInterface::class);
        $l10n                      = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        $this->controller = new CallbackController(
            $this->request,
            $this->callbackService,
            $this->notificationService,
            $this->appConfig,
            $this->userSession,
            $l10n,
            $logger,
        );
    }//end setUp()

    /**
     * Test attempt returns 400 when result is missing.
     *
     * @return void
     */
    public function testAttemptReturns400WhenResultMissing(): void
    {
        $this->request->method('getParam')->willReturnMap([
            ['result', '', ''],
            ['notes', '', ''],
        ]);

        $response = $this->controller->attempt('task-123');

        $this->assertSame(400, $response->getStatus());
    }//end testAttemptReturns400WhenResultMissing()

    /**
     * Test attempt returns 404 when task not found (no config).
     *
     * @return void
     */
    public function testAttemptReturns404WhenTaskNotFound(): void
    {
        $this->request->method('getParam')->willReturnMap([
            ['result', '', 'niet_bereikbaar'],
            ['notes', '', ''],
        ]);

        $this->appConfig->method('getValueString')->willReturn('');

        $response = $this->controller->attempt('task-123');

        $this->assertSame(404, $response->getStatus());
    }//end testAttemptReturns404WhenTaskNotFound()

    /**
     * Test attempt returns success with valid data.
     *
     * @return void
     */
    public function testAttemptReturnsSuccessWithValidData(): void
    {
        $this->request->method('getParam')->willReturnMap([
            ['result', '', 'niet_bereikbaar'],
            ['notes', '', 'Voicemail'],
        ]);

        $this->appConfig->method('getValueString')->willReturn('configured');

        $updatedTask = ['id' => 'task-123', 'attempts' => [['result' => 'niet_bereikbaar']]];
        $this->callbackService->method('addAttempt')->willReturn($updatedTask);
        $this->callbackService->method('isAttemptThresholdReached')->willReturn(false);

        $response = $this->controller->attempt('task-123');

        $this->assertSame(200, $response->getStatus());
    }//end testAttemptReturnsSuccessWithValidData()

    /**
     * Test claim returns 403 when user is not eligible.
     *
     * @return void
     */
    public function testClaimReturns403WhenNotEligible(): void
    {
        $this->appConfig->method('getValueString')->willReturn('configured');
        $this->callbackService->method('validateClaim')->willReturn([
            'eligible' => false,
            'reason'   => 'User is not a member of the assigned group',
        ]);

        $response = $this->controller->claim('task-123');

        $this->assertSame(403, $response->getStatus());
    }//end testClaimReturns403WhenNotEligible()

    /**
     * Test claim returns success when eligible.
     *
     * @return void
     */
    public function testClaimReturnsSuccessWhenEligible(): void
    {
        $this->appConfig->method('getValueString')->willReturn('configured');
        $this->callbackService->method('validateClaim')->willReturn([
            'eligible' => true,
            'reason'   => '',
        ]);
        $this->callbackService->method('applyClaim')->willReturn([
            'id' => 'task-123', 'assigneeUserId' => 'agent-001', 'status' => 'in_behandeling',
        ]);

        $response = $this->controller->claim('task-123');

        $this->assertSame(200, $response->getStatus());
    }//end testClaimReturnsSuccessWhenEligible()

    /**
     * Test complete returns 400 for invalid transition.
     *
     * @return void
     */
    public function testCompleteReturns400ForInvalidTransition(): void
    {
        $this->appConfig->method('getValueString')->willReturn('configured');
        $this->callbackService->method('validateStatusTransition')->willReturn([
            'valid'  => false,
            'reason' => 'Transition not allowed',
        ]);

        $response = $this->controller->complete('task-123');

        $this->assertSame(400, $response->getStatus());
    }//end testCompleteReturns400ForInvalidTransition()

    /**
     * Test reassign returns 400 when assignee is missing.
     *
     * @return void
     */
    public function testReassignReturns400WhenAssigneeMissing(): void
    {
        $this->request->method('getParam')->willReturnMap([
            ['assignee', '', ''],
            ['assigneeType', '', ''],
        ]);

        $response = $this->controller->reassign('task-123');

        $this->assertSame(400, $response->getStatus());
    }//end testReassignReturns400WhenAssigneeMissing()

    /**
     * Test reassign returns success with valid data.
     *
     * @return void
     */
    public function testReassignReturnsSuccessWithValidData(): void
    {
        $this->request->method('getParam')->willReturnMap([
            ['assignee', '', 'new-user'],
            ['assigneeType', '', 'user'],
        ]);

        $this->appConfig->method('getValueString')->willReturn('configured');
        $this->callbackService->method('applyReassignment')->willReturn([
            'id' => 'task-123', 'assigneeUserId' => 'new-user',
        ]);
        $this->callbackService->method('addAttempt')->willReturn([
            'id' => 'task-123', 'assigneeUserId' => 'new-user', 'attempts' => [],
        ]);

        $user = $this->createMock(\OCP\IUser::class);
        $user->method('getUID')->willReturn('current-user');
        $this->userSession->method('getUser')->willReturn($user);

        $response = $this->controller->reassign('task-123');

        $this->assertSame(200, $response->getStatus());
    }//end testReassignReturnsSuccessWithValidData()
}//end class
