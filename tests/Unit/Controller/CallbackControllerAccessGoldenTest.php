<?php

/**
 * Golden tests for who may act on a callback task, and what each refusal says.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/callback-management/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use ArrayObject;
use OCA\Pipelinq\Controller\CallbackController;
use OCA\Pipelinq\Service\CallbackService;
use OCA\Pipelinq\Service\NotificationService;
use OCA\Pipelinq\Service\ScheduledTaskService;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Every refusal and outcome of attempt, claim, complete and reassign.
 */
class CallbackControllerAccessGoldenTest extends TestCase {
	/**
	 * Everything the doubles saw, in order.
	 *
	 * @var ArrayObject<int, array<int, mixed>>
	 */
	private ArrayObject $calls;

	/**
	 * Set up the call log.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->calls = new ArrayObject();
	}//end setUp()

	/**
	 * Without a user every action answers 401 and touches nothing.
	 *
	 * @return void
	 */
	public function testNoUserIsRefused(): void {
		foreach (['attempt', 'claim', 'complete', 'reassign'] as $action) {
			$this->calls = new ArrayObject();
			$response = $this->controller(user: false, params: ['result' => 'reached', 'assignee' => 'bob', 'assigneeType' => 'user'])->$action('t-1');
			$this->assertSame([401, ['error' => 'T:Authentication required']], [$response->getStatus(), $response->getData()], $action);
			$this->assertSame([], $this->calls->getArrayCopy(), $action);
		}
	}//end testNoUserIsRefused()

	/**
	 * Input refusals come before the task is read; reassign checks admin
	 * rights after its input and before the task.
	 *
	 * @return void
	 */
	public function testInputAndAdminRefusals(): void {
		$response = $this->controller(params: [])->attempt('t-1');
		$this->assertSame([400, ['error' => 'T:Attempt result is required']], [$response->getStatus(), $response->getData()]);
		$this->assertSame([['language', 'nl']], $this->calls->getArrayCopy());

		foreach ([['assignee' => '', 'assigneeType' => 'user'], ['assignee' => 'bob', 'assigneeType' => 'team']] as $params) {
			$this->calls = new ArrayObject();
			$response = $this->controller(params: $params)->reassign('t-1');
			$this->assertSame([400, ['error' => 'T:Assignee and valid assignee type are required']], [$response->getStatus(), $response->getData()]);
			$this->assertSame([], $this->calls->getArrayCopy());
		}

		$this->calls = new ArrayObject();
		$response = $this->controller(params: ['assignee' => 'bob', 'assigneeType' => 'user'], admin: false)->reassign('t-1');
		$this->assertSame([403, ['error' => 'T:Only administrators may reassign tasks']], [$response->getStatus(), $response->getData()]);
		$this->assertSame([['isAdmin', 'agent-1']], $this->calls->getArrayCopy());
	}//end testInputAndAdminRefusals()

	/**
	 * A missing task answers 404; a task the user may not change answers 403
	 * on attempt and complete, an ineligible claim answers 403 with its reason.
	 *
	 * @return void
	 */
	public function testTaskRefusals(): void {
		$params = ['result' => 'reached', 'assignee' => 'bob', 'assigneeType' => 'group'];
		foreach (['attempt', 'claim', 'complete', 'reassign'] as $action) {
			$response = $this->controller(params: $params, task: null)->$action('t-1');
			$this->assertSame([404, ['error' => 'T:Task not found']], [$response->getStatus(), $response->getData()], $action);
		}

		foreach (['attempt', 'complete'] as $action) {
			$this->calls = new ArrayObject();
			$response = $this->controller(params: $params, mayChange: false)->$action('t-1');
			$this->assertSame([403, ['error' => 'T:Not authorized to modify this task']], [$response->getStatus(), $response->getData()], $action);
			$this->assertSame([['language', 'nl'], ['get', 't-1'], ['authorize', 'agent-1']], $this->calls->getArrayCopy(), $action);
		}

		$this->calls = new ArrayObject();
		$response = $this->controller(params: $params, claimable: false)->claim('t-1');
		$this->assertSame([403, ['error' => 'T:Already claimed']], [$response->getStatus(), $response->getData()]);
		$this->assertSame([['language', 'nl'], ['get', 't-1'], ['validateClaim']], $this->calls->getArrayCopy());

		$response = $this->controller(params: $params, transition: false)->complete('t-1');
		$this->assertSame([400, ['error' => 'T:Already completed']], [$response->getStatus(), $response->getData()]);
	}//end testTaskRefusals()

	/**
	 * The allowed actions: their writes, notifications and answers in order.
	 *
	 * @return void
	 */
	public function testAllowedActions(): void {
		$response = $this->controller(params: ['result' => 'reached', 'notes' => 'n'])->attempt('t-1');
		$this->assertSame([200, ['task' => ['id' => 't-1', 'createdBy' => 'carol', 'subject' => 'Bel terug', 'deadline' => 'tomorrow', 'attempts' => [1, 2]], 'suggestClose' => true, 'attemptCount' => 2]], [$response->getStatus(), $response->getData()]);
		$this->assertSame([['language', 'nl'], ['get', 't-1'], ['authorize', 'agent-1'], ['addAttempt', 'reached', 'n'], ['update', 't-1'], ['threshold']], $this->calls->getArrayCopy());

		$this->calls = new ArrayObject();
		$this->controller(params: [])->claim('t-1');
		$this->assertSame([['language', 'nl'], ['get', 't-1'], ['validateClaim'], ['applyClaim'], ['update', 't-1']], $this->calls->getArrayCopy());

		$this->calls = new ArrayObject();
		$this->controller(params: ['resultText' => 'done'])->complete('t-1');
		$this->assertSame([['language', 'nl'], ['get', 't-1'], ['authorize', 'agent-1'], ['transition', 'open', 'completed'], ['complete', 'done'], ['update', 't-1'], ['notifyCompleted', 'Bel terug', 'done', 'carol', 't-1', 'agent-1']], $this->calls->getArrayCopy());

		$this->calls = new ArrayObject();
		$this->controller(params: ['resultText' => 'done'], task: ['id' => 't-1', 'status' => 'open'])->complete('t-1');
		$this->assertNotContains('notifyCompleted', array_column($this->calls->getArrayCopy(), 0));

		foreach (['user' => true, 'group' => false] as $type => $notified) {
			$this->calls = new ArrayObject();
			$this->controller(params: ['assignee' => 'bob', 'assigneeType' => $type])->reassign('t-1');
			$expected = [['isAdmin', 'agent-1'], ['get', 't-1'], ['reassign', 'bob', $type], ['addAttempt', 'hertoegewezen', ''], ['update', 't-1']];
			if ($notified === true) {
				$expected[] = ['notifyReassigned', 'Bel terug', 'bob', 't-1', 'agent-1', 'tomorrow'];
			}

			$this->assertSame($expected, $this->calls->getArrayCopy(), $type);
		}
	}//end testAllowedActions()

	/**
	 * A failing write is logged with the action and answered with 500.
	 *
	 * @return void
	 */
	public function testFailuresAre500(): void {
		$messages = ['attempt' => 'Failed to log callback attempt', 'claim' => 'Failed to claim task', 'complete' => 'Failed to complete task', 'reassign' => 'Failed to reassign task'];
		foreach ($messages as $action => $message) {
			$this->calls = new ArrayObject();
			$response = $this->controller(params: ['result' => 'reached', 'assignee' => 'bob', 'assigneeType' => 'user'], updateFails: true)->$action('t-1');
			$this->assertSame([500, ['error' => 'T:' . $message]], [$response->getStatus(), $response->getData()], $action);
			$this->assertSame(['error', 'CallbackController::' . $action . ' failed', ['exception' => 'store down']], array_slice($this->calls->getArrayCopy(), -1)[0], $action);
		}
	}//end testFailuresAre500()

	/**
	 * Build the controller over recording doubles.
	 *
	 * @param array<string, string>     $params      The request parameters.
	 * @param bool                      $user        Whether a user is logged in.
	 * @param bool                      $admin       Whether the user is an admin.
	 * @param array<string, mixed>|null $task        The stored task, null when missing.
	 * @param bool                      $mayChange   Whether the user may change the task.
	 * @param bool                      $claimable   Whether the task may be claimed.
	 * @param bool                      $transition  Whether the completion is a valid transition.
	 * @param bool                      $updateFails Whether the task update throws.
	 *
	 * @return CallbackController
	 */
	private function controller(
		array $params,
		bool $user = true,
		bool $admin = true,
		?array $task = ['id' => 't-1', 'createdBy' => 'carol', 'subject' => 'Bel terug', 'deadline' => 'tomorrow'],
		bool $mayChange = true,
		bool $claimable = true,
		bool $transition = true,
		bool $updateFails = false,
	): CallbackController {
		$calls = $this->calls;
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));
		$request->method('getHeader')->willReturnCallback(static fn (string $name): string => $name === 'Accept-Language' ? 'nl' : '');

		$tasks = $this->createMock(ScheduledTaskService::class);
		$tasks->method('applyAcceptLanguage')->willReturnCallback(static function (?string $acceptLanguage) use ($calls): void {
			$calls[] = ['language', $acceptLanguage];
		});
		$tasks->method('getScheduledTask')->willReturnCallback(static function (string $id) use ($calls, $task): array {
			$calls[] = ['get', $id];
			if ($task === null) {
				throw new RuntimeException('missing');
			}

			return $task;
		});
		$tasks->method('authorizeTaskMutation')->willReturnCallback(static function (array $taskData, string $userId) use ($calls, $mayChange): void {
			$calls[] = ['authorize', $userId];
			if ($mayChange === false) {
				throw new OCSForbiddenException('no');
			}
		});
		$tasks->method('updateScheduledTask')->willReturnCallback(static function (string $id, array $taskData) use ($calls, $updateFails): array {
			$calls[] = ['update', $id];
			if ($updateFails === true) {
				throw new \Exception('store down');
			}

			return $taskData;
		});

		$callbacks = $this->createMock(CallbackService::class);
		$callbacks->method('addAttempt')->willReturnCallback(static function (array $taskData, string $result, string $notes) use ($calls): array {
			$calls[] = ['addAttempt', $result, $notes];
			$taskData['attempts'] = [1, 2];
			return $taskData;
		});
		$callbacks->method('isAttemptThresholdReached')->willReturnCallback(static function () use ($calls): bool {
			$calls[] = ['threshold'];
			return true;
		});
		$callbacks->method('validateClaim')->willReturnCallback(static function () use ($calls, $claimable): array {
			$calls[] = ['validateClaim'];
			return $claimable === true ? ['eligible' => true] : ['eligible' => false, 'reason' => 'Already claimed'];
		});
		$callbacks->method('applyClaim')->willReturnCallback(static function (array $taskData) use ($calls): array {
			$calls[] = ['applyClaim'];
			return $taskData;
		});
		$callbacks->method('validateStatusTransition')->willReturnCallback(static function (string $from, string $to) use ($calls, $transition): array {
			$calls[] = ['transition', $from, $to];
			return $transition === true ? ['valid' => true] : ['valid' => false, 'reason' => 'Already completed'];
		});
		$callbacks->method('applyCompletion')->willReturnCallback(static function (array $taskData, string $resultText) use ($calls): array {
			$calls[] = ['complete', $resultText];
			return $taskData;
		});
		$callbacks->method('applyReassignment')->willReturnCallback(static function (array $taskData, string $assignee, string $type) use ($calls): array {
			$calls[] = ['reassign', $assignee, $type];
			return $taskData;
		});

		$notifications = $this->createMock(NotificationService::class);
		$notifications->method('notifyTaskCompleted')->willReturnCallback(static function (...$args) use ($calls): void {
			$calls[] = array_merge(['notifyCompleted'], array_values($args));
		});
		$notifications->method('notifyTaskReassigned')->willReturnCallback(static function (...$args) use ($calls): void {
			$calls[] = array_merge(['notifyReassigned'], array_values($args));
		});

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static function (string $uid) use ($calls, $admin): bool {
			$calls[] = ['isAdmin', $uid];
			return $admin;
		});

		$session = $this->createMock(IUserSession::class);
		$account = $this->createMock(IUser::class);
		$account->method('getUID')->willReturn('agent-1');
		$session->method('getUser')->willReturn($user === true ? $account : null);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'T:' . $text);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(static function (string $message, array $context = []) use ($calls): void {
			$calls[] = ['error', $message, $context];
		});

		return new CallbackController($request, $callbacks, $notifications, $tasks, $groups, $session, $l10n, $logger);
	}//end controller()
}//end class
