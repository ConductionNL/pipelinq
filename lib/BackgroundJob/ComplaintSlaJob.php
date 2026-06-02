<?php

/**
 * Pipelinq ComplaintSlaJob.
 *
 * Background job for monitoring complaint SLA deadlines.
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
 * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-21
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use Exception;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\ComplaintSlaService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Timed background job for complaint SLA deadline monitoring.
 *
 * Runs every 15 minutes to check for complaints that have exceeded
 * their SLA deadline and logs warnings for each overdue complaint.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class ComplaintSlaJob extends TimedJob
{
    /**
     * Constructor.
     *
     * @param ITimeFactory        $time                The time factory.
     * @param ComplaintSlaService $complaintSlaService The complaint SLA service.
     * @param IAppConfig          $appConfig           The app configuration.
     * @param LoggerInterface     $logger              The logger.
     */
    public function __construct(
        ITimeFactory $time,
        private ComplaintSlaService $complaintSlaService,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);

        // Run every 15 minutes (900 seconds).
        $this->setInterval(seconds: 900);
        $this->setTimeSensitivity(sensitivity: self::TIME_SENSITIVE);
    }//end __construct()

    /**
     * Execute the background job.
     *
     * Checks configuration, then queries for open complaints
     * and logs warnings for any that are past their SLA deadline.
     *
     * @param mixed $argument The job argument (unused, required by TimedJob).
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-21
     */
    protected function run($argument): void
    {
        $register = $this->appConfig->getValueString(
            Application::APP_ID,
            'register',
            '',
        );

        $complaintSchema = $this->appConfig->getValueString(
            Application::APP_ID,
            'complaint_schema',
            '',
        );

        if ($register === '' || $complaintSchema === '') {
            $this->logger->debug(
                'ComplaintSlaJob: Skipping — register or complaint_schema not configured',
            );
            return;
        }

        $this->logger->info('ComplaintSlaJob: Starting SLA deadline check');

        try {
            $overdueCount = $this->checkOverdueComplaints(register: $register, complaintSchema: $complaintSchema);

            $this->logger->info(
                'ComplaintSlaJob: SLA deadline check completed',
                ['overdue' => $overdueCount],
            );
        } catch (Exception $e) {
            $this->logger->error(
                'ComplaintSlaJob: Error during SLA check',
                ['exception' => $e->getMessage()],
            );
        }//end try
    }//end run()

    /**
     * Iterate over open complaints and count overdue ones.
     *
     * Placeholder: replace the fetchComplaints() stub with a real
     * ObjectService::findAll() call once OpenRegister is wired in.
     *
     * @param string $register        The register UUID.
     * @param string $complaintSchema The complaint schema UUID.
     *
     * @return int The number of overdue complaints detected.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-21
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private function checkOverdueComplaints(string $register, string $complaintSchema): int
    {
        $overdueCount = 0;
        $complaints   = $this->fetchComplaints(register: $register, schema: $complaintSchema);

        foreach ($complaints as $complaint) {
            if ($this->complaintSlaService->isOverdue(complaint: $complaint) === true) {
                $overdueCount++;
                $this->logger->warning(
                    'ComplaintSlaJob: Overdue complaint detected',
                    ['id' => (string) ($complaint['id'] ?? 'unknown')],
                );
            }
        }

        return $overdueCount;
    }//end checkOverdueComplaints()

    /**
     * Fetch open complaints from OpenRegister.
     *
     * Returns an empty array until ObjectService is wired up.
     *
     * @param string $register The register UUID.
     * @param string $schema   The complaint schema UUID.
     *
     * @return array<int, array<string, mixed>> The complaints.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private function fetchComplaints(string $register, string $schema): array
    {
        // Placeholder until OpenRegister ObjectService is injected.
        // Future: return $this->objectService->findAll([...]).
        unset($register, $schema);

        return [];
    }//end fetchComplaints()
}//end class
