<?php

/**
 * ExportJobService: job CRUD, enabling, run recording and test runs.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Export
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Export;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\Export\CronExpressionHelper;
use OCA\Pipelinq\Service\Export\ExportDataService;
use OCA\Pipelinq\Service\Export\ExportDestinationService;
use OCA\Pipelinq\Service\Export\ExportJobService;
use OCA\Pipelinq\Service\Export\ExportRunSummary;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Every write disables or schedules the job as its transition requires and
 * saves it under the job schema; a test run reports per-schema samples.
 *
 * @covers \OCA\Pipelinq\Service\Export\ExportJobService
 * @uses   \OCA\Pipelinq\Service\Export\AbstractExportService
 * @uses   \OCA\Pipelinq\Service\Export\CronExpressionHelper
 * @uses   \OCA\Pipelinq\Service\Export\ExportRunSummary
 * @uses   \OCA\Pipelinq\Util\CastingPayloadToArray
 * @uses   \OCA\Pipelinq\Util\EntityPayload
 * @uses   \OCA\Pipelinq\Util\SerialisedPayload
 */
class ExportJobServiceTest extends TestCase {

	/**
	 * A job that passes validation.
	 *
	 * @var array<string, mixed>
	 */
	private const VALID = [
		'name' => 'Nightly',
		'sourceSchemas' => ['lead'],
		'format' => 'csv',
		'mode' => 'full',
		'scheduleCron' => '@daily',
		'destinationId' => 'dest-1',
	];

	/**
	 * The object store.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * The destination service.
	 *
	 * @var ExportDestinationService&MockObject
	 */
	private ExportDestinationService&MockObject $destinations;

	/**
	 * The extraction service.
	 *
	 * @var ExportDataService&MockObject
	 */
	private ExportDataService&MockObject $data;

	/**
	 * The service under test.
	 *
	 * @var ExportJobService
	 */
	private ExportJobService $service;

	/**
	 * The arguments of every saveObject call.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * Build the service over a configured job schema.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], ?string $register = null, ?string $schema = null, ?string $uuid = null): ObjectEntityInterface {
				$this->saved[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid];
				return $this->entity(payload: $object);
			}
		);

		$config = ['register' => 'reg', 'exportJob_schema' => 'jobs'];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		$this->destinations = $this->createMock(ExportDestinationService::class);
		$this->data = $this->createMock(ExportDataService::class);
		$this->data->method('schemaExists')->willReturn(true);
		$cron = new CronExpressionHelper();

		$this->service = new ExportJobService(
			$this->createMock(ContainerInterface::class),
			$appConfig,
			$this->objects,
			$this->destinations,
			$this->data,
			$cron,
			$this->createMock(LoggerInterface::class),
			new ExportRunSummary(cron: $cron),
		);
	}//end setUp()

	/**
	 * A new job is stored disabled, owned, unscheduled, with its cron normalised and identity stripped.
	 *
	 * @return void
	 */
	public function testCreateJob(): void {
		$job = $this->service->createJob(data: self::VALID + ['id' => 'x', 'uuid' => 'y', '@self' => [], 'enabled' => true], userId: 'alice');

		$this->assertFalse($job['enabled']);
		$this->assertSame('alice', $job['createdBy']);
		$this->assertSame('0 0 * * *', $job['scheduleCron']);
		$this->assertNull($job['nextRunAt']);
		$this->assertArrayHasKey('createdAt', $job);
		$this->assertArrayNotHasKey('id', $job);
		$this->assertArrayNotHasKey('uuid', $job);
		$this->assertSame(['reg', 'jobs', null], [$this->saved[0]['register'], $this->saved[0]['schema'], $this->saved[0]['uuid']]);
	}//end testCreateJob()

	/**
	 * An invalid job is refused before anything is saved.
	 *
	 * @return void
	 */
	public function testCreateJobRefusesInvalidJob(): void {
		$this->objects->expects($this->never())->method('saveObject');

		$this->expectException(OCSBadRequestException::class);
		$this->expectExceptionMessage('Job name is required.');
		$this->service->createJob(data: ['name' => ''] + self::VALID, userId: 'alice');
	}//end testCreateJobRefusesInvalidJob()

	/**
	 * An update merges over the stored job, keeps its id out of the body and disables it again.
	 *
	 * @return void
	 */
	public function testUpdateJobMergesAndDisables(): void {
		$this->stored(job: self::VALID + ['enabled' => true, 'nextRunAt' => '2026-01-01', 'createdBy' => 'bob']);

		$job = $this->service->updateJob(id: 'j1', data: ['name' => 'Weekly', 'scheduleCron' => '@weekly', 'id' => 'other']);

		$this->assertSame('Weekly', $job['name']);
		$this->assertSame('bob', $job['createdBy']);
		$this->assertSame('0 0 * * 0', $job['scheduleCron']);
		$this->assertFalse($job['enabled']);
		$this->assertNull($job['nextRunAt']);
		$this->assertSame('j1', $this->saved[0]['uuid']);
	}//end testUpdateJobMergesAndDisables()

	/**
	 * Updating a job that does not exist is not found.
	 *
	 * @return void
	 */
	public function testUpdateMissingJobIsNotFound(): void {
		$this->objects->method('find')->willReturn(null);

		$this->expectException(OCSNotFoundException::class);
		$this->expectExceptionMessage('Export job not found.');
		$this->service->updateJob(id: 'nope', data: []);
	}//end testUpdateMissingJobIsNotFound()

	/**
	 * Deleting checks existence, then deletes under the job schema.
	 *
	 * @return void
	 */
	public function testDeleteJob(): void {
		$this->stored(job: self::VALID);
		$this->objects->expects($this->once())->method('deleteObject')->with('j1', 'reg', 'jobs');

		$this->service->deleteJob(id: 'j1');
	}//end testDeleteJob()

	/**
	 * Deleting a missing job deletes nothing.
	 *
	 * @return void
	 */
	public function testDeleteMissingJobDeletesNothing(): void {
		$this->objects->method('find')->willReturn(null);
		$this->objects->expects($this->never())->method('deleteObject');

		$this->expectException(OCSNotFoundException::class);
		$this->service->deleteJob(id: 'nope');
	}//end testDeleteMissingJobDeletesNothing()

	/**
	 * Enabling requires a valid destination, then schedules the next run.
	 *
	 * @return void
	 */
	public function testEnableJob(): void {
		$this->stored(job: self::VALID + ['enabled' => false]);
		$this->destinations->expects($this->once())->method('isValid')->with('dest-1')->willReturn(true);

		$job = $this->service->enableJob(id: 'j1');

		$this->assertTrue($job['enabled']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T00:00:00[+-]\d{2}:\d{2}$/', $job['nextRunAt']);
		$this->assertSame('j1', $this->saved[0]['uuid']);
	}//end testEnableJob()

	/**
	 * An invalid destination keeps the job disabled and unsaved.
	 *
	 * @return void
	 */
	public function testEnableJobRefusesInvalidDestination(): void {
		$this->stored(job: self::VALID);
		$this->destinations->method('isValid')->willReturn(false);
		$this->objects->expects($this->never())->method('saveObject');

		$this->expectException(OCSBadRequestException::class);
		$this->expectExceptionMessage('Destination is invalid. Test connection first.');
		$this->service->enableJob(id: 'j1');
	}//end testEnableJobRefusesInvalidDestination()

	/**
	 * Disabling clears the schedule.
	 *
	 * @return void
	 */
	public function testDisableJob(): void {
		$this->stored(job: self::VALID + ['enabled' => true, 'nextRunAt' => '2026-01-01T00:00:00+00:00']);

		$job = $this->service->disableJob(id: 'j1');

		$this->assertFalse($job['enabled']);
		$this->assertNull($job['nextRunAt']);
	}//end testDisableJob()

	/**
	 * Recording a run copies its summary onto the job and reschedules an enabled job.
	 *
	 * @return void
	 */
	public function testRecordRun(): void {
		$this->stored(job: self::VALID + ['enabled' => true]);

		$job = $this->service->recordRun(
			jobId: 'j1',
			run: ['status' => 'succeeded', 'endedAt' => '2026-03-01T02:00:00+00:00', 'rowCount' => 5, 'fileCount' => 1, 'fileCountExpected' => 1, 'errorMessage' => '']
		);

		$this->assertSame('succeeded', $job['lastRunStatus']);
		$this->assertSame('2026-03-01T02:00:00+00:00', $job['lastRunAt']);
		$this->assertSame(5, $job['lastRunRows']);
		$this->assertNull($job['lastRunError']);
		$this->assertNotNull($job['nextRunAt']);
	}//end testRecordRun()

	/**
	 * A disabled job records the run but stays unscheduled.
	 *
	 * @return void
	 */
	public function testRecordRunOnDisabledJob(): void {
		$this->stored(job: self::VALID + ['enabled' => false]);

		$job = $this->service->recordRun(jobId: 'j1', run: ['status' => 'failed', 'errorMessage' => 'boom']);

		$this->assertSame('failed', $job['lastRunStatus']);
		$this->assertSame('boom', $job['lastRunError']);
		$this->assertNull($job['nextRunAt']);
	}//end testRecordRunOnDisabledJob()

	/**
	 * A job without schemas cannot be test-run and extracts nothing.
	 *
	 * @return void
	 */
	public function testTestRunWithoutSchemas(): void {
		$this->data->expects($this->never())->method('extractSchema');

		$this->assertSame(
			['success' => false, 'sample_rows' => 0, 'errors' => ['No source schemas selected.'], 'samples' => []],
			$this->service->testRun(job: ['sourceSchemas' => []])
		);
	}//end testTestRunWithoutSchemas()

	/**
	 * Each schema is extracted with the row cap; failures are collected, long previews truncated.
	 *
	 * @return void
	 */
	public function testTestRunCollectsSamplesAndErrors(): void {
		$long = str_repeat('a', 501);
		$this->data->expects($this->exactly(3))->method('extractSchema')->willReturnCallback(
			static function (array $job, string $schemaSlug, ?string $watermarkFrom = null, ?int $rowCap = null) use ($long): array {
				if ($watermarkFrom !== null || $rowCap !== ExportDataService::TEST_RUN_ROW_CAP) {
					throw new \LogicException('unexpected arguments');
				}

				return match ($schemaSlug) {
					'lead' => ['rows' => 2, 'dropped_columns' => ['secret'], 'contents' => "a,b\n1,2"],
					'client' => ['rows' => '3', 'dropped_columns' => [], 'contents' => $long],
					default => throw new \RuntimeException("Schema '{$schemaSlug}' failed."),
				};
			}
		);

		$result = $this->service->testRun(job: ['sourceSchemas' => ['lead', 'client', 'ghost'], 'format' => 'jsonl']);

		$this->assertFalse($result['success']);
		$this->assertSame(5, $result['sample_rows']);
		$this->assertSame(["Schema 'ghost' failed."], $result['errors']);
		$this->assertSame(
			['schema' => 'lead', 'rows' => 2, 'format' => 'jsonl', 'dropped_columns' => ['secret'], 'preview' => "a,b\n1,2"],
			$result['samples'][0]
		);
		$this->assertSame(str_repeat('a', 500) . '…', $result['samples'][1]['preview']);
		$this->assertSame(3, $result['samples'][1]['rows']);
	}//end testTestRunCollectsSamplesAndErrors()

	/**
	 * A clean test run succeeds and defaults the format to csv.
	 *
	 * @return void
	 */
	public function testTestRunSucceeds(): void {
		$this->data->method('extractSchema')->willReturn(['rows' => 1, 'dropped_columns' => [], 'contents' => str_repeat('b', 500)]);

		$result = $this->service->testRun(job: ['sourceSchemas' => ['lead']]);

		$this->assertTrue($result['success']);
		$this->assertSame([], $result['errors']);
		$this->assertSame('csv', $result['samples'][0]['format']);
		$this->assertSame(str_repeat('b', 500), $result['samples'][0]['preview']);
	}//end testTestRunSucceeds()

	/**
	 * Listing reads the job schema; describing delegates to the cron helper.
	 *
	 * @return void
	 */
	public function testListJobsAndDescribeSchedule(): void {
		$this->objects->expects($this->once())->method('findAll')
			->with(['filters' => ['register' => 'reg', 'schema' => 'jobs']])
			->willReturn([['id' => 'j1']]);

		$this->assertSame([['id' => 'j1']], $this->service->listJobs());
		$this->assertSame((new CronExpressionHelper())->describe(expression: '0 2 * * *', locale: 'nl'), $this->service->describeSchedule(expression: '0 2 * * *', locale: 'nl'));
		$this->assertSame('', $this->service->describeSchedule(expression: 'nope'));
	}//end testListJobsAndDescribeSchedule()

	/**
	 * Make the store return this job for id j1.
	 *
	 * @param array<string, mixed> $job The stored job.
	 *
	 * @return void
	 */
	private function stored(array $job): void {
		$this->objects->method('find')->willReturnCallback(
			fn (string $id, ?array $extend = [], bool $files = false, ?string $register = null, ?string $schema = null): ?ObjectEntityInterface => ($id === 'j1' && $schema === 'jobs') ? $this->entity(payload: $job + ['id' => 'j1']) : null
		);
	}//end stored()

	/**
	 * A stored object whose serialised form is the payload.
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
}//end class
