<?php

/**
 * Unit tests for MergeService.
 *
 * Drives the full reversible-merge lifecycle against an in-memory repository:
 * a side-effect-free preview, an atomic execute (source relink, status flip,
 * lineage + alias, survivor recompute, merge-operation snapshot, downstream
 * enqueue), idempotency rejection, reversal that restores the snapshot inside
 * the window, and rejection of reversal beyond the 30-day window. Mirrors spec
 * scenarios REQ-MDM-004-01..04.
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

use OCA\Pipelinq\Service\Mdm\MasterEntityService;
use OCA\Pipelinq\Service\Mdm\MergeService;
use OCA\Pipelinq\Service\Mdm\SyncQueueService;
use OCA\Pipelinq\Service\Mdm\TrustConfigurationService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

require_once __DIR__.'/InMemoryMdmObjectRepository.php';

/**
 * Tests for MergeService.
 */
final class MergeServiceTest extends TestCase
{
    /**
     * The in-memory repository.
     *
     * @var InMemoryMdmObjectRepository
     */
    private InMemoryMdmObjectRepository $repo;

    /**
     * The service under test.
     *
     * @var MergeService
     */
    private MergeService $service;

    /**
     * Set up the service stack with a never-called container for the queue.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->repo = new InMemoryMdmObjectRepository();
        $trust      = new TrustConfigurationService($this->repo);
        $master     = new MasterEntityService($this->repo, $trust, new NullLogger());
        $container  = $this->createStub(ContainerInterface::class);
        $syncQueue  = new SyncQueueService($this->repo, $container, new NullLogger());
        $this->service = new MergeService($this->repo, $master, $syncQueue, new NullLogger());

        // Trust config so survivorship is deterministic.
        $this->repo->seed('trustConfiguration', 't-name', ['entityType' => 'account', 'attribute' => 'name', 'sourceSystem' => 'kvk-api', 'trustTier' => 'gold', 'effectiveFrom' => '2026-01-01']);
        $this->repo->seed('trustConfiguration', 't-phone', ['entityType' => 'account', 'attribute' => 'phone', 'sourceSystem' => 'crm', 'trustTier' => 'bronze', 'effectiveFrom' => '2026-01-01']);
    }//end setUp()

    /**
     * Seed a from/into entity pair with one source record each.
     *
     * @return void
     */
    private function seedPair(): void
    {
        $this->repo->seed('masterEntity', 'into', ['masterId' => 'into', 'entityType' => 'account', 'status' => 'active', 'goldenRecord' => ['name' => 'Voorbeeld B.V.'], 'attributeProvenance' => [], 'mergedFrom' => [], 'aliases' => []]);
        $this->repo->seed('masterEntity', 'from', ['masterId' => 'from', 'entityType' => 'account', 'status' => 'active', 'goldenRecord' => ['name' => 'Voorbeeld BV', 'phone' => '020-9999999'], 'attributeProvenance' => [], 'mergedFrom' => [], 'aliases' => []]);

        $this->repo->seed('sourceRecord', 'sr-into', ['sourceRecordId' => 'sr-into', 'currentMasterEntity' => 'into', 'sourceSystem' => 'kvk-api', 'mappedAttributes' => ['name' => 'Voorbeeld B.V.'], 'lastChange' => '2026-05-01T00:00:00Z']);
        $this->repo->seed('sourceRecord', 'sr-from', ['sourceRecordId' => 'sr-from', 'currentMasterEntity' => 'from', 'sourceSystem' => 'crm', 'mappedAttributes' => ['phone' => '020-9999999'], 'lastChange' => '2026-05-01T00:00:00Z']);
    }//end seedPair()

    /**
     * Preview shows the post-merge record, downstream impact and reversal window,
     * with no side effects.
     *
     * @return void
     */
    public function testPreviewHasNoSideEffects(): void
    {
        $this->seedPair();

        $preview = $this->service->previewMerge('from', 'into');

        $this->assertSame('Voorbeeld B.V.', $preview['postMergeGoldenRecord']['name']);
        $this->assertSame('020-9999999', $preview['postMergeGoldenRecord']['phone']);
        $this->assertNotEmpty($preview['downstreamImpact']);
        $this->assertNotEmpty($preview['reversibleUntil']);

        // No mutation occurred.
        $this->assertSame('active', $this->repo->find('masterEntity', 'from')['status']);
        $this->assertSame('from', $this->repo->find('sourceRecord', 'sr-from')['currentMasterEntity']);
    }//end testPreviewHasNoSideEffects()

    /**
     * Execute relinks sources, flips status, records lineage, recomputes the
     * survivor, snapshots and enqueues downstream sync.
     *
     * @return void
     */
    public function testExecuteMergeAtomicEffects(): void
    {
        $this->seedPair();

        $operation = $this->service->executeMerge('from', 'into', 'alice', 'data-stewardship-review');

        // Source relinked.
        $this->assertSame('into', $this->repo->find('sourceRecord', 'sr-from')['currentMasterEntity']);
        // From flagged merged-into-other.
        $from = $this->repo->find('masterEntity', 'from');
        $this->assertSame('merged-into-other', $from['status']);
        $this->assertSame('into', $from['mergedIntoMasterId']);
        // Into recorded lineage + alias and recomputed.
        $into = $this->repo->find('masterEntity', 'into');
        $this->assertContains('from', $into['mergedFrom']);
        $this->assertContains('from', $into['aliases']);
        $this->assertSame('020-9999999', $into['goldenRecord']['phone']);
        // Merge-operation persisted with snapshot.
        $this->assertSame('into', $operation['mergedIntoMasterId']);
        $this->assertTrue($operation['reversible']);
        $this->assertArrayHasKey('entities', $operation['preMergeSnapshot']);
        // Downstream sync enqueued (5 systems).
        $this->assertCount(5, $this->repo->store['syncQueueItem'] ?? []);
    }//end testExecuteMergeAtomicEffects()

    /**
     * Merging an already-merged entity is rejected (idempotency).
     *
     * @return void
     */
    public function testMergeIdempotencyRejection(): void
    {
        $this->seedPair();
        $this->service->executeMerge('from', 'into', 'alice', 'manual-bulk');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already been merged');
        $this->service->executeMerge('from', 'into', 'alice', 'manual-bulk');
    }//end testMergeIdempotencyRejection()

    /**
     * Merging an entity into itself is rejected.
     *
     * @return void
     */
    public function testCannotMergeIntoSelf(): void
    {
        $this->seedPair();
        $this->expectException(RuntimeException::class);
        $this->service->executeMerge('into', 'into', 'alice', 'manual-bulk');
    }//end testCannotMergeIntoSelf()

    /**
     * Reversal within the window restores entities and source linkages.
     *
     * @return void
     */
    public function testReverseMergeRestoresSnapshot(): void
    {
        $this->seedPair();
        $operation = $this->service->executeMerge('from', 'into', 'alice', 'data-stewardship-review');

        $this->service->reverseMerge((string) $operation['id'], 'bob');

        // From restored to active; source relinked back to from.
        $this->assertSame('active', $this->repo->find('masterEntity', 'from')['status']);
        $this->assertSame('from', $this->repo->find('sourceRecord', 'sr-from')['currentMasterEntity']);
        // Operation marked reversed and no longer reversible.
        $reversed = $this->repo->find('mergeOperation', (string) $operation['id']);
        $this->assertSame('bob', $reversed['reversedBy']);
        $this->assertFalse($reversed['reversible']);
    }//end testReverseMergeRestoresSnapshot()

    /**
     * Reversal beyond the 30-day window is rejected (REQ-MDM-004-04).
     *
     * @return void
     */
    public function testReverseBeyondWindowRejected(): void
    {
        $operation = [
            'id'               => 'op1',
            'mergedIntoMasterId' => 'into',
            'mergedAt'         => '2026-01-01T00:00:00Z',
            'reversible'       => true,
            'preMergeSnapshot' => ['entities' => ['from' => []], 'sourceLinks' => []],
        ];
        $this->repo->seed('mergeOperation', 'op1', $operation);
        $this->repo->clock = '2026-06-03T00:00:00Z';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Reversal window has expired');
        $this->service->reverseMerge('op1', 'bob');
    }//end testReverseBeyondWindowRejected()

    /**
     * isReversible honours the window and the reversed flag (pure).
     *
     * @return void
     */
    public function testIsReversibleWindow(): void
    {
        $this->repo->clock = '2026-06-03T00:00:00Z';

        $this->assertTrue($this->service->isReversible(['reversible' => true, 'mergedAt' => '2026-05-20T00:00:00Z']));
        $this->assertFalse($this->service->isReversible(['reversible' => true, 'mergedAt' => '2026-01-01T00:00:00Z']));
        $this->assertFalse($this->service->isReversible(['reversible' => false, 'mergedAt' => '2026-06-01T00:00:00Z']));
        $this->assertFalse($this->service->isReversible(['reversible' => true, 'reversedAt' => '2026-06-02T00:00:00Z', 'mergedAt' => '2026-06-01T00:00:00Z']));
    }//end testIsReversibleWindow()
}//end class
