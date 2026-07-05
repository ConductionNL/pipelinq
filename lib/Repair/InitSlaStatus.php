<?php

/**
 * Pipelinq InitSlaStatus.
 *
 * Repair step that initialises `slaStatus` on existing tracked
 * objects (request / complaint) so post-upgrade SLA reporting can
 * include legacy rows. Idempotent: rows that already carry an
 * `slaStatus.policyId` are left untouched.
 *
 * @category Repair
 * @package  OCA\Pipelinq\Repair
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\SlaEngineService;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Repair step: bootstrap slaStatus on tracked objects.
 *
 * Walks request + complaint registers, picks rows without slaStatus,
 * resolves the most-specific active policy, computes deadlines and
 * persists the snapshot. Logs every initialised object via the
 * `OCA\Pipelinq` logger.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class InitSlaStatus implements IRepairStep
{
    private const BATCH_SIZE = 250;

    /**
     * Constructor.
     *
     * @param SlaEngineService   $engine    SLA engine.
     * @param ContainerInterface $container DI container.
     * @param IAppConfig         $appConfig App config.
     * @param LoggerInterface    $logger    PSR logger.
     */
    public function __construct(
        private SlaEngineService $engine,
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the repair step name.
     *
     * @return string Name.
     */
    public function getName(): string
    {
        return 'Initialise SLA tracking (slaStatus) on existing tracked objects';
    }//end getName()

    /**
     * Run the repair.
     *
     * @param IOutput $output Output.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Batched pagination over three tracked schema
     *   types with per-batch and per-object fault tolerance; the per-object logic is extracted to
     *   initialiseObject(), leaving only the loop/paging scaffold here.
     * @SuppressWarnings(PHPMD.NPathComplexity)      Same rationale: the nested paging loop and its
     *   independent guard clauses inflate the theoretical path count.
     */
    public function run(IOutput $output): void
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        if ($register === '') {
            $output->info('InitSlaStatus: pipelinq register not configured, skipping');
            return;
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (Throwable $e) {
            $output->warning('InitSlaStatus: OpenRegister ObjectService unavailable, skipping ('.$e->getMessage().')');
            return;
        }

        $now       = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $totalInit = 0;
        $totalSkip = 0;

        foreach (['request_schema' => 'request', 'complaint_schema' => 'klacht', 'callback_schema' => 'callback'] as $configKey => $type) {
            $schemaId = $this->appConfig->getValueString(Application::APP_ID, $configKey, '');
            if ($schemaId === '') {
                continue;
            }

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
                        'InitSlaStatus: findAll failed',
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

                foreach ($rows as $row) {
                    try {
                        $written = $this->initialiseObject(
                            row: $row,
                            type: $type,
                            register: $register,
                            schemaId: $schemaId,
                            objectService: $objectService,
                            fallbackStart: $now,
                        );
                    } catch (Throwable $e) {
                        $this->logger->warning(
                            'InitSlaStatus: per-object failed',
                            ['error' => $e->getMessage()]
                        );
                        $totalSkip++;
                        continue;
                    }//end try

                    if ($written === true) {
                        $totalInit++;
                        continue;
                    }

                    $totalSkip++;
                }//end foreach

                $offset += self::BATCH_SIZE;
                if ($offset >= 10000) {
                    break;
                }
            } while ($count === self::BATCH_SIZE);
        }//end foreach

        $output->info(sprintf('InitSlaStatus: initialised=%d, skipped=%d', $totalInit, $totalSkip));
        $this->logger->info(
            'InitSlaStatus: completed',
            ['initialised' => $totalInit, 'skipped' => $totalSkip]
        );
    }//end run()

    /**
     * Initialise slaStatus on a single tracked object.
     *
     * Skips objects with no UUID, an already-initialised slaStatus, or no matching
     * policy; otherwise writes the initial status and persists the object.
     *
     * @param mixed             $row           OR row (entity or array).
     * @param string            $type          SLA object type (request/klacht/callback).
     * @param string            $register      Register id.
     * @param string            $schemaId      Schema id.
     * @param object            $objectService OR ObjectService.
     * @param DateTimeImmutable $fallbackStart Fallback start instant when the object has none.
     *
     * @return bool True when a status was written, false when the object was skipped.
     */
    private function initialiseObject(
        mixed $row,
        string $type,
        string $register,
        string $schemaId,
        object $objectService,
        DateTimeImmutable $fallbackStart,
    ): bool {
        $data = (array) $row;
        if (is_object($row) === true && method_exists($row, 'getObject') === true) {
            $data = $row->getObject();
        }

        $uuid = (string) ($data['uuid'] ?? $data['id'] ?? '');
        if (is_object($row) === true && method_exists($row, 'getUuid') === true) {
            $uuid = (string) $row->getUuid();
        }

        if ($uuid === '') {
            return false;
        }

        $slaStatus = $data['slaStatus'] ?? null;
        if (is_array($slaStatus) === true && ($slaStatus['policyId'] ?? '') !== '') {
            return false;
        }

        $metadata = [
            'tier'           => (string) ($data['slaTier'] ?? $data['customerTier'] ?? ''),
            'organisationId' => (string) ($data['organisationId'] ?? $data['client'] ?? ''),
            'contractId'     => (string) ($data['contractId'] ?? ''),
        ];

        $policy = $this->engine->resolvePolicyForObject($type, $uuid, $metadata);
        if ($policy === null) {
            return false;
        }

        $startedAt         = $this->parseStarted(data: $data) ?? $fallbackStart;
        $data['slaStatus'] = $this->engine->initialiseStatus($policy, $startedAt);

        $objectService->saveObject(
            object: $data,
            extend: [],
            register: $register,
            schema: $schemaId,
            uuid: $uuid,
        );

        return true;
    }//end initialiseObject()

    /**
     * Parse the object's created/started timestamp.
     *
     * @param array<string, mixed> $data Row data.
     *
     * @return ?DateTimeImmutable Start instant, or null on missing/invalid.
     */
    private function parseStarted(array $data): ?DateTimeImmutable
    {
        foreach (['createdAt', 'created', '@self.createdAt', 'opened_at'] as $key) {
            if (isset($data[$key]) === false) {
                continue;
            }

            try {
                return new DateTimeImmutable((string) $data[$key]);
            } catch (Throwable $e) {
                continue;
            }
        }

        return null;
    }//end parseStarted()
}//end class
