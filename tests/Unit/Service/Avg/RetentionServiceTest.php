<?php

/**
 * Unit tests for RetentionService (pseudonymization + dossier deletion).
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

use DateTimeImmutable;
use OCA\Pipelinq\Service\Avg\AvgRepository;
use OCA\Pipelinq\Service\Avg\RetentionService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once __DIR__.'/AvgTestSupport.php';

/**
 * Tests for RetentionService.
 */
class RetentionServiceTest extends TestCase
{

    /**
     * The fake OR ObjectService.
     *
     * @var FakeAvgObjectService
     */
    private FakeAvgObjectService $objectService;

    /**
     * The repository.
     *
     * @var AvgRepository
     */
    private AvgRepository $repository;

    /**
     * The service under test.
     *
     * @var RetentionService
     */
    private RetentionService $service;

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
        $appConfig->method('getValueInt')->willReturnCallback(
            static fn (string $app, string $key, int $default=0, bool $lazy=false): int => $default
        );

        $this->repository = AvgRepositoryFactory::build($this->objectService, $appConfig);
        $this->service    = new RetentionService(
            repository: $this->repository,
            appConfig: $appConfig,
            logger: new NullLogger()
        );
    }//end setUp()

    /**
     * Evidence older than 30 days is pseudonymized; recent evidence is kept.
     *
     * @return void
     */
    public function testPseudonymizesOnlyExpiredEvidence(): void
    {
        $this->objectService->saveObject(
            object: ['verzoekId' => 'v1', 'inhoudPreview' => 'oude PII', 'verzameldOp' => (new DateTimeImmutable('-40 days'))->format('c')],
            extend: [],
            register: 'reg',
            schema: AvgRepository::SCHEMA_BEWIJS_ITEM
        );
        $this->objectService->saveObject(
            object: ['verzoekId' => 'v1', 'inhoudPreview' => 'verse PII', 'verzameldOp' => (new DateTimeImmutable('-5 days'))->format('c')],
            extend: [],
            register: 'reg',
            schema: AvgRepository::SCHEMA_BEWIJS_ITEM
        );

        $count = $this->service->pseudonymizeExpiredEvidence(now: new DateTimeImmutable());
        $this->assertSame(1, $count);

        $items    = $this->repository->findAll(schemaKey: AvgRepository::SCHEMA_BEWIJS_ITEM, filters: ['verzoekId' => 'v1']);
        $previews = array_map(static fn (array $i): string => (string) $i['inhoudPreview'], $items);
        $this->assertContains('verse PII', $previews);
        $this->assertNotContains('oude PII', $previews);
    }//end testPseudonymizesOnlyExpiredEvidence()

    /**
     * Dossiers past their retention date are deleted with their children.
     *
     * @return void
     */
    public function testDeletesExpiredDossiers(): void
    {
        $request   = $this->objectService->saveObject(
            object: ['kenmerk' => 'AVG-2021-0001', 'retentieTot' => (new DateTimeImmutable('-1 day'))->format('c')],
            extend: [],
            register: 'reg',
            schema: AvgRepository::SCHEMA_VERZOEK
        );
        $verzoekId = (string) $request['@self']['id'];

        $this->objectService->saveObject(
            object: ['verzoekId' => $verzoekId, 'type' => 'ontvangstbevestiging-verstuurd'],
            extend: [],
            register: 'reg',
            schema: AvgRepository::SCHEMA_TERMIJN_EVENT
        );

        // A still-active dossier must survive.
        $this->objectService->saveObject(
            object: ['kenmerk' => 'AVG-2026-0009', 'retentieTot' => (new DateTimeImmutable('+1 year'))->format('c')],
            extend: [],
            register: 'reg',
            schema: AvgRepository::SCHEMA_VERZOEK
        );

        $deleted = $this->service->deleteExpiredDossiers(now: new DateTimeImmutable());
        $this->assertSame(1, $deleted);

        $this->assertNull($this->repository->findOrNull(schemaKey: AvgRepository::SCHEMA_VERZOEK, id: $verzoekId));
        $this->assertCount(0, $this->repository->findAll(schemaKey: AvgRepository::SCHEMA_TERMIJN_EVENT, filters: ['verzoekId' => $verzoekId]));
        $this->assertCount(1, $this->repository->findAll(schemaKey: AvgRepository::SCHEMA_VERZOEK));
    }//end testDeletesExpiredDossiers()

    /**
     * REQ-AVG-014 boundary: the evidence pseudonymisation cut-off is the
     * app-owned `verzameldOp + N days` window (default 30), NOT OR's
     * processing-activity / audit-trail `MAX(created)` mechanism. Pinning the
     * exact cut-off (just-inside survives, just-outside pseudonymised) guards
     * against a future Seam-3 migration onto OR's AvgRetentionService silently
     * re-timing erasure.
     *
     * @return void
     */
    public function testEvidenceCutoffIsAppOwnedThirtyDayWindow(): void
    {
        $now = new DateTimeImmutable('2026-06-21T12:00:00+00:00');

        // 29 days old — just inside the 30-day window, must be kept.
        $this->objectService->saveObject(
            object: ['verzoekId' => 'v1', 'inhoudPreview' => 'binnen-termijn', 'verzameldOp' => $now->modify('-29 days')->format('c')],
            extend: [],
            register: 'reg',
            schema: AvgRepository::SCHEMA_BEWIJS_ITEM
        );
        // 31 days old — past the window, must be pseudonymised.
        $this->objectService->saveObject(
            object: ['verzoekId' => 'v1', 'inhoudPreview' => 'buiten-termijn', 'verzameldOp' => $now->modify('-31 days')->format('c')],
            extend: [],
            register: 'reg',
            schema: AvgRepository::SCHEMA_BEWIJS_ITEM
        );

        $count = $this->service->pseudonymizeExpiredEvidence(now: $now);
        $this->assertSame(1, $count, 'Exactly the over-30-day item is pseudonymised.');

        $items    = $this->repository->findAll(schemaKey: AvgRepository::SCHEMA_BEWIJS_ITEM, filters: ['verzoekId' => 'v1']);
        $previews = array_map(static fn (array $i): string => (string) $i['inhoudPreview'], $items);
        $this->assertContains('binnen-termijn', $previews);
        $this->assertNotContains('buiten-termijn', $previews);
    }//end testEvidenceCutoffIsAppOwnedThirtyDayWindow()
}//end class
