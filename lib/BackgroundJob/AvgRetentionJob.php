<?php

/**
 * Pipelinq AvgRetentionJob.
 *
 * Daily timed job that enforces the two-tier AVG retention policy (REQ-AVG-009):
 * it pseudonymizes evidence PII 30 days after delivery (metadata retained) and
 * hard-deletes request dossiers whose 5-year retention window has expired. Both
 * passes are server-authoritative and audit-logged in RetentionService.
 *
 * @category BackgroundJob
 * @package  OCA\Pipelinq\BackgroundJob
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#4.4
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use DateTimeImmutable;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Avg\RetentionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Daily timed job for AVG retention: evidence pseudonymization + dossier cleanup.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter) TimedJob::run requires the
 *  $argument parameter even though this job takes no per-run argument.
 *
 * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#4.4
 */
class AvgRetentionJob extends TimedJob
{
    /**
     * Constructor.
     *
     * @param ITimeFactory     $time      The time factory.
     * @param RetentionService $retention The retention service.
     * @param IAppConfig       $appConfig The app config.
     * @param LoggerInterface  $logger    The logger.
     */
    public function __construct(
        ITimeFactory $time,
        private RetentionService $retention,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
        // Run daily (86400 seconds).
        $this->setInterval(seconds: 86400);
    }//end __construct()

    /**
     * Execute the retention pass.
     *
     * @param mixed $argument The job argument (unused).
     *
     * @return void
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#4.4
     */
    protected function run($argument): void
    {
        if ($this->appConfig->getValueString(Application::APP_ID, 'register', '') === '') {
            return;
        }

        $now = new DateTimeImmutable();

        try {
            $pseudonymized = $this->retention->pseudonymizeExpiredEvidence(now: $now);
            $deleted       = $this->retention->deleteExpiredDossiers(now: $now);

            $this->logger->info(
                'AvgRetentionJob: completed',
                ['pseudonymized' => $pseudonymized, 'deleted' => $deleted]
            );
        } catch (\Throwable $e) {
            $this->logger->error('AvgRetentionJob: error', ['exception' => $e->getMessage()]);
        }
    }//end run()
}//end class
