<?php

/**
 * Pipelinq QueueService.
 *
 * Service for queue operations: capacity checks, overflow routing, and item assignment.
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for queue operations such as capacity checks, overflow routing, and item assignment.
 */
class QueueService
{
    /**
     * Constructor.
     *
     * @param IAppConfig         $appConfig The app config.
     * @param ContainerInterface $container The container.
     * @param LoggerInterface    $logger    The logger.
     */
    public function __construct(
        private IAppConfig $appConfig,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the number of items in a queue.
     *
     * @param string $queueId The queue UUID.
     *
     * @return int The number of items in the queue.
     */
    public function getQueueDepth(string $queueId): int
    {
        $registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schemaId   = $this->appConfig->getValueString(Application::APP_ID, 'request_schema', '');

        if ($registerId === '' || $schemaId === '') {
            $this->logger->warning('QueueService: Cannot get queue depth -- register or request schema not configured');
            return 0;
        }

        try {
            $objectService = $this->getObjectService();
            $results       = $objectService->findAll(
                [
                    'filters' => [
                        'register' => $registerId,
                        'schema'   => $schemaId,
                        'queue'    => $queueId,
                    ],
                    'limit'   => 1,
                ],
                _rbac: false,
                _multitenancy: false
            );

            return count($results);
        } catch (\Exception $e) {
            $this->logger->error(
                'QueueService: Failed to get queue depth',
                ['exception' => $e->getMessage(), 'queueId' => $queueId]
            );
            return 0;
        }//end try
    }//end getQueueDepth()

    /**
     * Check whether a queue is at capacity.
     *
     * @param array<string, mixed> $queue        The queue object.
     * @param int|null             $currentCount Optional override for current count.
     *
     * @return bool True if the queue is at or over capacity.
     */
    public function isAtCapacity(array $queue, ?int $currentCount = null): bool
    {
        $maxCapacity = $queue['maxCapacity'] ?? null;
        if ($maxCapacity === null || $maxCapacity <= 0) {
            return false;
        }

        if ($currentCount === null) {
            $queueId      = $queue['id'] ?? '';
            $currentCount = $this->getQueueDepth($queueId);
        }

        return $currentCount >= (int) $maxCapacity;
    }//end isAtCapacity()

    /**
     * Assign a request to a queue by updating its queue field.
     *
     * @param string $requestId The request UUID.
     * @param string $queueId   The queue UUID to assign to.
     *
     * @return bool True on success.
     */
    public function assignToQueue(string $requestId, string $queueId): bool
    {
        return $this->updateRequestQueueField($requestId, $queueId);
    }//end assignToQueue()

    /**
     * Remove a request from its queue by clearing the queue field.
     *
     * @param string $requestId The request UUID.
     *
     * @return bool True on success.
     */
    public function removeFromQueue(string $requestId): bool
    {
        return $this->updateRequestQueueField($requestId, null);
    }//end removeFromQueue()

    /**
     * Process overflow for all queues that are at capacity and have an overflow target.
     *
     * @return int The number of items moved.
     */
    public function processOverflow(): int
    {
        $registerId    = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
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
                ],
                _rbac: false,
                _multitenancy: false
            );

            foreach ($queues as $queue) {
                $movedCount += $this->processQueueOverflow($queue);
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

        $depth = $this->getQueueDepth($queueId);
        if ($depth <= (int) $maxCapacity) {
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

        $moved = $this->moveExcessItems($queueId, $overflowQueue, $excess);

        $this->logger->info("QueueService: Moved {$moved} items from '{$title}' to overflow");

        return $moved;
    }//end processQueueOverflow()

    /**
     * Move excess items from one queue to another.
     *
     * @param string $fromQueueId   Source queue UUID.
     * @param string $toQueueId     Target queue UUID.
     * @param int    $count         Number of items to move.
     *
     * @return int Number of items actually moved.
     */
    private function moveExcessItems(string $fromQueueId, string $toQueueId, int $count): int
    {
        $registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schemaId   = $this->appConfig->getValueString(Application::APP_ID, 'request_schema', '');

        if ($registerId === '' || $schemaId === '') {
            return 0;
        }

        $moved = 0;

        try {
            $objectService = $this->getObjectService();

            $items = $objectService->findAll(
                [
                    'filters' => [
                        'register' => $registerId,
                        'schema'   => $schemaId,
                        'queue'    => $fromQueueId,
                    ],
                    'limit'   => $count,
                    'order'   => ['dateCreated' => 'DESC'],
                ],
                _rbac: false,
                _multitenancy: false
            );

            foreach ($items as $item) {
                $itemId = $item['id'] ?? null;
                if ($itemId === null) {
                    continue;
                }

                if ($this->assignToQueue($itemId, $toQueueId) === true) {
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
     * Update the queue field on a request object.
     *
     * @param string      $requestId The request UUID.
     * @param string|null $queueId   The queue UUID, or null to clear.
     *
     * @return bool True on success.
     */
    private function updateRequestQueueField(string $requestId, ?string $queueId): bool
    {
        $registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schemaId   = $this->appConfig->getValueString(Application::APP_ID, 'request_schema', '');

        if ($registerId === '' || $schemaId === '') {
            $this->logger->warning('QueueService: Cannot update request -- register or request schema not configured');
            return false;
        }

        try {
            $objectService = $this->getObjectService();

            $objectService->saveObject(
                ['id' => $requestId, 'queue' => $queueId],
                [],
                $registerId,
                $schemaId,
                null,
                _rbac: false,
                _multitenancy: false
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
