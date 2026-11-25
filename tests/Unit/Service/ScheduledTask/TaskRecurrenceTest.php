<?php

/**
 * Unit tests for TaskRecurrence.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\ScheduledTask
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/specify-capability-rows/specs/task-background-jobs/spec.md#requirement-a-task-with-a-repeat-schedule-comes-back-on-that-schedule
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\ScheduledTask;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\Pipelinq\Service\ScheduledTask\ScheduledTaskStore;
use OCA\Pipelinq\Service\ScheduledTask\TaskRecurrence;
use OCA\Pipelinq\Service\SystemServiceAccount;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for TaskRecurrence.
 */
class TaskRecurrenceTest extends TestCase {

	/**
	 * OpenRegister's object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * The saves made, in order: [data, uuid].
	 *
	 * @var list<array{0: array<string, mixed>, 1: ?string}>
	 */
	private array $saves = [];

	/**
	 * What the lookup of an earlier occurrence answers.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $madeBefore = [];

	/**
	 * The recurrence over register "pipelinq", task schema "crmTask" and the given tasks.
	 *
	 * @param list<array<string, mixed>> $tasks The repeating tasks findAll answers.
	 *
	 * @return TaskRecurrence
	 */
	private function recurrence(array $tasks): TaskRecurrence {
		$this->objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$this->objects->method('findAll')->willReturnCallback(
			fn (array $config): array => isset($config['filters']['previousOccurrence']) === true ? $this->madeBefore : $tasks
		);
		$this->objects->method('saveObject')->willReturnCallback(
			function (array $data, ObjectSaveOptions $options): ObjectEntityInterface {
				$this->saves[] = [$data, $options->uuid];
				$entity        = $this->createMock(originalClassName: ObjectEntityInterface::class);
				$entity->method('jsonSerialize')->willReturn(['id' => ($options->uuid ?? 'new-'.count($this->saves))] + $data);
				return $entity;
			}
		);
		$store = $this->createMock(originalClassName: ScheduledTaskStore::class);
		$store->method('location')->willReturn(['pipelinq', 'crmTask']);
		$store->method('objects')->willReturn($this->objects);
		$account = $this->createMock(originalClassName: SystemServiceAccount::class);
		$account->method('runAsCurrentOrSystem')->willReturnCallback(static fn (callable $operation): mixed => $operation());

		return new TaskRecurrence(store: $store, systemAccount: $account, logger: $this->logger());
	}//end recurrence()

	/**
	 * A logger that fails the test on an error line.
	 *
	 * @return LoggerInterface
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context=[]): void {
				$this->fail(message: $message.': '.json_encode($context));
			}
		);

		return $logger;
	}//end logger()

	/**
	 * A weekly task due last Monday comes back, open, with the same title and assignee, due this Monday.
	 *
	 * @return void
	 */
	public function testAWeeklyTaskComesBackDueThisMonday(): void {
		$task = [
			'id'             => 't-1',
			'subject'        => 'Bel de nieuwe huurders',
			'assigneeUserId' => 'daan.verbeek',
			'repeat'         => 'weekly',
			'status'         => 'completed',
			'deadline'       => '2026-11-16T09:00:00+01:00',
			'attempts'       => [['result' => 'notified']],
			'@self'          => ['id' => 't-1'],
		];

		$made = $this->recurrence(tasks: [$task])->run(now: new DateTimeImmutable('2026-11-23T00:05:00+01:00'));

		$this->assertSame(expected: 1, actual: $made);
		[$occurrence, $uuid] = $this->saves[0];
		$this->assertNull(actual: $uuid);
		$this->assertSame(expected: 'Bel de nieuwe huurders', actual: $occurrence['subject']);
		$this->assertSame(expected: 'daan.verbeek', actual: $occurrence['assigneeUserId']);
		$this->assertSame(expected: 'open', actual: $occurrence['status']);
		$this->assertSame(expected: 'weekly', actual: $occurrence['repeat']);
		$this->assertSame(expected: '2026-11-23T09:00:00+01:00', actual: $occurrence['deadline']);
		$this->assertSame(expected: 't-1', actual: $occurrence['previousOccurrence']);
		$this->assertArrayNotHasKey(key: 'attempts', array: $occurrence);
		$this->assertArrayNotHasKey(key: '@self', array: $occurrence);

		[$source, $sourceUuid] = $this->saves[1];
		$this->assertSame(expected: 't-1', actual: $sourceUuid);
		$this->assertSame(expected: 'new-1', actual: $source['nextOccurrence']);
	}//end testAWeeklyTaskComesBackDueThisMonday()

	/**
	 * An occurrence made before whose link did not land is linked, not made again.
	 *
	 * @return void
	 */
	public function testAnEarlierOccurrenceIsLinkedNotMadeAgain(): void {
		$this->madeBefore = [['id' => 'occ-1', 'previousOccurrence' => 't-1']];
		$task             = ['id' => 't-1', 'subject' => 'Weekly', 'repeat' => 'weekly', 'deadline' => '2026-11-16T09:00:00+01:00'];

		$made = $this->recurrence(tasks: [$task])->run(now: new DateTimeImmutable('2026-11-23T00:05:00+01:00'));

		$this->assertSame(expected: 0, actual: $made);
		$this->assertCount(expectedCount: 1, haystack: $this->saves);
		$this->assertSame(expected: 't-1', actual: $this->saves[0][1]);
		$this->assertSame(expected: 'occ-1', actual: $this->saves[0][0]['nextOccurrence']);
	}//end testAnEarlierOccurrenceIsLinkedNotMadeAgain()

	/**
	 * A task still ahead, or one whose next occurrence was made, makes nothing.
	 *
	 * @return void
	 */
	public function testNothingIsMadeBeforeTheDueDateOrTwice(): void {
		$ahead = ['id' => 't-1', 'repeat' => 'daily', 'deadline' => '2026-11-26T09:00:00+01:00'];
		$done  = ['id' => 't-2', 'repeat' => 'daily', 'deadline' => '2026-11-20T09:00:00+01:00', 'nextOccurrence' => 't-3'];

		$this->assertSame(expected: 0, actual: $this->recurrence(tasks: [$ahead, $done])->run(now: new DateTimeImmutable('2026-11-25T12:00:00+01:00')));
		$this->assertSame(expected: [], actual: $this->saves);
	}//end testNothingIsMadeBeforeTheDueDateOrTwice()

	/**
	 * A task missed for a while comes back on the first interval that is not before today.
	 *
	 * @return void
	 */
	public function testAMissedTaskCatchesUpToToday(): void {
		$recurrence = $this->recurrence(tasks: []);
		$now        = new DateTimeImmutable('2026-11-25T12:00:00+01:00');

		$daily = $recurrence->nextDeadline(task: ['repeat' => 'daily', 'deadline' => '2026-11-20T09:00:00+01:00'], now: $now);
		$this->assertSame(expected: '2026-11-25T09:00:00+01:00', actual: $daily?->format(DATE_ATOM));

		$monthly = $recurrence->nextDeadline(task: ['repeat' => 'monthly', 'deadline' => '2026-08-31T09:00:00+02:00'], now: $now);
		$this->assertGreaterThanOrEqual(expected: '2026-11-25', actual: $monthly?->format('Y-m-d'));

		$this->assertNull(actual: $recurrence->nextDeadline(task: ['repeat' => 'yearly', 'deadline' => '2026-01-01'], now: $now));
		$this->assertNull(actual: $recurrence->nextDeadline(task: ['repeat' => 'weekly'], now: $now));
	}//end testAMissedTaskCatchesUpToToday()
}//end class
