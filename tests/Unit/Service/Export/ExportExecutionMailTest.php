<?php

/**
 * Pipelinq ExportExecutionMailTest.
 *
 * A run whose destination is mail is delivered through ReportMailRun, not
 * uploaded, and its outcome is recorded on the run and its job.
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
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/reports-compare-periods-and-scheduled-delivery/specs/report-periods-and-delivery/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Export;

use OCA\Pipelinq\Service\Export\ExportDataService;
use OCA\Pipelinq\Service\Export\ExportDestinationService;
use OCA\Pipelinq\Service\Export\ExportExecutionService;
use OCA\Pipelinq\Service\Export\ExportJobService;
use OCA\Pipelinq\Service\Export\ExportRunService;
use OCA\Pipelinq\Service\Export\ExportUploadService;
use OCA\Pipelinq\Service\Export\ReportMailRun;
use OCA\Pipelinq\Service\Export\SchemaEvolutionService;
use OCP\IMemcache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The mail branch of an export run.
 *
 * @covers \OCA\Pipelinq\Service\Export\ExportExecutionService
 * @uses \OCA\Pipelinq\Service\Export\PendingExportRun
 */
class ExportExecutionMailTest extends TestCase {

	/**
	 * The service with a destination of the given type.
	 *
	 * @param string             $type        The destination type.
	 * @param ReportMailRun|null $mailRun     The mail run, or none.
	 * @param ExportUploadService $uploads    The upload double.
	 * @param ExportRunService   $runs        The run double.
	 * @param ExportJobService   $jobs        The job double.
	 *
	 * @return ExportExecutionService
	 */
	private function service(string $type, ?ReportMailRun $mailRun, ExportUploadService $uploads, ExportRunService $runs, ExportJobService $jobs): ExportExecutionService {
		$jobs->method('getJob')->willReturn(['id' => 'job-1', 'name' => 'Open tickets', 'destinationId' => 'd-1', 'sourceSchemas' => ['ticket']]);

		$destinations = $this->createMock(ExportDestinationService::class);
		$destinations->method('getDestination')->willReturn(['id' => 'd-1', 'type' => $type, 'recipients' => [['type' => 'user', 'id' => 'anna']]]);

		$cache = $this->createMock(IMemcache::class);
		$cache->method('add')->willReturn(true);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);

		$data = $this->createMock(ExportDataService::class);
		$data->method('extractSchema')->willReturn(['schema' => 'ticket', 'rows' => 0, 'contents' => '', 'watermark_to' => null, 'column_definitions' => []]);

		return new ExportExecutionService(
			jobs: $jobs,
			runs: $runs,
			data: $data,
			uploads: $uploads,
			destinations: $destinations,
			evolution: $this->createMock(SchemaEvolutionService::class),
			cacheFactory: $factory,
			logger: $this->createMock(LoggerInterface::class),
			mailRun: $mailRun,
		);
	}//end service()

	/**
	 * A mail destination is delivered by mail and the outcome kept on the run and the job.
	 *
	 * @return void
	 */
	public function testAMailDestinationIsDeliveredByMail(): void {
		$outcome = ['status' => 'partial', 'rowCount' => 3, 'byteCount' => 20, 'fileCount' => 2, 'manifest' => ['mail' => []], 'errorMessage' => 'bram: refused'];
		$mailRun = $this->createMock(ReportMailRun::class);
		$mailRun->expects($this->once())->method('deliver')->with(
			$this->callback(static fn (array $job): bool => $job['id'] === 'job-1'),
			$this->callback(static fn (array $destination): bool => $destination['type'] === 'mail')
		)->willReturn($outcome);

		$uploads = $this->createMock(ExportUploadService::class);
		$uploads->expects($this->never())->method('uploadFiles');

		$runs = $this->createMock(ExportRunService::class);
		$runs->expects($this->once())->method('markRunning')->with('run-1');
		$runs->expects($this->once())->method('completeRun')->with('run-1', $outcome)->willReturn(['id' => 'run-1', 'status' => 'partial']);

		$jobs = $this->createMock(ExportJobService::class);
		$jobs->expects($this->once())->method('recordRun')->with('job-1', ['id' => 'run-1', 'status' => 'partial']);

		$run = $this->service(type: 'mail', mailRun: $mailRun, uploads: $uploads, runs: $runs, jobs: $jobs)->executeRun(run: ['id' => 'run-1', 'jobId' => 'job-1']);

		$this->assertSame(['id' => 'run-1', 'status' => 'partial'], $run);
	}//end testAMailDestinationIsDeliveredByMail()

	/**
	 * Another destination type never reaches the mail run.
	 *
	 * @return void
	 */
	public function testAWarehouseDestinationIsNotMailed(): void {
		$mailRun = $this->createMock(ReportMailRun::class);
		$mailRun->expects($this->never())->method('deliver');

		$uploads = $this->createMock(ExportUploadService::class);
		$uploads->expects($this->once())->method('uploadFiles')->willReturn(
			['status' => 'all_succeeded', 'byte_count' => 0, 'file_count' => 0, 'manifest' => [], 'destination_ack' => null, 'error_message' => null]
		);
		$runs = $this->createMock(ExportRunService::class);
		$runs->method('completeRun')->willReturn(['id' => 'run-1', 'status' => 'succeeded']);

		$this->service(type: 's3', mailRun: $mailRun, uploads: $uploads, runs: $runs, jobs: $this->createMock(ExportJobService::class))
			->executeRun(run: ['id' => 'run-1', 'jobId' => 'job-1']);
	}//end testAWarehouseDestinationIsNotMailed()
}//end class
