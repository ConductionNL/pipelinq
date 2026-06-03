<?php

/**
 * Pipelinq InitSlaStatus.
 *
 * Repair step that backfills the embedded slaStatus on existing tracked objects
 * (request, complaint) that predate the SLA engine.
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
 * @link https://codeberg.org/Conduction/pipelinq
 *
 * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\SlaTrackingService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Initialises slaStatus on pre-existing tracked objects (REQ-001).
 *
 * Idempotent: objects that already carry an slaStatus are skipped, so running
 * the step twice neither duplicates nor overwrites. Objects with no matching
 * policy are left untouched (the sweep job reconciles them once a policy
 * exists). Batched to avoid migration timeouts.
 *
 * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-001
 */
class InitSlaStatus implements IRepairStep
{
    /**
     * Page size for backfill paging.
     *
     * @var int
     */
    private const PAGE_SIZE = 100;

    /**
     * Constructor.
     *
     * @param IAppManager        $appManager The app manager.
     * @param IAppConfig         $appConfig  The app configuration.
     * @param ContainerInterface $container  The container.
     * @param LoggerInterface    $logger     The logger.
     */
    public function __construct(
        private readonly IAppManager $appManager,
        private readonly IAppConfig $appConfig,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the repair step name.
     *
     * @return string The name.
     *
     * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-001
     */
    public function getName(): string
    {
        return 'Initialize SLA status on existing Pipelinq request and complaint objects';
    }//end getName()

    /**
     * Run the backfill.
     *
     * @param IOutput $output The output interface.
     *
     * @return void
     *
     * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-001
     */
    public function run(IOutput $output): void
    {
        if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
            $output->warning('OpenRegister not installed -- skipping SLA status initialization');
            return;
        }

        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        if ($register === '') {
            $output->warning('Pipelinq register not configured -- skipping SLA status initialization');
            return;
        }

        try {
            $tracking = $this->container->get(SlaTrackingService::class);
        } catch (Throwable $e) {
            $this->logger->error('InitSlaStatus: tracking service unavailable', ['exception' => $e->getMessage()]);
            return;
        }

        $initialised = 0;
        foreach (['request' => 'request_schema', 'complaint' => 'complaint_schema'] as $type => $schemaKey) {
            $schema = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
            if ($schema === '') {
                continue;
            }

            $initialised += $this->backfillType(
                tracking: $tracking,
                objectType: $type,
                register: $register,
                schema: $schema,
                output: $output
            );
        }

        $output->info("SLA status initialized on {$initialised} object(s)");
        $this->logger->info('InitSlaStatus: backfill complete', ['initialised' => $initialised]);
    }//end run()

    /**
     * Backfill one tracked object type, paging through its objects.
     *
     * @param SlaTrackingService $tracking   The tracking service.
     * @param string             $objectType The tracked object type.
     * @param string             $register   The register id.
     * @param string             $schema     The schema id.
     * @param IOutput            $output     The output interface.
     *
     * @return int The number of objects initialised.
     */
    private function backfillType(
        SlaTrackingService $tracking,
        string $objectType,
        string $register,
        string $schema,
        IOutput $output
    ): int {
        $initialised = 0;
        $offset      = 0;

        for ($page = 0; $page < 10000; $page++) {
            try {
                $items = $this->objectService()->findAll(
                    [
                        'filters' => ['register' => $register, 'schema' => $schema],
                        'limit'   => self::PAGE_SIZE,
                        'offset'  => $offset,
                    ]
                );
            } catch (Throwable $e) {
                $output->warning("Failed to page {$objectType} objects: ".$e->getMessage());
                break;
            }

            if (count($items) === 0) {
                break;
            }

            foreach ($items as $item) {
                $initialised += $this->backfillObject(tracking: $tracking, objectType: $objectType, object: $item);
            }

            if (count($items) < self::PAGE_SIZE) {
                break;
            }

            $offset += self::PAGE_SIZE;
        }//end for

        return $initialised;
    }//end backfillType()

    /**
     * Backfill SLA status onto a single object (idempotent).
     *
     * @param SlaTrackingService $tracking   The tracking service.
     * @param string             $objectType The tracked object type.
     * @param mixed              $object     The raw object (entity or array).
     *
     * @return int 1 when the object was initialised, 0 otherwise.
     */
    private function backfillObject(SlaTrackingService $tracking, string $objectType, mixed $object): int
    {
        $data = $this->toArray(object: $object);
        if (is_array(($data['slaStatus'] ?? null)) === true) {
            // Already tracked — idempotent skip.
            return 0;
        }

        $objectId = (string) ($data['id'] ?? ($data['@self']['id'] ?? ''));
        if ($objectId === '') {
            return 0;
        }

        try {
            if ($tracking->onCreated(objectType: $objectType, objectId: $objectId, data: $data) !== null) {
                return 1;
            }
        } catch (Throwable $e) {
            $this->logger->warning('InitSlaStatus: object init failed', ['objectId' => $objectId, 'exception' => $e->getMessage()]);
        }

        return 0;
    }//end backfillObject()

    /**
     * Lazily resolve the OpenRegister ObjectService.
     *
     * @return object The ObjectService instance.
     */
    private function objectService(): object
    {
        return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
    }//end objectService()

    /**
     * Normalise an ObjectEntity-or-array to a plain array.
     *
     * @param mixed $object The raw object.
     *
     * @return array<string, mixed> The array form.
     */
    private function toArray(mixed $object): array
    {
        if (is_array($object) === true) {
            return $object;
        }

        if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
            $serialised = $object->jsonSerialize();
            if (is_array($serialised) === true) {
                return $serialised;
            }
        }

        return [];
    }//end toArray()
}//end class
