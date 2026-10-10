<?php

/**
 * Tests for TaskNameBackfillService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/round5-task-name-backfill/specs/repair-steps/spec.md#requirement-existing-tasks-are-named-after-their-subject-without-telling-anyone
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Pipelinq\Repair\BackfillTaskNames;
use OCA\Pipelinq\Service\ObjectEventSilence;
use OCA\Pipelinq\Service\TaskNameBackfillService;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A task without a name gets its subject, once, and nobody is told.
 *
 * The in-memory store behaves like OpenRegister where it matters here: a save
 * hydrates `@self.name` from the subject, and the "system scope" is a flag that
 * is only up while runAsSystem() runs its callable. Each write records whether
 * the scope was up at that moment, which is what decides in OpenRegister
 * whether the update event (and so the notification and activity) fires.
 *
 * @spec openspec/changes/round5-task-name-backfill/specs/repair-steps/spec.md#requirement-existing-tasks-are-named-after-their-subject-without-telling-anyone
 */
class TaskNameBackfillServiceTest extends TestCase {
	/**
	 * Tasks by uuid, as OpenRegister would render them.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $store = [];

	/**
	 * Every saveObject call: its named arguments plus `inScope`.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $writes = [];

	/**
	 * Whether the system scope is up right now.
	 *
	 * @var bool
	 */
	private bool $inScope = false;

	/**
	 * Build the service.
	 *
	 * @param bool     $supported   Whether OpenRegister ships the event gate.
	 * @param bool     $entersScope Whether runAsSystem() really raises the scope.
	 * @param string[] $failOn      Uuids whose save throws.
	 *
	 * @return TaskNameBackfillService
	 */
	private function service(bool $supported = true, bool $entersScope = true, array $failOn = []): TaskNameBackfillService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'register' => '20',
				'task_schema' => '35',
				default => $default,
			}
		);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('runAsSystem')->willReturnCallback(
			function (callable $operation) use ($entersScope) {
				$this->inScope = $entersScope;
				try {
					return $operation();
				} finally {
					$this->inScope = false;
				}
			}
		);
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => array_values($this->store)
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (
				array $object,
				?array $extend = [],
				string|int|null $register = null,
				string|int|null $schema = null,
				?string $uuid = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
				bool $silent = false,
				bool $_validation = true,
				?array $uploadedFiles = null,
				?IUser $currentUser = null,
			) use ($failOn): ObjectEntity {
				$this->writes[] = [
					'object' => $object,
					'register' => $register,
					'schema' => $schema,
					'uuid' => $uuid,
					'_rbac' => $_rbac,
					'_multitenancy' => $_multitenancy,
					'silent' => $silent,
					'currentUser' => $currentUser,
					'inScope' => $this->inScope,
				];
				if (in_array($uuid, $failOn, true) === true) {
					throw new RuntimeException('refused');
				}

				// OpenRegister hydrates the name from objectNameField on save.
				$this->store[(string)$uuid] = array_merge(
					$object,
					['@self' => ['id' => $uuid, 'name' => $object['subject']]]
				);
				return new ObjectEntity();
			}
		);

		$silence = $this->createMock(ObjectEventSilence::class);
		$silence->method('isSupported')->willReturn($supported);
		$silence->method('isActive')->willReturnCallback(fn (): bool => $supported && $this->inScope);

		$admin = $this->createMock(IUser::class);
		$adminGroup = $this->createMock(IGroup::class);
		$adminGroup->method('getUsers')->willReturn(['admin' => $admin]);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturn($adminGroup);

		return new TaskNameBackfillService(
			appConfig: $appConfig,
			objectService: $objectService,
			silence: $silence,
			groupManager: $groups,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end service()

	/**
	 * Put a task in the store.
	 *
	 * @param string      $uuid    The uuid.
	 * @param string      $subject The subject.
	 * @param string|null $name    Its current name.
	 *
	 * @return void
	 */
	private function task(string $uuid, string $subject, ?string $name): void {
		$this->store[$uuid] = [
			'id' => $uuid,
			'subject' => $subject,
			'type' => 'callbackRequest',
			'status' => 'open',
			'assigneeUserId' => 'jan',
			'deadline' => '2026-10-07 11:40:03',
			'@self' => ['id' => $uuid, 'name' => $name],
		];
	}//end task()

	/**
	 * A task named after its uuid is saved with its own data and gets its subject.
	 *
	 * @return void
	 */
	public function testATaskWithoutANameGetsItsSubject(): void {
		$this->task(uuid: 'aaaa-1', subject: 'Call back about the permit', name: 'aaaa-1');
		$this->task(uuid: 'aaaa-2', subject: 'Follow up the quote', name: null);

		$result = $this->service()->backfill();

		$this->assertSame(['status' => 'done', 'named' => 2, 'skipped' => 0, 'failed' => 0], $result);
		$this->assertCount(2, $this->writes);
		$this->assertSame('aaaa-1', $this->writes[0]['uuid']);
		$this->assertSame('20', $this->writes[0]['register']);
		$this->assertSame('35', $this->writes[0]['schema']);
		$this->assertSame('Call back about the permit', $this->writes[0]['object']['subject']);
		$this->assertSame('jan', $this->writes[0]['object']['assigneeUserId']);
		$this->assertArrayNotHasKey('@self', $this->writes[0]['object']);
		// The read-side date shape is put back into ISO 8601, or the save is refused.
		$this->assertMatchesRegularExpression('/^2026-10-07T11:40:03[+-]\d{2}:\d{2}$/', $this->writes[0]['object']['deadline']);
		$this->assertSame('Call back about the permit', $this->store['aaaa-1']['@self']['name']);
		$this->assertSame('Follow up the quote', $this->store['aaaa-2']['@self']['name']);
	}//end testATaskWithoutANameGetsItsSubject()

	/**
	 * A task that has a name, even one that is not its subject, is not saved.
	 * A task without a subject has nothing to be named after.
	 *
	 * @return void
	 */
	public function testATaskWithANameIsLeftAlone(): void {
		$this->task(uuid: 'bbbb-1', subject: 'Call back', name: 'Call back');
		$this->task(uuid: 'bbbb-2', subject: 'Call back', name: 'A name somebody chose');
		$this->task(uuid: 'bbbb-3', subject: '  ', name: 'bbbb-3');

		$result = $this->service()->backfill();

		$this->assertSame(['status' => 'done', 'named' => 0, 'skipped' => 3, 'failed' => 0], $result);
		$this->assertSame([], $this->writes);
	}//end testATaskWithANameIsLeftAlone()

	/**
	 * Every write runs inside the system scope, where OpenRegister withholds
	 * the update event, and skips the audit trail.
	 *
	 * @return void
	 */
	public function testNobodyIsTold(): void {
		$this->task(uuid: 'cccc-1', subject: 'Call back', name: 'cccc-1');
		$this->task(uuid: 'cccc-2', subject: 'Send the form', name: 'cccc-2');

		$this->service()->backfill();

		$this->assertCount(2, $this->writes);
		foreach ($this->writes as $write) {
			$this->assertTrue($write['inScope'], 'a write outside the system scope reaches the assignee');
			$this->assertTrue($write['silent']);
			$this->assertFalse($write['_rbac']);
			$this->assertFalse($write['_multitenancy']);
			$this->assertNotNull($write['currentUser']);
		}
	}//end testNobodyIsTold()

	/**
	 * An OpenRegister without the event gate, or a scope that did not come up,
	 * means no write at all.
	 *
	 * @return void
	 */
	public function testNoSilentPathMeansNoWrite(): void {
		$this->task(uuid: 'dddd-1', subject: 'Call back', name: 'dddd-1');

		$unsupported = $this->service(supported: false)->backfill();
		$this->assertSame('no-silent-path', $unsupported['status']);
		$this->assertSame([], $this->writes);

		$noScope = $this->service(entersScope: false)->backfill();
		$this->assertSame('scope-inactive', $noScope['status']);
		$this->assertSame([], $this->writes);
		$this->assertSame('dddd-1', $this->store['dddd-1']['@self']['name']);
	}//end testNoSilentPathMeansNoWrite()

	/**
	 * A second run finds every task named and writes nothing.
	 *
	 * @return void
	 */
	public function testASecondRunChangesNothing(): void {
		$this->task(uuid: 'eeee-1', subject: 'Call back', name: 'eeee-1');
		$this->task(uuid: 'eeee-2', subject: 'Send the form', name: 'Send the form');

		$first = $this->service()->backfill();
		$this->assertSame(1, $first['named']);
		$writesAfterFirst = count($this->writes);

		$second = $this->service()->backfill();

		$this->assertSame(['status' => 'done', 'named' => 0, 'skipped' => 2, 'failed' => 0], $second);
		$this->assertCount($writesAfterFirst, $this->writes);
	}//end testASecondRunChangesNothing()

	/**
	 * One refused save is counted and the rest still run.
	 *
	 * @return void
	 */
	public function testARefusedSaveIsCountedAndTheRestContinue(): void {
		$this->task(uuid: 'ffff-1', subject: 'Call back', name: 'ffff-1');
		$this->task(uuid: 'ffff-2', subject: 'Send the form', name: 'ffff-2');

		$result = $this->service(failOn: ['ffff-1'])->backfill();

		$this->assertSame(['status' => 'done', 'named' => 1, 'skipped' => 0, 'failed' => 1], $result);
		$this->assertSame('Send the form', $this->store['ffff-2']['@self']['name']);
	}//end testARefusedSaveIsCountedAndTheRestContinue()

	/**
	 * The repair step says why it wrote nothing, as a warning.
	 *
	 * @return void
	 */
	public function testTheStepSaysWhyItWroteNothing(): void {
		$this->task(uuid: 'gggg-1', subject: 'Call back', name: 'gggg-1');
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning')->with($this->stringContains('cannot save without notifying'));
		$output->expects($this->never())->method('info');

		(new BackfillTaskNames(service: $this->service(supported: false)))->run($output);
	}//end testTheStepSaysWhyItWroteNothing()
}//end class
