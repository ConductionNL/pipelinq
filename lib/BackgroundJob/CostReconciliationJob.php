<?php

/**
 * Pipelinq CostReconciliationJob.
 *
 * Daily job that converts deferred non-EUR message costs once an ECB rate is
 * available.
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
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-7.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\Service\CostCaptureService;
use OCA\Pipelinq\Service\MessageLogService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reconciles message costs persisted in a source currency (REQ-007).
 *
 * When a provider exposes cost in a non-EUR currency but the ECB rate was
 * unavailable at delivery time, the cost is stored in source currency with a
 * pending marker. This daily job retries the conversion once the rate becomes
 * available and clears the marker.
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-7.3
 */
class CostReconciliationJob extends TimedJob
{
    /**
     * Interval in seconds (1 day).
     *
     * @var int
     */
    private const INTERVAL = 86400;

    /**
     * Constructor.
     *
     * @param ITimeFactory       $time        The time factory.
     * @param CostCaptureService $costCapture The cost capture service.
     * @param MessageLogService  $messageLog  The message repository.
     * @param LoggerInterface    $logger      The logger.
     */
    public function __construct(
        ITimeFactory $time,
        private CostCaptureService $costCapture,
        private MessageLogService $messageLog,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
        $this->setInterval(seconds: self::INTERVAL);
    }//end __construct()

    /**
     * Run the cost reconciliation.
     *
     * @param mixed $argument The job argument (unused).
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) — $argument is required by the TimedJob signature
     * @spec                                          openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-7.3
     */
    protected function run(mixed $argument): void
    {
        try {
            $reconciled = $this->costCapture->reconcilePending(messageLog: $this->messageLog);
        } catch (Throwable $e) {
            $this->logger->error('CostReconciliationJob failed', ['exception' => $e->getMessage()]);
            return;
        }

        if ($reconciled > 0) {
            $this->logger->info('CostReconciliationJob: reconciled '.$reconciled.' message cost(s).');
        }
    }//end run()
}//end class
