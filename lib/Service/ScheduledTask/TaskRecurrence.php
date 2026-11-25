<?php

/**
 * Pipelinq TaskRecurrence.
 *
 * Brings a repeating task back on its schedule. A task that repeats every
 * day, week or month gets its next occurrence once its due date has come:
 * the same task, open, due one interval later (or, when the job was not run
 * for a while, the first interval that is not before today). The two are
 * linked through `nextOccurrence` and `previousOccurrence`, so each
 * occurrence is made once. The writes run as the signed-in user or, under
 * cron, as pipelinq's system account; OpenRegister's checks stay on.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\ScheduledTask
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/specify-capability-rows/specs/task-background-jobs/spec.md#requirement-a-task-with-a-repeat-schedule-comes-back-on-that-schedule
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\ScheduledTask;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\Pipelinq\Kernel\Contracts\TaskRecurrenceInterface;
use OCA\Pipelinq\Service\SystemServiceAccount;
use OCA\Pipelinq\Util\ResultToArray;
use Override;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Makes the next occurrence of a repeating task when it comes due.
 *
 * @spec openspec/changes/specify-capability-rows/specs/task-background-jobs/spec.md#requirement-a-task-with-a-repeat-schedule-comes-back-on-that-schedule
 */
class TaskRecurrence implements TaskRecurrenceInterface {
	use ResultToArray;

	/**
	 * The repeat values and the step each one takes.
	 *
	 * @var array<string, string>
	 */
	public const INTERVALS = [
		'daily'   => '+1 day',
		'weekly'  => '+1 week',
		'monthly' => '+1 month',
	];

	/**
	 * What an occurrence does not take over from the task it was made from.
	 *
	 * @var list<string>
	 */
	private const NOT_COPIED = ['id', 'uuid', 'attempts', 'nextOccurrence', 'previousOccurrence', 'status', 'deadline', 'completedAt'];

	/**
	 * Constructor.
	 *
	 * @param ScheduledTaskStore   $store         Where tasks live, and OpenRegister's object service.
	 * @param SystemServiceAccount $systemAccount The account the writes run as when nobody is signed in.
	 * @param LoggerInterface      $logger        Logger.
	 */
	public function __construct(
		private readonly ScheduledTaskStore $store,
		private readonly SystemServiceAccount $systemAccount,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Make the next occurrence of every repeating task that has come due.
	 *
	 * @param DateTimeImmutable|null $now The reference instant (default now).
	 *
	 * @return int How many occurrences were made.
	 *
	 * @spec openspec/changes/specify-capability-rows/specs/task-background-jobs/spec.md#requirement-a-task-with-a-repeat-schedule-comes-back-on-that-schedule
	 */
	#[Override]
	public function run(?DateTimeImmutable $now=null): int {
		$now ??= new DateTimeImmutable();

		return (int) $this->systemAccount->runAsCurrentOrSystem(fn (): int => $this->spawnDue(now: $now));
	}//end run()

	/**
	 * Make the occurrences that are due, as the acting account.
	 *
	 * @param DateTimeImmutable $now The reference instant.
	 *
	 * @return int How many occurrences were made.
	 */
	private function spawnDue(DateTimeImmutable $now): int {
		[$register, $schema] = $this->store->location();
		if ($register === '' || $schema === '') {
			return 0;
		}

		$target = new ObjectFindTarget(register: $register, schema: $schema);
		$made   = 0;
		foreach ($this->repeatingTasks(register: $register, schema: $schema) as $task) {
			$next = $this->nextDeadline(task: $task, now: $now);
			if ($next !== null && $this->spawn(task: $task, deadline: $next, target: $target) === true) {
				$made++;
			}
		}

		return $made;
	}//end spawnDue()

	/**
	 * The tasks that repeat.
	 *
	 * @param string $register The register.
	 * @param string $schema   The task schema.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function repeatingTasks(string $register, string $schema): array {
		try {
			$found = $this->store->objects()->findAll(
				['filters' => ['register' => $register, 'schema' => $schema, 'repeat' => array_keys(self::INTERVALS)], 'limit' => 500]
			);
		} catch (Throwable $e) {
			$this->logger->error('TaskRecurrence: reading the repeating tasks failed', ['exception' => $e->getMessage()]);
			return [];
		}

		return array_map(fn (mixed $result): array => $this->serializeResult(result: $result), array_values($found));
	}//end repeatingTasks()

	/**
	 * The due date of a task's next occurrence, or null when none is due yet.
	 *
	 * None is due while the task's own due date is still ahead, and none again
	 * once its next occurrence was made.
	 *
	 * @param array<string, mixed> $task The task.
	 * @param DateTimeImmutable    $now  The reference instant.
	 *
	 * @return DateTimeImmutable|null The next due date.
	 */
	public function nextDeadline(array $task, DateTimeImmutable $now): ?DateTimeImmutable {
		$step = self::INTERVALS[(string) ($task['repeat'] ?? '')] ?? null;
		$raw  = trim((string) ($task['deadline'] ?? ''));
		if ($step === null || $raw === '' || trim((string) ($task['nextOccurrence'] ?? '')) !== '') {
			return null;
		}

		try {
			$deadline = new DateTimeImmutable($raw);
		} catch (Throwable) {
			return null;
		}

		if ($deadline > $now) {
			return null;
		}

		$today = $now->setTime(0, 0);
		$next  = $deadline->modify($step);
		while ($next !== false && $next < $today) {
			$next = $next->modify($step);
		}

		return $next === false ? null : $next;
	}//end nextDeadline()

	/**
	 * Save the next occurrence and link the task to it.
	 *
	 * @param array<string, mixed> $task     The repeating task.
	 * @param DateTimeImmutable    $deadline The occurrence's due date.
	 * @param ObjectFindTarget     $target   The task register and schema.
	 *
	 * @return bool Whether the occurrence was made.
	 */
	private function spawn(array $task, DateTimeImmutable $deadline, ObjectFindTarget $target): bool {
		$sourceId   = (string) ($task['id'] ?? $task['@self']['id'] ?? '');
		$occurrence = array_filter(
			$task,
			static fn (string $key): bool => in_array($key, self::NOT_COPIED, true) === false && str_starts_with($key, '@') === false && str_starts_with($key, '_') === false,
			ARRAY_FILTER_USE_KEY
		);
		$occurrence['status']             = 'open';
		$occurrence['deadline']           = $deadline->format(DateTimeInterface::ATOM);
		$occurrence['previousOccurrence'] = $sourceId;

		try {
			// An occurrence made before, whose link did not land, is linked now rather than made again.
			$newId = $this->madeBefore(sourceId: $sourceId, target: $target);
			$made  = ($newId === '');
			if ($made === true) {
				$saved = $this->serializeResult(result: $this->store->objects()->saveObject($occurrence, new ObjectSaveOptions(target: $target)));
				$newId = (string) ($saved['id'] ?? $saved['@self']['id'] ?? '');
			}

			$this->store->objects()->saveObject(['nextOccurrence' => $newId] + $task, new ObjectSaveOptions(target: $target, uuid: $sourceId));
		} catch (Throwable $e) {
			$this->logger->error('TaskRecurrence: making the next occurrence failed', ['exception' => $e->getMessage(), 'id' => $sourceId]);
			return false;
		}

		return $made;
	}//end spawn()

	/**
	 * The id of an occurrence already made from this task, or ''.
	 *
	 * @param string           $sourceId The repeating task.
	 * @param ObjectFindTarget $target   The task register and schema.
	 *
	 * @return string The occurrence id.
	 */
	private function madeBefore(string $sourceId, ObjectFindTarget $target): string {
		$found = $this->store->objects()->findAll(
			['filters' => ['register' => $target->register, 'schema' => $target->schema, 'previousOccurrence' => $sourceId], 'limit' => 1]
		);
		$first = $this->serializeResult(result: (array_values($found)[0] ?? null));

		return (string) ($first['id'] ?? $first['@self']['id'] ?? '');
	}//end madeBefore()
}//end class
