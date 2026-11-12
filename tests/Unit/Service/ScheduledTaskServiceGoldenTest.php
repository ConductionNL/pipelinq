<?php

/**
 * Golden test for ScheduledTaskService's public surface.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
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

namespace OCA\Pipelinq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\LanguageService;
use OCA\Pipelinq\Service\NotificationService;
use OCA\Pipelinq\Service\ScheduledTaskService;
use OCA\Pipelinq\Tests\Unit\Support\Autowire;
use OCA\Pipelinq\Tests\Unit\Support\ComparesGolden;
use OCA\Pipelinq\Tests\Unit\Support\FindCallShape;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Calls every public method of ScheduledTaskService over configured and
 * unconfigured registers, sessions, group memberships, deadlines around now
 * and failing reads, writes and notifications: the result (or exception) and
 * every call made to the app config, the object service, the language
 * service, the group manager, the notification service and the logger, in
 * order. Times within two days of now are recorded as minutes from now. The
 * service is built by autowiring from the leaf doubles only. Compared with
 * ScheduledTaskServiceGolden.json; set GOLDEN_RECORD=1 to rewrite it, then run
 * once more without it.
 *
 * @covers \OCA\Pipelinq\Service\ScheduledTaskService
 * @covers \OCA\Pipelinq\Service\ScheduledTask\ScheduledTaskDeadlines
 * @covers \OCA\Pipelinq\Service\ScheduledTask\ScheduledTaskRights
 * @covers \OCA\Pipelinq\Service\ScheduledTask\ScheduledTaskStore
 */
class ScheduledTaskServiceGoldenTest extends TestCase {
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
			$actual[$name] = $this->masked(value: $this->runCase(case: $case));
		}

		$this->assertGolden(file: __DIR__.'/ScheduledTaskServiceGolden.json', actual: $actual);
	}//end testGolden()

	/**
	 * The cases: the call, the configuration, the caller and the stored tasks.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$valid = ['type' => 'callbackRequest', 'subject' => 'Bel terug', 'deadline' => '2026-12-31T14:00:00Z'];
		$t = static fn (string $modifier): string => (new DateTimeImmutable($modifier))->format(DATE_ATOM);
		$due = [
			['id' => 't-expired', 'status' => 'open', 'deadline' => $t('-10 hours'), 'assigneeUserId' => 'alice', 'subject' => 'Old'],
			['id' => 't-recent', 'status' => 'open', 'deadline' => $t('-1 hour'), 'assigneeUserId' => 'bob', 'attempts' => [['result' => 'x']]],
			['id' => 't-progress-cut', 'status' => 'in_progress', 'deadline' => $t('-1 hour'), 'assigneeUserId' => 'bob'],
			['id' => 't-progress-old', 'status' => 'in_progress', 'deadline' => $t('-9 hours'), 'attempts' => 'broken'],
			['id' => 't-future', 'status' => 'open', 'deadline' => $t('+1 hour')],
			['id' => 't-done', 'status' => 'done', 'deadline' => $t('-1 hour')],
			['id' => 't-nodeadline', 'status' => 'open'],
			['id' => 't-baddeadline', 'status' => 'open', 'deadline' => 'soon'],
			['id' => 't-noassignee', 'status' => 'open', 'deadline' => $t('-2 hours')],
		];
		return [
			'list unconfigured'          => ['call' => ['getScheduledTasks', [[]]], 'config' => ['register' => '']],
			'list defaults'              => ['call' => ['getScheduledTasks', [[]]]],
			'list filtered'              => ['call' => ['getScheduledTasks', [['status' => 'open', 'assigneeUserId' => 'bob', 'assigneeGroupId' => 'kcc', 'from' => '2026-01-01', 'to' => '2026-02-01', '_page' => 2, '_limit' => 2]]]],
			'list limits'                => ['call' => ['getScheduledTasks', [['_page' => -1, '_limit' => 500]]]],
			'list fails'                 => ['call' => ['getScheduledTasks', [[]]], 'fail' => 'findAll'],
			'get found'                  => ['call' => ['getScheduledTask', ['t-1']]],
			'get entity'                 => ['call' => ['getScheduledTask', ['t-entity']]],
			'get missing'                => ['call' => ['getScheduledTask', ['t-missing']]],
			'get fails'                  => ['call' => ['getScheduledTask', ['t-1']], 'fail' => 'find'],
			'get unconfigured'           => ['call' => ['getScheduledTask', ['t-1']], 'config' => ['task_schema' => '']],
			'create ok'                  => ['call' => ['createScheduledTask', [$valid + ['createdBy' => 'mallory']]]],
			'create no session'          => ['call' => ['createScheduledTask', [$valid]], 'user' => null],
			'create keeps status'        => ['call' => ['createScheduledTask', [$valid + ['status' => 'in_progress']]]],
			'create date only'           => ['call' => ['createScheduledTask', [['deadline' => '2026-12-31'] + $valid]]],
			'create bad type'            => ['call' => ['createScheduledTask', [['type' => 'x'] + $valid]]],
			'create blank subject'       => ['call' => ['createScheduledTask', [['subject' => '  '] + $valid]]],
			'create relative deadline'   => ['call' => ['createScheduledTask', [['deadline' => '+2 weeks'] + $valid]]],
			'create offset deadline'     => ['call' => ['createScheduledTask', [['deadline' => '2026-12-31T14:00+01:00'] + $valid]]],
			'create unconfigured'        => ['call' => ['createScheduledTask', [$valid]], 'config' => ['register' => '']],
			'update ok'                  => ['call' => ['updateScheduledTask', ['t-1', ['subject' => 'New', 'createdBy' => 'mallory', 'uuid' => 'x', 'status' => 'done', 'attempts' => []]]]],
			'update missing'             => ['call' => ['updateScheduledTask', ['t-missing', ['subject' => 'New']]]],
			'delete'                     => ['call' => ['deleteScheduledTask', ['t-1']]],
			'delete by found uuid'       => ['call' => ['deleteScheduledTask', ['t-slug']]],
			'delete missing'             => ['call' => ['deleteScheduledTask', ['t-missing']]],
			'delete read fails'          => ['call' => ['deleteScheduledTask', ['t-1']], 'fail' => 'find'],
			'delete unconfigured'        => ['call' => ['deleteScheduledTask', ['t-1']], 'config' => ['task_schema' => '']],
			'delete no uuid'             => ['call' => ['deleteScheduledTask', ['t-no-uuid']]],
			'delete refused'             => ['call' => ['deleteScheduledTask', ['t-1']], 'fail' => 'delete'],
			'overdue'                    => ['call' => ['getOverdueTasks', []]],
			'overdue unconfigured'       => ['call' => ['getOverdueTasks', []], 'config' => ['register' => '']],
			'overdue fails'              => ['call' => ['getOverdueTasks', []], 'fail' => 'findAll'],
			'pending default'            => ['call' => ['getPendingTasks', []]],
			'pending capped'             => ['call' => ['getPendingTasks', [5000]]],
			'pending floor'              => ['call' => ['getPendingTasks', [0]]],
			'pending fails'              => ['call' => ['getPendingTasks', [30]], 'fail' => 'findAll'],
			'process unconfigured'       => ['call' => ['processScheduledTasks', []], 'config' => ['register' => ''], 'due' => $due],
			'process default cut'        => ['call' => ['processScheduledTasks', []], 'due' => $due],
			'process cut config'         => ['call' => ['processScheduledTasks', []], 'due' => $due, 'config' => ['task_expiry.escalation_threshold_seconds' => '1800', 'task_expiry.escalation_group' => 'leads']],
			'process bad cut config'     => ['call' => ['processScheduledTasks', []], 'due' => $due, 'config' => ['task_expiry.escalation_threshold_seconds' => '-5', 'task_expiry.escalation_group' => 'ghosts']],
			'process notify fails'       => ['call' => ['processScheduledTasks', []], 'due' => $due, 'fail' => 'notify', 'config' => ['task_expiry.escalation_group' => 'leads']],
			'process save fails'         => ['call' => ['processScheduledTasks', []], 'due' => $due, 'fail' => 'save'],
			'authorize empty user'       => ['call' => ['authorizeTaskMutation', [['assigneeUserId' => 'bob'], '']]],
			'authorize assignee'         => ['call' => ['authorizeTaskMutation', [['assigneeUserId' => 'bob'], 'bob']]],
			'authorize group member'     => ['call' => ['authorizeTaskMutation', [['assigneeUserId' => 'alice', 'assigneeGroupId' => 'kcc'], 'bob']]],
			'authorize other group'      => ['call' => ['authorizeTaskMutation', [['assigneeGroupId' => 'sales'], 'bob']]],
			'authorize admin'            => ['call' => ['authorizeTaskMutation', [['assigneeUserId' => 'alice'], 'admin']]],
			'authorize nobody'           => ['call' => ['authorizeTaskMutation', [[], 'bob']]],
			'language empty'             => ['call' => ['applyAcceptLanguage', ['']]],
			'language list'              => ['call' => ['applyAcceptLanguage', ['en-GB,en;q=0.8']]],
			'language weighted'          => ['call' => ['applyAcceptLanguage', ['de;q=0.9']]],
			'language blank first'       => ['call' => ['applyAcceptLanguage', [' ;q=1,fr']]],
			'language fails'             => ['call' => ['applyAcceptLanguage', ['fr']], 'fail' => 'language'],
		];
	}//end cases()

	/**
	 * Run one case against a freshly autowired service.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, mixed>
	 */
	private function runCase(array $case): array {
		$this->trace = [];
		$fail = ($case['fail'] ?? '');
		$config = (($case['config'] ?? []) + ['register' => 'reg-1', 'task_schema' => 'task-schema']);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use ($config): string {
				$this->trace[] = ['config' => $key];
				return ($config[$key] ?? $default);
			}
		);

		$service = Autowire::build(
			class: ScheduledTaskService::class,
			doubles: [
				IAppConfig::class => $appConfig,
				IUserSession::class => $this->session(uid: (array_key_exists('user', $case) === true ? $case['user'] : 'agent')),
				IGroupManager::class => $this->groupManager(),
				NotificationService::class => $this->notifications(fail: $fail),
				LoggerInterface::class => $this->logger(),
				LanguageService::class => $this->language(fail: $fail),
				ObjectServiceInterface::class => $this->objects(fail: $fail, due: ($case['due'] ?? [])),
			]
		);

		[$method, $args] = $case['call'];
		try {
			$result = ['returned' => $service->$method(...$args)];
		} catch (Throwable $e) {
			$result = ['threw' => get_class($e), 'message' => preg_replace('/MockObject_\w+/', 'MockObject', $e->getMessage())];
		}

		return $result + ['trace' => $this->trace];
	}//end runCase()

	/**
	 * Replace every time within two days of now by its distance from now in minutes.
	 *
	 * @param mixed $value The recorded value.
	 *
	 * @return mixed
	 */
	private function masked(mixed $value): mixed {
		if (is_array($value) === true) {
			return array_map(fn (mixed $item): mixed => $this->masked(value: $item), $value);
		}

		if (is_string($value) === true && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $value) === 1) {
			$delta = ((new DateTimeImmutable($value))->getTimestamp() - time());
			if (abs($delta) < 172800) {
				return 'now'.sprintf('%+d', (int)round($delta / 60)).'m';
			}
		}

		return $value;
	}//end masked()

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
			$user = $this->user(uid: $uid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		return $session;
	}//end session()

	/**
	 * A user.
	 *
	 * @param string $uid The UID.
	 *
	 * @return IUser
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end user()

	/**
	 * Groups: `bob` is in `kcc`, `admin` is an admin, `leads` holds lead and alice.
	 *
	 * @return IGroupManager
	 */
	private function groupManager(): IGroupManager {
		$leads = $this->createMock(IGroup::class);
		$leads->method('getUsers')->willReturn([$this->user(uid: 'lead'), $this->user(uid: 'alice')]);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(
			function (string $uid, string $gid): bool {
				$this->trace[] = ['isInGroup' => [$uid, $gid]];
				return $uid === 'bob' && $gid === 'kcc';
			}
		);
		$groups->method('isAdmin')->willReturnCallback(
			function (string $uid): bool {
				$this->trace[] = ['isAdmin' => $uid];
				return $uid === 'admin';
			}
		);
		$groups->method('get')->willReturnCallback(
			function (string $gid) use ($leads): ?IGroup {
				$this->trace[] = ['group' => $gid];
				return ($gid === 'leads' ? $leads : null);
			}
		);
		return $groups;
	}//end groupManager()

	/**
	 * A notification service that records, and fails for `bob` when asked to.
	 *
	 * @param string $fail The failing call.
	 *
	 * @return NotificationService
	 */
	private function notifications(string $fail): NotificationService {
		$service = $this->createMock(NotificationService::class);
		$service->method('notifyAssignment')->willReturnCallback(
			function (string $entityType, string $title, string $assigneeUserId, string $objectId, string $author) use ($fail): void {
				$this->trace[] = ['notifyAssignment' => [$entityType, $title, $assigneeUserId, $objectId, $author]];
				if ($fail === 'notify') {
					throw new RuntimeException('push down');
				}
			}
		);
		$service->method('notifyTaskExpired')->willReturnCallback(
			function (string $title, string $userId, string $objectId, string $deadline) use ($fail): void {
				$this->trace[] = ['notifyTaskExpired' => [$title, $userId, $objectId, $deadline]];
				if ($fail === 'notify' && $userId === 'lead') {
					throw new RuntimeException('push down');
				}
			}
		);
		return $service;
	}//end notifications()

	/**
	 * A language service that records the preferred language.
	 *
	 * @param string $fail The failing call.
	 *
	 * @return LanguageService
	 */
	private function language(string $fail): LanguageService {
		$language = $this->createMock(LanguageService::class);
		$language->method('setPreferredLanguage')->willReturnCallback(
			function (string $lang) use ($fail): void {
				$this->trace[] = ['setPreferredLanguage' => $lang];
				if ($fail === 'language') {
					throw new RuntimeException('no language service');
				}
			}
		);
		return $language;
	}//end language()

	/**
	 * An object service over the case's due tasks; overdue reads answer the
	 * past ones, pending reads the future ones.
	 *
	 * @param string                           $fail The failing call.
	 * @param array<int, array<string, mixed>> $due  The tasks around now.
	 *
	 * @return ObjectServiceInterface
	 */
	private function objects(string $fail, array $due): ObjectServiceInterface {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config) use ($fail, $due): array {
				$this->trace[] = ['findAll' => $config];
				if ($fail === 'findAll') {
					throw new RuntimeException('index down');
				}

				$deadline = ($config['filters']['deadline'] ?? []);
				if (isset($deadline['<']) === true) {
					return $due;
				}

				if (isset($deadline['>=']) === true) {
					return [['id' => 't-soon', 'status' => 'open', 'deadline' => (new DateTimeImmutable('+30 minutes'))->format(DATE_ATOM), 'assigneeUserId' => 'bob']];
				}

				return [['id' => 't-1'], ['id' => 't-2']];
			}
		);
		$objects->method('find')->willReturnCallback(
			function (...$args) use ($fail): ?ObjectEntityInterface {
				$this->trace[] = ['find' => array_slice(FindCallShape::find(args: $args), 0, 5)];
				if ($fail === 'find') {
					throw new RuntimeException('find down');
				}

				if ($args[0] === 't-missing') {
					return null;
				}

				if ($args[0] === 't-entity') {
					return $this->entity(payload: ['id' => 't-entity', 'subject' => 'From entity']);
				}

				if ($args[0] === 't-slug') {
					return $this->entity(payload: ['id' => 'found-uuid', 'subject' => 'By slug']);
				}

				if ($args[0] === 't-no-uuid') {
					return $this->entity(payload: ['subject' => 'No id']);
				}

				return $this->entity(payload: ['id' => $args[0], 'subject' => 'Old', 'createdBy' => 'agent', 'status' => 'open']);
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (...$args) use ($fail): ObjectEntityInterface {
				$this->trace[] = ['saveObject' => array_slice($args, 0, 5)];
				if ($fail === 'save') {
					throw new RuntimeException('locked');
				}

				return $this->entity(payload: ['saved' => true] + $args[0]);
			}
		);
		$objects->method('deleteObject')->willReturnCallback(
			function (...$args) use ($fail): bool {
				$this->trace[] = ['deleteObject' => array_slice($args, 0, 3)];
				return $fail !== 'delete';
			}
		);
		return $objects;
	}//end objects()

	/**
	 * A stored object whose payload is its jsonSerialize().
	 *
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return ObjectEntityInterface
	 */
	private function entity(array $payload): ObjectEntityInterface {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('jsonSerialize')->willReturn($payload);
		return $entity;
	}//end entity()

	/**
	 * A logger that records every entry, with an exception reduced to its class and message.
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
