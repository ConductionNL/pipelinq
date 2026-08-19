<?php

/**
 * Pipelinq CostReconciliationJob.
 *
 * Daily reconciliation of `message.costEur` rows that were persisted
 * in their source currency because the ECB rate was temporarily
 * unavailable.
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
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#7.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\Service\CostReconciliationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Daily cost reconciliation.
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#7.3
 */
class CostReconciliationJob extends TimedJob
{
    /**
     * Constructor.
     *
     * @param ITimeFactory              $time       Time factory.
     * @param CostReconciliationService $reconciler Service.
     * @param LoggerInterface           $logger     Logger.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#7.3
     */
    public function __construct(
        ITimeFactory $time,
        private CostReconciliationService $reconciler,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);

        // Once a day.
        $this->setInterval(seconds: 86400);
    }//end __construct()

    /**
     * Run the reconciliation.
     *
     * @param mixed $argument Unused job argument.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function run($argument): void
    {
        try {
            $summary = $this->reconciler->reconcile();
            $this->logger->info(
                'CostReconciliationJob complete',
                $summary,
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'CostReconciliationJob failed',
                ['exception' => $e->getMessage()]
            );
        }
    }//end run()
}//end class
