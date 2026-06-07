<?php

/**
 * Unit tests for BlastService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/marketing-segmentation-and-blast-04-blast-attribution-services/tasks.md#task-2.3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\BlastService;
use OCA\Pipelinq\Service\SegmentService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for BlastService — A/B split determinism, send orchestration,
 * rate-limit resolution, and totals roll-up.
 *
 * @spec openspec/changes/marketing-segmentation-and-blast-04-blast-attribution-services/tasks.md#task-2.3
 */
class BlastServiceTest extends TestCase
{
    private ContainerInterface $container;
    private IAppConfig $appConfig;
    private SegmentService $segmentService;
    private LoggerInterface $logger;
    private object $objectService;

    /**
     * Service under test, instantiated in setUp().
     *
     * @var BlastService
     */
    private BlastService $service;

    /**
     * Set up.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->container      = $this->createMock(ContainerInterface::class);
        $this->appConfig      = $this->createMock(IAppConfig::class);
        $this->segmentService = $this->createMock(SegmentService::class);
        $this->logger         = $this->createMock(LoggerInterface::class);

        $this->objectService = new class {
            /** @var array<int, array<string, mixed>> */
            public array $saved = [];

            /** @var array<string, array<string, mixed>> */
            public array $store = [];

            /** @var array<int, array<string, mixed>> */
            public array $deliveries = [];

            /**
             * Mock find() — returns a stored blast or a delivery by id.
             *
             * @param string $id       Identifier.
             * @param mixed  $register Register slug.
             * @param mixed  $schema   Schema slug.
             *
             * @return array<string, mixed>|null Payload or null.
             */
            public function find(string $id, $register = null, $schema = null): ?array
            {
                return ($this->store[$id] ?? null);
            }

            /**
             * Mock findAll() — returns delivery rows filtered by blastId
             * and optional status / contactId.
             *
             * @param array<string, mixed> $filters  Filter map.
             * @param mixed                $register Register slug.
             * @param mixed                $schema   Schema slug.
             *
             * @return array<int, array<string, mixed>> Rows.
             */
            public function findAll(array $filters = [], $register = null, $schema = null): array
            {
                $out = [];
                foreach ($this->deliveries as $delivery) {
                    foreach ($filters as $k => $v) {
                        if (($delivery[$k] ?? null) !== $v) {
                            continue 2;
                        }
                    }
                    $out[] = $delivery;
                }
                return $out;
            }

            /**
             * Mock saveObject() — records the saved payload + writes it
             * back to the in-memory store keyed by id.
             *
             * @param array       $object   Payload.
             * @param mixed       $register Register.
             * @param mixed       $schema   Schema.
             * @param string|null $uuid     Existing id.
             *
             * @return array<string, mixed> The saved payload.
             */
            public function saveObject(array $object, $register = null, $schema = null, ?string $uuid = null): array
            {
                if ($uuid === null || $uuid === '') {
                    $uuid = ('saved-' . count($this->saved));
                }
                $object['uuid'] = $uuid;
                $this->saved[] = $object;
                $this->store[$uuid] = $object;
                return $object;
            }
        };

        $this->container->method('get')->willReturnCallback(
            function (string $id) {
                if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
                    return $this->objectService;
                }
                // ComplianceService and SourceService intentionally absent —
                // BlastService should fall back to the closed-fail path.
                throw new \RuntimeException('not registered: ' . $id);
            }
        );

        $this->appConfig->method('getValueString')->willReturnCallback(
            function (string $app, string $key, string $default) {
                return match ($key) {
                    'register'                 => 'pipelinq',
                    'blast_schema'             => 'blast',
                    'blastDelivery_schema'     => 'blastDelivery',
                    'campaignTemplate_schema'  => 'campaignTemplate',
                    'blast.dispatch_batch_size' => '50',
                    default                    => $default,
                };
            }
        );

        $this->service = new BlastService(
            $this->container,
            $this->appConfig,
            $this->segmentService,
            $this->logger,
        );
    }//end setUp()

    /**
     * variantFor returns "A" when abSplitPercent is null or out-of-range.
     *
     * @return void
     */
    public function testVariantForNoSplitReturnsA(): void
    {
        $this->assertSame('A', $this->service->variantFor('contact-1', null));
        $this->assertSame('A', $this->service->variantFor('contact-1', 0));
        $this->assertSame('A', $this->service->variantFor('contact-1', 101));
    }//end testVariantForNoSplitReturnsA()

    /**
     * variantFor is deterministic: same input → same output across calls.
     *
     * @return void
     */
    public function testVariantForIsDeterministicPerContact(): void
    {
        $first  = $this->service->variantFor('contact-deterministic-1', 50);
        $second = $this->service->variantFor('contact-deterministic-1', 50);
        $third  = $this->service->variantFor('contact-deterministic-1', 50);
        $this->assertSame($first, $second);
        $this->assertSame($second, $third);
    }//end testVariantForIsDeterministicPerContact()

    /**
     * variantFor approximates the requested split percentage across a
     * large synthetic contact-id population (the spec demands ~2,000/4,000
     * per variant at 50%; we permit a 10-point tolerance to stay
     * deterministic without flake).
     *
     * @return void
     */
    public function testVariantForApproximatesRequestedSplit(): void
    {
        $bCount = 0;
        $size   = 4000;
        for ($i = 0; $i < $size; $i++) {
            if ($this->service->variantFor('contact-' . $i, 50) === 'B') {
                $bCount++;
            }
        }
        $percent = ($bCount * 100.0 / $size);
        $this->assertGreaterThan(40.0, $percent, 'B share should be > 40% for a 50/50 split');
        $this->assertLessThan(60.0, $percent, 'B share should be < 60% for a 50/50 split');
    }//end testVariantForApproximatesRequestedSplit()

    /**
     * sliceMembersForAb routes members to the correct Blast id per
     * variant assignment.
     *
     * @return void
     */
    public function testSliceMembersForAbRoutesByVariant(): void
    {
        $members = [
            ['contactId' => 'contact-1', 'email' => 'a@example.test'],
            ['contactId' => 'contact-2', 'email' => 'b@example.test'],
            ['contactId' => 'contact-3', 'email' => 'c@example.test'],
        ];
        $sliced = $this->service->sliceMembersForAb($members, 50, 'parent-blast', 'child-blast');
        $this->assertCount(3, $sliced);
        foreach ($sliced as $row) {
            $expected = $this->service->variantFor($row['member']['contactId'], 50) === 'B' ? 'child-blast' : 'parent-blast';
            $this->assertSame($expected, $row['blastId']);
        }
    }//end testSliceMembersForAbRoutesByVariant()

    /**
     * sliceMembersForAb sends everyone to the parent blast when no split
     * is configured.
     *
     * @return void
     */
    public function testSliceMembersForAbSendsAllToParentWithoutSplit(): void
    {
        $members = [
            ['contactId' => 'contact-1', 'email' => 'a@example.test'],
            ['contactId' => 'contact-2', 'email' => 'b@example.test'],
        ];
        $sliced = $this->service->sliceMembersForAb($members, null, 'parent-blast', null);
        foreach ($sliced as $row) {
            $this->assertSame('parent-blast', $row['blastId']);
            $this->assertSame('A', $row['variant']);
        }
    }//end testSliceMembersForAbSendsAllToParentWithoutSplit()

    /**
     * sendBlast skips and stays in draft when ComplianceService is
     * unavailable — fail-closed default (no recipient is treated as
     * having lawful basis).
     *
     * @return void
     */
    public function testSendBlastFailsClosedWhenComplianceUnavailable(): void
    {
        $blast = [
            'uuid'      => 'blast-1',
            'name'      => 'Q4',
            'segmentId' => 'seg-1',
            'templateId'=> 'tmpl-1',
            'channel'   => 'email',
            'status'    => 'draft',
        ];
        $this->objectService->store['blast-1'] = $blast;
        $this->segmentService->method('getMembersForBlast')->willReturn([
            ['contactId' => 'c1', 'email' => 'c1@example.test'],
        ]);

        $summary = $this->service->sendBlast('blast-1', false);
        $this->assertSame('skipped-no-consent', $summary['status']);
        $this->assertSame(0, $summary['queued']);
        $this->assertSame(1, $summary['skippedNoConsent']);
    }//end testSendBlastFailsClosedWhenComplianceUnavailable()

    /**
     * sendBlast refuses to queue when the Blast is not in `draft`.
     *
     * @return void
     */
    public function testSendBlastRejectsNonDraftStatus(): void
    {
        $blast = [
            'uuid'      => 'blast-sent',
            'segmentId' => 'seg-1',
            'templateId'=> 'tmpl-1',
            'channel'   => 'email',
            'status'    => 'sent',
        ];
        $this->objectService->store['blast-sent'] = $blast;
        $summary = $this->service->sendBlast('blast-sent', false);
        $this->assertSame('not-draft-sent', $summary['status']);
        $this->assertSame(0, $summary['queued']);
    }//end testSendBlastRejectsNonDraftStatus()

    /**
     * sendBlast reports `not-found` when the Blast does not exist.
     *
     * @return void
     */
    public function testSendBlastReportsNotFound(): void
    {
        $summary = $this->service->sendBlast('missing', false);
        $this->assertSame('not-found', $summary['status']);
    }//end testSendBlastReportsNotFound()

    /**
     * updateBlastTotals recounts delivery statuses into the Blast totals
     * map matching the schema's keys.
     *
     * @return void
     */
    public function testUpdateBlastTotalsRecountsByStatus(): void
    {
        $blast = [
            'uuid'      => 'blast-2',
            'segmentId' => 'seg-1',
            'channel'   => 'email',
            'status'    => 'sending',
            'totals'    => [],
        ];
        $this->objectService->store['blast-2'] = $blast;
        $this->objectService->deliveries       = [
            ['uuid' => 'd1', 'blastId' => 'blast-2', 'status' => 'sent'],
            ['uuid' => 'd2', 'blastId' => 'blast-2', 'status' => 'sent'],
            ['uuid' => 'd3', 'blastId' => 'blast-2', 'status' => 'delivered'],
            ['uuid' => 'd4', 'blastId' => 'blast-2', 'status' => 'bounced'],
            ['uuid' => 'd5', 'blastId' => 'other', 'status' => 'sent'],
        ];

        $this->service->updateBlastTotals('blast-2');

        $persisted = end($this->objectService->saved);
        $this->assertIsArray($persisted);
        $this->assertSame(2, $persisted['totals']['sent']);
        $this->assertSame(1, $persisted['totals']['delivered']);
        $this->assertSame(1, $persisted['totals']['bounced']);
        $this->assertSame(0, $persisted['totals']['opened']);
    }//end testUpdateBlastTotalsRecountsByStatus()

    /**
     * transitionQueuedDeliveries flips queued rows for a contact to
     * `unsubscribed-before-send` without touching non-queued rows.
     *
     * @return void
     */
    public function testTransitionQueuedDeliveriesFlipsOnlyQueuedRows(): void
    {
        $blast = [
            'uuid'      => 'blast-3',
            'segmentId' => 'seg-1',
            'channel'   => 'email',
            'status'    => 'sending',
        ];
        $this->objectService->store['blast-3'] = $blast;
        $this->objectService->deliveries = [
            ['uuid' => 'd1', 'blastId' => 'blast-3', 'contactId' => 'c1', 'status' => 'queued'],
            ['uuid' => 'd2', 'blastId' => 'blast-3', 'contactId' => 'c1', 'status' => 'sent'],
            ['uuid' => 'd3', 'blastId' => 'blast-3', 'contactId' => 'c2', 'status' => 'queued'],
        ];

        $this->service->transitionQueuedDeliveries('c1', 'blast-3');

        $statusForD1 = null;
        $statusForD2 = null;
        $statusForD3 = null;
        foreach ($this->objectService->saved as $row) {
            if ($row['uuid'] === 'd1') {
                $statusForD1 = $row['status'];
            }
            if ($row['uuid'] === 'd2') {
                $statusForD2 = $row['status'];
            }
            if ($row['uuid'] === 'd3') {
                $statusForD3 = $row['status'];
            }
        }
        $this->assertSame('unsubscribed-before-send', $statusForD1);
        $this->assertNull($statusForD2, 'sent rows must not be touched');
        $this->assertNull($statusForD3, 'other contact rows must not be touched');
    }//end testTransitionQueuedDeliveriesFlipsOnlyQueuedRows()

    /**
     * createAbVariant clones name+segmentId+templateId, sets
     * abVariantOf=parent, and defaults the name suffix to "(Variant B)".
     *
     * @return void
     */
    public function testCreateAbVariantClonesParent(): void
    {
        $parent = [
            'uuid'              => 'blast-parent',
            'name'              => 'Q4 Gemeente',
            'segmentId'         => 'seg-1',
            'templateId'        => 'tmpl-1',
            'channel'           => 'email',
            'connectorSourceId' => 'oc-source-1',
            'createdBy'         => 'admin',
        ];
        $this->objectService->store['blast-parent'] = $parent;

        $childId = $this->service->createAbVariant('blast-parent', []);
        $this->assertNotSame('', $childId);

        $child = end($this->objectService->saved);
        $this->assertSame('seg-1', $child['segmentId']);
        $this->assertSame('tmpl-1', $child['templateId']);
        $this->assertSame('email', $child['channel']);
        $this->assertSame('blast-parent', $child['abVariantOf']);
        $this->assertStringContainsString('Variant B', $child['name']);
    }//end testCreateAbVariantClonesParent()
}//end class
