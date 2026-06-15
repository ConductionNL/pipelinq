<?php

/**
 * Pipelinq SyncQueueService.
 *
 * Outbound sync queue to downstream apps (Shillinq, Procest, Scholiq,
 * OpenCatalogi, Decidesk). Creates sync-queue-items on master-entity changes
 * and merges, processes the queue via OpenRegister's WebhookService delivery,
 * applies exponential-backoff retries, records confirmation callbacks and
 * moves exhausted items to dead-letter.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Mdm
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/master-data-management/specs.md#REQ-MDM-006
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Mdm;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use OCP\EventDispatcher\Event;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for the outbound downstream sync queue.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class SyncQueueService
{
    /**
     * The syncQueueItem schema slug.
     *
     * @var string
     */
    public const SCHEMA = 'syncQueueItem';

    /**
     * CloudEvent name webhooks subscribe to for MDM sync delivery.
     *
     * @var string
     */
    public const EVENT_SYNC = 'pipelinq.mdm.sync';

    /**
     * Exponential-backoff intervals in seconds, indexed by attemptCount.
     * 1m, 5m, 30m, 2h, 12h, 24h, 24h → ~7 days cumulative across 7 attempts.
     *
     * @var array<int, int>
     */
    public const BACKOFF_SECONDS = [60, 300, 1800, 7200, 43200, 86400, 86400];

    /**
     * Maximum delivery attempts before dead-letter.
     *
     * @var int
     */
    public const MAX_ATTEMPTS = 7;

    /**
     * Default number of items processed per queue run.
     *
     * @var int
     */
    public const DEFAULT_BATCH = 50;

    /**
     * Priority assigned per change type (higher delivered first).
     *
     * @var array<string, float>
     */
    private const PRIORITY = [
        'merge'         => 100.0,
        'reverse-merge' => 100.0,
        'soft-delete'   => 90.0,
        'create'        => 50.0,
        'update'        => 10.0,
    ];

    /**
     * Constructor.
     *
     * @param MdmObjectRepository $repository The MDM object repository.
     * @param ContainerInterface  $container  The DI container (WebhookService).
     * @param LoggerInterface     $logger     The logger.
     */
    public function __construct(
        private MdmObjectRepository $repository,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Enqueue a sync item for a downstream app.
     *
     * @param string               $masterEntityId The affected master entity.
     * @param string               $targetSystem   The downstream app.
     * @param string               $changeType     The change type.
     * @param array<string, mixed> $payload        The payload to transmit.
     *
     * @return array<string, mixed> The created sync-queue-item.
     */
    public function enqueueSync(
        string $masterEntityId,
        string $targetSystem,
        string $changeType,
        array $payload
    ): array {
        $now  = $this->repository->now();
        $item = [
            'id'           => $this->repository->uuid(),
            'masterEntity' => $masterEntityId,
            'targetSystem' => $targetSystem,
            'changeType'   => $changeType,
            'payload'      => $payload,
            'status'       => 'queued',
            'attemptCount' => 0,
            'nextRetryAt'  => $now,
            'priority'     => ($this->priorityFor(changeType: $changeType)),
        ];

        return $this->repository->save(self::SCHEMA, $item);
    }//end enqueueSync()

    /**
     * Priority for a change type.
     *
     * @param string $changeType The change type.
     *
     * @return float The priority.
     */
    public function priorityFor(string $changeType): float
    {
        return (self::PRIORITY[$changeType] ?? 1.0);
    }//end priorityFor()

    /**
     * Compute the next-retry timestamp for a given attempt count (pure).
     *
     * The interval for the Nth failed attempt is BACKOFF_SECONDS[N-1], so the
     * first failure waits 1m, the second 5m, and so on.
     *
     * @param int    $attemptCount The attempt count just consumed (1-based).
     * @param string $from         The reference timestamp (ISO 8601).
     *
     * @return string The next-retry timestamp.
     */
    public function nextRetryAt(int $attemptCount, string $from): string
    {
        $index   = min(($attemptCount - 1), (count(self::BACKOFF_SECONDS) - 1));
        $index   = max(0, $index);
        $seconds = self::BACKOFF_SECONDS[$index];

        try {
            $base = new DateTimeImmutable($from);
        } catch (Exception $e) {
            $base = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        }

        return $base->add(new DateInterval('PT'.$seconds.'S'))->format('Y-m-d\TH:i:s\Z');
    }//end nextRetryAt()

    /**
     * Process due queued items (status=queued and nextRetryAt in the past).
     *
     * @param int $batchSize The maximum items to process this run.
     *
     * @return array{processed: int, acknowledged: int, failed: int, deadLetter: int}
     */
    public function processQueue(int $batchSize=self::DEFAULT_BATCH): array
    {
        $now   = $this->repository->now();
        $items = $this->repository->findAll(self::SCHEMA, ['status' => 'queued']);

        // Filter to due items and order by priority DESC, then nextRetryAt ASC.
        $due = array_values(
            array_filter(
                $items,
                static fn (array $queued): bool => ((string) ($queued['nextRetryAt'] ?? '') <= $now)
            )
        );
        usort(
            $due,
            static function (array $a, array $b): int {
                $cmp = (((float) ($b['priority'] ?? 0)) <=> ((float) ($a['priority'] ?? 0)));
                if ($cmp !== 0) {
                    return $cmp;
                }

                return ((string) ($a['nextRetryAt'] ?? '') <=> (string) ($b['nextRetryAt'] ?? ''));
            }
        );

        $stats = ['processed' => 0, 'acknowledged' => 0, 'failed' => 0, 'deadLetter' => 0];
        foreach (array_slice($due, 0, $batchSize) as $item) {
            $result = $this->deliver(item: $item);
            $stats['processed']++;

            $bucket = 'failed';
            if ($result === 'acknowledged') {
                $bucket = 'acknowledged';
            }

            if ($result === 'dead-letter') {
                $bucket = 'deadLetter';
            }

            $stats[$bucket]++;
        }

        return $stats;
    }//end processQueue()

    /**
     * Attempt delivery of a single item and persist the outcome.
     *
     * @param array<string, mixed> $item The sync-queue-item.
     *
     * @return string The resulting status: acknowledged | queued | dead-letter.
     */
    private function deliver(array $item): string
    {
        $id   = (string) ($item['id'] ?? ($item['uuid'] ?? ''));
        $uuid = $this->nullableId(id: $id);
        $now  = $this->repository->now();

        $item['status']        = 'sending';
        $item['lastAttemptAt'] = $now;

        try {
            $reference = $this->dispatch(item: $item);

            $item['status']         = 'acknowledged';
            $item['acknowledgedAt'] = $now;
            $item['acknowledgmentReference'] = $reference;
            $this->repository->save(schemaSlug: self::SCHEMA, object: $item, uuid: $uuid);
            return 'acknowledged';
        } catch (\Throwable $e) {
            $attempt = ((int) ($item['attemptCount'] ?? 0)) + 1;
            $item['attemptCount'] = $attempt;
            $item['errorMessage'] = $e->getMessage();

            if ($attempt >= self::MAX_ATTEMPTS) {
                $item['status'] = 'dead-letter';
                $this->repository->save(schemaSlug: self::SCHEMA, object: $item, uuid: $uuid);
                $this->notifyDeadLetter(item: $item);
                return 'dead-letter';
            }

            $item['status']      = 'queued';
            $item['nextRetryAt'] = $this->nextRetryAt(attemptCount: $attempt, from: $now);
            $this->repository->save(schemaSlug: self::SCHEMA, object: $item, uuid: $uuid);
            return 'queued';
        }//end try
    }//end deliver()

    /**
     * Normalise an empty id to null so save() generates a fresh uuid.
     *
     * @param string $id The candidate id.
     *
     * @return string|null The id, or null when empty.
     */
    private function nullableId(string $id): ?string
    {
        if ($id === '') {
            return null;
        }

        return $id;
    }//end nullableId()

    /**
     * Dispatch the item to the target system via OR WebhookService.
     *
     * @param array<string, mixed> $item The sync-queue-item.
     *
     * @return string The acknowledgment reference.
     *
     * @throws \RuntimeException When delivery fails or no consumer exists.
     */
    private function dispatch(array $item): string
    {
        $webhookService = $this->container->get('OCA\OpenRegister\Service\WebhookService');
        $event          = new Event();
        $payload        = [
            'targetSystem' => ($item['targetSystem'] ?? ''),
            'changeType'   => ($item['changeType'] ?? ''),
            'masterEntity' => ($item['masterEntity'] ?? ''),
            'payload'      => ($item['payload'] ?? []),
        ];

        $response = $webhookService->dispatchEvent(
            _event: $event,
            eventName: self::EVENT_SYNC,
            payload: $payload
        );

        if (is_array($response) === true && isset($response['acknowledgmentReference']) === true) {
            return (string) $response['acknowledgmentReference'];
        }

        return 'pipelinq-mdm-'.($item['id'] ?? '');
    }//end dispatch()

    /**
     * Manually re-queue an item (admin retry), resetting attempt state.
     *
     * @param string $itemId The item uuid.
     *
     * @return array<string, mixed>|null The updated item, or null if absent.
     */
    public function retryItem(string $itemId): ?array
    {
        $item = $this->repository->find(self::SCHEMA, $itemId);
        if ($item === null) {
            return null;
        }

        $item['status']       = 'queued';
        $item['attemptCount'] = 0;
        $item['nextRetryAt']  = $this->repository->now();
        $item['errorMessage'] = '';

        return $this->repository->save(self::SCHEMA, $item, $itemId);
    }//end retryItem()

    /**
     * Force an item to dead-letter (admin / engine use).
     *
     * @param string $itemId The item uuid.
     * @param string $reason The dead-letter reason.
     *
     * @return array<string, mixed>|null The updated item, or null if absent.
     */
    public function markDeadLetter(string $itemId, string $reason=''): ?array
    {
        $item = $this->repository->find(self::SCHEMA, $itemId);
        if ($item === null) {
            return null;
        }

        $item['status']       = 'dead-letter';
        $item['errorMessage'] = $reason;
        $saved = $this->repository->save(self::SCHEMA, $item, $itemId);
        $this->notifyDeadLetter(item: $saved);

        return $saved;
    }//end markDeadLetter()

    /**
     * Record an acknowledgment callback from the target system.
     *
     * @param string $itemId    The item uuid.
     * @param string $reference The acknowledgment reference from the target.
     *
     * @return array<string, mixed>|null The updated item, or null if absent.
     */
    public function acknowledge(string $itemId, string $reference): ?array
    {
        $item = $this->repository->find(self::SCHEMA, $itemId);
        if ($item === null) {
            return null;
        }

        $item['status']         = 'acknowledged';
        $item['acknowledgedAt'] = $this->repository->now();
        $item['acknowledgmentReference'] = $reference;

        return $this->repository->save(self::SCHEMA, $item, $itemId);
    }//end acknowledge()

    /**
     * List sync-queue-items, optionally filtered by status and target system.
     *
     * @param string|null $status       Optional status filter.
     * @param string|null $targetSystem Optional target-system filter.
     *
     * @return array<int, array<string, mixed>> The matching items.
     */
    public function listItems(?string $status=null, ?string $targetSystem=null): array
    {
        $filters = [];
        if ($status !== null && $status !== '') {
            $filters['status'] = $status;
        }

        if ($targetSystem !== null && $targetSystem !== '') {
            $filters['targetSystem'] = $targetSystem;
        }

        return $this->repository->findAll(self::SCHEMA, $filters);
    }//end listItems()

    /**
     * Log a dead-letter item for admin attention.
     *
     * @param array<string, mixed> $item The dead-lettered item.
     *
     * @return void
     */
    private function notifyDeadLetter(array $item): void
    {
        $this->logger->error(
            'Pipelinq MDM: sync item moved to dead-letter',
            [
                'target'     => ($item['targetSystem'] ?? ''),
                'changeType' => ($item['changeType'] ?? ''),
                'master'     => ($item['masterEntity'] ?? ''),
                'error'      => ($item['errorMessage'] ?? ''),
            ]
        );
    }//end notifyDeadLetter()
}//end class
