<?php

/**
 * Unit tests for ObjectsMergedSyncListener.
 *
 * Verifies that OpenRegister's ObjectsMergedEvent drives the downstream sync
 * fan-out (replacing the retired app-side MergeService enqueue path): one
 * sync-queue item per downstream system, changeType `merge` vs `reverse-merge`,
 * and the survivor's OR-materialised golden record carried in the payload.
 * Non-ObjectsMergedEvent events are ignored (REQ-MDM-004).
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Listener
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

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\OpenRegister\Event\ObjectsMergedEvent;
use OCA\Pipelinq\Listener\ObjectsMergedSyncListener;
use OCA\Pipelinq\Service\Mdm\SyncQueueService;
use OCA\Pipelinq\Tests\Unit\Service\Mdm\InMemoryMdmObjectRepository;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

require_once __DIR__.'/../Service/Mdm/InMemoryMdmObjectRepository.php';

/**
 * Tests for ObjectsMergedSyncListener.
 */
final class ObjectsMergedSyncListenerTest extends TestCase
{
    /**
     * The in-memory repository.
     *
     * @var InMemoryMdmObjectRepository
     */
    private InMemoryMdmObjectRepository $repo;

    /**
     * The listener under test.
     *
     * @var ObjectsMergedSyncListener
     */
    private ObjectsMergedSyncListener $listener;

    /**
     * Set up the listener stack with a real SyncQueueService over the in-memory repo.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->repo = new InMemoryMdmObjectRepository();
        $container  = $this->createStub(ContainerInterface::class);
        $syncQueue  = new SyncQueueService($this->repo, $container, new NullLogger());
        $this->listener = new ObjectsMergedSyncListener($syncQueue, $this->repo, new NullLogger());
    }//end setUp()

    /**
     * A merge event enqueues one `merge` item per downstream system with the
     * survivor's golden record in the payload.
     *
     * @return void
     */
    public function testMergeEventEnqueuesDownstreamSync(): void
    {
        $this->repo->seed(
            'masterEntity',
            'survivor-1',
            ['masterId' => 'survivor-1', 'goldenRecord' => ['name' => 'Acme B.V.', 'kvkNumber' => '12345678']]
        );

        $this->listener->handle(
            new ObjectsMergedEvent('survivor-1', ['from-1', 'from-2'], 'op-1', false)
        );

        $items = $this->repo->store['syncQueueItem'] ?? [];
        $this->assertCount(5, $items);

        foreach ($items as $item) {
            $this->assertSame('merge', $item['changeType']);
            $this->assertSame('survivor-1', $item['masterEntity']);
            $this->assertSame(['from-1', 'from-2'], $item['payload']['mergedFrom']);
            $this->assertSame('op-1', $item['payload']['mergeOperationId']);
            $this->assertFalse($item['payload']['isReversal']);
            $this->assertSame('Acme B.V.', $item['payload']['goldenRecord']['name']);
        }
    }//end testMergeEventEnqueuesDownstreamSync()

    /**
     * A reversal event uses changeType `reverse-merge`.
     *
     * @return void
     */
    public function testReversalEventUsesReverseMergeChangeType(): void
    {
        $this->repo->seed('masterEntity', 'survivor-1', ['masterId' => 'survivor-1', 'goldenRecord' => []]);

        $this->listener->handle(
            new ObjectsMergedEvent('survivor-1', ['from-1'], 'op-2', true)
        );

        $items = array_values($this->repo->store['syncQueueItem'] ?? []);
        $this->assertCount(5, $items);
        $this->assertSame('reverse-merge', $items[0]['changeType']);
        $this->assertTrue($items[0]['payload']['isReversal']);
    }//end testReversalEventUsesReverseMergeChangeType()

    /**
     * A survivor that cannot be read still enqueues (empty golden record).
     *
     * @return void
     */
    public function testMissingSurvivorStillEnqueues(): void
    {
        $this->listener->handle(
            new ObjectsMergedEvent('ghost', ['from-1'], 'op-3', false)
        );

        $items = array_values($this->repo->store['syncQueueItem'] ?? []);
        $this->assertCount(5, $items);
        $this->assertSame([], $items[0]['payload']['goldenRecord']);
    }//end testMissingSurvivorStillEnqueues()

    /**
     * A non-ObjectsMergedEvent event is ignored.
     *
     * @return void
     */
    public function testUnrelatedEventIgnored(): void
    {
        $this->listener->handle(new Event());

        $this->assertArrayNotHasKey('syncQueueItem', $this->repo->store);
    }//end testUnrelatedEventIgnored()
}//end class
