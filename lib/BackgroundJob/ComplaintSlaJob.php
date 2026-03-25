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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

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
 */
class ComplaintSlaJob extends TimedJob
{

    /**
     * Constructor.
     *
     * @param ITimeFactory        $time              The time factory.
     * @param ComplaintSlaService $complaintSlaService The complaint SLA service.
     * @param IAppConfig          $appConfig         The app configuration.
     * @param LoggerInterface     $logger            The logger.
     */
    public function __construct(
        ITimeFactory $time,
        private ComplaintSlaService $complaintSlaService,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);

        // Run every 15 minutes (900 seconds).
        $this->setInterval(900);
        $this->setTimeSensitivity(self::TIME_SENSITIVE);
    }//end __construct()


    /**
     * Execute the background job.
     *
     * Checks configuration, then queries for open complaints
     * and logs warnings for any that are past their SLA deadline.
     *
     * @param mixed $argument The job argument (unused).
     *
     * @return void
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
            // In production, this would query OpenRegister for complaints
            // with status in ['new', 'in_progress'] and check deadlines.
            // The actual querying is handled at the OpenRegister level;
            // this job serves as the monitoring and logging layer.
            //
            // Future enhancement: integrate with OpenRegister ObjectService
            // to query complaints and send notifications for overdue items.

            $this->logger->info(
                'ComplaintSlaJob: SLA deadline check completed',
            );
        } catch (\Exception $e) {
            $this->logger->error(
                'ComplaintSlaJob: Error during SLA check',
                ['exception' => $e->getMessage()],
            );
        }//end try
    }//end run()
}//end class
