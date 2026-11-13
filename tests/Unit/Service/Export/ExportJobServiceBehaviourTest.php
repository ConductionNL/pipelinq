<?php

/**
 * ExportJobService: the job lifecycle and the test run.
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

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
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
use RuntimeException;

/**
 * Creating and updating always leave a job disabled; enabling needs a valid
 * destination; a test run reports per schema.
 *
 * @covers \OCA\Pipelinq\Service\Export\ExportJobService
 * @covers \OCA\Pipelinq\Service\Export\AbstractExportService
 * @uses   \OCA\Pipelinq\Util\CastingPayloadToArray
 * @uses   \OCA\Pipelinq\Util\EntityPayload
 * @uses   \OCA\Pipelinq\Util\SerialisedPayload
 */
class ExportJobServiceBehaviourTest extends TestCase {

	/**
	 * Stored jobs, keyed by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $jobs = [];

	/**
	 * Writes seen, as [verb, register, schema, uuid, object|null].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $writes = [];

	/**
	 * Destination service mock.
	 *
	 * @var ExportDestinationService&MockObject
	 */
	private ExportDestinationService&MockObject $destinations;

	/**
	 * Data service mock.
	 *
	 * @var ExportDataService&MockObject
	 */
	private ExportDataService&MockObject $data;

	/**
	 * Cron helper mock.
	 *
	 * @var CronExpressionHelper&MockObject
	 */
	private CronExpressionHelper&MockObject $cron;

	/**
	 * Run summary mock.
	 *
	 * @var ExportRunSummary&MockObject
	 */
	private ExportRunSummary&MockObject $summary;

	/**
	 * Build the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->destinations = $this->createMock(ExportDestinationService::class);
		$this->data = $this->createMock(ExportDataService::class);
		$this->data->method('schemaExists')->willReturn(true);
		$this->cron = $this->createMock(CronExpressionHelper::class);
		$this->cron->method('isValid')->willReturn(true);
		$this->cron->method('normalize')->willReturnCallback(static fn (string $e): string => 'norm:' . $e);
		$this->summary = $this->createMock(ExportRunSummary::class);
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
		$objects->method('findAll')->willReturnCallback(
			function (array $config = []): array {
				$this->writes[] = ['findAll', $config['filters']['register'], $config['filters']['schema'], null, null];
				return array_map(static fn (string $id, array $row): ObjectEntity => self::entity($id, $row), array_keys($this->jobs), $this->jobs);
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null): ObjectEntity {
				$this->writes[] = ['save', $register, $schema, $uuid, $object];
				$uuid = ($uuid ?? 'job-new');
				$this->jobs[$uuid] = $object;
				return self::entity($uuid, $object);
			}
		);
		$objects->method('deleteObject')->willReturnCallback(
			function (string $uuid, $register = null, $schema = null): bool {
				$this->writes[] = ['delete', $register, $schema, $uuid, null];
				unset($this->jobs[$uuid]);
				return true;
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => (['register' => 'reg', 'exportJob_schema' => 'jobs'][$key] ?? $default)
		);

		return new ExportJobService(
			$this->createMock(ContainerInterface::class),
			$appConfig,
			$objects,
			$this->destinations,
			$this->data,
			$this->cron,
			$this->createMock(LoggerInterface::class),
			$this->summary,
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
	 * A valid job payload.
	 *
	 * @return array<string, mixed>
	 */
	private static function valid(): array {
		return ['name' => 'Nightly', 'sourceSchemas' => ['lead'], 'format' => 'csv', 'mode' => 'full', 'scheduleCron' => '0 2 * * *', 'destinationId' => 'd-1'];
	}//end valid()

	/**
	 * Listing reads every job from the job schema.
	 *
	 * @return void
	 */
	public function testListingReadsTheJobSchema(): void {
		$this->jobs = ['j-1' => ['name' => 'A'], 'j-2' => ['name' => 'B']];

		$this->assertSame(['j-1', 'j-2'], array_column($this->service()->listJobs(), 'id'));
		$this->assertSame(['findAll', 'reg', 'jobs', null, null], $this->writes[0]);
	}//end testListingReadsTheJobSchema()

	/**
	 * A new job is stored disabled, with its creator, a creation time, a
	 * normalised schedule and no next run; ids in the payload are dropped.
	 *
	 * @return void
	 */
	public function testANewJobIsStoredDisabled(): void {
		$job = $this->service()->createJob(data: (self::valid() + ['enabled' => true, 'id' => 'x', 'uuid' => 'y', '@self' => []]), userId: 'ann');

		$saved = $this->writes[0];
		$this->assertSame(['save', 'reg', 'jobs', null], array_slice($saved, 0, 4));
		$this->assertFalse($saved[4]['enabled']);
		$this->assertSame('ann', $saved[4]['createdBy']);
		$this->assertSame('norm:0 2 * * *', $saved[4]['scheduleCron']);
		$this->assertNull($saved[4]['nextRunAt']);
		$this->assertArrayNotHasKey('uuid', $saved[4]);
		$this->assertArrayNotHasKey('@self', $saved[4]);
		$this->assertNotFalse(DateTimeImmutable::createFromFormat(DATE_ATOM, $saved[4]['createdAt']));
		$this->assertSame('job-new', $job['id']);
	}//end testANewJobIsStoredDisabled()

	/**
	 * An invalid job is refused before anything is written.
	 *
	 * @return void
	 */
	public function testAnInvalidJobIsNotStored(): void {
		$this->expectException(OCSBadRequestException::class);
		$this->expectExceptionMessage('Job name is required.');

		try {
			$this->service()->createJob(data: ['name' => ' '] + self::valid(), userId: 'ann');
		} finally {
			$this->assertSame([], $this->writes);
		}
	}//end testAnInvalidJobIsNotStored()

	/**
	 * An update merges onto the stored job and disables it again.
	 *
	 * @return void
	 */
	public function testAnUpdateMergesAndDisables(): void {
		$this->jobs['j-1'] = (self::valid() + ['enabled' => true, 'nextRunAt' => '2030-01-01', 'createdBy' => 'ann']);

		$this->service()->updateJob(id: 'j-1', data: ['name' => 'Weekly', 'id' => 'other']);

		$saved = $this->writes[0];
		$this->assertSame('j-1', $saved[3]);
		$this->assertSame(['Weekly', false, null, 'ann', 'norm:0 2 * * *'], [$saved[4]['name'], $saved[4]['enabled'], $saved[4]['nextRunAt'], $saved[4]['createdBy'], $saved[4]['scheduleCron']]);
		$this->assertSame('j-1', $saved[4]['id']);
	}//end testAnUpdateMergesAndDisables()

	/**
	 * Unknown jobs are not found for update, delete, enable, disable or record.
	 *
	 * @return void
	 */
	public function testUnknownJobsAreNotFound(): void {
		$service = $this->service();
		$calls = [
			fn () => $service->updateJob(id: 'gone', data: []),
			fn () => $service->deleteJob(id: 'gone'),
			fn () => $service->enableJob(id: 'gone'),
			fn () => $service->disableJob(id: 'gone'),
			fn () => $service->recordRun(jobId: 'gone', run: []),
		];
		foreach ($calls as $call) {
			try {
				$call();
				$this->fail('expected not found');
			} catch (OCSNotFoundException $e) {
				$this->assertSame('Export job not found.', $e->getMessage());
			}
		}

		$this->assertSame([], $this->writes);
	}//end testUnknownJobsAreNotFound()

	/**
	 * Deleting removes the job from the job schema.
	 *
	 * @return void
	 */
	public function testDeletingRemovesTheJob(): void {
		$this->jobs['j-1'] = self::valid();

		$this->service()->deleteJob(id: 'j-1');

		$this->assertSame([['delete', 'reg', 'jobs', 'j-1', null]], $this->writes);
	}//end testDeletingRemovesTheJob()

	/**
	 * Enabling refuses an invalid destination and otherwise schedules the job.
	 *
	 * @return void
	 */
	public function testEnablingNeedsAValidDestination(): void {
		$this->jobs['j-1'] = self::valid();
		$this->jobs['j-2'] = ['destinationId' => 'd-bad'];
		$this->destinations->method('isValid')->willReturnCallback(static fn (string $id): bool => $id === 'd-1');
		$this->summary->expects($this->once())->method('nextRunAt')
			->with($this->callback(static fn (array $job): bool => $job['enabled'] === true && $job['destinationId'] === 'd-1'))
			->willReturn('2030-01-02T02:00:00+00:00');
		$service = $this->service();

		$enabled = $service->enableJob(id: 'j-1');
		$this->assertTrue($enabled['enabled']);
		$this->assertSame('2030-01-02T02:00:00+00:00', $enabled['nextRunAt']);

		$this->expectException(OCSBadRequestException::class);
		$this->expectExceptionMessage('Destination is invalid. Test connection first.');
		$service->enableJob(id: 'j-2');
	}//end testEnablingNeedsAValidDestination()

	/**
	 * Disabling clears the next run.
	 *
	 * @return void
	 */
	public function testDisablingClearsTheNextRun(): void {
		$this->jobs['j-1'] = (self::valid() + ['enabled' => true, 'nextRunAt' => '2030-01-01']);

		$job = $this->service()->disableJob(id: 'j-1');

		$this->assertSame([false, null], [$job['enabled'], $job['nextRunAt']]);
		$this->assertSame('j-1', $this->writes[0][3]);
	}//end testDisablingClearsTheNextRun()

	/**
	 * Recording a run merges its summary into the job and reschedules it.
	 *
	 * @return void
	 */
	public function testRecordingARunMergesItsSummary(): void {
		$this->jobs['j-1'] = (self::valid() + ['lastRunStatus' => 'failed']);
		$run = ['status' => 'succeeded'];
		$this->summary->expects($this->once())->method('lastRunSummary')->with($run)->willReturn(['lastRunStatus' => 'succeeded', 'lastRunAt' => 'now']);
		$this->summary->method('nextRunAt')->willReturnCallback(
			static fn (array $job): ?string => ($job['lastRunStatus'] === 'succeeded' ? 'next' : null)
		);

		$job = $this->service()->recordRun(jobId: 'j-1', run: $run);

		$this->assertSame(['succeeded', 'now', 'next'], [$job['lastRunStatus'], $job['lastRunAt'], $job['nextRunAt']]);
	}//end testRecordingARunMergesItsSummary()

	/**
	 * A test run without schemas fails at once.
	 *
	 * @return void
	 */
	public function testATestRunNeedsSchemas(): void {
		$this->data->expects($this->never())->method('extractSchema');
		$expected = ['success' => false, 'sample_rows' => 0, 'errors' => ['No source schemas selected.'], 'samples' => []];

		$this->assertSame($expected, $this->service()->testRun(job: []));
		$this->assertSame($expected, $this->service()->testRun(job: ['sourceSchemas' => 'lead']));
	}//end testATestRunNeedsSchemas()

	/**
	 * A test run extracts each schema capped, previews the first 500 bytes,
	 * sums the rows and collects each schema's error.
	 *
	 * @return void
	 */
	public function testATestRunReportsPerSchema(): void {
		$long = str_repeat('x', 501);
		$this->data->method('extractSchema')->willReturnCallback(
			function (array $job, string $schemaSlug, ?string $watermarkFrom = null, ?int $rowCap = null) use ($long): array {
				$this->assertNull($watermarkFrom);
				$this->assertSame(ExportDataService::TEST_RUN_ROW_CAP, $rowCap);
				return match ($schemaSlug) {
					'lead' => ['rows' => '3', 'dropped_columns' => ['secret'], 'contents' => 'id' . "\n" . '1'],
					'client' => ['rows' => 2, 'dropped_columns' => [], 'contents' => $long],
					default => throw new RuntimeException("Schema '$schemaSlug' is not readable."),
				};
			}
		);

		$report = $this->service()->testRun(job: ['sourceSchemas' => ['lead', 'ghost', 'client'], 'format' => 'jsonl']);

		$this->assertFalse($report['success']);
		$this->assertSame(5, $report['sample_rows']);
		$this->assertSame(["Schema 'ghost' is not readable."], $report['errors']);
		$this->assertSame(['schema' => 'lead', 'rows' => 3, 'format' => 'jsonl', 'dropped_columns' => ['secret'], 'preview' => "id\n1"], $report['samples'][0]);
		$this->assertSame(str_repeat('x', 500) . '…', $report['samples'][1]['preview']);
		$this->assertSame('csv', $this->service()->testRun(job: ['sourceSchemas' => ['lead']])['samples'][0]['format']);
		$this->assertTrue($this->service()->testRun(job: ['sourceSchemas' => ['lead']])['success']);
	}//end testATestRunReportsPerSchema()

	/**
	 * Describing a schedule is the cron helper's description in that locale.
	 *
	 * @return void
	 */
	public function testDescribingASchedule(): void {
		$this->cron->expects($this->once())->method('describe')->with('0 2 * * *', 'nl')->willReturn('Elke dag om 02:00');

		$this->assertSame('Elke dag om 02:00', $this->service()->describeSchedule(expression: '0 2 * * *', locale: 'nl'));
	}//end testDescribingASchedule()
}//end class
