<?php

/**
 * Pipelinq QueueService.
 *
 * Service for queue operations: capacity checks, overflow routing, and item assignment.
 *
 * The queued items are request tickets: `request` is a subtype of the unified
 * `ticket` schema, resolved through {@see TicketService} with a `ticketType`
 * discriminator instead of the retired `request_schema` config key
 * (unify-ticket-supertype).
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
 * @spec openspec/specs/queue-management/spec.md
 * @spec openspec/changes/queue-management/tasks.md#task-1.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for queue operations such as capacity checks, overflow routing, and item assignment.
 *
 * @spec openspec/changes/reverse-2026-05-26-be-queue/tasks.md#task-1
 */
class QueueService
{
    /**
     * Constructor.
     *
     * @param IAppConfig              $appConfig        The app config.
     * @param ContainerInterface      $container        The container.
     * @param LoggerInterface         $logger           The logger.
     * @param RegisterResolverService $registerResolver The register resolver.
     * @param TicketService           $ticketService    Resolver for the unified ticket schema.
     */
    public function __construct(
        private IAppConfig $appConfig,
        private ContainerInterface $container,
        private LoggerInterface $logger,
        private RegisterResolverService $registerResolver,
        private readonly TicketService $ticketService,
    ) {
    }//end __construct()

    /**
     * Get the number of items in a queue.
     *
     * @param string $queueId The queue UUID.
     *
     * @return int The number of items in the queue.
     * @spec   openspec/changes/reverse-2026-05-26-be-queue/tasks.md#task-1
     */
    public function getQueueDepth(string $queueId): int
    {
        $registerId = $this->registerResolver->resolve('queue');
        $schemaId   = $this->ticketService->getSchemaId();

        if ($registerId === '' || $schemaId === '') {
            $this->logger->warning('QueueService: Cannot get queue depth -- register or ticket schema not configured');
            return 0;
        }

        try {
            $objectService = $this->getObjectService();

            // Push the count down into OpenRegister's query engine. The previous
            // implementation fetched findAll(limit: 1) and counted the result,
            // which capped the reported depth at 1 (a bug) and over-fetched.
            // Only request tickets are queued, so the count narrows on the
            // `ticketType` discriminator instead of on a per-type schema.
            return $objectService->count(
                [
                    'filters' => [
                        'register'   => $registerId,
                        'schema'     => $schemaId,
                        'ticketType' => TicketService::TYPE_REQUEST,
                        'queue'      => $queueId,
                    ],
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error(
                'QueueService: Failed to get queue depth',
                ['exception' => $e->getMessage(), 'queueId' => $queueId]
            );
            return 0;
        }//end try
    }//end getQueueDepth()

    /**
     * Assign a request to a queue by updating its queue field.
     *
     * @param string $requestId The request UUID.
     * @param string $queueId   The queue UUID to assign to.
     *
     * @return bool True on success.
     *
     * @spec openspec/specs/queue-management/spec.md
     */
    public function assignToQueue(string $requestId, string $queueId): bool
    {
        return $this->updateRequestQueueField(requestId: $requestId, queueId: $queueId);
    }//end assignToQueue()

    /**
     * Remove a request from its queue by clearing the queue field.
     *
     * @param string $requestId The request UUID.
     *
     * @return bool True on success.
     *
     * @spec openspec/specs/queue-management/spec.md
     */
    public function removeFromQueue(string $requestId): bool
    {
        return $this->updateRequestQueueField(requestId: $requestId, queueId: null);
    }//end removeFromQueue()

    /**
     * Determine whether a queue is at or over its maximum capacity.
     *
     * Returns false when no maxCapacity is configured (null or zero).
     *
     * @param array<string, mixed> $queue        The queue object array.
     * @param int                  $currentCount The current number of items in the queue.
     *
     * @return bool True when the queue is at or over capacity.
     * @spec   openspec/changes/reverse-2026-05-26-be-queue/tasks.md#task-2
     */
    public function isAtCapacity(array $queue, int $currentCount): bool
    {
        $maxCapacity = $queue['maxCapacity'] ?? null;

        if ($maxCapacity === null || (int) $maxCapacity <= 0) {
            return false;
        }

        return $currentCount >= (int) $maxCapacity;
    }//end isAtCapacity()

    /**
     * Process overflow for all queues that are at capacity and have an overflow target.
     *
     * @return int The number of items moved.
     * @spec   openspec/changes/reverse-2026-05-26-be-queue/tasks.md#task-3
     */
    public function processOverflow(): int
    {
        $registerId    = $this->registerResolver->resolve('queue');
        $queueSchemaId = $this->appConfig->getValueString(Application::APP_ID, 'queue_schema', '');

        if ($registerId === '' || $queueSchemaId === '') {
            $this->logger->warning('QueueService: Cannot process overflow -- register or queue schema not configured');
            return 0;
        }

        $movedCount = 0;

        try {
            $objectService = $this->getObjectService();

            $queues = $objectService->findAll(
                [
                    'filters' => [
                        'register' => $registerId,
                        'schema'   => $queueSchemaId,
                    ],
                    'limit'   => 200,
                ]
            );

            foreach ($queues as $queue) {
                $movedCount += $this->processQueueOverflow(queue: $queue);
            }
        } catch (\Exception $e) {
            $this->logger->error(
                'QueueService: Error during overflow processing',
                ['exception' => $e->getMessage()]
            );
        }//end try

        return $movedCount;
    }//end processOverflow()

    /**
     * Process overflow for a single queue.
     *
     * @param array<string, mixed> $queue The queue object.
     *
     * @return int Number of items moved.
     */
    private function processQueueOverflow(array $queue): int
    {
        $maxCapacity   = $queue['maxCapacity'] ?? null;
        $overflowQueue = $queue['overflowQueue'] ?? null;
        $queueId       = $queue['id'] ?? '';
        $title         = $queue['title'] ?? 'unknown';

        if ($maxCapacity === null || $maxCapacity <= 0) {
            return 0;
        }

        $depth = $this->getQueueDepth(queueId: $queueId);
        if ($this->isAtCapacity(queue: $queue, currentCount: $depth) === false) {
            return 0;
        }

        if ($overflowQueue === null || $overflowQueue === '') {
            $this->logger->warning(
                "QueueService: Queue '{$title}' is over capacity ({$depth}/{$maxCapacity}) but has no overflow target"
            );
            return 0;
        }

        $excess = $depth - (int) $maxCapacity;
        $this->logger->info(
            "QueueService: Moving {$excess} excess items from '{$title}' to overflow queue"
        );

        $moved = $this->moveExcessItems(fromQueueId: $queueId, toQueueId: $overflowQueue, count: $excess);

        $this->logger->info("QueueService: Moved {$moved} items from '{$title}' to overflow");

        return $moved;
    }//end processQueueOverflow()

    /**
     * Move excess items from one queue to another.
     *
     * @param string $fromQueueId Source queue UUID.
     * @param string $toQueueId   Target queue UUID.
     * @param int    $count       Number of items to move.
     *
     * @return int Number of items actually moved.
     */
    private function moveExcessItems(string $fromQueueId, string $toQueueId, int $count): int
    {
        $registerId = $this->registerResolver->resolve('queue');
        $schemaId   = $this->ticketService->getSchemaId();

        if ($registerId === '' || $schemaId === '') {
            return 0;
        }

        $moved = 0;

        try {
            $objectService = $this->getObjectService();

            $items = $objectService->findAll(
                [
                    'filters' => [
                        'register'   => $registerId,
                        'schema'     => $schemaId,
                        'ticketType' => TicketService::TYPE_REQUEST,
                        'queue'      => $fromQueueId,
                    ],
                    'limit'   => $count,
                    'order'   => ['dateCreated' => 'DESC'],
                ]
            );

            foreach ($items as $item) {
                $itemId = $item['id'] ?? null;
                if ($itemId === null) {
                    continue;
                }

                if ($this->assignToQueue(requestId: $itemId, queueId: $toQueueId) === true) {
                    $moved++;
                }
            }
        } catch (\Exception $e) {
            $this->logger->error(
                'QueueService: Error moving excess items',
                ['exception' => $e->getMessage()]
            );
        }//end try

        return $moved;
    }//end moveExcessItems()

    /**
     * Update the queue field on a request ticket.
     *
     * The write goes through TicketService::save(), which resolves the unified
     * ticket schema and stamps the `ticketType` discriminator onto the payload.
     * The object is still identified by its `id` in the payload (uuid: null),
     * exactly as before the ticket cutover.
     *
     * @param string      $requestId The request UUID.
     * @param string|null $queueId   The queue UUID, or null to clear.
     *
     * @return bool True on success.
     */
    private function updateRequestQueueField(string $requestId, ?string $queueId): bool
    {
        if ($this->ticketService->isConfigured() === false) {
            $this->logger->warning('QueueService: Cannot update request -- register or ticket schema not configured');
            return false;
        }

        try {
            $this->ticketService->save(
                ticketType: TicketService::TYPE_REQUEST,
                payload: [
                    'id'    => $requestId,
                    'queue' => $queueId,
                ],
                uuid: null
            );

            return true;
        } catch (\Exception $e) {
            $this->logger->error(
                'QueueService: Failed to update request queue field',
                ['exception' => $e->getMessage(), 'requestId' => $requestId]
            );
            return false;
        }//end try
    }//end updateRequestQueueField()

    /**
     * Get the OpenRegister ObjectService via the container.
     *
     * @return object The object service.
     */
    private function getObjectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');
    }//end getObjectService()
}//end class
