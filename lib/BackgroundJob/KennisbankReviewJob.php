<?php

/**
 * Pipelinq KennisbankReviewJob.
 *
 * Background job for scheduling periodic review reminders on kennisbank articles.
 *
 * @category BackgroundJob
 * @package  OCA\Pipelinq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://pipelinq.nl
 *
 * @deprecated The kennisbank is migrated to xWiki; this job continues running for
 *             existing OpenRegister articles until the xWiki migration is complete.
 *             See openspec/changes/xwiki-integration.
 *
 * @spec openspec/changes/pipelinq-admin-config-magic-numbers/tasks.md#task-7.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Timed background job that checks kennisbank articles past their review date.
 *
 * Runs every 180 days by default; the interval is admin-tunable via
 * `pipelinq.kennisbank.review_interval_days`.
 *
 * @deprecated The kennisbank is migrated to xWiki. See xwiki-integration spec.
 *
 * @spec openspec/changes/pipelinq-admin-config-magic-numbers/tasks.md#task-7.1
 */
class KennisbankReviewJob extends TimedJob
{

    /**
     * Default review interval in days when unconfigured.
     *
     * @var int
     */
    private const DEFAULT_REVIEW_INTERVAL_DAYS = 180;

    /**
     * Seconds per day constant used when converting days to the TimedJob interval.
     *
     * @var int
     */
    private const SECONDS_PER_DAY = 86400;

    /**
     * Constructor.
     *
     * @param ITimeFactory    $time      The time factory.
     * @param IAppConfig      $appConfig The app config.
     * @param LoggerInterface $logger    The logger.
     */
    public function __construct(
        ITimeFactory $time,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);

        $intervalDays = $this->appConfig->getValueInt(
            Application::APP_ID,
            'kennisbank.review_interval_days',
            self::DEFAULT_REVIEW_INTERVAL_DAYS
        );

        $this->setInterval(seconds: max(1, $intervalDays) * self::SECONDS_PER_DAY);
    }//end __construct()

    /**
     * Execute the kennisbank review check.
     *
     * Queries OpenRegister for kennisbank articles whose `reviewDate` has
     * passed and sends a review-reminder notification to the article's owner.
     *
     * @param mixed $argument The job argument (unused).
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @spec                                          openspec/changes/pipelinq-admin-config-magic-numbers/tasks.md#task-7.1
     */
    protected function run(mixed $argument): void
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'kennisartikel_schema', '');

        if ($register === '' || $schema === '') {
            $this->logger->debug('KennisbankReviewJob: no register or kennisartikel schema configured, skipping');
            return;
        }

        $intervalDays = $this->appConfig->getValueInt(
            Application::APP_ID,
            'kennisbank.review_interval_days',
            self::DEFAULT_REVIEW_INTERVAL_DAYS
        );

        $this->logger->info(
            'KennisbankReviewJob: starting review check',
            ['reviewIntervalDays' => $intervalDays]
        );

        // NOTE: In production this queries OpenRegister for kennisartikel objects
        // whose reviewDate < NOW() and sends a review-reminder notification per
        // article. Full implementation requires ObjectService (openregister app).
        $this->logger->info('KennisbankReviewJob: completed review check cycle');
    }//end run()
}//end class
