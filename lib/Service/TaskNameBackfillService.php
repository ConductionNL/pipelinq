<?php

/**
 * Pipelinq TaskNameBackfillService.
 *
 * Names every existing task after its subject, once, without telling anyone.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/round5-task-name-backfill/specs/repair-steps/spec.md#requirement-existing-tasks-are-named-after-their-subject-without-telling-anyone
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use Exception;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Saves each task whose name is empty or its uuid, so OpenRegister names it.
 *
 * pipelinq#2376 set `crmTask.configuration.objectNameField = subject`.
 * OpenRegister computes the name on save, so a task written before that keeps
 * its uuid as its name. Re-saving the task's own data is enough: the save
 * hydrates the name from the subject and changes nothing else.
 *
 * Ruben decided (10 October 2026) that this must not tell the assignee
 * anything. Every save therefore runs inside OpenRegister's system scope,
 * where it withholds the object update event, and the scope is checked before
 * each write: no proven silence, no write. See ObjectEventSilence.
 *
 * @spec openspec/changes/round5-task-name-backfill/specs/repair-steps/spec.md#requirement-existing-tasks-are-named-after-their-subject-without-telling-anyone
 */
class TaskNameBackfillService {
	/**
	 * Upper bound on the tasks read in one run.
	 *
	 * @var int
	 */
	private const SCAN_LIMIT = 10000;

	/**
	 * The crmTask date-time properties. OpenRegister's read side can hand these
	 * back as `Y-m-d H:i:s`, which fails its own `format: date-time` on the way
	 * back in, so the save would be refused.
	 *
	 * @var array<int, string>
	 */
	private const DATE_TIME_FIELDS = ['deadline', 'completedAt'];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig             $appConfig     Register and task schema ids.
	 * @param ObjectServiceInterface $objectService OpenRegister's published object service.
	 * @param ObjectEventSilence     $silence       Whether a write now reaches no listener.
	 * @param IGroupManager          $groupManager  Resolves an admin for the folder check.
	 * @param LoggerInterface        $logger        The logger.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
		private readonly ObjectEventSilence $silence,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Name every task that has no name yet, silently.
	 *
	 * `status` is `done` when the run completed, or the reason it wrote nothing
	 * (or stopped): `not-configured`, `no-silent-path`, `scope-inactive`,
	 * `read-failed`.
	 *
	 * @return array{status: string, named: int, skipped: int, failed: int} The outcome.
	 *
	 * @spec openspec/changes/round5-task-name-backfill/specs/repair-steps/spec.md#requirement-existing-tasks-are-named-after-their-subject-without-telling-anyone
	 */
	public function backfill(): array {
		$counts = ['status' => 'done', 'named' => 0, 'skipped' => 0, 'failed' => 0];

		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'task_schema', '');
		if ($register === '' || $schema === '') {
			$counts['status'] = 'not-configured';
			return $counts;
		}

		// An OpenRegister without the event gate would send "Task changed" to
		// every assignee. Write nothing; the next upgrade tries again.
		if ($this->silence->isSupported() === false) {
			$counts['status'] = 'no-silent-path';
			return $counts;
		}

		$result = $this->objectService->runAsSystem(
			fn (): array => $this->backfillInScope(register: $register, schema: $schema, counts: $counts)
		);

		if (is_array($result) === false) {
			$counts['status'] = 'scope-inactive';
			return $counts;
		}

		return $result;
	}//end backfill()

	/**
	 * Name the tasks, inside the system scope.
	 *
	 * Flags passed to OpenRegister, and why each one is safe here: this runs
	 * only from a repair step during `occ upgrade`; no route reaches it.
	 * - `_rbac: false` (read and write): read every task, not only the few an
	 *   anonymous CLI caller may see, and write it back without a per-user check.
	 * - `_multitenancy: false` (read and write): across every organisation.
	 * - `silent: true`: no audit trail row and no inverse-relation pass. It does
	 *   NOT withhold the event; the system scope does.
	 * - `currentUser`: the folder check needs a user to authorise.
	 *
	 * @param string                                                     $register The register id.
	 * @param string                                                     $schema   The crmTask schema id.
	 * @param array{status: string, named: int, skipped: int, failed: int} $counts   The counts so far.
	 *
	 * @return array{status: string, named: int, skipped: int, failed: int} The outcome.
	 */
	private function backfillInScope(string $register, string $schema, array $counts): array {
		if ($this->silence->isActive() === false) {
			$counts['status'] = 'scope-inactive';
			return $counts;
		}

		try {
			$rows = $this->objectService->findAll(
				config: [
					'filters' => ['register' => $register, 'schema' => $schema],
					'limit' => self::SCAN_LIMIT,
				],
				_rbac: false,
				_multitenancy: false,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq: could not read tasks for the task name backfill',
				['exception' => $e->getMessage()]
			);
			$counts['status'] = 'read-failed';
			return $counts;
		}

		$actingAdmin = $this->actingAdmin();

		foreach ($rows as $row) {
			$task = $this->toArray(row: $row);
			$uuid = (string)($task['@self']['id'] ?? $task['id'] ?? '');
			if ($uuid === '' || $this->needsName(task: $task, uuid: $uuid) === false) {
				$counts['skipped']++;
				continue;
			}

			// Checked at every write, not once: a scope that ended part-way
			// would let the rest of the saves reach the assignees.
			if ($this->silence->isActive() === false) {
				$counts['status'] = 'scope-inactive';
				break;
			}

			$payload = $task;
			unset($payload['@self']);
			$payload = $this->normaliseDateTimes(payload: $payload);

			try {
				$this->objectService->saveObject(
					object: $payload,
					extend: [],
					register: $register,
					schema: $schema,
					uuid: $uuid,
					_rbac: false,
					_multitenancy: false,
					silent: true,
					currentUser: $actingAdmin,
				);
				$counts['named']++;
			} catch (Throwable $e) {
				$counts['failed']++;
				$this->logger->error(
					'Pipelinq: could not name a task after its subject',
					['uuid' => $uuid, 'exception' => $e->getMessage()]
				);
			}
		}//end foreach

		return $counts;
	}//end backfillInScope()

	/**
	 * Whether a task still needs its name, and has a subject to take it from.
	 *
	 * A task saved before objectNameField existed carries its uuid as its name.
	 * Anything else that is not empty is a name somebody chose, and stays.
	 *
	 * @param array<string, mixed> $task The task.
	 * @param string               $uuid Its uuid.
	 *
	 * @return bool True when the task should be saved.
	 */
	private function needsName(array $task, string $uuid): bool {
		$subject = ($task['subject'] ?? null);
		if (is_string($subject) === false || trim($subject) === '') {
			return false;
		}

		$name = ($task['@self']['name'] ?? null);
		if ($name === null) {
			return true;
		}

		if (is_string($name) === false) {
			return false;
		}

		return trim($name) === '' || $name === $uuid;
	}//end needsName()

	/**
	 * Put the date-time properties back into ISO 8601.
	 *
	 * Only reshapes a value that parses as an instant; anything else is left
	 * for schema validation, so a genuinely bad value still fails.
	 *
	 * @param array<string, mixed> $payload The task fields.
	 *
	 * @return array<string, mixed> The fields with ISO date-times.
	 */
	private function normaliseDateTimes(array $payload): array {
		foreach (self::DATE_TIME_FIELDS as $field) {
			$value = ($payload[$field] ?? null);
			if (is_string($value) === false || $value === '') {
				continue;
			}

			try {
				$payload[$field] = (new DateTimeImmutable($value))->format('c');
			} catch (Exception $e) {
				continue;
			}
		}

		return $payload;
	}//end normaliseDateTimes()

	/**
	 * The first admin, for OpenRegister's object folder check.
	 *
	 * A repair step has no session, and the folder check denies a write with no
	 * acting user for any task that owns a folder.
	 *
	 * @return IUser|null The first admin, or null when none exists.
	 */
	private function actingAdmin(): ?IUser {
		$admins = $this->groupManager->get('admin')?->getUsers() ?? [];

		return (array_values($admins)[0] ?? null);
	}//end actingAdmin()

	/**
	 * Coerce an OpenRegister row into a plain array.
	 *
	 * @param mixed $row The row as returned by the object service.
	 *
	 * @return array<string, mixed> The row data, empty when unusable.
	 */
	private function toArray(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (($row instanceof \JsonSerializable) === true) {
			$data = $row->jsonSerialize();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return [];
	}//end toArray()
}//end class
