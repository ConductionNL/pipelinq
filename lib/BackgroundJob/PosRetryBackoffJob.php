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
 * @spec openspec/changes/pipelinq-bookkeeping-to-shillinq/specs/pipelinq-bookkeeping-to-shillinq/spec.md#REQ-PBTS-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\PosBookkeepingService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Periodic re-raise job for pending POS-day journal entries.
 *
 * @spec openspec/changes/pipelinq-bookkeeping-to-shillinq/specs/pipelinq-bookkeeping-to-shillinq/spec.md#REQ-PBTS-001
 */
class PosRetryBackoffJob extends TimedJob {
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
	 * @param PosBookkeepingService $service The bookkeeping service.
	 * @param ContainerInterface $container The DI container (OR ObjectService).
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
	 * @spec openspec/changes/pipelinq-bookkeeping-to-shillinq/specs/pipelinq-bookkeeping-to-shillinq/spec.md#REQ-PBTS-001
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
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
			$schema = $this->appConfig->getValueString(Application::APP_ID, 'posZReport_schema', '');
			if ($register === '' || $schema === '') {
				return [];
			}

			$rows = $objectService->findAll(
				config: [
					'filters' => [
						'register' => $register,
						'schema' => $schema,
					],
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'PosRetryBackoffJob: failed to read pending Z-reports',
				['exception' => $e->getMessage()]
			);
			return [];
		}//end try

		$pending = [];
		foreach (($rows ?? []) as $row) {
			$data = $this->toArray(object: $row);
			$status = (string)($data['bookkeepingStatus'] ?? 'pending');
			if ($status !== 'pending') {
				continue;
			}

			// Only re-raise Z-reports that actually carry takings (a draft / empty
			// day has no journal consequence).
			if ((int)($data['transactionCount'] ?? 0) <= 0) {
				continue;
			}

			$pending[] = $data;
		}

		return $pending;
	}//end findPendingZReports()

	/**
	 * Normalise an OR object (entity or array) into a plain array.
	 *
	 * @param mixed $object The OR object.
	 *
	 * @return array<string, mixed> The object as an array.
	 */
	private function toArray(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialized = $object->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		if (is_object($object) === true && method_exists($object, 'getObject') === true) {
			$data = $object->getObject();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return (array)$object;
	}//end toArray()
}//end class
