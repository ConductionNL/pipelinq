<?php

/**
 * Unit tests for EvidenceCollectionService (federated collection + dedup).
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Avg
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Avg;

use OCA\Pipelinq\Service\Avg\AvgEventService;
use OCA\Pipelinq\Service\Avg\AvgRepository;
use OCA\Pipelinq\Service\Avg\EvidenceCollectionService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

require_once __DIR__.'/AvgTestSupport.php';

/**
 * Tests for EvidenceCollectionService.
 *
 * Drives the collection flow over the in-memory fake OR. Asserts: objects
 * matching the data-subject BSN become BewijsItems, scope filtering excludes
 * out-of-scope registers, identical content is deduplicated (kept once), an
 * unconfigured external source produces no run abort, and a BSN-less request is
 * a no-op.
 */
class EvidenceCollectionServiceTest extends TestCase
{

    /**
     * The fake OR ObjectService.
     *
     * @var FakeAvgObjectService
     */
    private FakeAvgObjectService $objectService;

    /**
     * The repository under test.
     *
     * @var AvgRepository
     */
    private AvgRepository $repository;

    /**
     * The service under test.
     *
     * @var EvidenceCollectionService
     */
    private EvidenceCollectionService $service;

    /**
     * The OR-side objects to return for a BSN findAll.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $orObjects = [];

    /**
     * The filter array passed to the OR ObjectService::findAll for the
     * cross-entity subject query — captured so the OR-consumption-boundary
     * test (REQ-AVG-014) can assert a plain equality filter is used rather
     * than the admin-gated OR DsarService PII-index path.
     *
     * @var array<string, mixed>
     */
    private array $orFilters = [];

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->objectService = new FakeAvgObjectService();
        $appConfig           = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            static fn (string $app, string $key, string $default='', bool $lazy=false): string
                => ($key === 'register' ? 'reg' : $key)
        );
        $this->repository = AvgRepositoryFactory::build($this->objectService, $appConfig);
        $events           = new AvgEventService(repository: $this->repository, logger: new NullLogger());

        // A container whose ObjectService::findAll returns a BSN-scoped result set
        // for evidence queries (limit/bsn filter), and partitions the AVG store by
        // schema for everything else. We delegate to a small wrapper so the same
        // fake backs the repository while answering the cross-entity BSN query.
        $orObjects     = &$this->orObjects;
        $orFilters     = &$this->orFilters;
        $objectService = $this->objectService;
        $container     = new class($objectService, $orObjects, $orFilters) implements ContainerInterface {
            /**
             * @param FakeAvgObjectService             $objectService The AVG store.
             * @param array<int, array<string, mixed>> $orObjects     The BSN result set.
             * @param array<string, mixed>             $orFilters     Captured OR find filters.
             */
            public function __construct(
                private FakeAvgObjectService $objectService,
                private array &$orObjects,
                private array &$orFilters
            ) {
            }//end __construct()

            /**
             * @param string $id The service id.
             *
             * @return mixed The service.
             */
            public function get(string $id): mixed
            {
                if ($id === 'OCA\OpenRegister\Service\ObjectService') {
                    $store     = $this->objectService;
                    $orObjects = &$this->orObjects;
                    $orFilters = &$this->orFilters;
                    return new class($store, $orObjects, $orFilters) {
                        /**
                         * @param FakeAvgObjectService             $store     The AVG store.
                         * @param array<int, array<string, mixed>> $orObjects The BSN result set.
                         * @param array<string, mixed>             $orFilters Captured OR find filters.
                         */
                        public function __construct(
                            private FakeAvgObjectService $store,
                            private array &$orObjects,
                            private array &$orFilters
                        ) {
                        }//end __construct()

                        /**
                         * @param array<string, mixed> $config The find config.
                         *
                         * @return array<int, array<string, mixed>> The results.
                         */
                        public function findAll(array $config): array
                        {
                            $filters = (array) ($config['filters'] ?? []);
                            if (isset($filters['bsn']) === true) {
                                $this->orFilters = $filters;
                                return $this->orObjects;
                            }

                            return $this->store->findAll($config);
                        }//end findAll()

                        /**
                         * @param array<string, mixed> $object   The object.
                         * @param array<string, mixed> $extend   Unused.
                         * @param string               $register The register.
                         * @param string               $schema   The schema.
                         * @param string|null          $uuid     The id.
                         *
                         * @return array<string, mixed> The saved object.
                         */
                        public function saveObject(array $object, array $extend, string $register, string $schema, ?string $uuid=null): array
                        {
                            return $this->store->saveObject($object, $extend, $register, $schema, $uuid);
                        }//end saveObject()

                        /**
                         * @param string $id       The id.
                         * @param string $register The register.
                         * @param string $schema   The schema.
                         *
                         * @return array<string, mixed>|null The object.
                         */
                        public function find(string $id, string $register, string $schema): ?array
                        {
                            return $this->store->find($id, $register, $schema);
                        }//end find()
                    };
                }//end if

                throw new \RuntimeException('not found: '.$id);
            }//end get()

            /**
             * @param string $id The service id.
             *
             * @return bool Whether the service exists.
             */
            public function has(string $id): bool
            {
                return ($id === 'OCA\OpenRegister\Service\ObjectService');
            }//end has()
        };

        $sourcesConfig = $this->createMock(IAppConfig::class);
        $sourcesConfig->method('getValueString')->willReturn('');

        $this->service = new EvidenceCollectionService(
            repository: $this->repository,
            container: $container,
            appConfig: $sourcesConfig,
            events: $events,
            logger: new NullLogger()
        );
    }//end setUp()

    /**
     * Seed a request as a stored array.
     *
     * @param array<string, mixed> $overrides Field overrides.
     *
     * @return array<string, mixed> The stored request.
     */
    private function seedRequest(array $overrides=[]): array
    {
        return $this->objectService->saveObject(
            object: array_merge(
                ['verzoekerBsn' => '123456782', 'scope' => []],
                $overrides
            ),
            extend: [],
            register: 'reg',
            schema: AvgRepository::SCHEMA_VERZOEK
        );
    }//end seedRequest()

    /**
     * Build an OR object with the given register, schema and payload.
     *
     * @param string               $register The register id.
     * @param string               $schema   The schema id.
     * @param array<string, mixed> $payload  The object payload.
     *
     * @return array<string, mixed> The OR object.
     */
    private function orObject(string $register, string $schema, array $payload): array
    {
        return array_merge(
            $payload,
            ['@self' => ['id' => uniqid('o', true), 'register' => $register, 'schema' => $schema]]
        );
    }//end orObject()

    /**
     * Count BewijsItems for a request.
     *
     * @param string $verzoekId The request id.
     *
     * @return array<int, array<string, mixed>> The items.
     */
    private function evidenceFor(string $verzoekId): array
    {
        return $this->repository->findAll(
            schemaKey: AvgRepository::SCHEMA_BEWIJS_ITEM,
            filters: ['verzoekId' => $verzoekId]
        );
    }//end evidenceFor()

    /**
     * Matching OR objects become BewijsItems linked to the request.
     *
     * @return void
     */
    public function testCollectsMatchingObjectsAsEvidence(): void
    {
        $this->orObjects = [
            $this->orObject('zaken', 'zaak', ['naam' => 'Aanvraag A']),
            $this->orObject('contacten', 'contact', ['naam' => 'Burger']),
        ];
        $request         = $this->seedRequest();

        $result = $this->service->collect(request: $request);

        $this->assertSame(2, $result['collected']);
        $this->assertCount(2, $this->evidenceFor($this->repository->idOf($request)));
    }//end testCollectsMatchingObjectsAsEvidence()

    /**
     * A scope filter excludes out-of-scope registers.
     *
     * @return void
     */
    public function testScopeFilterExcludesOutOfScope(): void
    {
        $this->orObjects = [
            $this->orObject('zaken', 'zaak', ['naam' => 'In scope']),
            $this->orObject('financien', 'factuur', ['bedrag' => 10]),
        ];
        $request         = $this->seedRequest(['scope' => ['zaken']]);

        $result = $this->service->collect(request: $request);

        $this->assertSame(1, $result['collected']);
    }//end testScopeFilterExcludesOutOfScope()

    /**
     * Identical content is deduplicated — the duplicate is flagged and excluded
     * from export, the original is kept.
     *
     * @return void
     */
    public function testDeduplicatesIdenticalContent(): void
    {
        $payload         = ['naam' => 'Zelfde inhoud', 'waarde' => 42];
        $this->orObjects = [
            $this->orObject('zaken', 'zaak', $payload),
            $this->orObject('archief', 'zaak', $payload),
        ];
        $request         = $this->seedRequest();

        $this->service->collect(request: $request);

        $items      = $this->evidenceFor($this->repository->idOf($request));
        $duplicates = array_filter($items, static fn (array $i): bool => (bool) ($i['gedupliceerd'] ?? false) === true);
        $this->assertCount(1, $duplicates);
        foreach ($duplicates as $dup) {
            $this->assertFalse($dup['opgenomenInExport']);
        }
    }//end testDeduplicatesIdenticalContent()

    /**
     * A request without a BSN collects nothing (no source query).
     *
     * @return void
     */
    public function testNoBsnIsNoOp(): void
    {
        $this->orObjects = [$this->orObject('zaken', 'zaak', ['naam' => 'X'])];
        $request         = $this->seedRequest(['verzoekerBsn' => '']);

        $result = $this->service->collect(request: $request);

        $this->assertSame(0, $result['collected']);
        $this->assertCount(0, $this->evidenceFor($this->repository->idOf($request)));
    }//end testNoBsnIsNoOp()

    /**
     * REQ-AVG-014 boundary: the subject find delegates to OR's generic
     * ObjectService::findAll with a PLAIN equality filter on `bsn` — not the
     * admin-gated OR DsarService PII-index path. Pinning this guards against a
     * future Seam-3 migration silently changing the find surface (the OR
     * compliance subsystem matches the openregister_entities PII index and
     * throws for non-admin callers; the AVG handler must not depend on that).
     *
     * @return void
     */
    public function testSubjectFindUsesPlainEqualityFilterNotDsar(): void
    {
        $this->orObjects = [$this->orObject('zaken', 'zaak', ['naam' => 'A'])];
        $request         = $this->seedRequest(['verzoekerBsn' => '123456782']);

        $this->service->collect(request: $request);

        // Exactly a plain equality filter on the subject identifier reached OR.
        $this->assertSame(['bsn' => '123456782'], $this->orFilters);
        // No PII-index / type / mode keys (the DsarService contract) leaked in.
        $this->assertArrayNotHasKey('type', $this->orFilters);
        $this->assertArrayNotHasKey('mode', $this->orFilters);
    }//end testSubjectFindUsesPlainEqualityFilterNotDsar()
}//end class
