<?php

/**
 * ExportJobService: counting failed runs in a row and switching a job off.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Export
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/exports-switch-off-after-repeated-failure/specs/export-failure-switch-off/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Export;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Pipelinq\Service\Export\CronExpressionHelper;
use OCA\Pipelinq\Service\Export\ExportDataService;
use OCA\Pipelinq\Service\Export\ExportDestinationService;
use OCA\Pipelinq\Service\Export\ExportJobService;
use OCA\Pipelinq\Service\Export\ExportJobSwitchOffNotifier;
use OCA\Pipelinq\Service\Export\ExportRunSummary;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A succeeded run resets the count, a failed run adds one, a partial run
 * leaves it; an enabled job is switched off at the threshold with its reason
 * and the administrators are told once; switching it on clears it all.
 *
 * @covers \OCA\Pipelinq\Service\Export\ExportJobService
 * @covers \OCA\Pipelinq\Service\Export\AbstractExportService
 * @uses   \OCA\Pipelinq\Util\CastingPayloadToArray
 * @uses   \OCA\Pipelinq\Util\EntityPayload
 * @uses   \OCA\Pipelinq\Util\SerialisedPayload
 *
 * @spec openspec/changes/exports-switch-off-after-repeated-failure/specs/export-failure-switch-off/spec.md
 */
class ExportJobSwitchOffTest extends TestCase {

	/**
	 * Stored jobs, keyed by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $jobs = [];

	/**
	 * Saves seen, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saves = [];

	/**
	 * The app config values.
	 *
	 * @var array<string, string>
	 */
	private array $config = ['register' => 'reg', 'exportJob_schema' => 'jobs'];

	/**
	 * The switch-off notifier mock.
	 *
	 * @var ExportJobSwitchOffNotifier&MockObject
	 */
	private ExportJobSwitchOffNotifier&MockObject $notifier;

	/**
	 * The destination service mock.
	 *
	 * @var ExportDestinationService&MockObject
	 */
	private ExportDestinationService&MockObject $destinations;

	/**
	 * Build the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->notifier = $this->createMock(ExportJobSwitchOffNotifier::class);
		$this->destinations = $this->createMock(ExportDestinationService::class);
	}//end setUp()

	/**
	 * The service under test over an array-backed object service.
	 *
	 * @return ExportJobService
	 */
	private function service(): ExportJobService {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			fn (int|string $id): ?ObjectEntity => (isset($this->jobs[$id]) === true ? self::entity((string)$id, $this->jobs[$id]) : null)
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null): ObjectEntity {
				$this->saves[] = $object;
				$this->jobs[(string)$uuid] = $object;
				return self::entity((string)$uuid, $object);
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);

		$summary = $this->createMock(ExportRunSummary::class);
		$summary->method('lastRunSummary')->willReturnCallback(
			static fn (array $run): array => ['lastRunStatus' => (string)($run['status'] ?? ''), 'lastRunError' => (string)($run['errorMessage'] ?? '')]
		);
		$summary->method('nextRunAt')->willReturn('2026-11-14T01:00:00+00:00');

		return new ExportJobService(
			$this->createMock(ContainerInterface::class),
			$appConfig,
			$objects,
			$this->destinations,
			$this->createMock(ExportDataService::class),
			$this->createMock(CronExpressionHelper::class),
			$this->createMock(LoggerInterface::class),
			$summary,
			$this->notifier,
		);
	}//end service()

	/**
	 * An entity with the given payload.
	 *
	 * @param string               $uuid The uuid.
	 * @param array<string, mixed> $data The payload.
	 *
	 * @return ObjectEntity
	 */
	private static function entity(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * An enabled job with this many failed runs in a row.
	 *
	 * @param int  $failures The count.
	 * @param bool $enabled  Whether the job is enabled.
	 *
	 * @return void
	 */
	private function storeJob(int $failures, bool $enabled = true): void {
		$this->jobs['j-1'] = [
			'name' => 'Leads and pipeline value',
			'enabled' => $enabled,
			'destinationId' => 'd-1',
			'consecutiveFailures' => $failures,
		];
	}//end storeJob()

	/**
	 * A succeeded run sets the count to 0, a failed one adds one, a partial
	 * one leaves it, and none of them below the threshold switches the job off.
	 *
	 * @param string $status   The run status.
	 * @param int    $expected The count after the run.
	 *
	 * @return void
	 */
	#[DataProvider('outcomes')]
	public function testTheRunOutcomeSetsTheCount(string $status, int $expected): void {
		$this->storeJob(failures: 1);
		$this->notifier->expects($this->never())->method('notifySwitchedOff');

		$job = $this->service()->recordRun(jobId: 'j-1', run: ['status' => $status, 'errorMessage' => 'connection refused']);

		$this->assertSame($expected, $job['consecutiveFailures']);
		$this->assertTrue($job['enabled']);
		$this->assertSame('2026-11-14T01:00:00+00:00', $job['nextRunAt']);
		$this->assertArrayNotHasKey('pausedReason', $job);
	}//end testTheRunOutcomeSetsTheCount()

	/**
	 * The run outcomes and the count each leaves, starting from 1.
	 *
	 * @return array<string, array{0: string, 1: int}>
	 */
	public static function outcomes(): array {
		return [
			'succeeded resets' => ['succeeded', 0],
			'failed adds one' => ['failed', 2],
			'partial leaves it' => ['partial', 1],
		];
	}//end outcomes()

	/**
	 * A job without a count yet starts from zero.
	 *
	 * @return void
	 */
	public function testAJobWithoutACountStartsFromZero(): void {
		$this->jobs['j-1'] = ['name' => 'Nightly', 'enabled' => true];

		$job = $this->service()->recordRun(jobId: 'j-1', run: ['status' => 'failed']);

		$this->assertSame(1, $job['consecutiveFailures']);
		$this->assertTrue($job['enabled']);
	}//end testAJobWithoutACountStartsFromZero()

	/**
	 * The third failed run in a row switches the job off with its reason and
	 * time in the same save, and tells the administrators once.
	 *
	 * @return void
	 */
	public function testTheThirdFailureSwitchesTheJobOffAndNotifiesOnce(): void {
		$this->storeJob(failures: 2);
		$this->notifier->expects($this->once())->method('notifySwitchedOff')->with(
			$this->callback(static fn (array $job): bool => $job['name'] === 'Leads and pipeline value' && $job['id'] === 'j-1'),
			3,
			'connection refused'
		);

		$job = $this->service()->recordRun(jobId: 'j-1', run: ['status' => 'failed', 'errorMessage' => 'connection refused']);

		$this->assertCount(1, $this->saves);
		$this->assertFalse($job['enabled']);
		$this->assertNull($job['nextRunAt']);
		$this->assertSame(3, $job['consecutiveFailures']);
		$this->assertSame('Switched off after 3 failed runs in a row', $job['pausedReason']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $job['pausedAt']);
		$this->assertSame('failed', $job['lastRunStatus']);
	}//end testTheThirdFailureSwitchesTheJobOffAndNotifiesOnce()

	/**
	 * The second failed run in a row leaves the job on.
	 *
	 * @return void
	 */
	public function testTheSecondFailureLeavesTheJobOn(): void {
		$this->storeJob(failures: 1);
		$this->notifier->expects($this->never())->method('notifySwitchedOff');

		$job = $this->service()->recordRun(jobId: 'j-1', run: ['status' => 'failed']);

		$this->assertTrue($job['enabled']);
		$this->assertSame(2, $job['consecutiveFailures']);
	}//end testTheSecondFailureLeavesTheJobOn()

	/**
	 * A job that is already off is not switched off again and nobody is told
	 * again, however many runs fail.
	 *
	 * @return void
	 */
	public function testAJobThatIsOffIsNotSwitchedOffAgain(): void {
		$this->storeJob(failures: 5, enabled: false);
		$this->notifier->expects($this->never())->method('notifySwitchedOff');

		$job = $this->service()->recordRun(jobId: 'j-1', run: ['status' => 'failed']);

		$this->assertFalse($job['enabled']);
		$this->assertSame(6, $job['consecutiveFailures']);
		$this->assertArrayNotHasKey('pausedReason', $job);
	}//end testAJobThatIsOffIsNotSwitchedOffAgain()

	/**
	 * A partial run never switches a job off, even at the threshold.
	 *
	 * @return void
	 */
	public function testAPartialRunAtTheThresholdLeavesTheJobOn(): void {
		$this->storeJob(failures: 3);
		$this->notifier->expects($this->never())->method('notifySwitchedOff');

		$job = $this->service()->recordRun(jobId: 'j-1', run: ['status' => 'partial']);

		$this->assertTrue($job['enabled']);
		$this->assertSame(3, $job['consecutiveFailures']);
	}//end testAPartialRunAtTheThresholdLeavesTheJobOn()

	/**
	 * With a threshold of 1 the first failed run switches the job off.
	 *
	 * @return void
	 */
	public function testAStricterThresholdSwitchesOffAfterOneFailure(): void {
		$this->config[ExportJobService::FAILURE_THRESHOLD_KEY] = '1';
		$this->storeJob(failures: 0);
		$this->notifier->expects($this->once())->method('notifySwitchedOff')->with($this->anything(), 1, '');

		$job = $this->service()->recordRun(jobId: 'j-1', run: ['status' => 'failed']);

		$this->assertFalse($job['enabled']);
		$this->assertSame('Switched off after 1 failed runs in a row', $job['pausedReason']);
	}//end testAStricterThresholdSwitchesOffAfterOneFailure()

	/**
	 * The threshold is the stored value when it is 1 to 10, else 3.
	 *
	 * @param string $stored   The stored value.
	 * @param int    $expected The threshold used.
	 *
	 * @return void
	 */
	#[DataProvider('storedThresholds')]
	public function testTheThresholdFallsBackToThreeOutsideTheRange(string $stored, int $expected): void {
		$this->config[ExportJobService::FAILURE_THRESHOLD_KEY] = $stored;

		$this->assertSame($expected, $this->service()->failureThreshold());
	}//end testTheThresholdFallsBackToThreeOutsideTheRange()

	/**
	 * Stored threshold values and the threshold each gives.
	 *
	 * @return array<string, array{0: string, 1: int}>
	 */
	public static function storedThresholds(): array {
		return [
			'one' => ['1', 1],
			'ten' => ['10', 10],
			'padded' => [' 5 ', 5],
			'zero' => ['0', 3],
			'eleven' => ['11', 3],
			'negative' => ['-2', 3],
			'decimal' => ['2.5', 3],
			'text' => ['abc', 3],
			'empty' => ['', 3],
		];
	}//end storedThresholds()

	/**
	 * Without a stored value the threshold is 3.
	 *
	 * @return void
	 */
	public function testTheDefaultThresholdIsThree(): void {
		$this->assertSame(3, $this->service()->failureThreshold());
	}//end testTheDefaultThresholdIsThree()

	/**
	 * Only a whole number from 1 to 10 is a valid threshold.
	 *
	 * @return void
	 */
	public function testOnlyAWholeNumberFromOneToTenIsValid(): void {
		$this->assertTrue(ExportJobService::isValidThreshold(value: '1'));
		$this->assertTrue(ExportJobService::isValidThreshold(value: '10'));
		$this->assertFalse(ExportJobService::isValidThreshold(value: '0'));
		$this->assertFalse(ExportJobService::isValidThreshold(value: '11'));
		$this->assertFalse(ExportJobService::isValidThreshold(value: '3a'));
		$this->assertFalse(ExportJobService::isValidThreshold(value: ''));
	}//end testOnlyAWholeNumberFromOneToTenIsValid()

	/**
	 * Switching a switched-off job back on clears the reason and the time and
	 * starts the count again.
	 *
	 * @return void
	 */
	public function testSwitchingOnStartsOver(): void {
		$this->jobs['j-1'] = [
			'name' => 'Nightly',
			'enabled' => false,
			'destinationId' => 'd-1',
			'consecutiveFailures' => 3,
			'pausedReason' => 'Switched off after 3 failed runs in a row',
			'pausedAt' => '2026-10-06T01:00:00+00:00',
		];
		$this->destinations->method('isValid')->with('d-1')->willReturn(true);

		$job = $this->service()->enableJob(id: 'j-1');

		$this->assertTrue($job['enabled']);
		$this->assertSame(0, $job['consecutiveFailures']);
		$this->assertNull($job['pausedReason']);
		$this->assertNull($job['pausedAt']);
		$this->assertSame('2026-11-14T01:00:00+00:00', $job['nextRunAt']);
	}//end testSwitchingOnStartsOver()
}//end class
