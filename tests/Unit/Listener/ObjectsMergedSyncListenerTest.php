<?php

/**
 * Unit tests for ObjectsMergedSyncListener.
 *
 * Verifies that OpenRegister's ObjectsMergedEvent drives the downstream sync
 * fan-out directly through OR's WebhookService (retire-mdm-sync-queue removed
 * the app-side queue): one dispatch per downstream system, changeType `merge`
 * vs `reverse-merge`, the survivor's OR-materialised golden record carried in
 * the payload, an event-driven golden-record projection, no queue rows, and
 * OR-absent degradation. Non-ObjectsMergedEvent events are ignored
 * (REQ-MDM-006 / REQ-MDM-011).
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
use OCA\OpenRegister\Service\WebhookService;
use OCA\Pipelinq\Listener\ObjectsMergedSyncListener;
use OCA\Pipelinq\Service\Mdm\OpenRegisterSyncService;
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
     * Records every WebhookService::dispatchEvent payload.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $dispatched = [];

    /**
     * The listener under test.
     *
     * @var ObjectsMergedSyncListener
     */
    private ObjectsMergedSyncListener $listener;

    /**
     * Set up the listener with a dispatch-recording WebhookService.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->repo       = new InMemoryMdmObjectRepository();
        $this->dispatched = [];

        $container = $this->buildContainer(webhook: $this->recordingWebhook());
        $orSync    = new OpenRegisterSyncService($this->repo, new NullLogger());
        $this->listener = new ObjectsMergedSyncListener($container, $this->repo, $orSync, new NullLogger());
    }//end setUp()

    /**
     * A WebhookService that records dispatches into $this->dispatched.
     *
     * @return WebhookService The recording double.
     */
    private function recordingWebhook(): WebhookService
    {
        $sink = &$this->dispatched;
        return new class ($sink) extends WebhookService {
            /**
             * @param array<int, array<string, mixed>> $sink Reference to the record sink.
             */
            public function __construct(private array &$sink)
            {
            }

            /**
             * Record the dispatch instead of delivering it.
             *
             * @param Event                $_event    The event.
             * @param string               $eventName The event name.
             * @param array<string, mixed> $payload   The payload.
             *
             * @return void
             */
            public function dispatchEvent(Event $_event, string $eventName, array $payload): void
            {
                $this->sink[] = $payload;
            }
        };
    }//end recordingWebhook()

    /**
     * Build a container stub that resolves WebhookService to the given double,
     * or throws (OR-absent) when $webhook is null.
     *
     * @param WebhookService|null $webhook The WebhookService double, or null for OR-absent.
     *
     * @return ContainerInterface The container stub.
     */
    private function buildContainer(?WebhookService $webhook): ContainerInterface
    {
        $container = $this->createStub(ContainerInterface::class);
        if ($webhook === null) {
            $container->method('get')->willThrowException(new \RuntimeException('OR absent'));
            return $container;
        }

        $container->method('get')->willReturn($webhook);
        return $container;
    }//end buildContainer()

    /**
     * A merge event dispatches one `merge` webhook per downstream system with
     * the survivor's golden record in the payload — and creates no queue rows.
     *
     * @return void
     */
    public function testMergeEventDispatchesDownstreamSync(): void
    {
        $this->repo->seed(
            'masterEntity',
            'survivor-1',
            ['masterId' => 'survivor-1', 'entityType' => 'contact', 'goldenRecord' => ['name' => 'Acme B.V.', 'kvkNumber' => '12345678']]
        );

        $this->listener->handle(
            new ObjectsMergedEvent('survivor-1', ['from-1', 'from-2'], 'op-1', false)
        );

        $this->assertCount(5, $this->dispatched);
        $this->assertArrayNotHasKey('syncQueueItem', $this->repo->store);

        foreach ($this->dispatched as $payload) {
            $this->assertSame('merge', $payload['changeType']);
            $this->assertSame('survivor-1', $payload['masterEntity']);
            $this->assertSame(['from-1', 'from-2'], $payload['payload']['mergedFrom']);
            $this->assertSame('op-1', $payload['payload']['mergeOperationId']);
            $this->assertFalse($payload['payload']['isReversal']);
            $this->assertSame('Acme B.V.', $payload['payload']['goldenRecord']['name']);
        }

        $targets = array_map(static fn (array $p): string => (string) $p['targetSystem'], $this->dispatched);
        $this->assertSame(['shillinq', 'procest', 'scholiq', 'opencatalogi', 'decidesk'], $targets);
    }//end testMergeEventDispatchesDownstreamSync()

    /**
     * A merge event projects the survivor golden record onto its OR schema
     * instance (event-driven, replacing the retired poller).
     *
     * @return void
     */
    public function testMergeEventProjectsGoldenRecord(): void
    {
        $this->repo->seed(
            'masterEntity',
            'survivor-1',
            ['masterId' => 'survivor-1', 'entityType' => 'contact', 'goldenRecord' => ['name' => 'Acme B.V.']]
        );

        $this->listener->handle(
            new ObjectsMergedEvent('survivor-1', ['from-1'], 'op-1', false)
        );

        // OpenRegisterSyncService writes a canonical `contact` OR object.
        $contacts = $this->repo->store['contact'] ?? [];
        $this->assertCount(1, $contacts);
        $contact = array_values($contacts)[0];
        $this->assertSame('survivor-1', $contact['masterEntityRef']);
        $this->assertTrue($contact['isMasterRecord']);
        $this->assertSame('Acme B.V.', $contact['name']);
    }//end testMergeEventProjectsGoldenRecord()

    /**
     * A reversal event uses changeType `reverse-merge`.
     *
     * @return void
     */
    public function testReversalEventUsesReverseMergeChangeType(): void
    {
        $this->repo->seed('masterEntity', 'survivor-1', ['masterId' => 'survivor-1', 'entityType' => 'contact', 'goldenRecord' => []]);

        $this->listener->handle(
            new ObjectsMergedEvent('survivor-1', ['from-1'], 'op-2', true)
        );

        $this->assertCount(5, $this->dispatched);
        $this->assertSame('reverse-merge', $this->dispatched[0]['changeType']);
        $this->assertTrue($this->dispatched[0]['payload']['isReversal']);
    }//end testReversalEventUsesReverseMergeChangeType()

    /**
     * A survivor that cannot be read still dispatches (empty golden record).
     *
     * @return void
     */
    public function testMissingSurvivorStillDispatches(): void
    {
        $this->listener->handle(
            new ObjectsMergedEvent('ghost', ['from-1'], 'op-3', false)
        );

        $this->assertCount(5, $this->dispatched);
        $this->assertSame([], $this->dispatched[0]['payload']['goldenRecord']);
    }//end testMissingSurvivorStillDispatches()

    /**
     * When OpenRegister is absent, the merge save is not blocked: no dispatch,
     * no Throwable escapes.
     *
     * @return void
     */
    public function testOrAbsentDegradesGracefully(): void
    {
        $this->repo->seed('masterEntity', 'survivor-1', ['masterId' => 'survivor-1', 'entityType' => 'contact', 'goldenRecord' => ['name' => 'Acme']]);

        $container = $this->buildContainer(webhook: null);
        $orSync    = new OpenRegisterSyncService($this->repo, new NullLogger());
        $listener  = new ObjectsMergedSyncListener($container, $this->repo, $orSync, new NullLogger());

        $listener->handle(new ObjectsMergedEvent('survivor-1', ['from-1'], 'op-4', false));

        $this->assertSame([], $this->dispatched);
        // The projection still runs (it does not need the WebhookService).
        $this->assertCount(1, $this->repo->store['contact'] ?? []);
    }//end testOrAbsentDegradesGracefully()

    /**
     * A non-ObjectsMergedEvent event is ignored.
     *
     * @return void
     */
    public function testUnrelatedEventIgnored(): void
    {
        $this->listener->handle(new Event());

        $this->assertSame([], $this->dispatched);
        $this->assertArrayNotHasKey('contact', $this->repo->store);
    }//end testUnrelatedEventIgnored()
}//end class
