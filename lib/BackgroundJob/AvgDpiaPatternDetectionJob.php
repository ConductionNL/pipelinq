<?php

/**
 * Pipelinq AvgDpiaPatternDetectionJob.
 *
 * Weekly timed job that runs the DPIA pattern analysis: when many similar AVG
 * requests (same article + scope) arrive within the rolling 30-day window the
 * matching requests are flagged for DPIA review and the FG is informed, with an
 * optional linked Procest improvement item (REQ-AVG-010).
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
 * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#4.3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use DateTimeImmutable;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Avg\DpiaDetectionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Weekly timed job for DPIA pattern detection.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter) TimedJob::run requires the
 *  $argument parameter even though this job takes no per-run argument.
 *
 * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#4.3
 */
class AvgDpiaPatternDetectionJob extends TimedJob
{
    /**
     * Constructor.
     *
     * @param ITimeFactory         $time      The time factory.
     * @param DpiaDetectionService $detection The DPIA detection service.
     * @param IAppConfig           $appConfig The app config.
     * @param LoggerInterface      $logger    The logger.
     */
    public function __construct(
        ITimeFactory $time,
        private DpiaDetectionService $detection,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
        // Run weekly (604800 seconds).
        $this->setInterval(seconds: 604800);
    }//end __construct()

    /**
     * Execute the DPIA pattern detection pass.
     *
     * @param mixed $argument The job argument (unused).
     *
     * @return void
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#4.3
     */
    protected function run($argument): void
    {
        if ($this->appConfig->getValueString(Application::APP_ID, 'register', '') === '') {
            return;
        }

        $now = new DateTimeImmutable();

        try {
            $patterns = $this->detection->detectPatterns(now: $now);
            $flagged  = $this->detection->analyzeAndFlag(now: $now);
            foreach ($patterns as $pattern) {
                $this->detection->linkToProcest(pattern: $pattern);
            }

            $this->logger->info(
                'AvgDpiaPatternDetectionJob: completed',
                ['patterns' => count($patterns), 'flagged' => $flagged]
            );
        } catch (\Throwable $e) {
            $this->logger->error('AvgDpiaPatternDetectionJob: error', ['exception' => $e->getMessage()]);
        }
    }//end run()
}//end class
