<?php

/**
 * Pipelinq AutomationDispatchJob.
 *
 * One-shot queued background job that runs the CRM automations matching a fired
 * trigger. Enqueued by ObjectEventHandlerService after an entity save so the
 * save response is never delayed by automation execution (REQ-NFR-001). The job
 * payload carries the trigger type, the entity id and a snapshot of the entity
 * data; execution and logging are delegated to AutomationService /
 * MarketingSequenceService.
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
 * @spec openspec/changes/crm-workflow-automation/tasks.md#task-4.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\Service\AutomationService;
use OCA\Pipelinq\Service\MarketingSequenceService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Queued job that executes automations matched to a CRM trigger.
 *
 * Runs out-of-band on the next cron tick. All failures are caught and logged so
 * a single bad automation never blocks the queue.
 *
 * @spec openspec/changes/crm-workflow-automation/tasks.md#task-4.1
 */
class AutomationDispatchJob extends QueuedJob
{
    /**
     * Constructor.
     *
     * @param ITimeFactory             $time              The time factory.
     * @param AutomationService        $automationService The automation engine.
     * @param MarketingSequenceService $marketingService  The marketing sequencer.
     * @param LoggerInterface          $logger            The logger.
     */
    public function __construct(
        ITimeFactory $time,
        private AutomationService $automationService,
        private MarketingSequenceService $marketingService,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
    }//end __construct()

    /**
     * Execute the queued automation dispatch.
     *
     * @param mixed $argument The job argument: trigger, entityId, entityData.
     *
     * @return void
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-4.1
     */
    protected function run(mixed $argument): void
    {
        if (is_array($argument) === false) {
            return;
        }

        $trigger    = (string) ($argument['trigger'] ?? '');
        $entityId   = (string) ($argument['entityId'] ?? '');
        $entityData = ($argument['entityData'] ?? []);
        if (is_array($entityData) === false) {
            $entityData = [];
        }

        if ($trigger === '') {
            return;
        }

        try {
            $count = $this->automationService->runTrigger(
                trigger: $trigger,
                entityId: $entityId,
                entityData: $entityData
            );

            // Contact / lead saves additionally feed the marketing sequencer.
            if (in_array($trigger, ['contact_created', 'lead_created', 'lead_stage_changed'], true) === true) {
                $count += $this->marketingService->evaluateAndRun(
                    entityId: $entityId,
                    entityData: $entityData
                );
            }

            if ($count > 0) {
                $this->logger->info(
                    'Pipelinq: dispatched automations',
                    ['trigger' => $trigger, 'count' => $count]
                );
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                'Pipelinq: automation dispatch failed',
                ['trigger' => $trigger, 'exception' => $e->getMessage()]
            );
        }//end try
    }//end run()
}//end class
