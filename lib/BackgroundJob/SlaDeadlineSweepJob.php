<?php

/**
 * Pipelinq SlaDeadlineSweepJob.
 *
 * Scheduled sweep job: catches deadline crossings that did not
 * coincide with an object update event. Re-evaluates target statuses
 * and fires escalations idempotently.
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
 * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-008
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\SlaEngineService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sweep job: every N seconds (default 300s, configurable 60-1800s),
 * walk all in-flight tracked objects with slaStatus set, re-evaluate
 * their targets, and fire any escalation chains whose thresholds are
 * newly crossed.
 *
 * Idempotent: per-object `currentEscalationLevel` prevents duplicate
 * firings across runs.
 */
class SlaDeadlineSweepJob extends TimedJob
{
    private const BATCH_SIZE = 100;

    private const TRACKED_SCHEMA_KEYS = [
        'request_schema',
        'complaint_schema',
        'callback_schema',
    ];

    /**
     * Constructor.
     *
     * @param ITimeFactory       $time      Time factory.
     * @param SlaEngineService   $engine    SLA engine.
     * @param ContainerInterface $container DI container (OR ObjectService).
     * @param IAppConfig         $appConfig App config.
     * @param LoggerInterface    $logger    PSR logger.
     */
    public function __construct(
        ITimeFactory $time,
        private SlaEngineService $engine,
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
        $configured = (int) $this->appConfig->getValueString(
            Application::APP_ID,
            'sla_sweep_interval_seconds',
            '300',
        );
        if ($configured < 60) {
            $configured = 60;
        } else if ($configured > 1800) {
            $configured = 1800;
        }

        $this->setInterval(seconds: $configured);
        $this->setTimeSensitivity(sensitivity: self::TIME_SENSITIVE);
    }//end __construct()

    /**
     * Run the sweep.
     *
     * @param mixed $argument Job argument (unused).
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function run($argument): void
    {
        unset($argument);

        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        if ($register === '') {
            $this->logger->debug('SlaDeadlineSweepJob: register not configured, skipping');
            return;
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (Throwable $e) {
            $this->logger->warning(
                'SlaDeadlineSweepJob: ObjectService unavailable',
                ['error' => $e->getMessage()]
            );
            return;
        }

        $startTime = microtime(true);
        $processed = 0;
        $escalated = 0;
        $now       = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        try {
            $policies = $this->indexPolicies();
        } catch (Throwable $e) {
            $this->logger->error(
                'SlaDeadlineSweepJob: failed to index policies',
                ['error' => $e->getMessage()]
            );
            return;
        }

        foreach (self::TRACKED_SCHEMA_KEYS as $schemaConfigKey) {
            $schemaId = $this->appConfig->getValueString(Application::APP_ID, $schemaConfigKey, '');
            if ($schemaId === '') {
                continue;
            }

            $type   = $this->schemaTypeFromKey(key: $schemaConfigKey);
            $offset = 0;
            do {
                try {
                    $rows = $objectService->findAll(
                        config: [
                            'register' => $register,
                            'schema'   => $schemaId,
                            'limit'    => self::BATCH_SIZE,
                            'offset'   => $offset,
                        ]
                    );
                } catch (Throwable $e) {
                    $this->logger->warning(
                        'SlaDeadlineSweepJob: findAll failed',
                        ['error' => $e->getMessage(), 'schema' => $schemaId]
                    );
                    break;
                }

                if (is_array($rows) === false) {
                    $rows = iterator_to_array($rows);
                }

                $count = count($rows);
                if ($count === 0) {
                    break;
                }

                foreach ($rows as $entity) {
                    $processed++;
                    $fired = $this->processEntity(
                        entity: $entity,
                        type: $type,
                        policies: $policies,
                        now: $now,
                        register: $register,
                        schemaId: $schemaId,
                        objectService: $objectService
                    );
                    if ($fired === true) {
                        $escalated++;
                    }
                }

                $offset += self::BATCH_SIZE;
                // Safety bound: don't exceed 5000 objects per schema per run.
                if ($offset >= 5000) {
                    break;
                }
            } while ($count === self::BATCH_SIZE);
        }//end foreach

        $elapsed = (microtime(true) - $startTime);
        $this->logger->info(
            'SlaDeadlineSweepJob: completed',
            ['processed' => $processed, 'escalated' => $escalated, 'elapsedSeconds' => round($elapsed, 3)]
        );
    }//end run()

    /**
     * Process a single tracked-object row.
     *
     * @param mixed                               $entity        Object entity.
     * @param string                              $type          Tracked object type.
     * @param array<string, array<string, mixed>> $policies      Policy index by identity.
     * @param DateTimeInterface                   $now           Now.
     * @param string                              $register      Register UUID.
     * @param string                              $schemaId      Schema UUID.
     * @param object                              $objectService OR ObjectService.
     *
     * @return bool True when an escalation fired (for run stats).
     */
    private function processEntity(
        $entity,
        string $type,
        array $policies,
        DateTimeInterface $now,
        string $register,
        string $schemaId,
        object $objectService,
    ): bool {
        try {
            if (is_object($entity) === true && method_exists($entity, 'getObject') === true) {
                $data = $entity->getObject();
            } else {
                $data = (array) $entity;
            }

            if (is_object($entity) === true && method_exists($entity, 'getUuid') === true) {
                $uuid = (string) $entity->getUuid();
            } else {
                $uuid = (string) ($data['uuid'] ?? $data['id'] ?? '');
            }

            $slaStatus = $data['slaStatus'] ?? null;
            if (is_array($slaStatus) === false || ($slaStatus['policyId'] ?? '') === '') {
                return false;
            }

            // Skip paused timers.
            if (($slaStatus['pausedAt'] ?? null) !== null) {
                return false;
            }

            $policy = $policies[(string) $slaStatus['policyId']] ?? null;
            if ($policy === null) {
                return false;
            }

            $previousLevel        = (int) ($slaStatus['currentEscalationLevel'] ?? 0);
            $slaStatus['targets'] = $this->engine->evaluateTargets($slaStatus['targets'] ?? [], $policy, $now);

            $result = $this->engine->executeEscalations(
                $policy,
                $type,
                $uuid,
                $slaStatus['targets'],
                $slaStatus['targets'],
                $previousLevel,
            );

            $escalated = ($result['level'] > $previousLevel);
            if ($escalated === true) {
                $slaStatus['currentEscalationLevel'] = $result['level'];
                foreach ($slaStatus['targets'] as $idx => $target) {
                    $slaStatus['targets'][$idx]['breachEventIds'] = array_values(
                            array_unique(
                            array_merge(
                        (array) ($target['breachEventIds'] ?? []),
                        $result['eventIds']
                            )
                            )
                            );
                }
            }

            $slaStatus['lastEvaluatedAt'] = $now->format(DateTimeInterface::ATOM);
            $data['slaStatus']            = $slaStatus;

            if ($uuid !== '') {
                $objectService->saveObject(
                    object: $data,
                    extend: [],
                    register: $register,
                    schema: $schemaId,
                    uuid: $uuid,
                );
            }

            return $escalated;
        } catch (Throwable $e) {
            $this->logger->warning(
                'SlaDeadlineSweepJob: per-object processing failed',
                ['error' => $e->getMessage()]
            );
            return false;
        }//end try
    }//end processEntity()

    /**
     * Build an index of policies by their identity for O(1) lookup.
     *
     * @return array<string, array<string, mixed>> Index.
     */
    private function indexPolicies(): array
    {
        $index = [];
        foreach ($this->engine->loadActivePolicies() as $policy) {
            $key = $this->engine->policyIdentity($policy);
            if ($key !== '') {
                $index[$key] = $policy;
            }
        }

        return $index;
    }//end indexPolicies()

    /**
     * Map a schema-config-key back to its tracked object type.
     *
     * @param string $key The config key (e.g. request_schema).
     *
     * @return string Tracked object type slug.
     */
    private function schemaTypeFromKey(string $key): string
    {
        return match ($key) {
            'request_schema'   => 'request',
            'complaint_schema' => 'klacht',
            'callback_schema'  => 'callback',
            default            => 'unknown',
        };
    }//end schemaTypeFromKey()
}//end class
