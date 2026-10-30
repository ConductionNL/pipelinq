<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Pipelinq\Tests\Unit\Service\Export
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://pipelinq.app
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Export;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Pipelinq\Service\Export\CronExpressionHelper;
use OCA\Pipelinq\Service\Export\ExportDataService;
use OCA\Pipelinq\Service\Export\ExportDestinationService;
use OCA\Pipelinq\Service\Export\ExportFailureSwitch;
use OCA\Pipelinq\Service\Export\ExportJobService;
use OCA\Pipelinq\Service\Export\ExportRunSummary;
use OCA\Pipelinq\Service\NotificationService;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * An export job that keeps failing switches itself off and tells the
 * administrators once; switching it on starts the count over.
 *
 * @spec openspec/changes/exports-switch-off-after-repeated-failure/specs/export-failure-switch-off/spec.md#requirement-an-export-job-switches-off-after-repeated-failed-runs-req-efs-001
 * @spec openspec/changes/exports-switch-off-after-repeated-failure/specs/export-failure-switch-off/spec.md#requirement-administrators-are-told-once-when-a-job-is-switched-off-req-efs-002
 * @spec openspec/changes/exports-switch-off-after-repeated-failure/specs/export-failure-switch-off/spec.md#requirement-the-bi-export-page-shows-a-switched-off-job-and-lets-an-administrator-switch-it-back-on-req-efs-003
 * @spec openspec/changes/exports-switch-off-after-repeated-failure/specs/export-failure-switch-off/spec.md#requirement-an-administrator-sets-the-threshold-req-efs-004
 */
class ExportFailureSwitchTest extends TestCase {
	/**
	 * In-memory app config.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * Notifications sent, as [userId, subject, parameters].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $sent = [];

	/**
	 * Stored export jobs by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $jobs = [];

	/**
	 * Build an app config over the in-memory store.
	 *
	 * @return IAppConfig The app config.
	 */
	private function appConfig(): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);

		return $appConfig;
	}//end appConfig()

	/**
	 * Build the switch with two administrators and a recording notifier.
	 *
	 * @return ExportFailureSwitch The switch.
	 */
	private function failureSwitch(): ExportFailureSwitch {
		$admins = [];
		foreach (['admin', 'beheer'] as $uid) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$admins[] = $user;
		}

		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn($admins);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->with('admin')->willReturn($group);

		$notifier = $this->createMock(NotificationService::class);
		$notifier->method('sendNotification')->willReturnCallback(
			function (string $userId, string $subject, array $parameters): void {
				$this->sent[] = [$userId, $subject, $parameters];
			}
		);

		return new ExportFailureSwitch(
			appConfig: $this->appConfig(),
			groupManager: $groups,
			notifier: $notifier,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end failureSwitch()

	/**
	 * Build the real job service over an in-memory object store.
	 *
	 * @return ExportJobService The job service.
	 */
	private function jobService(): ExportJobService {
		$this->config += ['register' => 'pipelinq', 'exportJob_schema' => 'exportJob'];

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			fn (string $id): ?ObjectEntityInterface => isset($this->jobs[$id]) === true ? self::entity(row: $this->jobs[$id]) : null
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, array $extend, string $register, string $schema, ?string $uuid): ObjectEntityInterface {
				$this->jobs[(string)$uuid] = $object + ['id' => $uuid];
				return self::entity(row: $this->jobs[(string)$uuid]);
			}
		);

		$destinations = $this->createMock(ExportDestinationService::class);
		$destinations->method('isValid')->willReturn(true);

		return new ExportJobService(
			container: $this->createMock(ContainerInterface::class),
			appConfig: $this->appConfig(),
			objectService: $objects,
			destinations: $destinations,
			data: $this->createMock(ExportDataService::class),
			cron: new CronExpressionHelper(),
			logger: $this->createMock(LoggerInterface::class),
			summary: new ExportRunSummary(cron: new CronExpressionHelper()),
			failureSwitch: $this->failureSwitch(),
		);
	}//end jobService()

	/**
	 * Wrap a row as the entity ObjectService returns.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return ObjectEntityInterface The entity.
	 */
	private static function entity(array $row): ObjectEntityInterface {
		$entity = new ObjectEntity();
		$entity->setUuid((string)($row['id'] ?? ''));
		$entity->setObject($row);

		return $entity;
	}//end entity()

	/**
	 * A failed run as the execution service hands it over.
	 *
	 * @return array<string, mixed> The run.
	 */
	private static function failedRun(): array {
		return ['status' => 'failed', 'endedAt' => '2026-10-06T01:00:00+02:00', 'errorMessage' => 'connection refused'];
	}//end failedRun()

	/**
	 * The third failed run in a row switches the job off with the reason and
	 * the moment, and tells each administrator once with name, count and error.
	 *
	 * @return void
	 */
	public function testTheThirdFailedRunSwitchesTheJobOffAndTellsTheAdminsOnce(): void {
		$service = $this->jobService();
		$this->jobs['job-1'] = [
			'id' => 'job-1',
			'name' => 'Leads and pipeline value',
			'enabled' => true,
			'scheduleCron' => '@daily',
			'consecutiveFailures' => 2,
		];

		$job = $service->recordRun(jobId: 'job-1', run: self::failedRun());

		$this->assertFalse($job['enabled']);
		$this->assertNull($job['nextRunAt']);
		$this->assertSame(3, $job['consecutiveFailures']);
		$this->assertSame('Switched off after 3 failed runs in a row', $job['pausedReason']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', (string)$job['pausedAt']);
		$this->assertSame('failed', $job['lastRunStatus']);

		$parameters = ['name' => 'Leads and pipeline value', 'failures' => 3, 'error' => 'connection refused'];
		$this->assertSame(
			[
				['admin', 'export_job_switched_off', $parameters],
				['beheer', 'export_job_switched_off', $parameters],
			],
			$this->sent
		);
	}//end testTheThirdFailedRunSwitchesTheJobOffAndTellsTheAdminsOnce()

	/**
	 * Below the threshold a failed run only counts, and nobody is told.
	 *
	 * @return void
	 */
	public function testAFailedRunBelowTheThresholdOnlyCounts(): void {
		$service = $this->jobService();
		$this->jobs['job-1'] = ['id' => 'job-1', 'name' => 'Tickets', 'enabled' => true, 'scheduleCron' => '@daily', 'consecutiveFailures' => 1];

		$job = $service->recordRun(jobId: 'job-1', run: self::failedRun());

		$this->assertTrue($job['enabled']);
		$this->assertSame(2, $job['consecutiveFailures']);
		$this->assertArrayNotHasKey('pausedReason', $job);
		$this->assertSame([], $this->sent);
	}//end testAFailedRunBelowTheThresholdOnlyCounts()

	/**
	 * A succeeded run resets the count; a partial run leaves it.
	 *
	 * @return void
	 */
	public function testASucceededRunResetsAndAPartialRunLeavesTheCount(): void {
		$switch = $this->failureSwitch();
		$job    = ['enabled' => true, 'consecutiveFailures' => 2];

		$this->assertSame(0, $switch->countRun(job: $job, status: 'succeeded')['consecutiveFailures']);
		$partial = $switch->countRun(job: $job, status: 'partial');
		$this->assertSame(2, $partial['consecutiveFailures']);
		$this->assertTrue($partial['enabled']);
	}//end testASucceededRunResetsAndAPartialRunLeavesTheCount()

	/**
	 * A job that is already off is not switched off again, so nobody is told twice.
	 *
	 * @return void
	 */
	public function testAJobThatIsOffIsNotSwitchedOffAgain(): void {
		$service = $this->jobService();
		$this->jobs['job-1'] = [
			'id' => 'job-1',
			'name' => 'Tickets',
			'enabled' => false,
			'consecutiveFailures' => 3,
			'pausedReason' => 'Switched off after 3 failed runs in a row',
			'pausedAt' => '2026-10-05T01:00:00+02:00',
		];

		$job = $service->recordRun(jobId: 'job-1', run: self::failedRun());

		$this->assertSame(4, $job['consecutiveFailures']);
		$this->assertSame('2026-10-05T01:00:00+02:00', $job['pausedAt']);
		$this->assertSame([], $this->sent);
	}//end testAJobThatIsOffIsNotSwitchedOffAgain()

	/**
	 * With the threshold at 1 the first failed run switches the job off.
	 *
	 * @return void
	 */
	public function testAStricterThresholdSwitchesOffAfterOneRun(): void {
		$this->config[ExportFailureSwitch::THRESHOLD_KEY] = '1';
		$job = $this->failureSwitch()->countRun(job: ['enabled' => true], status: 'failed');

		$this->assertFalse($job['enabled']);
		$this->assertSame('Switched off after 1 failed run', $job['pausedReason']);
	}//end testAStricterThresholdSwitchesOffAfterOneRun()

	/**
	 * The threshold is a whole number from 1 to 10; anything else reads as 3.
	 *
	 * @return void
	 */
	public function testTheThresholdIsAWholeNumberFromOneToTen(): void {
		$switch = $this->failureSwitch();
		$this->assertSame(3, $switch->threshold());

		foreach (['1' => 1, '10' => 10, ' 7 ' => 7, '0' => 3, '11' => 3, '2.5' => 3, 'abc' => 3, '' => 3] as $stored => $expected) {
			$this->config[ExportFailureSwitch::THRESHOLD_KEY] = (string)$stored;
			$this->assertSame($expected, $switch->threshold(), 'stored ' . var_export($stored, true));
		}
	}//end testTheThresholdIsAWholeNumberFromOneToTen()

	/**
	 * Switching the job on clears the reason and starts the count over.
	 *
	 * @return void
	 */
	public function testSwitchingTheJobOnStartsOver(): void {
		$service = $this->jobService();
		$this->jobs['job-1'] = [
			'id' => 'job-1',
			'name' => 'Leads and pipeline value',
			'enabled' => false,
			'scheduleCron' => '@daily',
			'destinationId' => 'dest-1',
			'consecutiveFailures' => 3,
			'pausedReason' => 'Switched off after 3 failed runs in a row',
			'pausedAt' => '2026-10-06T01:00:00+02:00',
		];

		$job = $service->enableJob(id: 'job-1');

		$this->assertTrue($job['enabled']);
		$this->assertSame(0, $job['consecutiveFailures']);
		$this->assertNull($job['pausedReason']);
		$this->assertNull($job['pausedAt']);
		$this->assertNotNull($job['nextRunAt']);
	}//end testSwitchingTheJobOnStartsOver()
}//end class
