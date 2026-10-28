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

use DateTimeImmutable;
use OCA\Pipelinq\Service\Export\CronExpressionHelper;
use OCA\Pipelinq\Service\Export\ExportRunSummary;
use PHPUnit\Framework\TestCase;

/**
 * Board PqBiExport: a job shows its last run (status, when, rows) and its
 * next run, so the list can show and filter them.
 *
 * @spec openspec/changes/bi-export-board/specs/bi-export-board/spec.md#requirement-a-job-shows-its-last-run-and-its-next-run
 */
class ExportJobRunSummaryTest extends TestCase {
	/**
	 * The summary under test.
	 *
	 * @var ExportRunSummary
	 */
	private ExportRunSummary $jobs;

	/**
	 * Build the summary over the real cron helper.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->jobs = new ExportRunSummary(cron: new CronExpressionHelper());
	}//end setUp()

	/**
	 * A finished run gives the job its status, when it ended, its rows and files, and its error.
	 *
	 * @return void
	 */
	public function testLastRunSummary(): void {
		$summary = $this->jobs->lastRunSummary(run: [
			'status'    => 'partial',
			'startedAt' => '2026-10-05T03:00:00+02:00',
			'endedAt'   => '2026-10-05T03:12:00+02:00',
			'rowCount'  => 480,
			'fileCount' => 2,
			'fileCountExpected' => 3,
		]);
		$this->assertSame(
			expected: [
				'lastRunStatus' => 'partial',
				'lastRunAt'     => '2026-10-05T03:12:00+02:00',
				'lastRunRows'   => 480,
				'lastRunFiles'  => 2,
				'lastRunFilesExpected' => 3,
				'lastRunError'  => null,
			],
			actual: $summary
		);

		$failed = $this->jobs->lastRunSummary(
			run: ['status' => 'failed', 'startedAt' => '2026-10-06T01:00:00+02:00', 'errorMessage' => 'verbinding geweigerd']
		);
		$this->assertSame(expected: 'failed', actual: $failed['lastRunStatus']);
		$this->assertSame(expected: '2026-10-06T01:00:00+02:00', actual: $failed['lastRunAt']);
		$this->assertSame(expected: 'verbinding geweigerd', actual: $failed['lastRunError']);
	}//end testLastRunSummary()

	/**
	 * An enabled job runs next at its schedule's next minute; a disabled one is not scheduled.
	 *
	 * @return void
	 */
	public function testNextRunAt(): void {
		$now = new DateTimeImmutable('2026-10-07T10:30:00+02:00');
		$this->assertSame(
			expected: '2026-10-08T02:00:00+02:00',
			actual: $this->jobs->nextRunAt(job: ['enabled' => true, 'scheduleCron' => '0 2 * * *'], now: $now)
		);
		$this->assertNull(actual: $this->jobs->nextRunAt(job: ['enabled' => false, 'scheduleCron' => '0 2 * * *'], now: $now));
		$this->assertNull(actual: $this->jobs->nextRunAt(job: ['enabled' => true, 'scheduleCron' => ''], now: $now));
	}//end testNextRunAt()
}//end class
