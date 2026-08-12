<?php

/**
 * Pipelinq BudgetPeriodResetJob.
 *
 * Daily job that rolls every messageSendBudget row whose
 * periodResetAt has passed — resets counters and advances the
 * window.
 *
 * @category BackgroundJob
 * @package  OCA\Pipelinq\BackgroundJob
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#7.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\Service\BudgetService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Daily messageSendBudget reset.
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#7.2
 */
class BudgetPeriodResetJob extends TimedJob {
	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory.
	 * @param BudgetService $budgetService Budget reset orchestrator.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#7.2
	 */
	public function __construct(
		ITimeFactory $time,
		private BudgetService $budgetService,
		private LoggerInterface $logger,
	) {
		parent::__construct(time: $time);

		// Once a day.
		$this->setInterval(seconds: 86400);
	}//end __construct()

	/**
	 * Run the reset.
	 *
	 * @param mixed $argument Unused job argument.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	protected function run($argument): void {
		try {
			$reset = $this->budgetService->resetPeriods();
			$this->logger->info(
				'BudgetPeriodResetJob complete',
				['rowsReset' => $reset]
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'BudgetPeriodResetJob failed',
				['exception' => $e->getMessage()]
			);
		}
	}//end run()
}//end class
