<?php

/**
 * Unit tests for SchedulesController's pending-window endpoint.
 *
 * Verifies the wire contract of `GET /api/schedules/pending`: the
 * authentication gate, the `{items, total}` envelope, the server-side
 * clamping of the `window` query parameter, the non-admin scoping that keeps
 * one user's pending tasks out of another user's response, and the masked
 * error body on an unexpected service failure.
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
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\SchedulesController;
use OCA\Pipelinq\Service\ScheduledTaskService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for SchedulesController::pending().
 */
class SchedulesControllerTest extends TestCase {
	/**
	 * Mock request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * Mock scheduled-task service.
	 *
	 * @var ScheduledTaskService&MockObject
	 */
	private ScheduledTaskService $tasks;

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
	 * The controller under test.
	 *
	 * @var SchedulesController
	 */
	private SchedulesController $controller;

	/**
	 * Build the controller with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->tasks = $this->createMock(ScheduledTaskService::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$logger = $this->createMock(LoggerInterface::class);

		$this->controller = new SchedulesController($this->request,
			$this->tasks,
			$this->groupManager,
			$this->userSession,
			$logger
		);
	}//end setUp()

	/**
	 * Stub the acting user and the admin decision.
	 *
	 * @param string|null $uid The acting UID, or null for no session.
	 * @param bool $isAdmin Whether the user is a Nextcloud admin.
	 *
	 * @return void
	 */
	private function authenticate(?string $uid, bool $isAdmin = false): void {
		if ($uid === null) {
			$this->userSession->method('getUser')->willReturn(null);
			return;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn($isAdmin);
	}//end authenticate()

	/**
	 * An unauthenticated caller gets 401 and the generic message body.
	 *
	 * @return void
	 */
	public function testPendingRequiresAuthentication(): void {
		$this->authenticate(null);
		$this->tasks->expects($this->never())->method('getPendingTasks');

		$response = $this->controller->pending();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['message' => 'Authentication required'], $response->getData());
	}//end testPendingRequiresAuthentication()

	/**
	 * An admin gets the full window in an `{items, total}` envelope, and the
	 * total matches the number of items actually returned.
	 *
	 * @return void
	 */
	public function testPendingReturnsItemsEnvelopeForAdmin(): void {
		$this->authenticate('supervisor', isAdmin: true);
		$this->request->method('getParam')->willReturn(60);
		$this->tasks->method('getPendingTasks')->willReturn(
			[
				['id' => 't1', 'assigneeUserId' => 'alice', 'status' => 'open'],
				['id' => 't2', 'assigneeUserId' => 'bob', 'status' => 'open'],
			]
		);

		$response = $this->controller->pending();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertArrayHasKey('items', $data);
		$this->assertArrayHasKey('total', $data);
		$this->assertCount(2, $data['items']);
		$this->assertSame(2, $data['total']);
		$this->assertSame('t1', $data['items'][0]['id']);
	}//end testPendingReturnsItemsEnvelopeForAdmin()

	/**
	 * A non-admin only ever sees their own pending tasks, and the reported
	 * total reflects the filtered set — not the pre-filter one.
	 *
	 * @return void
	 */
	public function testPendingScopesToOwnTasksForNonAdmin(): void {
		$this->authenticate('alice', isAdmin: false);
		$this->request->method('getParam')->willReturn(60);
		$this->tasks->method('getPendingTasks')->willReturn(
			[
				['id' => 't1', 'assigneeUserId' => 'alice', 'status' => 'open'],
				['id' => 't2', 'assigneeUserId' => 'bob', 'status' => 'open'],
			]
		);

		$response = $this->controller->pending();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(1, $data['items']);
		$this->assertSame('t1', $data['items'][0]['id']);
		$this->assertSame(1, $data['total']);
		// The list is re-indexed, so the JSON encodes as an array, not an object.
		$this->assertSame([0], array_keys($data['items']));
	}//end testPendingScopesToOwnTasksForNonAdmin()

	/**
	 * An oversized window is clamped to one day before it reaches the service.
	 *
	 * @return void
	 */
	public function testPendingClampsWindowToOneDay(): void {
		$this->authenticate('supervisor', isAdmin: true);
		$this->request->method('getParam')->willReturn('99999');
		$this->tasks->expects($this->once())
			->method('getPendingTasks')
			->with(1440)
			->willReturn([]);

		$response = $this->controller->pending();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['items' => [], 'total' => 0], $response->getData());
	}//end testPendingClampsWindowToOneDay()

	/**
	 * A zero or negative window is clamped up to one minute, so the endpoint
	 * never asks the service for an inverted time window.
	 *
	 * @return void
	 */
	public function testPendingClampsWindowToOneMinute(): void {
		$this->authenticate('supervisor', isAdmin: true);
		$this->request->method('getParam')->willReturn('-30');
		$this->tasks->expects($this->once())
			->method('getPendingTasks')
			->with(1)
			->willReturn([]);

		$response = $this->controller->pending();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testPendingClampsWindowToOneMinute()

	/**
	 * An unexpected service failure answers 500 with a masked message — the
	 * raw exception text never reaches the client.
	 *
	 * @return void
	 */
	public function testPendingMasksUnexpectedFailure(): void {
		$this->authenticate('supervisor', isAdmin: true);
		$this->request->method('getParam')->willReturn(60);
		$this->tasks->method('getPendingTasks')
			->willThrowException(new \RuntimeException('pg: connection refused on 10.0.0.5'));

		$response = $this->controller->pending();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['message' => 'Operation failed'], $response->getData());
		$this->assertStringNotContainsString('10.0.0.5', json_encode($response->getData()));
	}//end testPendingMasksUnexpectedFailure()
}//end class
