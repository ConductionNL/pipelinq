<?php

/**
 * Pipelinq AvgDeadlineTrackerJob.
 *
 * Hourly timed job that drives the AVG deadline lifecycle: the 7-day advance
 * reminder, the <72h team-lead escalation, and the deadline-breach detection
 * (which records the termijn-overschreden TermijnEvent and alerts the FG). Each
 * milestone is idempotent in DeadlineTrackerService, so running hourly never
 * double-notifies.
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
 * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#4.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use DateTimeImmutable;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Avg\DeadlineTrackerService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Timed job for AVG deadline reminders, escalations and breaches.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter) TimedJob::run requires the
 *  $argument parameter even though this job takes no per-run argument.
 *
 * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#4.2
 */
class AvgDeadlineTrackerJob extends TimedJob
{
    /**
     * Constructor.
     *
     * @param ITimeFactory           $time      The time factory.
     * @param DeadlineTrackerService $tracker   The deadline tracker service.
     * @param IAppConfig             $appConfig The app config.
     * @param LoggerInterface        $logger    The logger.
     */
    public function __construct(
        ITimeFactory $time,
        private DeadlineTrackerService $tracker,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
        $this->setInterval(seconds: 3600);
        $this->setTimeSensitivity(sensitivity: self::TIME_SENSITIVE);
    }//end __construct()

    /**
     * Execute the deadline-tracking pass.
     *
     * @param mixed $argument The job argument (unused).
     *
     * @return void
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#4.2
     */
    protected function run($argument): void
    {
        if ($this->appConfig->getValueString(Application::APP_ID, 'register', '') === '') {
            return;
        }

        $now = new DateTimeImmutable();

        try {
            $reminders   = $this->tracker->sendReminders(now: $now);
            $escalations = $this->tracker->checkEscalations(now: $now);
            $breaches    = $this->tracker->checkBreaches(now: $now);

            $this->logger->info(
                'AvgDeadlineTrackerJob: completed',
                ['reminders' => $reminders, 'escalations' => $escalations, 'breaches' => $breaches]
            );
        } catch (\Throwable $e) {
            $this->logger->error('AvgDeadlineTrackerJob: error', ['exception' => $e->getMessage()]);
        }
    }//end run()
}//end class
