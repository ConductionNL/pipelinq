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
 * @spec openspec/changes/klachtenregistratie/tasks.md#task-12
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use Exception;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\ComplaintSlaService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Timed background job for complaint SLA deadline monitoring.
 *
 * Runs every 15 minutes to check for complaints that have exceeded
 * their SLA deadline and logs warnings for each overdue complaint.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 * @spec                                          openspec/changes/klachtenregistratie/tasks.md#task-12
 */
class ComplaintSlaJob extends TimedJob
{
    /**
     * Constructor.
     *
     * @param ITimeFactory        $time                The time factory.
     * @param ComplaintSlaService $complaintSlaService The complaint SLA service.
     * @param IAppConfig          $appConfig           The app configuration.
     * @param ContainerInterface  $container           The dependency injection container.
     * @param LoggerInterface     $logger              The logger.
     *
     * @spec openspec/changes/klachtenregistratie/tasks.md#task-12
     */
    public function __construct(
        ITimeFactory $time,
        private ComplaintSlaService $complaintSlaService,
        private IAppConfig $appConfig,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);

        // Run every 15 minutes (900 seconds).
        $this->setInterval(interval: 900);
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
     * @spec   openspec/changes/klachtenregistratie/tasks.md#task-12
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
            // Query OpenRegister for open complaints (new, in_progress status).
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $result        = $objectService->findAll(
                register: $register,
                schema: $complaintSchema,
                filters: ['status' => ['new', 'in_progress']],
            );

            $complaints   = ($result['results'] ?? $result ?? []);
            $overdueCount = 0;

            foreach ($complaints as $complaint) {
                if ($this->complaintSlaService->isOverdue(complaint: $complaint) === true) {
                    $overdueCount++;
                    $complaintId = $complaint['id'] ?? 'unknown';
                    $deadline    = $complaint['slaDeadline'] ?? 'unknown';
                    $this->logger->warning(
                        'ComplaintSlaJob: Complaint {complaintId} is overdue (SLA deadline: {deadline})',
                        [
                            'complaintId' => $complaintId,
                            'deadline'    => $deadline,
                        ],
                    );
                }
            }//end foreach

            $this->logger->info(
                'ComplaintSlaJob: SLA deadline check completed ({overdueCount} overdue complaints found)',
                ['overdueCount' => $overdueCount],
            );
        } catch (Exception $e) {
            $this->logger->error(
                'ComplaintSlaJob: Error during SLA check',
                ['exception' => $e->getMessage()],
            );
        }//end try
    }//end run()
}//end class
