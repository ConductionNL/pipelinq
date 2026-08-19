<?php

/**
 * Pipelinq TemplateApprovalSyncJob.
 *
 * Hourly job that polls Meta / BSP for WhatsApp template approval
 * state and reconciles the local messageTemplate rows.
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
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#7.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\Service\TemplateApprovalSyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Hourly WhatsApp template-state sync.
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#7.1
 */
class TemplateApprovalSyncJob extends TimedJob
{
    /**
     * Constructor.
     *
     * @param ITimeFactory                $time        Time factory.
     * @param TemplateApprovalSyncService $syncService Sync orchestrator.
     * @param LoggerInterface             $logger      Logger.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#7.1
     */
    public function __construct(
        ITimeFactory $time,
        private TemplateApprovalSyncService $syncService,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);

        // 1 hour interval (3600 seconds) per design.md decision 4.
        $this->setInterval(seconds: 3600);
    }//end __construct()

    /**
     * Run the sync.
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
            $summary = $this->syncService->syncAll();
            $this->logger->info(
                'TemplateApprovalSyncJob complete',
                $summary,
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'TemplateApprovalSyncJob failed',
                ['exception' => $e->getMessage()]
            );
        }
    }//end run()
}//end class
