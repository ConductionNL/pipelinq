<?php

/**
 * Pipelinq BrpMonitorJob.
 *
 * Daily service-monitor job (REQ-BSN-010): aggregates the last 24h of BSN audit
 * records into a report (lookups, cache-hit ratio, error rate, average response
 * time) via {@see BrpMonitorService}, persists it to app config for the admin
 * BRP-Monitor tile, and logs an elevated-error-rate warning past the threshold.
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
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#3.4
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\BrpMonitorService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Generates and stores the daily BRP service-health report.
 *
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#3.4
 */
class BrpMonitorJob extends TimedJob
{
    /**
     * Run interval in seconds (daily).
     *
     * @var int
     */
    private const INTERVAL = 86400;

    /**
     * Error-rate alert threshold (percent).
     *
     * @var float
     */
    private const ERROR_THRESHOLD = 10.0;

    /**
     * App-config key holding the latest serialized report.
     *
     * @var string
     */
    private const REPORT_KEY = 'brp.monitor_report';

    /**
     * Constructor.
     *
     * @param ITimeFactory      $time      The time factory.
     * @param IAppConfig        $appConfig The app config.
     * @param BrpMonitorService $monitor   The monitor aggregation service.
     * @param LoggerInterface   $logger    The logger.
     */
    public function __construct(
        ITimeFactory $time,
        private IAppConfig $appConfig,
        private BrpMonitorService $monitor,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
        $this->setInterval(seconds: self::INTERVAL);
    }//end __construct()

    /**
     * Aggregate the last 24h and persist the report.
     *
     * @param mixed $argument The job argument (unused).
     *
     * @return void
     *
     * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#3.4
     */
    protected function run(mixed $argument): void
    {
        $since = (new DateTimeImmutable('-24 hours'))->format(DateTimeInterface::ATOM);

        try {
            $report = $this->monitor->report($since);
        } catch (\Throwable $e) {
            $this->logger->warning('BrpMonitorJob: aggregation failed', ['exception' => $e->getMessage()]);
            return;
        }

        $report['generatedAt'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
        $this->appConfig->setValueString(Application::APP_ID, self::REPORT_KEY, (string) json_encode($report));

        if ((float) ($report['errorRate'] ?? 0) > self::ERROR_THRESHOLD) {
            $this->logger->warning(
                'BrpMonitorJob: BRP error rate exceeded threshold',
                ['errorRate' => $report['errorRate'], 'lookups' => $report['lookups']]
            );
        }

        $this->logger->info('BrpMonitorJob: report generated', ['report' => $report]);
    }//end run()
}//end class
