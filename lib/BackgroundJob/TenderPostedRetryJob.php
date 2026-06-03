<?php

/**
 * Pipelinq TenderPostedRetryJob.
 *
 * Background job that re-emits the per-tender GL CloudEvent for settled POS
 * tenders whose first emission did not reach a consumer (e.g. shillinq was
 * briefly unavailable on settlement). Tenders carry a glPosted flag and a
 * glPostAttempts counter; this job re-posts the unposted ones under a configurable
 * attempt cap, soft-failing afterwards. Consumer-side idempotency (dedup by the
 * CloudEvents id) prevents duplicate GL entries on retry.
 *
 * @category BackgroundJob
 * @package  OCA\Pipelinq\BackgroundJob
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/pos-split-tender/tasks.md#6.3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\PosPaymentService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Re-posts GL CloudEvents for unposted settled tenders.
 *
 * Runs every 5 minutes by default (admin-tunable via
 * `pipelinq.tender_gl_retry.*`). Caps per-tender attempts at
 * DEFAULT_MAX_ATTEMPTS so a permanently-unavailable consumer does not retry
 * forever.
 *
 * @spec openspec/changes/pos-split-tender/tasks.md#6.3
 */
class TenderPostedRetryJob extends TimedJob
{
    /**
     * Default poll interval in seconds (5 minutes) when unconfigured.
     *
     * @var int
     */
    private const DEFAULT_INTERVAL = 300;

    /**
     * Default per-tender attempt cap when unconfigured.
     *
     * @var int
     */
    private const DEFAULT_MAX_ATTEMPTS = 10;

    /**
     * Constructor.
     *
     * @param ITimeFactory      $time           The time factory.
     * @param IAppConfig        $appConfig      The app config.
     * @param PosPaymentService $paymentService The POS payment service.
     * @param LoggerInterface   $logger         The logger.
     */
    public function __construct(
        ITimeFactory $time,
        private IAppConfig $appConfig,
        private PosPaymentService $paymentService,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
        $this->setInterval(
            seconds: $this->appConfig->getValueInt(
                Application::APP_ID,
                'tender_gl_retry.poll_interval_seconds',
                self::DEFAULT_INTERVAL
            )
        );
    }//end __construct()

    /**
     * Run one retry sweep across all unposted settled tenders.
     *
     * @param mixed $argument The job argument (unused).
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) $argument is part of the
     *  TimedJob::run() contract; this job is driven solely by its interval.
     *
     * @spec openspec/changes/pos-split-tender/tasks.md#6.3
     */
    protected function run(mixed $argument): void
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'posTender_schema', '');

        if ($register === '' || $schema === '') {
            $this->logger->debug('TenderPostedRetryJob: no register or posTender schema configured, skipping');
            return;
        }

        $maxAttempts = $this->appConfig->getValueInt(
            Application::APP_ID,
            'tender_gl_retry.max_attempts',
            self::DEFAULT_MAX_ATTEMPTS
        );

        $reposted = $this->paymentService->retryAllUnpostedTenders(maxAttempts: $maxAttempts);

        if ($reposted > 0) {
            $this->logger->info('TenderPostedRetryJob: re-posted tender GL events', ['count' => $reposted]);
        }
    }//end run()
}//end class
