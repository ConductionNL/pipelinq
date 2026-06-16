<?php

/**
 * Unit tests for SyncQueueService.
 *
 * Covers enqueue + priority assignment, the exponential-backoff schedule, the
 * retry / dead-letter transitions through a stubbed WebhookService, the
 * acknowledgment callback, and priority-ordered queue processing. Mirrors spec
 * scenarios REQ-MDM-006-01..03.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Mdm
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Mdm;

use OCA\Pipelinq\Service\Mdm\SyncQueueService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

require_once __DIR__.'/InMemoryMdmObjectRepository.php';

/**
 * A WebhookService test double whose delivery outcome is scripted.
 */
final class FakeWebhookService
{
    /**
     * Whether dispatch should throw.
     *
     * @var bool
     */
    public bool $fail = false;

    /**
     * Dispatch an event, optionally failing.
     *
     * @param mixed  $_event    The event.
     * @param string $eventName The event name.
     * @param array  $payload   The payload.
     *
     * @return array<string, mixed> The delivery response.
     */
    public function dispatchEvent($_event, string $eventName, array $payload): array
    {
        if ($this->fail === true) {
            throw new \RuntimeException('HTTP 500');
        }

        return ['acknowledgmentReference' => 'SHQ-2026-12345'];
    }//end dispatchEvent()
}//end class

/**
 * Tests for SyncQueueService.
 */
final class SyncQueueServiceTest extends TestCase
{
    /**
     * The in-memory repository.
     *
     * @var InMemoryMdmObjectRepository
     */
    private InMemoryMdmObjectRepository $repo;

    /**
     * The fake webhook service.
     *
     * @var FakeWebhookService
     */
    private FakeWebhookService $webhook;

    /**
     * The service under test.
     *
     * @var SyncQueueService
     */
    private SyncQueueService $service;

    /**
     * Set up the service with a container returning the fake webhook service.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->repo    = new InMemoryMdmObjectRepository();
        $this->webhook = new FakeWebhookService();

        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturn($this->webhook);

        $this->service = new SyncQueueService($this->repo, $container, new NullLogger());
    }//end setUp()

    /**
     * Enqueue creates a queued item with change-type priority.
     *
     * @return void
     */
    public function testEnqueueAssignsPriority(): void
    {
        $merge  = $this->service->enqueueSync('m1', 'shillinq', 'merge', ['x' => 1]);
        $update = $this->service->enqueueSync('m1', 'shillinq', 'update', ['x' => 1]);

        $this->assertSame('queued', $merge['status']);
        $this->assertGreaterThan($update['priority'], $merge['priority']);
    }//end testEnqueueAssignsPriority()

    /**
     * The backoff schedule follows 1m, 5m, 30m, 2h … cumulatively.
     *
     * @return void
     */
    public function testBackoffSchedule(): void
    {
        $from = '2026-06-03T00:00:00Z';
        $this->assertSame('2026-06-03T00:01:00Z', $this->service->nextRetryAt(1, $from));
        $this->assertSame('2026-06-03T00:05:00Z', $this->service->nextRetryAt(2, $from));
        $this->assertSame('2026-06-03T00:30:00Z', $this->service->nextRetryAt(3, $from));
        $this->assertSame('2026-06-03T02:00:00Z', $this->service->nextRetryAt(4, $from));
    }//end testBackoffSchedule()

    /**
     * Successful delivery acknowledges the item and records the reference.
     *
     * @return void
     */
    public function testProcessAcknowledges(): void
    {
        $this->service->enqueueSync('m1', 'shillinq', 'merge', ['x' => 1]);

        $stats = $this->service->processQueue();

        $this->assertSame(1, $stats['acknowledged']);
        $item = array_values($this->repo->store['syncQueueItem'])[0];
        $this->assertSame('acknowledged', $item['status']);
        $this->assertSame('SHQ-2026-12345', $item['acknowledgmentReference']);
    }//end testProcessAcknowledges()

    /**
     * A failed delivery increments attemptCount and schedules a retry.
     *
     * @return void
     */
    public function testFailedDeliveryRetries(): void
    {
        $this->webhook->fail = true;
        $this->service->enqueueSync('m1', 'shillinq', 'merge', ['x' => 1]);

        $stats = $this->service->processQueue();

        $this->assertSame(1, $stats['failed']);
        $item = array_values($this->repo->store['syncQueueItem'])[0];
        $this->assertSame('queued', $item['status']);
        $this->assertSame(1, $item['attemptCount']);
        $this->assertNotEmpty($item['nextRetryAt']);
        $this->assertSame('HTTP 500', $item['errorMessage']);
    }//end testFailedDeliveryRetries()

    /**
     * After the maximum attempts the item is dead-lettered.
     *
     * @return void
     */
    public function testDeadLetterAfterMaxAttempts(): void
    {
        $this->webhook->fail = true;
        $saved = $this->service->enqueueSync('m1', 'shillinq', 'merge', ['x' => 1]);
        $id    = (string) $saved['id'];

        // Pre-age to the last attempt so one more failure dead-letters it.
        $item                 = $this->repo->find('syncQueueItem', $id);
        $item['attemptCount'] = (SyncQueueService::MAX_ATTEMPTS - 1);
        $this->repo->save('syncQueueItem', $item, $id);

        $stats = $this->service->processQueue();

        $this->assertSame(1, $stats['deadLetter']);
        $this->assertSame('dead-letter', $this->repo->find('syncQueueItem', $id)['status']);
    }//end testDeadLetterAfterMaxAttempts()

    /**
     * Manual retry resets the item to queued with a fresh attempt count.
     *
     * @return void
     */
    public function testManualRetryResets(): void
    {
        $saved = $this->service->enqueueSync('m1', 'shillinq', 'merge', ['x' => 1]);
        $id    = (string) $saved['id'];
        $item  = $this->repo->find('syncQueueItem', $id);
        $item['status']       = 'dead-letter';
        $item['attemptCount'] = 7;
        $this->repo->save('syncQueueItem', $item, $id);

        $retried = $this->service->retryItem($id);

        $this->assertSame('queued', $retried['status']);
        $this->assertSame(0, $retried['attemptCount']);
    }//end testManualRetryResets()

    /**
     * Acknowledge records the callback reference.
     *
     * @return void
     */
    public function testAcknowledgeCallback(): void
    {
        $saved = $this->service->enqueueSync('m1', 'shillinq', 'merge', ['x' => 1]);
        $id    = (string) $saved['id'];

        $acked = $this->service->acknowledge($id, 'SHQ-9999');

        $this->assertSame('acknowledged', $acked['status']);
        $this->assertSame('SHQ-9999', $acked['acknowledgmentReference']);
    }//end testAcknowledgeCallback()

    /**
     * Higher-priority items are processed before lower-priority ones.
     *
     * @return void
     */
    public function testPriorityOrdering(): void
    {
        $this->service->enqueueSync('m1', 'shillinq', 'update', ['x' => 1]);
        $this->service->enqueueSync('m2', 'shillinq', 'merge', ['x' => 1]);

        $stats = $this->service->processQueue(1);

        // Only one processed; it must be the merge (higher priority).
        $this->assertSame(1, $stats['processed']);
        $merge = null;
        foreach ($this->repo->store['syncQueueItem'] as $item) {
            if ($item['changeType'] === 'merge') {
                $merge = $item;
            }
        }

        $this->assertSame('acknowledged', $merge['status']);
    }//end testPriorityOrdering()
}//end class
