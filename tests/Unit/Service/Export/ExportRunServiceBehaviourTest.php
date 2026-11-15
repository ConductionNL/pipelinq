<?php

/**
 * ExportRunService: the run lifecycle and its schema snapshots.
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

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Pipelinq\Service\Export\ExportRunService;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Each lifecycle step reads the run, changes only its own fields and writes
 * it back under the same id; snapshots are found per job and schema.
 *
 * @covers \OCA\Pipelinq\Service\Export\ExportRunService
 * @covers \OCA\Pipelinq\Service\Export\AbstractExportService
 * @uses   \OCA\Pipelinq\Util\CastingPayloadToArray
 * @uses   \OCA\Pipelinq\Util\EntityPayload
 * @uses   \OCA\Pipelinq\Util\SerialisedPayload
 */
class ExportRunServiceBehaviourTest extends TestCase {

	private const ATOM = '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d$/';

	/**
	 * Stored objects per schema, keyed by uuid.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $rows = [];

	/**
	 * Saves seen, as [register, schema, uuid, object].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $saves = [];

	/**
	 * App-config values.
	 *
	 * @var array<string, string>
	 */
	private array $config = ['register' => 'reg', 'exportRun_schema' => 'runs', 'exportSchemaSnapshot_schema' => 'snaps'];

	/**
	 * Object service mock.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * Build the array-backed object service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->objects->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null): ?ObjectEntity {
				$row = ($this->rows[$schema][$id] ?? null);
				return ($row === null ? null : self::entity((string)$id, $row));
			}
		);
		$this->objects->method('findAll')->willReturnCallback(
			function (array $config = []): array {
				$filters = $config['filters'];
				$out = [];
				foreach (($this->rows[$filters['schema']] ?? []) as $uuid => $row) {
					$keep = true;
					foreach (array_diff_key($filters, ['register' => 1, 'schema' => 1]) as $k => $v) {
						$keep = ($keep && (string)($row[$k] ?? '') === (string)$v);
					}

					if ($keep === true) {
						$out[] = self::entity($uuid, $row);
					}
				}

				return $out;
			}
		);
		$this->objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null): ObjectEntity {
				$this->saves[] = [$register, $schema, $uuid, $object];
				$uuid = ($uuid ?? 'new-' . count($this->saves));
				$this->rows[$schema][$uuid] = $object;
				return self::entity($uuid, $object);
			}
		);
	}//end setUp()

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
	 * The service under test.
	 *
	 * @return ExportRunService
	 */
	private function service(): ExportRunService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);

		return new ExportRunService($this->createMock(ContainerInterface::class), $appConfig, $this->objects);
	}//end service()

	/**
	 * A pending run names its job, mode and expected file count; a job
	 * without a mode or a usable schema list is a full run of no files.
	 *
	 * @return void
	 */
	public function testAPendingRunDescribesItsJob(): void {
		$service = $this->service();

		$run = $service->createPendingRun(job: ['uuid' => 'job-1', 'mode' => 'incremental', 'sourceSchemas' => ['lead', 'client']], watermarkFrom: '2026-01-01');
		$this->assertSame('new-1', $run['id']);
		$this->assertSame(
			['reg', 'runs', null, ['jobId' => 'job-1', 'status' => 'pending', 'modeUsed' => 'incremental', 'watermarkFrom' => '2026-01-01', 'fileManifestJson' => [], 'fileCountExpected' => 2]],
			$this->saves[0]
		);

		$service->createPendingRun(job: ['id' => 'job-2', 'uuid' => 'ignored', 'sourceSchemas' => 'lead']);
		$this->assertSame(
			['jobId' => 'job-2', 'status' => 'pending', 'modeUsed' => 'full', 'watermarkFrom' => null, 'fileManifestJson' => [], 'fileCountExpected' => 0],
			$this->saves[1][3]
		);
	}//end testAPendingRunDescribesItsJob()

	/**
	 * An unknown run, and a lookup that throws, are not found.
	 *
	 * @return void
	 */
	public function testAMissingRunIsNotFound(): void {
		$this->assertNotFound(fn () => $this->service()->getRun(runId: 'gone'));

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willThrowException(new RuntimeException('db'));
		$this->objects = $objects;
		$this->assertNotFound(fn () => $this->service()->getRun(runId: 'r-1'));
	}//end testAMissingRunIsNotFound()

	/**
	 * Without a register or run schema nothing is read.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredRegisterIsNotFound(): void {
		$this->config = ['register' => 'reg'];
		$this->objects->expects($this->never())->method('find');

		try {
			$this->service()->getRun(runId: 'r-1');
			$this->fail('expected not found');
		} catch (OCSNotFoundException $e) {
			$this->assertSame('Export register of schema is niet geconfigureerd.', $e->getMessage());
		}
	}//end testAnUnconfiguredRegisterIsNotFound()

	/**
	 * Assert the call throws "Export run not found.".
	 *
	 * @param callable $call The call.
	 *
	 * @return void
	 */
	private function assertNotFound(callable $call): void {
		try {
			$call();
			$this->fail('expected not found');
		} catch (OCSNotFoundException $e) {
			$this->assertSame('Export run not found.', $e->getMessage());
		}
	}//end assertNotFound()

	/**
	 * Running and skipping stamp their own fields and keep the rest; the
	 * read-back @self is not written.
	 *
	 * @return void
	 */
	public function testRunningAndSkippingStampTheRun(): void {
		$this->rows['runs']['r-1'] = ['jobId' => 'job-1', 'status' => 'pending'];
		$service = $this->service();

		$running = $service->markRunning(runId: 'r-1');
		$this->assertSame('running', $running['status']);
		$this->assertSame(['reg', 'runs', 'r-1'], array_slice($this->saves[0], 0, 3));
		$this->assertArrayNotHasKey('@self', $this->saves[0][3]);
		$this->assertSame('job-1', $this->saves[0][3]['jobId']);
		$this->assertMatchesRegularExpression(self::ATOM, $this->saves[0][3]['startedAt']);

		$skipped = $service->markSkippedOverlap(runId: 'r-1', reason: 'previous run still active');
		$this->assertSame('skipped_overlap', $skipped['status']);
		$this->assertSame('previous run still active', $skipped['errorMessage']);
		$this->assertMatchesRegularExpression(self::ATOM, $skipped['endedAt']);
		$this->assertSame($running['startedAt'], $skipped['startedAt']);
	}//end testRunningAndSkippingStampTheRun()

	/**
	 * Completing copies the outcome's counts and manifest, and the optional
	 * fields only when the outcome carries them.
	 *
	 * @return void
	 */
	public function testCompletingCopiesTheOutcome(): void {
		$this->rows['runs']['r-1'] = ['status' => 'running', 'watermarkTo' => 'old', 'errorMessage' => 'old'];
		$service = $this->service();

		$done = $service->completeRun(
			runId: 'r-1',
			outcome: ['status' => 'succeeded', 'rowCount' => '12', 'byteCount' => 300, 'fileCount' => 2, 'manifest' => ['a.csv'], 'watermarkTo' => 'w2', 'destinationAck' => ['ok' => true], 'errorMessage' => null]
		);
		$this->assertSame(
			['succeeded', 12, 300, 2, ['a.csv'], 'w2', ['ok' => true], null],
			[$done['status'], $done['rowCount'], $done['byteCount'], $done['fileCount'], $done['fileManifestJson'], $done['watermarkTo'], $done['destinationAck'], $done['errorMessage']]
		);

		$this->rows['runs']['r-2'] = ['status' => 'running', 'watermarkTo' => 'old', 'errorMessage' => 'kept'];
		$failed = $service->completeRun(runId: 'r-2', outcome: []);
		$this->assertSame(['failed', 0, 0, 0, [], 'old', 'kept'], [$failed['status'], $failed['rowCount'], $failed['byteCount'], $failed['fileCount'], $failed['fileManifestJson'], $failed['watermarkTo'], $failed['errorMessage']]);
		$this->assertArrayNotHasKey('destinationAck', $failed);
	}//end testCompletingCopiesTheOutcome()

	/**
	 * A snapshot is stored under the snapshot schema and listed per run.
	 *
	 * @return void
	 */
	public function testSnapshotsAreStoredAndListedPerRun(): void {
		$service = $this->service();

		$service->createSnapshot(runId: 'r-1', schemaName: 'lead', columnDefinitions: [['name' => 'id']]);
		$service->createSnapshot(runId: 'r-2', schemaName: 'lead', columnDefinitions: [], comparedToPrevious: ['added' => ['x']]);

		$this->assertSame(
			['reg', 'snaps', null, ['runId' => 'r-1', 'pipelinqSchemaName' => 'lead', 'columnDefinitionsJson' => [['name' => 'id']], 'comparedToPrevious' => []]],
			$this->saves[0]
		);
		$this->assertSame(['new-2'], array_column($service->listSnapshots(runId: 'r-2'), 'id'));
		$this->assertSame([], $service->listSnapshots(runId: 'r-3'));
	}//end testSnapshotsAreStoredAndListedPerRun()

	/**
	 * The previous snapshot is the newest run's snapshot of that schema.
	 *
	 * @return void
	 */
	public function testThePreviousSnapshotIsTheNewestRunsOfThatSchema(): void {
		$this->rows['runs'] = [
			'r-old' => ['jobId' => 'job-1', 'startedAt' => '2026-01-01T00:00:00Z'],
			'r-new' => ['jobId' => 'job-1', 'startedAt' => '2026-02-01T00:00:00Z'],
			'r-other' => ['jobId' => 'job-2', 'startedAt' => '2026-03-01T00:00:00Z'],
		];
		$this->rows['snaps'] = [
			's-1' => ['runId' => 'r-old', 'pipelinqSchemaName' => 'lead'],
			's-2' => ['runId' => 'r-new', 'pipelinqSchemaName' => 'client'],
			's-3' => ['runId' => 'r-new', 'pipelinqSchemaName' => 'lead'],
			's-4' => ['runId' => 'r-other', 'pipelinqSchemaName' => 'lead'],
		];
		$service = $this->service();

		$this->assertSame('s-3', $service->findPreviousSnapshot(jobId: 'job-1', schemaName: 'lead')['id']);
		$this->assertSame('s-2', $service->findPreviousSnapshot(jobId: 'job-1', schemaName: 'client')['id']);
		$this->assertNull($service->findPreviousSnapshot(jobId: 'job-1', schemaName: 'contact'));
	}//end testThePreviousSnapshotIsTheNewestRunsOfThatSchema()

	/**
	 * A run read back without any id is skipped when looking for snapshots.
	 *
	 * @return void
	 */
	public function testARunWithoutAnIdIsSkipped(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			static fn (array $config = []): array => ($config['filters']['schema'] === 'runs' ? [['jobId' => 'job-1']] : [['pipelinqSchemaName' => 'lead']])
		);
		$this->objects = $objects;

		$this->assertNull($this->service()->findPreviousSnapshot(jobId: 'job-1', schemaName: 'lead'));
	}//end testARunWithoutAnIdIsSkipped()

	/**
	 * The last succeeded run is the newest succeeded run of that job; a
	 * failing read counts as none.
	 *
	 * @return void
	 */
	public function testTheLastSucceededRun(): void {
		$this->rows['runs'] = [
			'r-1' => ['jobId' => 'job-1', 'status' => 'succeeded', 'startedAt' => '2026-01-01'],
			'r-2' => ['jobId' => 'job-1', 'status' => 'failed', 'startedAt' => '2026-03-01'],
			'r-3' => ['jobId' => 'job-1', 'status' => 'succeeded', 'startedAt' => '2026-02-01'],
		];
		$service = $this->service();

		$this->assertSame('r-3', $service->lastSucceededRun(jobId: 'job-1')['id']);
		$this->assertNull($service->lastSucceededRun(jobId: 'job-9'));

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willThrowException(new RuntimeException('db'));
		$this->objects = $objects;
		$this->assertNull($this->service()->lastSucceededRun(jobId: 'job-1'));
	}//end testTheLastSucceededRun()
}//end class
