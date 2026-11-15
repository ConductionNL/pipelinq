<?php

/**
 * Pipelinq PosRetryBackoffJob.
 *
 * Periodic background job that re-raises the journal entry for any posZReport
 * whose shillinq bookkeeping projection is still `pending` — i.e. the
 * registry-mediated `shillinq.JournalEntry.raise` has not yet been accepted
 * (shillinq was unreachable / the integration was unconfigured at close). The
 * re-raise reuses the deterministic SHA256(zReport.uuid + reportDate)
 * idempotency key so shillinq de-duplicates against any journal it already
 * created; a permanent failure (max attempts) flips the projection to `failed`
 * and alerts the accounting administrator. The POS day is never blocked: this
 * job is purely the server's continuation of an already-closed Z-report.
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
 * @spec openspec/specs/pipelinq-bookkeeping-to-shillinq/spec.md#REQ-PBTS-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\Kernel\Contracts\PosBookkeepingServiceInterface;
use OCA\Pipelinq\Util\CastingPayloadToArray;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Periodic re-raise job for pending POS-day journal entries.
 *
 * @spec openspec/specs/pipelinq-bookkeeping-to-shillinq/spec.md#REQ-PBTS-001
 */
class PosRetryBackoffJob extends TimedJob {
	use CastingPayloadToArray;

	/**
	 * Polling interval in seconds (15 minutes).
	 *
	 * @var int
	 */
	private const INTERVAL = 900;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time The time factory.
	 * @param IAppConfig $appConfig The app config.
	 * @param PosBookkeepingServiceInterface $service The bookkeeping service.
	 * @param ContainerInterface $container The DI container (OR ObjectService).
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private IAppConfig $appConfig,
		private PosBookkeepingServiceInterface $service,
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL);
	}//end __construct()

	/**
	 * Re-raise every posZReport still pending its shillinq journal entry.
	 *
	 * Best-effort: the job never throws and never re-raises a Z-report whose
	 * projection is already `raised` or terminally `failed`. When the shillinq
	 * integration is unreachable the service leaves the projection `pending`,
	 * so the next poll picks it up again.
	 *
	 * @param mixed $argument Optional payload (unused).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $argument is required by TimedJob::run().
	 *
	 * @spec openspec/specs/pipelinq-bookkeeping-to-shillinq/spec.md#REQ-PBTS-001
	 */
	protected function run(mixed $argument): void {
		if ($this->service->shouldDispatch() === false) {
			// No configured shillinq journal integration — nothing to retry.
			return;
		}

		$pending = $this->findPendingZReports();
		foreach ($pending as $zReport) {
			try {
				$result = $this->service->dispatchRaise(zReport: $zReport);
				$this->logger->info(
					'PosRetryBackoffJob: journal raise retried',
					[
						'zReportId' => (string)($zReport['id'] ?? ''),
						'bookkeepingStatus' => (string)($result['bookkeepingStatus'] ?? ''),
					]
				);
			} catch (\Throwable $e) {
				$this->logger->warning(
					'PosRetryBackoffJob: journal raise retry failed',
					['zReportId' => (string)($zReport['id'] ?? ''), 'exception' => $e->getMessage()]
				);
			}//end try
		}
	}//end run()

	/**
	 * Read every posZReport whose bookkeepingStatus is still `pending`.
	 *
	 * Best-effort: any failure to read OpenRegister returns an empty list so the
	 * upgrade / cron run is never failed by a bookkeeping outage.
	 *
	 * @return array<int, array<string, mixed>> The pending Z-reports.
	 */
	private function findPendingZReports(): array {
		$pending = [];
		foreach ($this->zReportRows() as $row) {
			$data = $this->toArray(object: $row);
			if ($this->awaitsBookkeeping(data: $data) === true) {
				$pending[] = $data;
			}
		}

		return $pending;
	}//end findPendingZReports()

	/**
	 * Every posZReport, or [] when they cannot be read (logged).
	 *
	 * @return iterable<mixed> The Z-reports.
	 */
	private function zReportRows(): iterable {
		try {
			$rows = (new ConfiguredSchemaRows(container: $this->container, appConfig: $this->appConfig))
				->rows(schemaKey: 'posZReport_schema');
		} catch (\Throwable $e) {
			$this->logger->warning(
				'PosRetryBackoffJob: failed to read pending Z-reports',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		return ($rows ?? []);
	}//end zReportRows()

	/**
	 * Whether a Z-report still awaits its bookkeeping post.
	 *
	 * @param array<string, mixed> $data The Z-report.
	 *
	 * @return bool True when it is pending and carries takings.
	 */
	private function awaitsBookkeeping(array $data): bool {
		if ((string)($data['bookkeepingStatus'] ?? 'pending') !== 'pending') {
			return false;
		}

		// Only re-raise Z-reports that actually carry takings (a draft / empty
		// day has no journal consequence).
		return (int)($data['transactionCount'] ?? 0) > 0;
	}//end awaitsBookkeeping()
}//end class
