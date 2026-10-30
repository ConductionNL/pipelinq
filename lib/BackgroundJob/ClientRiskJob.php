<?php

/**
 * Pipelinq ClientRiskJob.
 *
 * Daily recompute of every client's churn risk. Writes a client only when its
 * level or its reasons changed (ClientRiskService::changed()).
 *
 * Registered via appinfo/info.xml <background-jobs>.
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
 * @spec openspec/changes/clients-history-summary-and-risk/specs/client-insight/spec.md#requirement-every-client-carries-a-risk-level-with-its-reasons-req-cin-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\Service\ClientRiskService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Daily churn risk recompute.
 *
 * @spec openspec/changes/clients-history-summary-and-risk/specs/client-insight/spec.md#requirement-every-client-carries-a-risk-level-with-its-reasons-req-cin-001
 */
class ClientRiskJob extends TimedJob {
	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory.
	 * @param ClientRiskService $riskService The risk service.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private ClientRiskService $riskService,
		private LoggerInterface $logger,
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
	 * @spec openspec/changes/clients-history-summary-and-risk/specs/client-insight/spec.md#requirement-every-client-carries-a-risk-level-with-its-reasons-req-cin-001
	 */
	protected function run($argument): void {
		unset($argument);

		try {
			$written = $this->riskService->recomputeAll();
		} catch (Throwable $e) {
			$this->logger->error('ClientRiskJob: recompute failed', ['exception' => $e->getMessage()]);
			return;
		}

		if ($written > 0) {
			$this->logger->info('ClientRiskJob: churn risk changed', ['clients' => $written]);
		}
	}//end run()
}//end class
