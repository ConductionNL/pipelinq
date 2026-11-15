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

use OCA\Pipelinq\Service\Export\ExportExtraction;
use OCA\Pipelinq\Service\Export\PendingExportRun;
use OCA\Pipelinq\Service\Export\SchemaExtractRun;
use PHPUnit\Framework\TestCase;

/**
 * The values an export run carries between its steps: the pending run's
 * ids and watermark, and whether a schema extract is incremental.
 *
 * @covers \OCA\Pipelinq\Service\Export\PendingExportRun
 * @covers \OCA\Pipelinq\Service\Export\SchemaExtractRun
 * @covers \OCA\Pipelinq\Service\Export\ExportExtraction
 */
class ExportRunValuesTest extends TestCase {

	/**
	 * The run id is the id, else the uuid, else empty; the job id is a string.
	 *
	 * @return void
	 */
	public function testThePendingRunIdsPreferTheIdOverTheUuid(): void {
		$both = new PendingExportRun(run: ['id' => 12, 'uuid' => 'u-1', 'jobId' => 7]);
		$this->assertSame('12', $both->runId());
		$this->assertSame('7', $both->jobId());

		$uuid = new PendingExportRun(run: ['uuid' => 'u-1']);
		$this->assertSame('u-1', $uuid->runId());
		$this->assertSame('', $uuid->jobId());

		$this->assertSame('', (new PendingExportRun(run: []))->runId());
	}//end testThePendingRunIdsPreferTheIdOverTheUuid()

	/**
	 * A watermark is passed on as given; a missing or empty one is null.
	 *
	 * @return void
	 */
	public function testAnEmptyWatermarkIsNull(): void {
		$this->assertSame('2026-05-01T00:00:00Z', (new PendingExportRun(run: ['watermarkFrom' => '2026-05-01T00:00:00Z']))->watermarkFrom());
		$this->assertNull((new PendingExportRun(run: ['watermarkFrom' => '']))->watermarkFrom());
		$this->assertNull((new PendingExportRun(run: ['watermarkFrom' => null]))->watermarkFrom());
		$this->assertNull((new PendingExportRun(run: []))->watermarkFrom());
	}//end testAnEmptyWatermarkIsNull()

	/**
	 * Only an explicit incremental mode makes the extract incremental; a job
	 * without a mode is a full export.
	 *
	 * @return void
	 */
	public function testOnlyAnIncrementalModeIsIncremental(): void {
		$this->assertTrue((new SchemaExtractRun(job: ['mode' => 'incremental'], rows: []))->isIncremental());
		$this->assertFalse((new SchemaExtractRun(job: ['mode' => 'full'], rows: []))->isIncremental());
		$this->assertFalse((new SchemaExtractRun(job: ['mode' => 'Incremental'], rows: []))->isIncremental());
		$this->assertFalse((new SchemaExtractRun(job: [], rows: []))->isIncremental());
	}//end testOnlyAnIncrementalModeIsIncremental()

	/**
	 * A schema extract starts with its rows, no dropped columns and no watermark.
	 *
	 * @return void
	 */
	public function testASchemaExtractStartsClean(): void {
		$run = new SchemaExtractRun(job: ['mode' => 'full'], rows: [['id' => 1]]);

		$this->assertSame([['id' => 1]], $run->rows);
		$this->assertSame([], $run->droppedColumns);
		$this->assertNull($run->watermarkTo);
	}//end testASchemaExtractStartsClean()

	/**
	 * An extraction starts with no files, no rows and no watermark, and keeps
	 * the pending run and job it was made for.
	 *
	 * @return void
	 */
	public function testAnExtractionStartsEmpty(): void {
		$pending = new PendingExportRun(run: ['id' => 'r-1']);
		$extraction = new ExportExtraction(pending: $pending, job: ['id' => 'j-1']);

		$this->assertSame($pending, $extraction->pending);
		$this->assertSame(['id' => 'j-1'], $extraction->job);
		$this->assertSame([], $extraction->files);
		$this->assertSame(0, $extraction->totalRows);
		$this->assertNull($extraction->watermarkTo);
	}//end testAnExtractionStartsEmpty()
}//end class
