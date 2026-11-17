<?php

/**
 * Unit tests for how FollowUpReminderJob degrades: an unset schema, a failing
 * read, a calendar leaf that answers with something else than a list, an
 * OpenRegister that cannot be resolved, and markers that cannot be pruned.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\BackgroundJob;

use OCA\Pipelinq\BackgroundJob\FollowUpReminderJob;
use OCA\Pipelinq\Kernel\Contracts\NotificationServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the failure paths of FollowUpReminderJob.
 *
 * @covers \OCA\Pipelinq\BackgroundJob\FollowUpReminderJob
 * @uses   \OCA\Pipelinq\Util\CastingPayloadToArray
 * @uses   \OCA\Pipelinq\Util\EntityPayload
 * @uses   \OCA\Pipelinq\Util\SerialisedPayload
 * @uses   \OCA\Pipelinq\Util\SerialisedToArray
 * @uses   \OCA\Pipelinq\BackgroundJob\FollowUpSweep
 */
class FollowUpReminderJobFailureTest extends TestCase {

	/**
	 * The fixed "now" of every run.
	 */
	private const NOW = 1_790_000_000;

	/**
	 * In-memory app config.
	 *
	 * @var array<string, string>
	 */
	private array $store = ['register' => 'pipelinq', 'lead_schema' => 'lead', 'task_schema' => 'task'];

	/**
	 * Log calls as [level, message].
	 *
	 * @var list<array{0: string, 1: string}>
	 */
	private array $logs = [];

	/**
	 * Notifications sent, as [userId, subject].
	 *
	 * @var list<array{0: string, 1: string}>
	 */
	private array $sent = [];

	/**
	 * Run the job once over the given container.
	 *
	 * @param ContainerInterface $container The container.
	 * @param bool $keysFail Whether listing the config keys throws.
	 *
	 * @return void
	 */
	private function runJob(ContainerInterface $container, bool $keysFail = false): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->store[$key] ?? $default)
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->store[$key] = $value;
				return true;
			}
		);
		if ($keysFail === true) {
			$config->method('getKeys')->willThrowException(new RuntimeException('config table locked'));
		} else {
			$config->method('getKeys')->willReturnCallback(fn (): array => array_keys($this->store));
		}

		$notifications = $this->createMock(NotificationServiceInterface::class);
		$notifications->method('sendNotification')->willReturnCallback(
			function (string $userId, string $subject): void {
				$this->sent[] = [$userId, $subject];
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->logs[] = [$level, $message];
				}
			);
		}

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		$job = new FollowUpReminderJob($time, $config, $notifications, $container, $this->createMock(IGroupManager::class), $logger);
		$method = new \ReflectionMethod($job, 'run');
		$method->invoke($job, null);
	}//end runJob()

	/**
	 * A container holding the given services by id.
	 *
	 * @param array<string, mixed> $services The services; a Throwable is thrown on get().
	 *
	 * @return ContainerInterface
	 */
	private function container(array $services): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturnCallback(static fn (string $id): bool => array_key_exists($id, $services));
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($services): mixed {
				if ($services[$id] instanceof \Throwable) {
					throw $services[$id];
				}

				return $services[$id];
			}
		);
		return $container;
	}//end container()

	/**
	 * An object service answering per schema; a Throwable is thrown.
	 *
	 * @param array<string, mixed> $bySchema Rows or a Throwable per schema.
	 *
	 * @return object
	 */
	private function objectService(array $bySchema): object {
		return new class($bySchema) {
			/**
			 * @param array<string, mixed> $bySchema Rows or a Throwable per schema.
			 */
			public function __construct(
				private array $bySchema,
			) {
			}

			/**
			 * The rows of the schema in the filter.
			 *
			 * @param array<string, mixed> $config The query.
			 *
			 * @return array<int, mixed>
			 */
			public function findAll(array $config = []): array {
				$rows = ($this->bySchema[$config['filters']['schema']] ?? []);
				if ($rows instanceof \Throwable) {
					throw $rows;
				}

				return $rows;
			}
		};
	}//end objectService()

	/**
	 * A calendar leaf returning the given value for every lead.
	 *
	 * @param mixed $events What getLinkedEvents() returns, or a Throwable to throw.
	 *
	 * @return object
	 */
	private function leaf(mixed $events): object {
		return new class($events) {
			/**
			 * @param mixed $events The answer.
			 */
			public function __construct(
				private mixed $events,
			) {
			}

			/**
			 * The linked events.
			 *
			 * @param string $objectUuid The lead.
			 *
			 * @return mixed
			 */
			public function getLinkedEvents(string $objectUuid): mixed {
				if ($this->events instanceof \Throwable) {
					throw $this->events;
				}

				return $this->events;
			}
		};
	}//end leaf()

	/**
	 * The messages logged at one level.
	 *
	 * @param string $level The level.
	 *
	 * @return list<string>
	 */
	private function messages(string $level): array {
		return array_values(array_map(static fn (array $log): string => $log[1], array_filter($this->logs, static fn (array $log): bool => $log[0] === $level)));
	}//end messages()

	/**
	 * An open lead with an assignee.
	 *
	 * @return array<string, mixed>
	 */
	private static function lead(): array {
		return ['id' => 'lead-1', 'title' => 'Renewal', 'assignee' => 'alice', 'status' => 'open'];
	}//end lead()

	/**
	 * A due open follow-up task.
	 *
	 * @return array<string, mixed>
	 */
	private static function task(): array {
		return ['id' => 'task-1', 'subject' => 'Call back', 'status' => 'open', 'type' => 'followUpTask', 'assigneeUserId' => 'bob', 'deadline' => gmdate('c', self::NOW + 3600)];
	}//end task()

	/**
	 * A schema that is not configured is not read; the other schema still is.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredTaskSchemaIsSkippedWhileLeadsAreStillReminded(): void {
		unset($this->store['task_schema']);
		$events = [['uid' => 'ev-1', 'summary' => 'Demo', 'dtstart' => gmdate('c', self::NOW + 600)]];

		$this->runJob($this->container([
			'OCA\\OpenRegister\\Service\\ObjectService' => $this->objectService(['lead' => [self::lead()], '' => [self::task()]]),
			'OCA\\OpenRegister\\Service\\CalendarLinkService' => $this->leaf($events),
		]));

		$this->assertSame([['alice', FollowUpReminderJob::SUBJECT_EVENT]], $this->sent);
	}//end testAnUnconfiguredTaskSchemaIsSkippedWhileLeadsAreStillReminded()

	/**
	 * A failing read of one schema is logged and the other schema still runs.
	 *
	 * @return void
	 */
	public function testAFailingReadIsLoggedAndTheOtherSchemaStillRuns(): void {
		$this->runJob($this->container([
			'OCA\\OpenRegister\\Service\\ObjectService' => $this->objectService(['lead' => new RuntimeException('index offline'), 'task' => [self::task()]]),
			'OCA\\OpenRegister\\Service\\CalendarLinkService' => $this->leaf([]),
		]));

		$this->assertContains('FollowUpReminderJob: failed to read lead_schema', $this->messages('warning'));
		$this->assertSame(FollowUpReminderJob::SUBJECT_TASK, $this->sent[0][1] ?? null);
	}//end testAFailingReadIsLoggedAndTheOtherSchemaStillRuns()

	/**
	 * Linked events that are not a list, or a read that throws, remind nobody.
	 *
	 * @return void
	 */
	public function testLinkedEventsThatAreNotAListOrThrowRemindNobody(): void {
		unset($this->store['task_schema']);
		foreach (['not a list', new RuntimeException('caldav down')] as $answer) {
			$this->runJob($this->container([
				'OCA\\OpenRegister\\Service\\ObjectService' => $this->objectService(['lead' => [self::lead()]]),
				'OCA\\OpenRegister\\Service\\CalendarLinkService' => $this->leaf($answer),
			]));
		}

		$this->assertSame([], $this->sent);
		$this->assertSame(['FollowUpReminderJob: failed to read linked events'], $this->messages('warning'));
	}//end testLinkedEventsThatAreNotAListOrThrowRemindNobody()

	/**
	 * An OpenRegister that throws on get(), or answers with something that is
	 * not an object, counts as unavailable.
	 *
	 * @return void
	 */
	public function testAnObjectServiceThatThrowsOrIsNotAnObjectCountsAsUnavailable(): void {
		$this->runJob($this->container(['OCA\\OpenRegister\\Service\\ObjectService' => new RuntimeException('boot failed')]));
		$this->runJob($this->container(['OCA\\OpenRegister\\Service\\ObjectService' => 'a string']));

		$this->assertSame(
			['FollowUpReminderJob: OpenRegister is unavailable, skipping', 'FollowUpReminderJob: OpenRegister is unavailable, skipping'],
			$this->messages('info')
		);
		$this->assertSame([], $this->sent);
	}//end testAnObjectServiceThatThrowsOrIsNotAnObjectCountsAsUnavailable()

	/**
	 * Markers that cannot be listed are logged and the reminders already sent stay sent.
	 *
	 * @return void
	 */
	public function testMarkersThatCannotBeListedAreLoggedAfterTheReminders(): void {
		$this->runJob(
			$this->container([
				'OCA\\OpenRegister\\Service\\ObjectService' => $this->objectService(['task' => [self::task()]]),
			]),
			keysFail: true
		);

		$this->assertSame([['bob', FollowUpReminderJob::SUBJECT_TASK]], $this->sent);
		$this->assertSame(['FollowUpReminderJob: failed to prune reminder markers'], $this->messages('warning'));
	}//end testMarkersThatCannotBeListedAreLoggedAfterTheReminders()
}//end class
