<?php

/**
 * Pipelinq ClientRiskJob.
 *
 * Recomputes every client's churn risk once a day.
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
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/clients-history-summary-and-risk/tasks.md#task-1.3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\Service\ClientRiskService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The daily recompute of design D2: silence and the 90- and 180-day windows
 * move with the calendar, so a client's risk changes without any record
 * changing. A client whose level and reasons stay the same is not rewritten.
 *
 * @spec openspec/changes/clients-history-summary-and-risk/tasks.md#task-1.3
 */
class ClientRiskJob extends TimedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory      $time   Time factory.
	 * @param ClientRiskService $risk   Scores and stores a client's risk.
	 * @param LoggerInterface   $logger PSR logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ClientRiskService $risk,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 86400);
		$this->setTimeSensitivity(sensitivity: self::TIME_INSENSITIVE);
	}//end __construct()

	/**
	 * Recompute every client.
	 *
	 * @param mixed $argument Job argument (unused).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/changes/clients-history-summary-and-risk/tasks.md#task-1.3
	 */
	protected function run($argument): void {
		unset($argument);

		try {
			$tally = $this->risk->recomputeAll();
		} catch (Throwable $e) {
			$this->logger->error('ClientRiskJob: the risk recompute failed', ['exception' => $e->getMessage()]);
			return;
		}

		$this->logger->info('ClientRiskJob: client risk recomputed', $tally);
	}//end run()
}//end class
