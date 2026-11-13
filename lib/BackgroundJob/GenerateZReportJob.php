<?php

/**
 * Pipelinq GenerateZReportJob.
 *
 * Daily timed background job that aggregates yesterday's confirmed/settled
 * posTransaction objects into one posZReport per active terminal and emits
 * the pipelinq.PosZReport.submitted CloudEvent. The job runs once an hour
 * but only fires the actual aggregation when the configured
 * `pos_eod.z_report_time` HH:MM matches the current UTC hour:minute window —
 * an admin-tunable daily schedule with no extra scheduler infrastructure.
 *
 * The job never raises the shillinq journal directly: it only generates the
 * operational posZReport (status=ready, bookkeepingStatus=pending). The
 * manager-gated controller / UI issues the registry-mediated
 * shillinq.JournalEntry.raise, and PosRetryBackoffJob re-raises any that stay
 * pending. This split keeps the daily run cheap and lets an unreachable shillinq
 * never block the next day's Z-report generation.
 *
 * @category BackgroundJob
 * @package  OCA\Pipelinq\BackgroundJob
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/archive/2026-06-14-pos-end-of-day-bookkeeping-post/tasks.md#3.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Service\PosBookkeepingService;
use OCA\Pipelinq\Util\CastingPayloadToArray;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Daily Z-report generation job.
 *
 * Polls once per hour and only does work when the current UTC HH:MM falls
 * inside the same hour as the configured `pos_eod.z_report_time`. On a match
 * the job:
 *
 *   1. Reads every active terminalId from yesterday's posTransaction set
 *      (terminals that didn't sell are not reported).
 *   2. For each terminal, calls PosBookkeepingService::generateZReport, which
 *      persists the Z-report and emits pipelinq.PosZReport.submitted.
 *   3. Returns without raising the shillinq journal — the registry-mediated
 *      raise is the separate manager-gated / retry-job workflow.
 *
 * @spec openspec/changes/archive/2026-06-14-pos-end-of-day-bookkeeping-post/tasks.md#3.1
 */
class GenerateZReportJob extends TimedJob {
	use CastingPayloadToArray;

	/**
	 * Polling interval in seconds (1 hour).
	 *
	 * @var int
	 */
	private const INTERVAL = 3600;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time The time factory.
	 * @param IAppConfig $appConfig The app config.
	 * @param PosBookkeepingService $service The bookkeeping service.
	 * @param ContainerInterface $container The DI container (for OR ObjectService).
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private IAppConfig $appConfig,
		private PosBookkeepingService $service,
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL);
	}//end __construct()

	/**
	 * Run the daily Z-report generation when the configured HH:MM is reached.
	 *
	 * @param mixed $argument Optional payload (unused).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $argument is required by TimedJob::run().
	 *
	 * @spec openspec/changes/archive/2026-06-14-pos-end-of-day-bookkeeping-post/tasks.md#3.1
	 */
	protected function run(mixed $argument): void {
		$configured = trim(
			$this->appConfig->getValueString(AppIdentity::APP_ID, 'pos_eod.z_report_time', '23:59')
		);

		if ($this->isDueNow(configured: $configured) === false) {
			return;
		}

		$reportDate = $this->reportDateForRun();
		$terminals = $this->discoverTerminals(reportDate: $reportDate);

		if ($terminals === []) {
			// Even with no terminals, emit a zero-value Z-report so reconciliation
			// sees the day was reviewed (REQ-POS-BK-001-02).
			$this->safeGenerate(reportDate: $reportDate, terminalId: null);
			return;
		}

		foreach ($terminals as $terminalId) {
			$this->safeGenerate(reportDate: $reportDate, terminalId: $terminalId);
		}
	}//end run()

	/**
	 * Whether the configured HH:MM falls inside the current UTC poll window.
	 *
	 * The job polls hourly so a "23:59" configuration triggers any time during
	 * UTC hour 23. This avoids cron-precision drift and keeps the schedule
	 * tolerant of clock skew.
	 *
	 * @param string $configured The configured HH:MM.
	 *
	 * @return bool True when the job should run.
	 */
	private function isDueNow(string $configured): bool {
		if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $configured) !== 1) {
			return false;
		}

		$configuredHour = (int)substr($configured, 0, 2);
		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$currentHour = (int)$now->format('H');

		return $configuredHour === $currentHour;
	}//end isDueNow()

	/**
	 * The reportDate for the current run (yesterday in UTC).
	 *
	 * @return string The date in YYYY-MM-DD.
	 */
	private function reportDateForRun(): string {
		return (new DateTimeImmutable('now', new DateTimeZone('UTC')))
			->modify('-1 day')
			->format('Y-m-d');
	}//end reportDateForRun()

	/**
	 * Enumerate distinct terminalIds with at least one transaction on the date.
	 *
	 * Best-effort: any failure to read OR returns an empty list so the
	 * fall-back zero-value report still emits.
	 *
	 * @param string $reportDate The target date.
	 *
	 * @return array<int, string> The terminal IDs.
	 */
	private function discoverTerminals(string $reportDate): array {
		$terminals = [];
		foreach ($this->transactionRows() as $row) {
			$terminalId = $this->terminalSellingOn(data: $this->toArray(object: $row), reportDate: $reportDate);
			if ($terminalId !== '') {
				$terminals[$terminalId] = true;
			}
		}

		return array_keys($terminals);
	}//end discoverTerminals()

	/**
	 * Every posTransaction, or [] when they cannot be read (logged).
	 *
	 * @return iterable<mixed> The transactions.
	 */
	private function transactionRows(): iterable {
		try {
			$rows = (new ConfiguredSchemaRows(container: $this->container, appConfig: $this->appConfig))
				->rows(schemaKey: 'posTransaction_schema');
		} catch (\Throwable $e) {
			$this->logger->warning(
				'GenerateZReportJob: failed to enumerate terminals',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		return ($rows ?? []);
	}//end transactionRows()

	/**
	 * The terminal of a transaction settled (or confirmed) on the report date, or ''.
	 *
	 * @param array<string, mixed> $data       The transaction.
	 * @param string               $reportDate The target date (UTC, Y-m-d).
	 *
	 * @return string The terminal ID, or '' when the transaction does not count.
	 */
	private function terminalSellingOn(array $data, string $reportDate): string {
		$terminalId = (string)($data['terminalId'] ?? '');
		$stamp = (string)($data['settledAt'] ?? $data['confirmedAt'] ?? '');
		if ($terminalId === '' || $stamp === '') {
			return '';
		}

		if ($this->utcDay(stamp: $stamp) !== $reportDate) {
			return '';
		}

		return $terminalId;
	}//end terminalSellingOn()

	/**
	 * The UTC calendar day of a timestamp, or null when it does not parse.
	 *
	 * @param string $stamp The timestamp.
	 *
	 * @return string|null The day as Y-m-d.
	 */
	private function utcDay(string $stamp): ?string {
		try {
			return (new DateTimeImmutable($stamp))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');
		} catch (\Throwable $e) {
			return null;
		}
	}//end utcDay()

	/**
	 * Generate a Z-report and swallow any exception (job must not throw).
	 *
	 * @param string $reportDate The report date.
	 * @param string|null $terminalId The terminal, or null for all-terminals.
	 *
	 * @return void
	 */
	private function safeGenerate(string $reportDate, ?string $terminalId): void {
		try {
			$report = $this->service->generateZReport(reportDate: $reportDate, terminalId: $terminalId);
			$this->logger->info(
				'GenerateZReportJob: Z-report created',
				[
					'reportDate' => $reportDate,
					'terminalId' => $terminalId,
					'transactionCount' => (int)($report['transactionCount'] ?? 0),
					'total' => (float)($report['total'] ?? 0),
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'GenerateZReportJob: failed to generate Z-report',
				[
					'reportDate' => $reportDate,
					'terminalId' => $terminalId,
					'exception' => $e->getMessage(),
				]
			);
		}//end try
	}//end safeGenerate()
}//end class
