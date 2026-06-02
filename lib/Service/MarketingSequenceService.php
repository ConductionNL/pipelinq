<?php

/**
 * Pipelinq MarketingSequenceService.
 *
 * Marketing-automation sequencer: evaluates segment conditions against a
 * contact / lead and runs the matched automation's ordered action sequence,
 * with a 24-hour per (contact + automation) deduplication guard so the same
 * sequence does not re-fire on every save. Segment evaluation is
 * case-insensitive AND logic; dedup is derived from the append-only
 * automationLog, never from client state.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
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

use DateTimeImmutable;
use RuntimeException;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Trigger-based marketing automation: segment matching + sequenced actions.
 *
 * Reuses AutomationService for the actual action dispatch and logging; this
 * service adds the marketing-specific segment evaluation and the 24-hour
 * deduplication that distinguishes a marketing sequence from a one-shot CRM
 * automation. All reads/writes are scoped to this app's register/schema.
 *
 * @spec openspec/changes/crm-workflow-automation/tasks.md#task-2.4
 */
class MarketingSequenceService
{
    /**
     * Deduplication window in seconds (24 hours).
     *
     * @var int
     */
    private const DEDUP_WINDOW_SECONDS = 86400;

    /**
     * The marketing-segment trigger type.
     *
     * @var string
     */
    public const TRIGGER = 'marketing_segment_match';

    /**
     * Constructor.
     *
     * @param ContainerInterface $container         The DI container (OR ObjectService).
     * @param IAppConfig         $appConfig         The app config.
     * @param AutomationService  $automationService The automation engine.
     * @param LoggerInterface    $logger            The logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private AutomationService $automationService,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Check whether an entity matches a segment's conditions.
     *
     * ALL conditions must be satisfied (AND logic); a partial match does not
     * qualify. String comparison is case-insensitive. An empty condition set
     * matches every entity.
     *
     * @param array<string, mixed> $segmentConditions The segment condition map.
     * @param array<string, mixed> $entity            The contact / lead data.
     *
     * @return bool Whether the entity is in the segment.
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-2.4
     */
    public function evaluateSegment(array $segmentConditions, array $entity): bool
    {
        // Reuse the engine's condition evaluator so segment + trigger semantics
        // never diverge (single source of truth for AND / case-insensitive).
        return $this->automationService->evaluateTriggerConditions(
            conditions: $segmentConditions,
            entity: $entity
        );
    }//end evaluateSegment()

    /**
     * Evaluate every active marketing automation for an entity and run matches.
     *
     * For each active marketing_segment_match automation whose segment the
     * entity is in, the sequence is enqueued (subject to the 24-hour dedup
     * guard). Returns the number of sequences actually started.
     *
     * @param string               $entityId   The contact / lead UUID.
     * @param array<string, mixed> $entityData The contact / lead data.
     *
     * @return int The number of sequences started.
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-2.4
     */
    public function evaluateAndRun(string $entityId, array $entityData): int
    {
        $automations = $this->fetchMarketingAutomations();

        $started = 0;
        foreach ($automations as $automation) {
            if ((bool) ($automation['isActive'] ?? false) !== true) {
                continue;
            }

            $conditions = ($automation['triggerConditions'] ?? []);
            if (is_array($conditions) === false) {
                $conditions = [];
            }

            if ($this->evaluateSegment(segmentConditions: $conditions, entity: $entityData) === false) {
                continue;
            }

            $automationId = (string) ($automation['id'] ?? $automation['uuid'] ?? '');
            if ($this->enqueueSequence(automationId: $automationId, entityId: $entityId, entityData: $entityData) === true) {
                $started++;
            }
        }//end foreach

        return $started;
    }//end evaluateAndRun()

    /**
     * Schedule (run) a marketing automation's sequence for a matched entity.
     *
     * Applies the 24-hour deduplication guard: if the same (automation + entity)
     * pair already has a log entry within the window, the sequence is skipped
     * and false is returned. Otherwise the automation's actions run and a log is
     * written, and true is returned.
     *
     * @param string               $automationId The automation UUID.
     * @param string               $entityId     The matched entity UUID.
     * @param array<string, mixed> $entityData   The matched entity data.
     *
     * @return bool Whether the sequence was started (false when deduplicated).
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-2.4
     */
    public function enqueueSequence(string $automationId, string $entityId, array $entityData=[]): bool
    {
        if ($automationId === '' || $entityId === '') {
            return false;
        }

        if ($this->wasRecentlyTriggered(automationId: $automationId, entityId: $entityId) === true) {
            $this->logger->debug(
                'Pipelinq: marketing sequence skipped (deduplicated)',
                ['automation' => $automationId, 'entity' => $entityId]
            );
            return false;
        }

        $automation = $this->fetchAutomation(id: $automationId);
        if ($automation === []) {
            return false;
        }

        $result = $this->automationService->executeAutomation(automation: $automation, entityData: $entityData);
        $this->automationService->logExecution(
            automationId: $automationId,
            entityId: $entityId,
            result: $result,
            triggerData: $entityData
        );

        return true;
    }//end enqueueSequence()

    /**
     * Check whether (automation + entity) fired within the dedup window.
     *
     * @param string $automationId The automation UUID.
     * @param string $entityId     The entity UUID.
     *
     * @return bool Whether a recent log entry exists.
     */
    private function wasRecentlyTriggered(string $automationId, string $entityId): bool
    {
        [$register, $schema] = $this->scope(schemaKey: 'automationLog_schema');

        try {
            $results = $this->getObjectService()->findAll(
                config: [
                    'filters' => [
                        'register'      => $register,
                        'schema'        => $schema,
                        'automation'    => $automationId,
                        'triggerEntity' => $entityId,
                    ],
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Pipelinq: dedup lookup failed', ['exception' => $e->getMessage()]);
            return false;
        }

        $cutoff = (new DateTimeImmutable())->getTimestamp() - self::DEDUP_WINDOW_SECONDS;
        foreach (($results ?? []) as $result) {
            $log         = $this->toArray(object: $result);
            $triggeredAt = (string) ($log['triggeredAt'] ?? '');
            if ($triggeredAt === '') {
                continue;
            }

            $timestamp = strtotime($triggeredAt);
            if ($timestamp !== false && $timestamp >= $cutoff) {
                return true;
            }
        }//end foreach

        return false;
    }//end wasRecentlyTriggered()

    /**
     * Fetch all marketing automations (trigger = marketing_segment_match).
     *
     * @return array<int, array<string, mixed>> The marketing automations.
     */
    private function fetchMarketingAutomations(): array
    {
        [$register, $schema] = $this->scope(schemaKey: 'automation_schema');

        try {
            $results = $this->getObjectService()->findAll(
                config: [
                    'filters' => [
                        'register' => $register,
                        'schema'   => $schema,
                        'trigger'  => self::TRIGGER,
                    ],
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Pipelinq: failed to fetch marketing automations', ['exception' => $e->getMessage()]);
            return [];
        }

        $automations = [];
        foreach (($results ?? []) as $result) {
            $automations[] = $this->toArray(object: $result);
        }

        return $automations;
    }//end fetchMarketingAutomations()

    /**
     * Fetch a single automation by id, scoped to this app.
     *
     * @param string $id The automation UUID.
     *
     * @return array<string, mixed> The automation, or an empty array.
     */
    private function fetchAutomation(string $id): array
    {
        [$register, $schema] = $this->scope(schemaKey: 'automation_schema');

        try {
            $object = $this->getObjectService()->find(id: $id, register: $register, schema: $schema);
        } catch (\Throwable $e) {
            $object = null;
        }

        if ($object === null) {
            return [];
        }

        return $this->toArray(object: $object);
    }//end fetchAutomation()

    /**
     * Resolve the register + a schema config key into their stored IDs.
     *
     * @param string $schemaKey The app-config schema key.
     *
     * @return array{0: string, 1: string} The [register, schema] IDs.
     *
     * @throws RuntimeException If the register or schema is not configured.
     */
    private function scope(string $schemaKey): array
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');

        if ($register === '' || $schema === '') {
            throw new RuntimeException('Automation register or schema is not configured.');
        }

        return [$register, $schema];
    }//end scope()

    /**
     * Get the OpenRegister ObjectService.
     *
     * @return object The object service.
     *
     * @throws RuntimeException If OpenRegister is not available.
     */
    private function getObjectService(): object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            throw new RuntimeException('OpenRegister service is not available.');
        }
    }//end getObjectService()

    /**
     * Normalise an OR object (entity or array) into a plain array.
     *
     * @param mixed $object The OR object.
     *
     * @return array<string, mixed> The object as an array.
     */
    private function toArray(mixed $object): array
    {
        if (is_array($object) === true) {
            return $object;
        }

        if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
            $serialized = $object->jsonSerialize();
            if (is_array($serialized) === true) {
                return $serialized;
            }
        }

        if (is_object($object) === true && method_exists($object, 'getObject') === true) {
            $data = $object->getObject();
            if (is_array($data) === true) {
                return $data;
            }
        }

        return (array) $object;
    }//end toArray()
}//end class
