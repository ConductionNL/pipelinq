<?php

/**
 * Pipelinq TenderPostedRetryJob.
 *
 * Periodic background job that re-emits `nl.pipelinq.pos.tender.posted`
 * CloudEvents for posTender objects whose initial settle-path emission did
 * not reach Shillinq (glPosted=false). The PosTenderService's per-tender
 * attempt counter prevents an infinite loop: after MAX_GL_POST_ATTEMPTS the
 * tender is soft-failed (the event is still on the staging record, an
 * operator can re-process it through the bookkeeping pipeline).
 *
 * Runs every 5 minutes by default — the Nextcloud job runner caps the
 * effective interval to the configured cron cadence, so a 5-minute cadence
 * runs roughly every cron tick on a healthy deployment.
 *
 * @category BackgroundJob
 * @package  OCA\Pipelinq\BackgroundJob
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/pos-split-tender/specs.md#REQ-PST-006
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\Service\PosTenderService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Retry job for un-posted tender CloudEvents.
 *
 * @spec openspec/changes/pos-split-tender/specs.md#REQ-PST-006
 */
class TenderPostedRetryJob extends TimedJob {
	/**
	 * Job interval in seconds. 300 = 5 minutes per the spec.
	 *
	 * @var int
	 */
	private const INTERVAL_SECONDS = 300;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time The time factory.
	 * @param PosTenderService $service The tender service.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private PosTenderService $service,
		private LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
	}//end __construct()

	/**
	 * Re-emit `tender.posted` CloudEvents for unposted tenders.
	 *
	 * @param mixed $argument The job argument (unused).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $argument is required by TimedJob::run().
	 *
	 * @spec openspec/changes/pos-split-tender/specs.md#REQ-PST-006
	 */
	protected function run(mixed $argument): void {
		try {
			$unposted = $this->service->listUnpostedTenders();
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq TenderPostedRetryJob: failed to list unposted tenders',
				['exception' => $e->getMessage()]
			);
			return;
		}

		if ($unposted === []) {
			return;
		}

		$count = 0;
		foreach ($unposted as $tender) {
			$transactionUuid = (string)($tender['transaction'] ?? '');
			if ($transactionUuid === '') {
				continue;
			}

			try {
				$eventId = $this->service->emitSingleTenderPosted(
					transactionUuid: $transactionUuid,
					transactionReference: '',
					tender: $tender
				);
				if ($eventId !== '') {
					$count++;
				}
			} catch (Throwable $e) {
				$this->logger->info(
					'Pipelinq TenderPostedRetryJob: per-tender re-emit failed (soft)',
					['exception' => $e->getMessage()]
				);
			}
		}//end foreach

		if ($count > 0) {
			$this->logger->info(
				'Pipelinq TenderPostedRetryJob: re-emitted ' . $count . ' tender CloudEvent(s)'
			);
		}
	}//end run()
}//end class
