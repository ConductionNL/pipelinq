<?php

/**
 * Pipelinq ForecastSnapshotJob.
 *
 * Weekly background job that generates immutable forecast snapshots for every
 * rep, team, division and the company for the open fiscal period.
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
 * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-004-01
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\SnapshotGenerationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Timed job that drives weekly forecast snapshot generation.
 *
 * Cron resolution is coarse, so the job runs hourly and only acts on the
 * configured generation day (default Monday) at or after the configured hour
 * (default 06:00) in the org timezone, generating at most one snapshot set per
 * day per period.
 */
class ForecastSnapshotJob extends TimedJob
{
    /**
     * App-config key for the generation weekday (1=Mon … 7=Sun, ISO-8601).
     *
     * @var string
     */
    public const DAY_KEY = 'forecast_generation_day';

    /**
     * App-config key for the generation hour (0-23).
     *
     * @var string
     */
    public const HOUR_KEY = 'forecast_generation_hour';

    /**
     * App-config key for the generation timezone.
     *
     * @var string
     */
    public const TIMEZONE_KEY = 'forecast_generation_timezone';

    /**
     * App-config key tracking the last generation date (yyyy-mm-dd).
     *
     * @var string
     */
    public const LAST_RUN_KEY = 'forecast_last_generated_date';

    /**
     * Constructor.
     *
     * @param ITimeFactory              $time              The time factory.
     * @param SnapshotGenerationService $generationService The snapshot generation service.
     * @param IAppConfig                $appConfig         The app configuration.
     * @param LoggerInterface           $logger            The logger.
     */
    public function __construct(
        ITimeFactory $time,
        private SnapshotGenerationService $generationService,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);

        // Evaluate hourly; the run guard enforces the weekly Monday 06:00 cadence.
        $this->setInterval(seconds: 3600);
        $this->setTimeSensitivity(sensitivity: self::TIME_SENSITIVE);
    }//end __construct()

    /**
     * Execute the job.
     *
     * @param mixed $argument The job argument (unused, required by TimedJob).
     *
     * @return void
     *
     * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-004-01
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function run($argument): void
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'forecastSnapshot_schema', '');
        if ($register === '' || $schema === '') {
            $this->logger->debug('ForecastSnapshotJob: skipping — register or forecastSnapshot_schema not configured');
            return;
        }

        $now = $this->orgNow();
        if ($this->shouldRun(now: $now) === false) {
            return;
        }

        $today = $now->format('Y-m-d');
        $this->logger->info('ForecastSnapshotJob: generating forecast snapshots', ['date' => $today]);

        try {
            $summary = $this->generationService->generate(asOf: $now);
            $this->appConfig->setValueString(Application::APP_ID, self::LAST_RUN_KEY, $today);
            $this->logger->info('ForecastSnapshotJob: completed', $summary);
        } catch (Exception $e) {
            $this->logger->error('ForecastSnapshotJob: generation failed', ['exception' => $e->getMessage()]);
        }
    }//end run()

    /**
     * Whether the job should generate now (correct weekday/hour, not already run today).
     *
     * @param DateTimeImmutable $now The current time in the org timezone.
     *
     * @return bool True when generation should proceed.
     */
    private function shouldRun(DateTimeImmutable $now): bool
    {
        $day  = $this->appConfig->getValueInt(Application::APP_ID, self::DAY_KEY, 1);
        $hour = $this->appConfig->getValueInt(Application::APP_ID, self::HOUR_KEY, 6);

        if ((int) $now->format('N') !== $day) {
            return false;
        }

        if ((int) $now->format('G') < $hour) {
            return false;
        }

        $lastRun = $this->appConfig->getValueString(Application::APP_ID, self::LAST_RUN_KEY, '');
        return $lastRun !== $now->format('Y-m-d');
    }//end shouldRun()

    /**
     * The current time in the org-configured timezone.
     *
     * @return DateTimeImmutable The current time.
     */
    private function orgNow(): DateTimeImmutable
    {
        $tz  = $this->appConfig->getValueString(Application::APP_ID, self::TIMEZONE_KEY, 'UTC');
        $now = (new DateTimeImmutable())->setTimestamp($this->time->getTime());
        if ($tz === '') {
            $tz = 'UTC';
        }

        try {
            return $now->setTimezone(new DateTimeZone($tz));
        } catch (\Exception $e) {
            return $now->setTimezone(new DateTimeZone('UTC'));
        }
    }//end orgNow()
}//end class
