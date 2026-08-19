<?php

/**
 * Unit tests for DrainMdmSyncQueue.
 *
 * Verifies the one-time drain of in-flight `syncQueueItem` rows through
 * OpenRegister's WebhookService: non-terminal rows dispatched exactly once and
 * marked terminal, terminal rows skipped, idempotent re-run (zero dispatches),
 * failed hand-offs left non-terminal, and an OR-absent / no-schema no-op
 * (REQ-MDM-014).
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Repair;

use OCA\OpenRegister\Service\WebhookService;
use OCA\Pipelinq\Repair\DrainMdmSyncQueue;
use OCA\Pipelinq\Tests\Unit\Service\Mdm\InMemoryMdmObjectRepository;
use OCP\EventDispatcher\Event;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

require_once __DIR__.'/../Service/Mdm/InMemoryMdmObjectRepository.php';

/**
 * Tests for DrainMdmSyncQueue.
 */
final class DrainMdmSyncQueueTest extends TestCase
{
    /**
     * The in-memory repository.
     *
     * @var InMemoryMdmObjectRepository
     */
    private InMemoryMdmObjectRepository $repo;

    /**
     * Records every WebhookService::dispatchEvent payload.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $dispatched = [];

    /**
     * Set up an empty repo and dispatch sink.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->repo       = new InMemoryMdmObjectRepository();
        $this->dispatched = [];
    }//end setUp()

    /**
     * Build the repair step against a WebhookService that either records
     * dispatches or throws on a matching target (to test failure handling).
     *
     * @param string|null $failTarget A targetSystem whose dispatch throws, or null.
     *
     * @return DrainMdmSyncQueue The repair step.
     */
    private function buildStep(?string $failTarget=null): DrainMdmSyncQueue
    {
        $sink    = &$this->dispatched;
        $webhook = new class ($sink, $failTarget) extends WebhookService {
            /**
             * @param array<int, array<string, mixed>> $sink       Reference to the record sink.
             * @param string|null                      $failTarget A target that throws, or null.
             */
            public function __construct(private array &$sink, private ?string $failTarget)
            {
            }

            /**
             * Record the dispatch, or throw for the configured failing target.
             *
             * @param Event                $_event    The event.
             * @param string               $eventName The event name.
             * @param array<string, mixed> $payload   The payload.
             *
             * @return void
             */
            public function dispatchEvent(Event $_event, string $eventName, array $payload): void
            {
                if ($this->failTarget !== null && ($payload['targetSystem'] ?? '') === $this->failTarget) {
                    throw new \RuntimeException('delivery failed');
                }

                $this->sink[] = $payload;
            }
        };

        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturn($webhook);

        return new DrainMdmSyncQueue($this->repo, $container, new NullLogger());
    }//end buildStep()

    /**
     * Build the repair step against an absent OpenRegister (container throws).
     *
     * @return DrainMdmSyncQueue The repair step.
     */
    private function buildStepOrAbsent(): DrainMdmSyncQueue
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willThrowException(new \RuntimeException('OR absent'));

        return new DrainMdmSyncQueue($this->repo, $container, new NullLogger());
    }//end buildStepOrAbsent()

    /**
     * Seed a syncQueueItem row.
     *
     * @param string $id     The row id.
     * @param string $status The row status.
     * @param string $target The target system.
     *
     * @return void
     */
    private function seedRow(string $id, string $status, string $target='shillinq'): void
    {
        $this->repo->seed(
            'syncQueueItem',
            $id,
            [
                'id'           => $id,
                'status'       => $status,
                'targetSystem' => $target,
                'changeType'   => 'merge',
                'masterEntity' => 'm-'.$id,
                'payload'      => ['k' => $id],
            ]
        );
    }//end seedRow()

    /**
     * Pending rows are drained once; the already-delivered row is skipped.
     *
     * @return void
     */
    public function testPendingRowsDrainedOnceDeliveredSkipped(): void
    {
        $this->seedRow('a', 'queued');
        $this->seedRow('b', 'failed');
        $this->seedRow('c', 'sending');
        $this->seedRow('d', 'acknowledged');

        $this->buildStep()->run($this->createStub(IOutput::class));

        // Exactly three dispatches (a, b, c); the acknowledged row d is skipped.
        $this->assertCount(3, $this->dispatched);

        foreach (['a', 'b', 'c'] as $id) {
            $row = $this->repo->find('syncQueueItem', $id);
            $this->assertSame('sent', $row['status']);
            $this->assertStringStartsWith('drained:', (string) $row['acknowledgmentReference']);
        }

        $this->assertSame('acknowledged', $this->repo->find('syncQueueItem', 'd')['status']);
    }//end testPendingRowsDrainedOnceDeliveredSkipped()

    /**
     * A second run dispatches nothing (idempotent).
     *
     * @return void
     */
    public function testIdempotentRerun(): void
    {
        $this->seedRow('a', 'queued');
        $this->seedRow('b', 'queued');

        $this->buildStep()->run($this->createStub(IOutput::class));
        $this->assertCount(2, $this->dispatched);

        // Reset the sink and run again — everything is now terminal (`sent`).
        $this->dispatched = [];
        $this->buildStep()->run($this->createStub(IOutput::class));
        $this->assertCount(0, $this->dispatched);
    }//end testIdempotentRerun()

    /**
     * A failed hand-off leaves its row non-terminal; a re-run retries only it.
     *
     * @return void
     */
    public function testFailedHandOffStaysPending(): void
    {
        $this->seedRow('a', 'queued', 'shillinq');
        $this->seedRow('b', 'queued', 'procest');

        // Dispatch to procest throws.
        $this->buildStep(failTarget: 'procest')->run($this->createStub(IOutput::class));

        $this->assertSame('sent', $this->repo->find('syncQueueItem', 'a')['status']);
        $this->assertSame('queued', $this->repo->find('syncQueueItem', 'b')['status']);

        // Re-run without the injected failure: only b (still queued) is retried.
        $this->dispatched = [];
        $this->buildStep()->run($this->createStub(IOutput::class));
        $this->assertCount(1, $this->dispatched);
        $this->assertSame('procest', $this->dispatched[0]['targetSystem']);
        $this->assertSame('sent', $this->repo->find('syncQueueItem', 'b')['status']);
    }//end testFailedHandOffStaysPending()

    /**
     * OpenRegister absent: rows are left untouched (no dispatch, no throw).
     *
     * @return void
     */
    public function testOrAbsentLeavesRowsInPlace(): void
    {
        $this->seedRow('a', 'queued');

        $this->buildStepOrAbsent()->run($this->createStub(IOutput::class));

        $this->assertCount(0, $this->dispatched);
        $this->assertSame('queued', $this->repo->find('syncQueueItem', 'a')['status']);
    }//end testOrAbsentLeavesRowsInPlace()

    /**
     * An empty queue is a clean no-op.
     *
     * @return void
     */
    public function testEmptyQueueNoOp(): void
    {
        $this->buildStep()->run($this->createStub(IOutput::class));
        $this->assertCount(0, $this->dispatched);
    }//end testEmptyQueueNoOp()
}//end class
