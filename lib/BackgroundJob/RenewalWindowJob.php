<?php

/**
 * Pipelinq RenewalWindowJob.
 *
 * Nightly sweep: evaluates every contract for its renewal window. Flips
 * `active` contracts entering their window to `expiring` (creating exactly one
 * renewal lead), reconciles won/lost renewal leads, churns silently-expired
 * contracts, and creates notice-deadline My Work entries. Idempotent across
 * runs (guarded by renewalLeadRef / renewalLeadOutcome / noticeReminderSent).
 *
 * Registered via appinfo/info.xml <background-jobs> (the valid bootstrap
 * pattern — NOT IRegistrationContext::registerJob, per the 2026-06 jobs sweep).
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
 * @spec openspec/specs/contract-renewal-tracking/spec.md#requirement-renewal-window-detection
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\Service\ContractService;
use OCA\Pipelinq\Service\RenewalEngineService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Nightly renewal-window evaluation job (runs every 24h).
 *
 * @spec openspec/specs/contract-renewal-tracking/spec.md#requirement-renewal-window-detection
 * @spec openspec/specs/contract-renewal-tracking/spec.md#requirement-renewal-lead-automation
 */
class RenewalWindowJob extends TimedJob
{
    /**
     * Constructor.
     *
     * @param ITimeFactory         $time            Time factory.
     * @param ContractService      $contractService Contract lifecycle service.
     * @param RenewalEngineService $engine          Renewal engine.
     * @param LoggerInterface      $logger          PSR logger.
     */
    public function __construct(
        ITimeFactory $time,
        private ContractService $contractService,
        private RenewalEngineService $engine,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
        // Daily; renewal windows are evaluated on a calendar-day granularity.
        $this->setInterval(seconds: 86400);
        $this->setTimeSensitivity(sensitivity: self::TIME_INSENSITIVE);
    }//end __construct()

    /**
     * Run the renewal-window sweep.
     *
     * @param mixed $argument Job argument (unused).
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * @spec openspec/specs/contract-renewal-tracking/spec.md#requirement-renewal-window-detection
     */
    protected function run($argument): void
    {
        unset($argument);

        $contracts = $this->contractService->loadAll();
        if (empty($contracts) === true) {
            return;
        }

        $today       = date('Y-m-d');
        $transitions = 0;

        foreach ($contracts as $contract) {
            try {
                // First reconcile any expiring contract whose renewal lead resolved.
                if ((string) ($contract['status'] ?? '') === 'expiring'
                    && ((string) ($contract['renewalLeadOutcome'] ?? '')) !== ''
                ) {
                    $action = $this->engine->reconcile($contract);
                    if ($action !== 'noop') {
                        $transitions++;
                    }

                    continue;
                }

                $action = $this->engine->processContract($contract, $today);
                if ($action !== 'noop') {
                    $transitions++;
                }
            } catch (Throwable $e) {
                $this->logger->error(
                    'RenewalWindowJob: contract processing failed',
                    [
                        'contract' => ($contract['id'] ?? ''),
                        'error'    => $e->getMessage(),
                    ]
                );
            }//end try
        }//end foreach

        if ($transitions > 0) {
            $this->logger->info('RenewalWindowJob: processed contracts', ['transitions' => $transitions]);
        }
    }//end run()
}//end class
