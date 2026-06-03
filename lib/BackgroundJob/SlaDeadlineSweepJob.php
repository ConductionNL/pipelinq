<?php

/**
 * Pipelinq SlaDeadlineSweepJob.
 *
 * Scheduled job that detects SLA deadline crossings which did not coincide with
 * an object event (the clock simply ran out) and fires the escalation chain.
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
 * @link https://codeberg.org/Conduction/pipelinq
 *
 * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-008
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\SlaTrackingService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Timed job that reconciles in-flight SLA objects (REQ-008).
 *
 * Runs every 5 minutes by default (configurable 60-1800s). It pages through the
 * SLA-tracked registers, reconciles each in-flight object (status on-track or
 * at-risk) whose deadline may have crossed, and persists status changes in
 * batches. Escalations are idempotent — already-fired levels are recorded in the
 * slaStatus and never re-fire, so re-runs are safe.
 *
 * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-008
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class SlaDeadlineSweepJob extends TimedJob
{
    /**
     * Default sweep interval in seconds.
     *
     * @var int
     */
    private const DEFAULT_INTERVAL = 300;

    /**
     * Page size for paging through tracked objects.
     *
     * @var int
     */
    private const PAGE_SIZE = 100;

    /**
     * Statuses considered in-flight (still consuming an SLA target).
     *
     * @var array<int, string>
     */
    private const IN_FLIGHT = ['on-track', 'at-risk'];

    /**
     * Constructor.
     *
     * @param ITimeFactory       $time        The time factory.
     * @param SlaTrackingService $slaTracking The SLA tracking orchestrator.
     * @param IAppConfig         $appConfig   The app configuration.
     * @param ContainerInterface $container   Container for ObjectService lookup.
     * @param LoggerInterface    $logger      The logger.
     */
    public function __construct(
        ITimeFactory $time,
        private SlaTrackingService $slaTracking,
        private IAppConfig $appConfig,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);

        $interval = $this->appConfig->getValueInt(Application::APP_ID, 'sla_sweep_interval', self::DEFAULT_INTERVAL);
        $interval = max(60, min(1800, $interval));
        $this->setInterval(seconds: $interval);
        $this->setTimeSensitivity(sensitivity: self::TIME_SENSITIVE);
    }//end __construct()

    /**
     * Execute the sweep.
     *
     * @param mixed $argument The job argument (unused).
     *
     * @return void
     */
    protected function run($argument): void
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        if ($register === '') {
            $this->logger->debug('SlaDeadlineSweepJob: register not configured, skipping');
            return;
        }

        $types      = $this->trackedTypes();
        $processed  = 0;
        $reconciled = 0;

        foreach ($types as $objectType) {
            $schema = $this->schemaFor(objectType: $objectType);
            if ($schema === '') {
                continue;
            }

            [$swept, $changed] = $this->sweepType(objectType: $objectType, register: $register, schema: $schema);
            $processed        += $swept;
            $reconciled       += $changed;
        }

        $this->logger->info(
            'SlaDeadlineSweepJob: completed',
            ['processed' => $processed, 'reconciled' => $reconciled]
        );
    }//end run()

    /**
     * Sweep a single tracked object type, paging through its objects.
     *
     * @param string $objectType The tracked object type.
     * @param string $register   The register id.
     * @param string $schema     The schema id.
     *
     * @return array{0: int, 1: int} [processed, reconciled] counts.
     */
    private function sweepType(string $objectType, string $register, string $schema): array
    {
        $processed  = 0;
        $reconciled = 0;
        $offset     = 0;

        // Cap pages to keep within the 60s budget even on pathological sizes.
        for ($page = 0; $page < 1000; $page++) {
            $items = $this->fetchPage(register: $register, schema: $schema, offset: $offset);
            if (count($items) === 0) {
                break;
            }

            foreach ($items as $item) {
                $data = $this->toArray(object: $item);
                if ($this->isInFlight(data: $data) === false) {
                    continue;
                }

                $processed++;
                $objectId = (string) ($data['id'] ?? ($data['@self']['id'] ?? ''));
                if ($objectId === '') {
                    continue;
                }

                try {
                    // Reconcile (evaluate + escalate + persist) any status
                    // changes. The tracking service is server-authoritative and
                    // idempotent; already-fired escalation levels never re-fire.
                    if ($this->slaTracking->reconcile(objectType: $objectType, objectId: $objectId, data: $data) !== null) {
                        $reconciled++;
                    }
                } catch (Throwable $e) {
                    // Partial failure: log and continue; next run retries.
                    $this->logger->warning(
                        'SlaDeadlineSweepJob: object reconcile failed',
                        ['objectType' => $objectType, 'objectId' => $objectId, 'exception' => $e->getMessage()]
                    );
                }
            }//end foreach

            if (count($items) < self::PAGE_SIZE) {
                break;
            }

            $offset += self::PAGE_SIZE;
        }//end for

        return [$processed, $reconciled];
    }//end sweepType()

    /**
     * Fetch one page of objects for a register/schema.
     *
     * @param string $register The register id.
     * @param string $schema   The schema id.
     * @param int    $offset   The page offset.
     *
     * @return array<int, mixed> The page of raw objects.
     */
    private function fetchPage(string $register, string $schema, int $offset): array
    {
        try {
            return $this->objectService()->findAll(
                [
                    'filters' => ['register' => $register, 'schema' => $schema],
                    'limit'   => self::PAGE_SIZE,
                    'offset'  => $offset,
                ]
            );
        } catch (Throwable $e) {
            $this->logger->error('SlaDeadlineSweepJob: fetch failed', ['exception' => $e->getMessage()]);
            return [];
        }
    }//end fetchPage()

    /**
     * Whether an object has at least one in-flight SLA target.
     *
     * @param array<string, mixed> $data The object data.
     *
     * @return bool True when a target is on-track or at-risk.
     */
    private function isInFlight(array $data): bool
    {
        $slaStatus = ($data['slaStatus'] ?? null);
        if (is_array($slaStatus) === false) {
            return false;
        }

        if (($slaStatus['pausedAt'] ?? null) !== null) {
            return false;
        }

        foreach (($slaStatus['targets'] ?? []) as $target) {
            if (in_array(($target['status'] ?? ''), self::IN_FLIGHT, true) === true) {
                return true;
            }
        }

        return false;
    }//end isInFlight()

    /**
     * The configured SLA-tracked object types.
     *
     * @return array<int, string> The tracked types.
     */
    private function trackedTypes(): array
    {
        $configured = $this->appConfig->getValueString(Application::APP_ID, 'sla_tracked_types', 'request,complaint');
        return array_values(array_filter(array_map('trim', explode(',', $configured))));
    }//end trackedTypes()

    /**
     * Resolve the schema id for a tracked object type.
     *
     * @param string $objectType The tracked object type.
     *
     * @return string The schema id, or empty when unconfigured.
     */
    private function schemaFor(string $objectType): string
    {
        $map = ['request' => 'request_schema', 'complaint' => 'complaint_schema'];
        $key = ($map[$objectType] ?? ($objectType.'_schema'));
        return $this->appConfig->getValueString(Application::APP_ID, $key, '');
    }//end schemaFor()

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
