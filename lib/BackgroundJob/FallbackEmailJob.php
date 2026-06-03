<?php

/**
 * Pipelinq FallbackEmailJob.
 *
 * Timed job (daily) that emails the fallback copy of any Berichtenbox message
 * that has remained unread for 5 Dutch working days, as mandated by BBK 1.7
 * Art. 3.5. Exceptions are logged and never abort the run.
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
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#REQ-FALLBACK-004
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\BerichtenboxService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends 5-working-day unread fallback emails once per day.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class FallbackEmailJob extends TimedJob
{
    /**
     * Constructor.
     *
     * @param ITimeFactory        $time                The time factory.
     * @param BerichtenboxService $berichtenboxService The core service.
     * @param IAppConfig          $appConfig           The app config.
     * @param LoggerInterface     $logger              The logger.
     */
    public function __construct(
        ITimeFactory $time,
        private BerichtenboxService $berichtenboxService,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
        // Run once per day (86400s); Nextcloud schedules the actual time of day.
        $this->setInterval(seconds: 86400);
        $this->setTimeSensitivity(sensitivity: self::TIME_INSENSITIVE);
    }//end __construct()

    /**
     * Execute the job.
     *
     * @param mixed $argument The job argument (unused).
     *
     * @return void
     */
    protected function run($argument): void
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'berichtenboxMessage_schema', '');
        if ($register === '' || $schema === '') {
            $this->logger->debug('FallbackEmailJob: skipping — Berichtenbox not configured');
            return;
        }

        try {
            $sent = $this->berichtenboxService->processFallbackQueue();
            $this->logger->info('FallbackEmailJob: fallback run complete', ['sent' => $sent]);
        } catch (Throwable $e) {
            $this->logger->error('FallbackEmailJob: fallback run failed', ['exception' => $e->getMessage()]);
        }
    }//end run()
}//end class
