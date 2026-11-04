<?php

/**
 * Golden test for SchedulesController, called as Nextcloud dispatches it.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\SchedulesController;
use OCA\Pipelinq\Service\ScheduledTaskService;
use OCA\Pipelinq\Tests\Unit\Support\Autowire;
use OCA\Pipelinq\Tests\Unit\Support\ComparesGolden;
use OCA\Pipelinq\Tests\Unit\Support\NextcloudDispatch;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use DomainException;
use RuntimeException;
use InvalidArgumentException;
use Throwable;

/**
 * Every action of SchedulesController over the session, admin and owner,
 * parameter and failure cases: the response and every call made to the task
 * service, the group manager and the logger, in order. The controller is built
 * by autowiring from the leaf doubles only. Compared with
 * SchedulesControllerGolden.json; set GOLDEN_RECORD=1 to rewrite it, then run
 * once more without it.
 *
 * @covers \OCA\Pipelinq\Controller\SchedulesController
 * @covers \OCA\Pipelinq\Lifecycle\SessionUser
 */
class SchedulesControllerGoldenTest extends TestCase {
	use ComparesGolden;

	/**
	 * What the current case did, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $trace = [];

	/**
	 * Run every case and compare it with the golden file.
	 *
	 * @return void
	 */
	public function testGolden(): void {
		$actual = [];
		foreach ($this->cases() as $name => $case) {
			$actual[$name] = $this->runCase(case: $case);
		}

		$this->assertGolden(file: __DIR__.'/SchedulesControllerGolden.json', actual: $actual);
	}//end testGolden()

	/**
	 * Forged identity parameters change nothing but the stored payload of an update.
	 *
	 * @return void
	 */
	public function testForgedIdentityParametersChangeNothing(): void {
		$forged = ['uid' => 'mallory', 'userId' => 'mallory', 'createdBy' => 'mallory', 'isAdmin' => true];
		foreach ($this->cases() as $name => $case) {
			if ($case['action'] === 'update') {
				continue;
			}

			$plain = $this->runCase(case: $case);
			$case['params'] = (($case['params'] ?? []) + $forged);
			$this->assertSame($plain, $this->runCase(case: $case), $name);
		}
	}//end testForgedIdentityParametersChangeNothing()

	/**
	 * The cases: action, caller, request parameters and how the service answers.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$create = ['type' => 'callbackRequest', 'subject' => 'Bel terug', 'deadline' => '2026-11-04T10:00:00+00:00'];
		$full = $create + ['description' => 'd', 'priority' => 'high', 'assigneeUserId' => 'bob', 'assigneeGroupId' => 'kcc', 'clientId' => 'c-1', 'requestId' => 'r-1', 'callbackPhoneNumber' => '+316', 'preferredTimeSlot' => 'am'];
		return [
			'index no session'            => ['action' => 'index', 'user' => null],
			'index empty uid'             => ['action' => 'index', 'user' => ''],
			'index agent'                 => ['action' => 'index'],
			'index agent asks other'      => ['action' => 'index', 'params' => ['assigneeUserId' => 'bob', 'status' => 'open', 'assigneeGroupId' => 'kcc', 'from' => 'a', 'to' => 'b', '_page' => '2', '_limit' => '5']],
			'index admin asks other'      => ['action' => 'index', 'user' => 'admin', 'params' => ['assigneeUserId' => 'bob', '_page' => 'x', '_limit' => ['3']]],
			'index admin all'             => ['action' => 'index', 'user' => 'admin'],
			'index fails'                 => ['action' => 'index', 'fail' => 'list'],
			'create no session'           => ['action' => 'create', 'params' => $create, 'user' => null],
			'create empty uid'            => ['action' => 'create', 'params' => $create, 'user' => ''],
			'create no type'              => ['action' => 'create', 'params' => ['subject' => 's', 'deadline' => 'd']],
			'create bad type'             => ['action' => 'create', 'params' => ['type' => 'party'] + $create],
			'create no subject'           => ['action' => 'create', 'params' => ['subject' => ''] + $create],
			'create no deadline'          => ['action' => 'create', 'params' => ['deadline' => ''] + $create],
			'create minimal'              => ['action' => 'create', 'params' => $create],
			'create full agent'           => ['action' => 'create', 'params' => $full],
			'create full self'            => ['action' => 'create', 'params' => ['assigneeUserId' => 'agent'] + $full],
			'create full admin'           => ['action' => 'create', 'params' => $full, 'user' => 'admin'],
			'create invalid'              => ['action' => 'create', 'params' => $create, 'fail' => 'invalid'],
			'create fails'                => ['action' => 'create', 'params' => $create, 'fail' => 'create'],
			'pending no session'          => ['action' => 'pending', 'user' => null],
			'pending empty uid'           => ['action' => 'pending', 'user' => ''],
			'pending agent'               => ['action' => 'pending'],
			'pending admin'               => ['action' => 'pending', 'user' => 'admin', 'params' => ['window' => '30']],
			'pending window high'         => ['action' => 'pending', 'params' => ['window' => '5000']],
			'pending window low'          => ['action' => 'pending', 'params' => ['window' => '-3']],
			'pending window 1440'         => ['action' => 'pending', 'params' => ['window' => '1440']],
			'pending window 1'            => ['action' => 'pending', 'params' => ['window' => '1']],
			'pending window text'         => ['action' => 'pending', 'params' => ['window' => 'soon']],
			'pending fails'               => ['action' => 'pending', 'fail' => 'pending'],
			'show no session'             => ['action' => 'show', 'params' => ['id' => 't-agent'], 'user' => null],
			'show empty uid'              => ['action' => 'show', 'params' => ['id' => 't-agent'], 'user' => ''],
			'show own'                    => ['action' => 'show', 'params' => ['id' => 't-agent']],
			'show other'                  => ['action' => 'show', 'params' => ['id' => 't-bob']],
			'show other admin'            => ['action' => 'show', 'params' => ['id' => 't-bob'], 'user' => 'admin'],
			'show unassigned'             => ['action' => 'show', 'params' => ['id' => 't-none']],
			'show missing'                => ['action' => 'show', 'params' => ['id' => 't-missing']],
			'show fails'                  => ['action' => 'show', 'params' => ['id' => 't-agent'], 'fail' => 'get'],
			'show array id'               => ['action' => 'show', 'params' => ['id' => ['t-agent']]],
			'update missing'              => ['action' => 'update', 'params' => ['id' => 't-missing', 'subject' => 'x']],
			'update no session'           => ['action' => 'update', 'params' => ['id' => 't-agent', 'subject' => 'x'], 'user' => null],
			'update empty uid'            => ['action' => 'update', 'params' => ['id' => 't-agent', 'subject' => 'x'], 'user' => ''],
			'update not allowed'          => ['action' => 'update', 'params' => ['id' => 't-bob', 'subject' => 'x']],
			'update own strips'           => ['action' => 'update', 'params' => ['id' => 't-agent', '_route' => 'r', 'subject' => 'x', 'assigneeUserId' => 'eve', 'assigneeGroupId' => 'g', 'status' => 'done', 'createdAt' => 'c', 'attempts' => [], 'priority' => 'low']],
			'update admin keeps'          => ['action' => 'update', 'user' => 'admin', 'params' => ['id' => 't-bob', '_route' => 'r', 'subject' => 'x', 'assigneeUserId' => 'eve', 'status' => 'done', 'attempts' => []]],
			'update invalid'              => ['action' => 'update', 'params' => ['id' => 't-agent', 'subject' => 'x'], 'fail' => 'invalid'],
			'update fails'                => ['action' => 'update', 'params' => ['id' => 't-agent', 'subject' => 'x'], 'fail' => 'update'],
			'destroy missing'             => ['action' => 'destroy', 'params' => ['id' => 't-missing']],
			'destroy no session'          => ['action' => 'destroy', 'params' => ['id' => 't-agent'], 'user' => null],
			'destroy empty uid'           => ['action' => 'destroy', 'params' => ['id' => 't-agent'], 'user' => ''],
			'destroy not allowed'         => ['action' => 'destroy', 'params' => ['id' => 't-bob']],
			'destroy own'                 => ['action' => 'destroy', 'params' => ['id' => 't-agent']],
			'destroy admin'               => ['action' => 'destroy', 'params' => ['id' => 't-bob'], 'user' => 'admin'],
			'destroy fails'               => ['action' => 'destroy', 'params' => ['id' => 't-agent'], 'fail' => 'delete'],
		];
	}//end cases()

	/**
	 * Run one case against a freshly autowired controller.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, mixed>
	 */
	private function runCase(array $case): array {
		$this->trace = [];
		$params = ($case['params'] ?? []);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => (isset($params[$key]) === true ? $params[$key] : $default)
		);
		$request->method('getParams')->willReturn($params);
		$request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => ($name === 'Accept-Language' ? 'nl-NL,nl;q=0.9' : '')
		);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(
			function (string $uid): bool {
				$this->trace[] = ['isAdmin' => $uid];
				return $uid === 'admin';
			}
		);

		$doubles = [
			IRequest::class => $request,
			IUserSession::class => $this->session(uid: (array_key_exists('user', $case) === true ? $case['user'] : 'agent')),
			IGroupManager::class => $groups,
			ScheduledTaskService::class => $this->tasks(fail: ($case['fail'] ?? '')),
			LoggerInterface::class => $this->logger(),
		];
		$controller = Autowire::build(class: SchedulesController::class, doubles: $doubles);

		try {
			$response = NextcloudDispatch::call(controller: $controller, method: $case['action'], params: $params);
			$result = ['class' => get_class($response), 'status' => $response->getStatus()];
			if ($response instanceof JSONResponse) {
				$result['data'] = $response->getData();
			}
		} catch (Throwable $e) {
			$result = ['threw' => get_class($e), 'message' => $e->getMessage()];
		}

		return $result + ['trace' => $this->trace];
	}//end runCase()

	/**
	 * A session for the given user (or none).
	 *
	 * @param string|null $uid The acting user.
	 *
	 * @return IUserSession
	 */
	private function session(?string $uid): IUserSession {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		return $session;
	}//end session()

	/**
	 * A task service over three stored tasks; `fail` names the call that fails.
	 *
	 * @param string $fail The failing call, or ''.
	 *
	 * @return ScheduledTaskService
	 */
	private function tasks(string $fail): ScheduledTaskService {
		$stored = [
			't-agent' => ['id' => 't-agent', 'assigneeUserId' => 'agent', 'status' => 'open'],
			't-bob' => ['id' => 't-bob', 'assigneeUserId' => 'bob', 'status' => 'open'],
			't-none' => ['id' => 't-none', 'status' => 'open'],
		];
		$tasks = $this->createMock(ScheduledTaskService::class);
		$tasks->method('applyAcceptLanguage')->willReturnCallback(
			function (string $acceptLanguage): void {
				$this->trace[] = ['applyAcceptLanguage' => $acceptLanguage];
			}
		);
		$tasks->method('getScheduledTasks')->willReturnCallback(
			function (array $params) use ($fail): array {
				$this->trace[] = ['getScheduledTasks' => $params];
				$this->failWhen(fail: $fail, call: 'list');
				return ['items' => [['id' => 't-agent']], 'total' => 1, 'page' => 1, 'pages' => 1];
			}
		);
		$tasks->method('createScheduledTask')->willReturnCallback(
			function (array $data) use ($fail): array {
				$this->trace[] = ['createScheduledTask' => $data];
				$this->failWhen(fail: $fail, call: 'create');
				return ['id' => 't-new'] + $data;
			}
		);
		$tasks->method('getPendingTasks')->willReturnCallback(
			function (int $windowMinutes = 60) use ($fail, $stored): array {
				$this->trace[] = ['getPendingTasks' => $windowMinutes];
				$this->failWhen(fail: $fail, call: 'pending');
				return array_values($stored);
			}
		);
		$tasks->method('getScheduledTask')->willReturnCallback(
			function (string $id) use ($fail, $stored): array {
				$this->trace[] = ['getScheduledTask' => $id];
				$this->failWhen(fail: $fail, call: 'get');
				if (isset($stored[$id]) === false) {
					throw new RuntimeException('Scheduled task not found: '.$id);
				}

				return $stored[$id];
			}
		);
		$tasks->method('authorizeTaskMutation')->willReturnCallback(
			function (array $task, string $userId): void {
				$this->trace[] = ['authorizeTaskMutation' => [$task['id'], $userId]];
				if ($userId !== 'admin' && ($task['assigneeUserId'] ?? '') !== $userId) {
					throw new OCSForbiddenException('no');
				}
			}
		);
		$tasks->method('updateScheduledTask')->willReturnCallback(
			function (string $id, array $data) use ($fail): array {
				$this->trace[] = ['updateScheduledTask' => [$id, $data]];
				$this->failWhen(fail: $fail, call: 'update');
				return ['id' => $id] + $data;
			}
		);
		$tasks->method('deleteScheduledTask')->willReturnCallback(
			function (string $id) use ($fail): void {
				$this->trace[] = ['deleteScheduledTask' => $id];
				$this->failWhen(fail: $fail, call: 'delete');
			}
		);
		return $tasks;
	}//end tasks()

	/**
	 * Throw the case's failure when this is the failing call.
	 *
	 * @param string $fail The failing call.
	 * @param string $call This call.
	 *
	 * @return void
	 */
	private function failWhen(string $fail, string $call): void {
		if ($fail === 'invalid' && in_array($call, ['create', 'update'], true) === true) {
			throw new InvalidArgumentException('bad field');
		}

		if ($fail === $call) {
			throw new DomainException('store down: secret detail');
		}
	}//end failWhen()

	/**
	 * A logger that records every entry, with the exception reduced to its class and message.
	 *
	 * @return LoggerInterface
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string|\Stringable $message, array $context = []) use ($level): void {
					if (($context['exception'] ?? null) instanceof Throwable) {
						$context['exception'] = get_class($context['exception']).': '.$context['exception']->getMessage();
					}

					$this->trace[] = ['log' => [$level, (string)$message, $context]];
				}
			);
		}

		return $logger;
	}//end logger()
}//end class
