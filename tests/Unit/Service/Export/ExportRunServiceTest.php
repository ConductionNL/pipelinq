<?php

/**
 * ExportRunService: run lifecycle, snapshots and lookups.
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
use OCA\Pipelinq\Service\Export\ExportRunService;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Every state transition writes the run it read back under the run schema,
 * with the fields that transition owns; snapshots live under their own schema.
 *
 * @covers \OCA\Pipelinq\Service\Export\ExportRunService
 * @uses   \OCA\Pipelinq\Service\Export\AbstractExportService
 * @uses   \OCA\Pipelinq\Util\CastingPayloadToArray
 * @uses   \OCA\Pipelinq\Util\EntityPayload
 * @uses   \OCA\Pipelinq\Util\SerialisedPayload
 */
class ExportRunServiceTest extends TestCase {

	/**
	 * The object store.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * The service under test.
	 *
	 * @var ExportRunService
	 */
	private ExportRunService $service;

	/**
	 * The arguments of every saveObject call.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * Build the service over a configured register and both schemas.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], ?string $register = null, ?string $schema = null, ?string $uuid = null): ObjectEntityInterface {
				$this->saved[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid];
				return $this->entity(payload: $object + ['id' => ($uuid ?? 'new-id')]);
			}
		);

		$config = ['register' => 'reg', 'exportRun_schema' => 'runs', 'exportSchemaSnapshot_schema' => 'snaps'];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		$this->service = new ExportRunService($this->createMock(ContainerInterface::class), $appConfig, $this->objects);
	}//end setUp()

	/**
	 * A pending run takes the job id, mode and one expected file per dataset.
	 *
	 * @return void
	 */
	public function testCreatePendingRunDescribesTheJob(): void {
		$run = $this->service->createPendingRun(job: ['uuid' => 'job-7', 'mode' => 'incremental', 'sourceSchemas' => ['a', 'b']], watermarkFrom: '2026-01-01');

		$this->assertSame('new-id', $run['id']);
		$this->assertSame(
			[
				'object' => [
					'jobId' => 'job-7',
					'status' => 'pending',
					'modeUsed' => 'incremental',
					'watermarkFrom' => '2026-01-01',
					'fileManifestJson' => [],
					'fileCountExpected' => 2,
				],
				'register' => 'reg',
				'schema' => 'runs',
				'uuid' => null,
			],
			$this->saved[0]
		);
	}//end testCreatePendingRunDescribesTheJob()

	/**
	 * A job without datasets, mode or id still yields a well-formed full run.
	 *
	 * @return void
	 */
	public function testCreatePendingRunDefaults(): void {
		$this->service->createPendingRun(job: ['sourceSchemas' => 'lead']);

		$object = $this->saved[0]['object'];
		$this->assertSame('', $object['jobId']);
		$this->assertSame('full', $object['modeUsed']);
		$this->assertNull($object['watermarkFrom']);
		$this->assertSame(0, $object['fileCountExpected']);
	}//end testCreatePendingRunDefaults()

	/**
	 * A run is read from the run schema; a missing one and a store failure are both not found.
	 *
	 * @return void
	 */
	public function testGetRun(): void {
		$this->objects->expects($this->exactly(3))->method('find')->willReturnCallback(
			function (string $id, ?array $extend = [], bool $files = false, ?string $register = null, ?string $schema = null): ?ObjectEntityInterface {
				if ($register !== 'reg' || $schema !== 'runs') {
					return null;
				}

				if ($id === 'boom') {
					throw new \RuntimeException('down');
				}

				return ($id === 'r1') ? $this->entity(payload: ['id' => 'r1', 'status' => 'pending']) : null;
			}
		);

		$this->assertSame(['id' => 'r1', 'status' => 'pending'], $this->service->getRun(runId: 'r1'));
		foreach (['missing', 'boom'] as $id) {
			try {
				$this->service->getRun(runId: $id);
				$this->fail('Expected not found for ' . $id);
			} catch (OCSNotFoundException $e) {
				$this->assertSame('Export run not found.', $e->getMessage());
			}
		}
	}//end testGetRun()

	/**
	 * An unconfigured run schema is refused before the store is asked.
	 *
	 * @return void
	 */
	public function testUnconfiguredSchemaIsRefused(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$this->objects->expects($this->never())->method('find');
		$service = new ExportRunService($this->createMock(ContainerInterface::class), $appConfig, $this->objects);

		$this->expectException(OCSNotFoundException::class);
		$this->expectExceptionMessage('Export register of schema is niet geconfigureerd.');
		$service->getRun(runId: 'r1');
	}//end testUnconfiguredSchemaIsRefused()

	/**
	 * Running stamps a start; skipping stamps an end and the reason; both save under the run's id.
	 *
	 * @return void
	 */
	public function testMarkRunningAndSkipped(): void {
		$this->objects->method('find')->willReturn($this->entity(payload: ['id' => 'r1', 'status' => 'pending', '@self' => ['x' => 1]]));

		$running = $this->service->markRunning(runId: 'r1');
		$this->assertSame('running', $running['status']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $running['startedAt']);
		$this->assertArrayNotHasKey('@self', $this->saved[0]['object']);
		$this->assertSame('r1', $this->saved[0]['uuid']);
		$this->assertSame('runs', $this->saved[0]['schema']);

		$skipped = $this->service->markSkippedOverlap(runId: 'r1', reason: 'still busy');
		$this->assertSame('skipped_overlap', $skipped['status']);
		$this->assertSame('still busy', $skipped['errorMessage']);
		$this->assertArrayHasKey('endedAt', $skipped);
		$this->assertArrayNotHasKey('startedAt', $skipped);
		$this->assertSame('r1', $this->saved[1]['uuid']);
	}//end testMarkRunningAndSkipped()

	/**
	 * A full outcome is copied onto the run, with counts cast to integers.
	 *
	 * @return void
	 */
	public function testCompleteRunWithFullOutcome(): void {
		$this->objects->method('find')->willReturn($this->entity(payload: ['id' => 'r1', 'status' => 'running']));

		$run = $this->service->completeRun(
			runId: 'r1',
			outcome: [
				'status' => 'succeeded',
				'rowCount' => '12',
				'byteCount' => 340,
				'fileCount' => 2,
				'manifest' => [['file' => 'a.csv']],
				'watermarkTo' => '2026-02-01',
				'destinationAck' => ['etag' => 'x'],
				'errorMessage' => null,
			]
		);

		$this->assertSame('succeeded', $run['status']);
		$this->assertSame(12, $run['rowCount']);
		$this->assertSame(340, $run['byteCount']);
		$this->assertSame(2, $run['fileCount']);
		$this->assertSame([['file' => 'a.csv']], $run['fileManifestJson']);
		$this->assertSame('2026-02-01', $run['watermarkTo']);
		$this->assertSame(['etag' => 'x'], $run['destinationAck']);
		$this->assertArrayHasKey('errorMessage', $run);
		$this->assertNull($run['errorMessage']);
		$this->assertArrayHasKey('endedAt', $run);
	}//end testCompleteRunWithFullOutcome()

	/**
	 * An empty outcome fails the run with zero counts and adds no optional fields.
	 *
	 * @return void
	 */
	public function testCompleteRunWithEmptyOutcomeFails(): void {
		$this->objects->method('find')->willReturn($this->entity(payload: ['id' => 'r1']));

		$run = $this->service->completeRun(runId: 'r1', outcome: []);

		$this->assertSame('failed', $run['status']);
		$this->assertSame([0, 0, 0], [$run['rowCount'], $run['byteCount'], $run['fileCount']]);
		$this->assertSame([], $run['fileManifestJson']);
		$this->assertArrayNotHasKey('watermarkTo', $run);
		$this->assertArrayNotHasKey('destinationAck', $run);
		$this->assertArrayNotHasKey('errorMessage', $run);
	}//end testCompleteRunWithEmptyOutcomeFails()

	/**
	 * A snapshot is saved new under the snapshot schema; listing filters by run.
	 *
	 * @return void
	 */
	public function testSnapshots(): void {
		$this->service->createSnapshot(runId: 'r1', schemaName: 'lead', columnDefinitions: ['a' => 'string']);
		$this->assertSame(
			[
				'object' => ['runId' => 'r1', 'pipelinqSchemaName' => 'lead', 'columnDefinitionsJson' => ['a' => 'string'], 'comparedToPrevious' => []],
				'register' => 'reg',
				'schema' => 'snaps',
				'uuid' => null,
			],
			$this->saved[0]
		);

		$this->objects->expects($this->once())->method('findAll')
			->with(['filters' => ['register' => 'reg', 'schema' => 'snaps', 'runId' => 'r1']])
			->willReturn([['runId' => 'r1']]);
		$this->assertSame([['runId' => 'r1']], $this->service->listSnapshots(runId: 'r1'));
	}//end testSnapshots()

	/**
	 * A failing store lists nothing rather than throwing.
	 *
	 * @return void
	 */
	public function testListSnapshotsSwallowsStoreFailure(): void {
		$this->objects->method('findAll')->willThrowException(new \RuntimeException('down'));

		$this->assertSame([], $this->service->listSnapshots(runId: 'r1'));
	}//end testListSnapshotsSwallowsStoreFailure()

	/**
	 * The previous snapshot is the newest run's snapshot of that schema; runs without an id are skipped.
	 *
	 * @return void
	 */
	public function testFindPreviousSnapshot(): void {
		$this->objects->method('findAll')->willReturnCallback(
			static function (array $config = []): array {
				$filters = $config['filters'];
				if ($filters['schema'] === 'runs') {
					return [
						['id' => 'old', 'startedAt' => '2026-01-01'],
						['startedAt' => '2026-03-01'],
						['uuid' => 'new', 'startedAt' => '2026-02-01'],
					];
				}

				return [
					'old' => [['runId' => 'old', 'pipelinqSchemaName' => 'lead']],
					'new' => [['runId' => 'new', 'pipelinqSchemaName' => 'client'], ['runId' => 'new', 'pipelinqSchemaName' => 'lead']],
				][$filters['runId']] ?? [];
			}
		);

		$this->assertSame(['runId' => 'new', 'pipelinqSchemaName' => 'lead'], $this->service->findPreviousSnapshot(jobId: 'j', schemaName: 'lead'));
		$this->assertNull($this->service->findPreviousSnapshot(jobId: 'j', schemaName: 'ticket'));
	}//end testFindPreviousSnapshot()

	/**
	 * The last succeeded run is the newest one the store returns for the job and status.
	 *
	 * @return void
	 */
	public function testLastSucceededRun(): void {
		$calls = [];
		$this->objects->method('findAll')->willReturnCallback(
			static function (array $config = []) use (&$calls): array {
				$calls[] = $config['filters'];
				return ($config['filters']['jobId'] === 'j1')
					? [['id' => 'a', 'startedAt' => '2026-01-01'], ['id' => 'b', 'startedAt' => '2026-01-02']]
					: [];
			}
		);

		$this->assertSame('b', $this->service->lastSucceededRun(jobId: 'j1')['id']);
		$this->assertNull($this->service->lastSucceededRun(jobId: 'j2'));
		$this->assertSame(['register' => 'reg', 'schema' => 'runs', 'jobId' => 'j1', 'status' => 'succeeded'], $calls[0]);
	}//end testLastSucceededRun()

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
