<?php

/**
 * Pipelinq MarketingSequenceService.
 *
 * Service that evaluates segment conditions and orchestrates marketing
 * automation sequences for matched contacts/leads.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/crm-workflow-automation/tasks.md#task-2.4
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service that drives marketing-automation sequences.
 *
 * Uses AutomationService for the condition logic and execution, and queries
 * automationLog via ObjectService for the 24-hour deduplication check.
 *
 * @spec openspec/changes/crm-workflow-automation/tasks.md#task-2.4
 */
class MarketingSequenceService
{
    private const DEDUPE_WINDOW_SECONDS = 86400;

    /**
     * Constructor.
     *
     * @param ContainerInterface $container         DI container (lazy OpenRegister lookup).
     * @param IAppConfig         $appConfig         App config.
     * @param AutomationService  $automationService Underlying automation engine.
     * @param LoggerInterface    $logger            Logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private AutomationService $automationService,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Evaluate marketing segment conditions against entity data (AND, case-insensitive).
     *
     * Delegates to AutomationService::evaluateTriggerConditions so segment
     * and trigger evaluation share one code path.
     *
     * @param array $segmentConditions Conditions keyed by field.
     * @param array $entity            Entity data snapshot.
     *
     * @return bool True when ALL conditions match.
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-2.4
     */
    public function evaluateSegment(array $segmentConditions, array $entity): bool
    {
        return $this->automationService->evaluateTriggerConditions($segmentConditions, $entity);
    }//end evaluateSegment()

    /**
     * Enqueue the action sequence for an automation+entity pair.
     *
     * Skips execution when an automationLog already exists for the same
     * (automation, entity) pair within the dedupe window (REQ-MKT-004).
     *
     * @param string $automationId Automation slug or UUID.
     * @param string $entityId     Entity slug or UUID.
     *
     * @return void
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-2.4
     */
    public function enqueueSequence(string $automationId, string $entityId): void
    {
        if ($this->wasRecentlyFired($automationId, $entityId) === true) {
            $this->logger->debug(
                'MarketingSequenceService.enqueueSequence skipped (dedupe window)',
                ['automation' => $automationId, 'entity' => $entityId]
            );
            return;
        }

        $automation = $this->automationService->findAutomation($automationId);
        if ($automation === null) {
            return;
        }

        $results = $this->automationService->executeAutomation($automation, ['id' => $entityId]);
        $this->automationService->logExecution(
            $automationId,
            $entityId,
            ['actionsExecuted' => $results, 'status' => 'success']
        );
    }//end enqueueSequence()

    /**
     * Execute the next single step of an in-progress sequence and schedule the next.
     *
     * @param string $automationId Automation ID.
     * @param string $entityId     Entity ID.
     * @param int    $stepIndex    Zero-based index of the step to execute.
     *
     * @return void
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-2.4
     */
    public function executeNextStep(string $automationId, string $entityId, int $stepIndex): void
    {
        $automation = $this->automationService->findAutomation($automationId);
        if ($automation === null) {
            return;
        }
        $actions = ($automation['actions'] ?? []);
        if (is_array($actions) === false || isset($actions[$stepIndex]) === false) {
            return;
        }
        $action = $actions[$stepIndex];
        if (is_array($action) === false) {
            return;
        }
        $type   = (string) ($action['type'] ?? '');
        if ($type === '') {
            return;
        }
        $result = $this->automationService->dispatchAction($type, $action, ['id' => $entityId]);
        $this->automationService->logExecution(
            $automationId,
            $entityId,
            ['actionsExecuted' => [$result], 'status' => ($result['result'] ?? 'success')]
        );
    }//end executeNextStep()

    /**
     * Check whether an automation already fired for an entity within the dedupe window.
     *
     * @param string $automationId Automation slug or UUID.
     * @param string $entityId     Entity slug or UUID.
     *
     * @return bool True when a matching log within DEDUPE_WINDOW_SECONDS exists.
     */
    private function wasRecentlyFired(string $automationId, string $entityId): bool
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'automationLog_schema', '');
        if ($register === '' || $schema === '') {
            return false;
        }
        try {
            $rows = $this->getObjectService()->findAll([
                'register' => $register,
                'schema'   => $schema,
                'filters'  => [
                    'automation'    => $automationId,
                    'triggerEntity' => $entityId,
                ],
                'limit'    => 25,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'MarketingSequenceService.wasRecentlyFired query failed',
                ['exception' => $e->getMessage()]
            );
            return false;
        }

        $cutoff = (time() - self::DEDUPE_WINDOW_SECONDS);
        foreach ($rows as $row) {
            $data = $row;
            if (is_object($row) === true && method_exists($row, 'getObject') === true) {
                $candidate = $row->getObject();
                if (is_array($candidate) === true) {
                    $data = $candidate;
                }
            }
            if (is_array($data) === false) {
                continue;
            }
            $when = strtotime((string) ($data['triggeredAt'] ?? ''));
            if ($when !== false && $when >= $cutoff) {
                return true;
            }
        }
        return false;
    }//end wasRecentlyFired()

    /**
     * Resolve the OpenRegister ObjectService.
     *
     * @return object The ObjectService instance.
     *
     * @throws \RuntimeException When OpenRegister is not available.
     */
    private function getObjectService(): object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            throw new \RuntimeException('OpenRegister ObjectService is not available.');
        }
    }//end getObjectService()
}//end class
