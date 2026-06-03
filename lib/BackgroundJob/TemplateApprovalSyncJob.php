<?php

/**
 * Pipelinq TemplateApprovalSyncJob.
 *
 * Hourly job that syncs WhatsApp template approval state from the provider.
 *
 * @category BackgroundJob
 * @package  OCA\Pipelinq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-7.1
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
use Throwable;

/**
 * Polls the provider for template approval changes and reconciles them locally.
 *
 * Runs hourly. Meta does not push template status changes, so polling is the
 * only way to keep `messageTemplate.status` current and to alert on rejections
 * (REQ-009). All provider calls are guarded; when no WhatsApp provider is
 * configured the job is a no-op.
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-7.1
 */
class TemplateApprovalSyncJob extends TimedJob
{
    /**
     * Interval in seconds (1 hour).
     *
     * @var int
     */
    private const INTERVAL = 3600;

    /**
     * Constructor.
     *
     * @param ITimeFactory                $time        The time factory.
     * @param TemplateApprovalSyncService $syncService The template sync service.
     * @param LoggerInterface             $logger      The logger.
     */
    public function __construct(
        ITimeFactory $time,
        private TemplateApprovalSyncService $syncService,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
        $this->setInterval(seconds: self::INTERVAL);
    }//end __construct()

    /**
     * Run the template approval sync.
     *
     * @param mixed $argument The job argument (unused).
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) — $argument is required by the TimedJob signature
     * @spec                                          openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-7.1
     */
    protected function run(mixed $argument): void
    {
        try {
            $summary = $this->syncService->sync();
        } catch (Throwable $e) {
            $this->logger->error('TemplateApprovalSyncJob failed', ['exception' => $e->getMessage()]);
            return;
        }

        if ($summary['updated'] > 0) {
            $this->logger->info(
                'TemplateApprovalSyncJob: updated '.$summary['updated'].' template(s), '.$summary['alerted'].' alert(s).'
            );
        }
    }//end run()
}//end class
