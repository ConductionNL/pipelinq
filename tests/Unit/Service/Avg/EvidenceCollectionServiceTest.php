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
 * Drives the collection flow over the in-memory fake OR. Discovery now ADOPTS
 * OpenRegister's canonical NER-index discovery (findSubjectData) instead of the
 * earlier BSN-equality findAll filter. Asserts the NEW behaviour: objects the OR
 * NER index ties to the subject become BewijsItems, scope filtering excludes
 * out-of-scope registers, identical content is deduplicated (kept once), an
 * unconfigured external source produces no run abort, a BSN-less request is a
 * no-op, and discovery goes through OR's findSubjectData (not a plain bsn filter).
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
     * The fake OR GDPR capability backing the NER-index discovery.
     *
     * @var FakeOrGdpr
     */
    private FakeOrGdpr $orGdpr;

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

        // The container is now used only for the best-effort OpenConnector probe
        // (external AVG-export sources). OR subject discovery goes through the
        // OrGdprBridge / NER index, not through this container. The probe reports
        // OpenConnector as absent so external collection is a graceful no-op.
        $container = new class implements ContainerInterface {
            /**
             * @param string $id The service id.
             *
             * @return mixed Never returns; no service is exposed.
             */
            public function get(string $id): mixed
            {
                throw new \RuntimeException('not found: '.$id);
            }//end get()

            /**
             * @param string $id The service id.
             *
             * @return bool Always false (no external source wired).
             */
            public function has(string $id): bool
            {
                return false;
            }//end has()
        };

        $sourcesConfig = $this->createMock(IAppConfig::class);
        $sourcesConfig->method('getValueString')->willReturn('');

        $this->orGdpr  = new FakeOrGdpr();
        $this->service = new EvidenceCollectionService(
            repository: $this->repository,
            container: $container,
            appConfig: $sourcesConfig,
            events: $events,
            orGdpr: OrGdprBridgeFactory::build($this->orGdpr),
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
     * Build an OR NER-discovery envelope ({object, gdprEntities}) for the given
     * register, schema and payload — the shape findSubjectData returns.
     *
     * @param string               $register The register id.
     * @param string               $schema   The schema id.
     * @param array<string, mixed> $payload  The object payload.
     *
     * @return array<string, mixed> The discovery envelope.
     */
    private function orObject(string $register, string $schema, array $payload): array
    {
        $object = array_merge(
            $payload,
            ['@self' => ['id' => uniqid('o', true), 'register' => $register, 'schema' => $schema]]
        );

        return [
            'object'       => $object,
            'gdprEntities' => [['type' => 'bsn', 'value' => '123456782', 'category' => 'bsn', 'detectedAt' => '']],
        ];
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
        $this->orGdpr->subjectData = [
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
        $this->orGdpr->subjectData = [
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
        $this->orGdpr->subjectData = [
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
        $this->orGdpr->subjectData = [$this->orObject('zaken', 'zaak', ['naam' => 'X'])];
        $request         = $this->seedRequest(['verzoekerBsn' => '']);

        $result = $this->service->collect(request: $request);

        $this->assertSame(0, $result['collected']);
        $this->assertCount(0, $this->evidenceFor($this->repository->idOf($request)));
    }//end testNoBsnIsNoOp()

    /**
     * AUTHORIZED behavioural change: subject discovery now goes through OR's
     * canonical NER-index discovery (DataSubjectRequestService::findSubjectData),
     * NOT the earlier plain `bsn`-equality ObjectService::findAll. This test pins
     * the new consumption boundary: the BSN is passed straight to findSubjectData
     * as the subject identifier (no synthetic `bsn` column filter), so discovery
     * picks up every object the NER index ties to the subject.
     *
     * @return void
     */
    public function testSubjectDiscoveryUsesOrNerIndex(): void
    {
        $this->orGdpr->subjectData = [$this->orObject('zaken', 'zaak', ['naam' => 'A'])];
        $request                   = $this->seedRequest(['verzoekerBsn' => '123456782']);

        $this->service->collect(request: $request);

        // Exactly one findSubjectData call, with the subject's BSN as the subject id.
        $discovery = array_values(
            array_filter($this->orGdpr->calls, static fn (array $c): bool => $c[0] === 'findSubjectData')
        );
        $this->assertCount(1, $discovery);
        $this->assertSame('123456782', $discovery[0][1]['subjectId']);
    }//end testSubjectDiscoveryUsesOrNerIndex()
}//end class
