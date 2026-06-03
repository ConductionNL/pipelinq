<?php

/**
 * Pipelinq DispatchQueuedMessagesJob.
 *
 * Timed job (every 5 minutes) that dispatches queued Berichtenbox messages:
 * resolves each citizen's mailbox, delivers via Logius or falls back to email,
 * and applies the retry backoff. Exceptions are logged and never abort the run.
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
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#REQ-OUTBOUND-001
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
 * Dispatches queued Berichtenbox messages every 5 minutes.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class DispatchQueuedMessagesJob extends TimedJob
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
        $this->setInterval(seconds: 300);
        $this->setTimeSensitivity(sensitivity: self::TIME_SENSITIVE);
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
            $this->logger->debug('DispatchQueuedMessagesJob: skipping — Berichtenbox not configured');
            return;
        }

        try {
            $processed = $this->berichtenboxService->dispatchQueuedMessages();
            $this->logger->info('DispatchQueuedMessagesJob: dispatch run complete', ['processed' => $processed]);
        } catch (Throwable $e) {
            $this->logger->error('DispatchQueuedMessagesJob: dispatch run failed', ['exception' => $e->getMessage()]);
        }
    }//end run()
}//end class
