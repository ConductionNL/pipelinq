<?php

/**
 * Unit tests for CallbackController.
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

use OCA\Pipelinq\Controller\CallbackController;
use OCA\Pipelinq\Service\CallbackService;
use OCA\Pipelinq\Service\NotificationService;
use OCA\Pipelinq\Service\ScheduledTaskService;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for CallbackController.
 */
class CallbackControllerTest extends TestCase {
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
	 * Mock scheduled task service.
	 *
	 * @var ScheduledTaskService&MockObject
	 */
	private ScheduledTaskService $scheduledTaskService;

	/**
	 * Mock app config.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig $appConfig;

	/**
	 * Mock group manager.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager $groupManager;

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
	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->callbackService = $this->createMock(CallbackService::class);
		$this->notificationService = $this->createMock(NotificationService::class);
		$this->scheduledTaskService = $this->createMock(ScheduledTaskService::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$user = $this->createMock(\OCP\IUser::class);
		$user->method('getUID')->willReturn('test-agent');
		$this->userSession->method('getUser')->willReturn($user);
		$logger = $this->createMock(LoggerInterface::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->controller = new CallbackController(
			$this->request,
			$this->callbackService,
			$this->notificationService,
			$this->scheduledTaskService,
			$this->groupManager,
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
	public function testAttemptReturns400WhenResultMissing(): void {
		$this->request->method('getParam')->willReturnMap([
			['result', '', ''],
			['notes', '', ''],
		]);

		$response = $this->controller->attempt('task-123');

		$this->assertSame(400, $response->getStatus());
	}//end testAttemptReturns400WhenResultMissing()

	/**
	 * Test attempt returns 404 when task not found.
	 *
	 * @return void
	 */
	public function testAttemptReturns404WhenTaskNotFound(): void {
		$this->request->method('getParam')->willReturnMap([
			['result', '', 'niet_bereikbaar'],
			['notes', '', ''],
		]);

		$this->scheduledTaskService->method('getScheduledTask')
			->willThrowException(new \RuntimeException('Task not found'));

		$response = $this->controller->attempt('task-123');

		$this->assertSame(404, $response->getStatus());
	}//end testAttemptReturns404WhenTaskNotFound()

	/**
	 * Test attempt returns success with valid data.
	 *
	 * @return void
	 */
	public function testAttemptReturnsSuccessWithValidData(): void {
		$this->request->method('getParam')->willReturnMap([
			['result', '', 'niet_bereikbaar'],
			['notes', '', 'Voicemail'],
		]);

		$taskData = ['id' => 'task-123', 'status' => 'open', 'assigneeUserId' => 'test-agent', 'attempts' => []];
		$this->scheduledTaskService->method('getScheduledTask')->willReturn($taskData);
		// authorizeTaskMutation is void; not throwing = authorized.

		$updatedTask = ['id' => 'task-123', 'attempts' => [['result' => 'niet_bereikbaar']]];
		$this->callbackService->method('addAttempt')->willReturn($updatedTask);
		$this->callbackService->method('isAttemptThresholdReached')->willReturn(false);
		$this->scheduledTaskService->method('updateScheduledTask')->willReturn($updatedTask);

		$response = $this->controller->attempt('task-123');

		$this->assertSame(200, $response->getStatus());
	}//end testAttemptReturnsSuccessWithValidData()

	/**
	 * Test claim returns 403 when user is not eligible.
	 *
	 * @return void
	 */
	public function testClaimReturns403WhenNotEligible(): void {
		$taskData = ['id' => 'task-123', 'status' => 'open', 'assigneeGroupId' => 'support'];
		$this->scheduledTaskService->method('getScheduledTask')->willReturn($taskData);
		$this->callbackService->method('validateClaim')->willReturn([
			'eligible' => false,
			'reason' => 'User is not a member of the assigned group',
		]);

		$response = $this->controller->claim('task-123');

		$this->assertSame(403, $response->getStatus());
	}//end testClaimReturns403WhenNotEligible()

	/**
	 * Test claim returns success when eligible.
	 *
	 * @return void
	 */
	public function testClaimReturnsSuccessWhenEligible(): void {
		$taskData = ['id' => 'task-123', 'status' => 'open'];
		$this->scheduledTaskService->method('getScheduledTask')->willReturn($taskData);
		$this->callbackService->method('validateClaim')->willReturn([
			'eligible' => true,
			'reason' => '',
		]);
		$claimedTask = ['id' => 'task-123', 'assigneeUserId' => 'agent-001', 'status' => 'in_progress'];
		$this->callbackService->method('applyClaim')->willReturn($claimedTask);
		$this->scheduledTaskService->method('updateScheduledTask')->willReturn($claimedTask);

		$response = $this->controller->claim('task-123');

		$this->assertSame(200, $response->getStatus());
	}//end testClaimReturnsSuccessWhenEligible()

	/**
	 * Test complete returns 400 for invalid transition.
	 *
	 * @return void
	 */
	public function testCompleteReturns400ForInvalidTransition(): void {
		$taskData = ['id' => 'task-123', 'status' => 'open', 'assigneeUserId' => 'test-agent'];
		$this->scheduledTaskService->method('getScheduledTask')->willReturn($taskData);
		// authorizeTaskMutation is void; not throwing = authorized.
		$this->callbackService->method('validateStatusTransition')->willReturn([
			'valid' => false,
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
	public function testReassignReturns400WhenAssigneeMissing(): void {
		$this->request->method('getParam')->willReturnMap([
			['assignee', '', ''],
			['assigneeType', '', ''],
		]);
		// Admin check: user is admin.
		$this->groupManager->method('isAdmin')->willReturn(true);

		$response = $this->controller->reassign('task-123');

		$this->assertSame(400, $response->getStatus());
	}//end testReassignReturns400WhenAssigneeMissing()

	/**
	 * Test reassign returns 403 when caller is not an admin.
	 *
	 * @return void
	 */
	public function testReassignReturns403WhenNotAdmin(): void {
		$this->request->method('getParam')->willReturnMap([
			['assignee', '', 'new-user'],
			['assigneeType', '', 'user'],
		]);
		$this->groupManager->method('isAdmin')->willReturn(false);

		$response = $this->controller->reassign('task-123');

		$this->assertSame(403, $response->getStatus());
	}//end testReassignReturns403WhenNotAdmin()

	/**
	 * Test reassign returns success with valid data (admin user).
	 *
	 * @return void
	 */
	public function testReassignReturnsSuccessWithValidData(): void {
		$this->request->method('getParam')->willReturnMap([
			['assignee', '', 'new-user'],
			['assigneeType', '', 'user'],
		]);
		$this->groupManager->method('isAdmin')->willReturn(true);

		$taskData = ['id' => 'task-123', 'status' => 'open', 'subject' => 'Call'];
		$reassignedTask = ['id' => 'task-123', 'assigneeUserId' => 'new-user'];
		$withAttemptTask = ['id' => 'task-123', 'assigneeUserId' => 'new-user', 'attempts' => []];

		$this->scheduledTaskService->method('getScheduledTask')->willReturn($taskData);
		$this->callbackService->method('applyReassignment')->willReturn($reassignedTask);
		$this->callbackService->method('addAttempt')->willReturn($withAttemptTask);
		$this->scheduledTaskService->method('updateScheduledTask')->willReturn($withAttemptTask);

		$response = $this->controller->reassign('task-123');

		$this->assertSame(200, $response->getStatus());
	}//end testReassignReturnsSuccessWithValidData()
}//end class
