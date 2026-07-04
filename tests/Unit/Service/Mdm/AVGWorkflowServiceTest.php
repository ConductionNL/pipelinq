<?php

/**
 * Unit tests for AVGWorkflowService.
 *
 * Covers the right-of-deletion workflow: initiation logging, the atomic
 * approve-and-execute (source anonymisation, soft-delete, golden-record + audit
 * redaction, downstream soft-delete enqueue), cooling-off enforcement on hard
 * delete and the hard delete itself. Mirrors spec scenarios REQ-MDM-009-01..03.
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

use OCA\Pipelinq\Service\Mdm\AVGWorkflowService;
use OCA\Pipelinq\Service\Mdm\SyncQueueService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

require_once __DIR__.'/InMemoryMdmObjectRepository.php';

/**
 * Tests for AVGWorkflowService.
 */
final class AVGWorkflowServiceTest extends TestCase
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
     * @var AVGWorkflowService
     */
    private AVGWorkflowService $service;

    /**
     * Set up the service stack.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->repo    = new InMemoryMdmObjectRepository();
        $container     = $this->createStub(ContainerInterface::class);
        $syncQueue     = new SyncQueueService($this->repo, $container, new NullLogger());
        $this->service = new AVGWorkflowService($this->repo, $syncQueue, new NullLogger());
    }//end setUp()

    /**
     * Seed a contact master entity with two source records.
     *
     * @return void
     */
    private function seedSubject(): void
    {
        $this->repo->seed('masterEntity', 'p1', ['masterId' => 'p1', 'entityType' => 'contact', 'status' => 'active', 'goldenRecord' => ['name' => 'Pietje Puk', 'email' => 'p@x.nl'], 'attributeProvenance' => ['name' => ['value' => 'Pietje Puk', 'sourceSystem' => 'crm']], 'gdprNotes' => '']);
        $this->repo->seed('sourceRecord', 's1', ['sourceRecordId' => 's1', 'currentMasterEntity' => 'p1', 'sourceSystem' => 'crm', 'rawAttributes' => ['name' => 'Pietje Puk'], 'mappedAttributes' => ['name' => 'Pietje Puk'], 'withdrawn' => false]);
        $this->repo->seed('sourceRecord', 's2', ['sourceRecordId' => 's2', 'currentMasterEntity' => 'p1', 'sourceSystem' => 'shillinq', 'rawAttributes' => ['email' => 'p@x.nl'], 'mappedAttributes' => ['email' => 'p@x.nl'], 'withdrawn' => false]);
    }//end seedSubject()

    /**
     * Initiation records the request and lists the source-record count.
     *
     * @return void
     */
    public function testInitiate(): void
    {
        $this->seedSubject();

        $result = $this->service->initiateRightOfDeletion('p1', 'GR-2026-5001');

        $this->assertSame('pending-review', $result['status']);
        $this->assertSame(2, $result['sourceRecordCount']);
        $this->assertStringContainsString('GR-2026-5001', $this->repo->find('masterEntity', 'p1')['gdprNotes']);
    }//end testInitiate()

    /**
     * Approve-and-execute soft-deletes, anonymises sources and enqueues sync.
     *
     * @return void
     */
    public function testApproveAndExecute(): void
    {
        $this->seedSubject();

        $result = $this->service->approveAndExecuteRightOfDeletion('p1', 'GR-2026-5001', 'bob');

        $this->assertSame('soft-deleted', $result['status']);
        $this->assertSame(2, $result['anonymisedSources']);

        $entity = $this->repo->find('masterEntity', 'p1');
        $this->assertSame('soft-deleted', $entity['status']);
        // Golden record redacted; provenance structure kept but value redacted.
        $this->assertSame(AVGWorkflowService::REDACTED, $entity['goldenRecord']['name']);
        $this->assertSame(AVGWorkflowService::REDACTED, $entity['attributeProvenance']['name']['value']);
        $this->assertSame('crm', $entity['attributeProvenance']['name']['sourceSystem']);

        // Sources anonymised + withdrawn.
        $source = $this->repo->find('sourceRecord', 's1');
        $this->assertTrue($source['withdrawn']);
        $this->assertSame(AVGWorkflowService::REDACTED, $source['mappedAttributes']['name']);

        // Soft-delete sync enqueued for all 5 downstream systems.
        $this->assertCount(5, $this->repo->store['syncQueueItem'] ?? []);
    }//end testApproveAndExecute()

    /**
     * Hard delete is blocked before the cooling-off period elapses.
     *
     * @return void
     */
    public function testHardDeleteBlockedDuringCoolingOff(): void
    {
        $this->seedSubject();
        $this->repo->clock = '2026-06-03T00:00:00Z';
        $this->service->approveAndExecuteRightOfDeletion('p1', 'GR-1', 'bob');

        // Same day → cooling-off not elapsed.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cooling-off');
        $this->service->confirmHardDelete('p1', 'admin');
    }//end testHardDeleteBlockedDuringCoolingOff()

    /**
     * Hard delete after cooling-off permanently removes entity + sources.
     *
     * @return void
     */
    public function testHardDeleteAfterCoolingOff(): void
    {
        $this->seedSubject();
        $this->repo->clock = '2026-06-03T00:00:00Z';
        $this->service->approveAndExecuteRightOfDeletion('p1', 'GR-1', 'bob');

        // Advance the clock past the 30-day cooling-off window.
        $this->repo->clock = '2026-07-10T00:00:00Z';

        $result = $this->service->confirmHardDelete('p1', 'admin');

        $this->assertSame('hard-deleted', $result['status']);
        $this->assertNull($this->repo->find('masterEntity', 'p1'));
        $this->assertNull($this->repo->find('sourceRecord', 's1'));
        $this->assertNull($this->repo->find('sourceRecord', 's2'));
    }//end testHardDeleteAfterCoolingOff()

    /**
     * Hard delete is rejected for a non-soft-deleted entity.
     *
     * @return void
     */
    public function testHardDeleteRejectedForActiveEntity(): void
    {
        $this->seedSubject();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not soft-deleted');
        $this->service->confirmHardDelete('p1', 'admin');
    }//end testHardDeleteRejectedForActiveEntity()

    /**
     * redactMap replaces every value with the redaction token (pure).
     *
     * @return void
     */
    public function testRedactMap(): void
    {
        $redacted = $this->service->redactMap(['name' => 'Pietje', 'email' => 'p@x.nl']);
        $this->assertSame(['name' => AVGWorkflowService::REDACTED, 'email' => AVGWorkflowService::REDACTED], $redacted);
        $this->assertSame([], $this->service->redactMap('not-an-array'));
    }//end testRedactMap()

    /**
     * Cooling-off elapsed detection is correct around the boundary.
     *
     * @return void
     */
    public function testCoolingOffBoundary(): void
    {
        $entity = ['gdprNotes' => '[2026-06-03T00:00:00Z] AVG right-of-deletion executed by bob'];
        $this->assertFalse($this->service->isCoolingOffElapsed($entity, '2026-06-20T00:00:00Z'));
        $this->assertTrue($this->service->isCoolingOffElapsed($entity, '2026-07-04T00:00:00Z'));
    }//end testCoolingOffBoundary()
}//end class
