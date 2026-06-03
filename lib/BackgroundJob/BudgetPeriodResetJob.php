<?php

/**
 * Pipelinq BudgetPeriodResetJob.
 *
 * Daily job that resets elapsed message-send budget period counters.
 *
 * @category BackgroundJob
 * @package  OCA\Pipelinq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-5.4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use DateTimeImmutable;
use OCA\Pipelinq\Service\BudgetService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Resets `messageSendBudget` period counters whose `periodResetAt` has elapsed.
 *
 * Runs daily; the actual reset (and `periodResetAt` advance) is delegated to
 * {@see BudgetService::resetElapsedPeriods()} so the boundary logic is fully
 * unit-tested (REQ-006).
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-5.4
 */
class BudgetPeriodResetJob extends TimedJob
{
    /**
     * Interval in seconds (1 day).
     *
     * @var int
     */
    private const INTERVAL = 86400;

    /**
     * Constructor.
     *
     * @param ITimeFactory    $time          The time factory.
     * @param BudgetService   $budgetService The budget service.
     * @param LoggerInterface $logger        The logger.
     */
    public function __construct(
        ITimeFactory $time,
        private BudgetService $budgetService,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
        $this->setInterval(seconds: self::INTERVAL);
    }//end __construct()

    /**
     * Run the budget period reset.
     *
     * @param mixed $argument The job argument (unused).
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) — $argument is required by the TimedJob signature
     * @spec                                          openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-5.4
     */
    protected function run(mixed $argument): void
    {
        $now   = new DateTimeImmutable('@'.$this->time->getTime());
        $reset = $this->budgetService->resetElapsedPeriods(now: $now);
        if ($reset > 0) {
            $this->logger->info('BudgetPeriodResetJob: reset '.$reset.' budget period(s).');
        }
    }//end run()
}//end class
