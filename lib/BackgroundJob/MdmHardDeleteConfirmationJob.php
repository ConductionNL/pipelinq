<?php

/**
 * Pipelinq MdmHardDeleteConfirmationJob.
 *
 * Daily background job that surfaces soft-deleted Master Entities whose 30-day
 * AVG cooling-off period has elapsed, logging them for admin confirmation. The
 * actual hard delete remains an explicit admin action (never automatic) so a
 * mistaken right-of-deletion stays recoverable until a human confirms.
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
 * @spec openspec/changes/master-data-management/specs.md#REQ-MDM-009
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\Service\Mdm\AVGWorkflowService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Daily hard-delete eligibility notifier for the AVG workflow.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter) run()'s $argument is required
 *  by the TimedJob contract but unused by this job.
 */
class MdmHardDeleteConfirmationJob extends TimedJob
{
    /**
     * Constructor.
     *
     * @param ITimeFactory       $time   The time factory.
     * @param AVGWorkflowService $avg    The AVG workflow service.
     * @param LoggerInterface    $logger The logger.
     */
    public function __construct(
        ITimeFactory $time,
        private AVGWorkflowService $avg,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);

        // Run once per day.
        $this->setInterval(seconds: 86400);
        $this->setTimeSensitivity(sensitivity: self::TIME_INSENSITIVE);
    }//end __construct()

    /**
     * Surface hard-delete-eligible soft-deleted entities for admin attention.
     *
     * @param mixed $argument The job argument (unused).
     *
     * @return void
     */
    protected function run($argument): void
    {
        try {
            $candidates = $this->avg->listHardDeleteCandidates();
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Pipelinq MDM: hard-delete candidate scan failed',
                ['exception' => $e->getMessage()]
            );
            return;
        }

        foreach ($candidates as $entity) {
            $masterId = (string) ($entity['masterId'] ?? ($entity['id'] ?? ''));
            $this->logger->info(
                'Pipelinq MDM: master entity ready for hard delete (cooling-off elapsed)',
                ['master' => $masterId]
            );
        }

        if (empty($candidates) === false) {
            $this->logger->info(
                'Pipelinq MDM: hard-delete candidates pending admin confirmation',
                ['count' => count($candidates)]
            );
        }
    }//end run()
}//end class
