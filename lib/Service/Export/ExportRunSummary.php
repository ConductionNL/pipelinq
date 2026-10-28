<?php

/**
 * Pipelinq ExportRunSummary.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Export
 * @author   Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://pipelinq.app
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/bi-export-board/specs/bi-export-board/spec.md#requirement-a-job-shows-its-last-run-and-its-next-run
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Export;

use DateTimeInterface;

/**
 * What an export job keeps of its runs (board PqBiExport): the summary of
 * its last finished run, and when it runs next on its schedule.
 *
 * @spec openspec/changes/bi-export-board/specs/bi-export-board/spec.md#requirement-a-job-shows-its-last-run-and-its-next-run
 */
class ExportRunSummary {
	/**
	 * Constructor.
	 *
	 * @param CronExpressionHelper $cron The cron helper.
	 */
	public function __construct(
		private CronExpressionHelper $cron,
	) {
	}//end __construct()

	/**
	 * What a job shows of its last run: the status, when it ended (else began),
	 * the rows and files it wrote, the files it should have, and its error.
	 *
	 * @param array<string, mixed> $run The finished run.
	 *
	 * @return array{lastRunStatus: string, lastRunAt: string|null, lastRunRows: int, lastRunFiles: int,
	 *     lastRunFilesExpected: int, lastRunError: string|null}
	 *
	 * @spec openspec/changes/bi-export-board/specs/bi-export-board/spec.md#requirement-a-job-shows-its-last-run-and-its-next-run
	 */
	public function lastRunSummary(array $run): array {
		$endedAt = ($run['endedAt'] ?? $run['startedAt'] ?? null);
		if (is_string($endedAt) === false || $endedAt === '') {
			$endedAt = null;
		}

		$error = ($run['errorMessage'] ?? null);
		if (is_string($error) === false || $error === '') {
			$error = null;
		}

		return [
			'lastRunStatus' => (string)($run['status'] ?? 'failed'),
			'lastRunAt'     => $endedAt,
			'lastRunRows'   => (int)($run['rowCount'] ?? 0),
			'lastRunFiles'  => (int)($run['fileCount'] ?? 0),
			'lastRunFilesExpected' => (int)($run['fileCountExpected'] ?? 0),
			'lastRunError'  => $error,
		];
	}//end lastRunSummary()

	/**
	 * When an enabled job runs next on its schedule, or null when it is
	 * disabled or its schedule never fires.
	 *
	 * @param array<string, mixed> $job The job.
	 * @param DateTimeInterface $now The moment to look ahead from.
	 *
	 * @return string|null The next run as an ISO 8601 moment.
	 *
	 * @spec openspec/changes/bi-export-board/specs/bi-export-board/spec.md#requirement-a-job-shows-its-last-run-and-its-next-run
	 */
	public function nextRunAt(array $job, DateTimeInterface $now): ?string {
		if (($job['enabled'] ?? false) !== true) {
			return null;
		}

		$next = $this->cron->nextRunAfter(expression: (string)($job['scheduleCron'] ?? ''), from: $now);

		return $next?->format(DateTimeInterface::ATOM);
	}//end nextRunAt()
}//end class
