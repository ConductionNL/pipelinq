<?php

/**
 * Pipelinq GenerateZReportJob.
 *
 * Scheduled daily background job to generate Z-reports from confirmed/settled
 * POS transactions for the previous calendar day.
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
 * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#3.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use DateTimeImmutable;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\PosBookkeepingService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Daily background job to generate posZReport objects from confirmed transactions.
 *
 * Runs every 60 seconds and checks whether the configured generation time
 * (HH:MM, default 23:59) has been reached. Only generates once per day to
 * prevent duplicate reports. Creates one Z-report per terminal found.
 *
 * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#3.1
 */
class GenerateZReportJob extends TimedJob
{
    /**
     * Constructor.
     *
     * @param ITimeFactory          $time               Time factory (required by TimedJob).
     * @param PosBookkeepingService $bookkeepingService The bookkeeping service.
     * @param IAppConfig            $appConfig          App configuration for scheduled time.
     * @param LoggerInterface       $logger             Logger.
     */
    public function __construct(
        ITimeFactory $time,
        private PosBookkeepingService $bookkeepingService,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);

        // Run every 60 seconds to check whether the scheduled time has been reached.
        $this->setInterval(seconds: 60);
        $this->setTimeSensitivity(sensitivity: self::TIME_SENSITIVE);
    }//end __construct()

    /**
     * Execute the Z-report generation job.
     *
     * Checks whether the configured daily generation time has passed and
     * whether a report has already been generated today. If conditions are met,
     * generates Z-reports for yesterday's (UTC) confirmed/settled transactions.
     *
     * @param mixed $argument The job argument (unused; required by TimedJob).
     *
     * @return void
     *
     * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#3.1
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function run($argument): void
    {
        try {
            $now            = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $configuredTime = (string) $this->appConfig->getValueString(Application::APP_ID, 'bookkeeping_z_report_time', '23:59');
            $lastRunDate    = (string) $this->appConfig->getValueString(Application::APP_ID, 'bookkeeping_last_run_date', '');

            // Parse configured time (HH:MM).
            $timeParts = explode(':', $configuredTime);
            if (count($timeParts) !== 2) {
                $timeParts = ['23', '59'];
            }

            $scheduledHour   = (int) $timeParts[0];
            $scheduledMinute = (int) $timeParts[1];

            $currentHour   = (int) $now->format('H');
            $currentMinute = (int) $now->format('i');
            $todayDate     = $now->format('Y-m-d');

            // Only run once per day after the configured time.
            $timeReached = ($currentHour > $scheduledHour)
                || ($currentHour === $scheduledHour && $currentMinute >= $scheduledMinute);

            if ($timeReached === false || $lastRunDate === $todayDate) {
                return;
            }

            // Report date is the previous day (UTC).
            $reportDate = $now->modify('-1 day')->format('Y-m-d');

            $this->logger->info(
                'GenerateZReportJob: generating Z-reports for date {date}',
                ['date' => $reportDate]
            );

            $createdIds = $this->bookkeepingService->generateZReport(
                reportDate: $reportDate,
                terminalId: null
            );

            // Mark today as done.
            $this->appConfig->setValueString(Application::APP_ID, 'bookkeeping_last_run_date', $todayDate);

            // Emit submitted events for all created Z-reports.
            foreach ($createdIds as $zReportId) {
                $this->bookkeepingService->emitZReportSubmittedEvent(zReportId: $zReportId);
            }

            $this->logger->info(
                'GenerateZReportJob: created {count} Z-report(s) for {date}',
                ['count' => count($createdIds), 'date' => $reportDate]
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'GenerateZReportJob failed',
                ['exception' => $e]
            );
        }//end try
    }//end run()
}//end class
