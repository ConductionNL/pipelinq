<?php

/**
 * Unit tests for BundleService (assembly, hashing, one-time secure download).
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

use OCA\Pipelinq\Service\Avg\AvgAccessService;
use OCA\Pipelinq\Service\Avg\AvgEventService;
use OCA\Pipelinq\Service\Avg\AvgRepository;
use OCA\Pipelinq\Service\Avg\AvgRequestService;
use OCA\Pipelinq\Service\Avg\BundleService;
use OCA\Pipelinq\Service\Avg\DeadlineService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once __DIR__.'/AvgTestSupport.php';

/**
 * Tests for BundleService.
 */
class BundleServiceTest extends TestCase
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
     * @var BundleService
     */
    private BundleService $service;

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
            static fn (string $app, string $key, string $default = '', bool $lazy = false): string
                => ($key === 'register' ? 'reg' : ($key === 'avg_pki_cert_path' ? '' : $key))
        );
        $appConfig->method('getValueInt')->willReturnCallback(
            static fn (string $app, string $key, int $default = 0, bool $lazy = false): int => $default
        );

        $this->repository = AvgRepositoryFactory::build($this->objectService, $appConfig);

        $requests = new AvgRequestService(
            repository: $this->repository,
            deadline: new DeadlineService(orGdpr: OrGdprBridgeFactory::build(new FakeOrGdpr())),
            access: new AvgAccessService(
                groupManager: $this->createMock(IGroupManager::class),
                appConfig: $appConfig
            ),
            events: new AvgEventService(repository: $this->repository, logger: new NullLogger()),
            logger: new NullLogger()
        );

        $this->service = new BundleService(
            repository: $this->repository,
            container: AvgRepositoryFactory::container($this->objectService),
            appConfig: $appConfig,
            orGdpr: OrGdprBridgeFactory::build(new FakeOrGdpr()),
            requests: $requests,
            logger: new NullLogger()
        );
    }//end setUp()

    /**
     * Seed a request plus one included evidence item, returning the request array.
     *
     * @return array<string, mixed> The stored request.
     */
    private function seedRequestWithEvidence(): array
    {
        $request = $this->objectService->saveObject(
            object: ['kenmerk' => 'AVG-2026-0001', 'artikel' => 'art-15-inzage', 'verzoekerNaam' => 'X'],
            extend: [],
            register: 'reg',
            schema: AvgRepository::SCHEMA_VERZOEK
        );
        $this->objectService->saveObject(
            object: [
                'verzoekId'         => $request['@self']['id'],
                'categorie'         => 'vergunning',
                'inhoudPreview'     => 'preview',
                'opgenomenInExport' => true,
            ],
            extend: [],
            register: 'reg',
            schema: AvgRepository::SCHEMA_BEWIJS_ITEM
        );

        return $request;
    }//end seedRequestWithEvidence()

    /**
     * Generating an empty bundle (no included evidence) is rejected.
     *
     * @return void
     */
    public function testGenerateRejectsEmptyBundle(): void
    {
        $request = $this->objectService->saveObject(
            object: ['kenmerk' => 'AVG-2026-0002'],
            extend: [],
            register: 'reg',
            schema: AvgRepository::SCHEMA_VERZOEK
        );

        $this->expectException(OCSBadRequestException::class);
        $this->service->generate(request: $request, userId: 'handler1');
    }//end testGenerateRejectsEmptyBundle()

    /**
     * Generation computes a hash, stores only the token hash, and seals with the
     * sha256-manifest fallback when no PKI cert is configured.
     *
     * @return void
     */
    public function testGenerateSealsAndHashesToken(): void
    {
        $result = $this->service->generate(request: $this->seedRequestWithEvidence(), userId: 'handler1');

        $this->assertNotSame('', (string) $result['downloadToken']);
        $this->assertSame('sha256-manifest', $result['bundle']['ondertekeningsType']);
        $this->assertSame(64, strlen((string) $result['bundle']['sha256']));
        $this->assertArrayNotHasKey('downloadCode', $result['bundle']);
        // The raw token is never stored; only its hash.
        $this->assertSame(
            hash('sha256', (string) $result['downloadToken']),
            (string) $result['bundle']['downloadCodeHash']
        );
    }//end testGenerateSealsAndHashesToken()

    /**
     * A correct token consumes the download once and confirms receipt; a wrong
     * token is rejected.
     *
     * @return void
     */
    public function testDownloadConsumesOnceAndRejectsWrongToken(): void
    {
        $result   = $this->service->generate(request: $this->seedRequestWithEvidence(), userId: 'handler1');
        $bundleId = (string) $result['bundle']['@self']['id'];
        $token    = (string) $result['downloadToken'];

        $consumed = $this->service->consumeDownload(bundleId: $bundleId, token: $token);
        $this->assertTrue($consumed['verzoekerOntvangstBevestigd']);
        $this->assertNotSame('', (string) $consumed['uitgeleverdOp']);

        $this->expectException(OCSForbiddenException::class);
        $this->service->consumeDownload(bundleId: $bundleId, token: 'wrong-token');
    }//end testDownloadConsumesOnceAndRejectsWrongToken()

    /**
     * The AP-escalation dossier bundles the request and its children.
     *
     * @return void
     */
    public function testAssembleDossierIncludesChildren(): void
    {
        $request   = $this->seedRequestWithEvidence();
        $verzoekId = (string) $request['@self']['id'];
        $this->objectService->saveObject(
            object: ['verzoekId' => $verzoekId, 'type' => 'ontvangstbevestiging-verstuurd'],
            extend: [],
            register: 'reg',
            schema: AvgRepository::SCHEMA_TERMIJN_EVENT
        );

        $dossier = $this->service->assembleDossier(request: $request);
        $this->assertCount(1, $dossier['termijnEvents']);
        $this->assertCount(1, $dossier['bewijsItems']);
        $this->assertSame('AVG-2026-0001', $dossier['verzoek']['kenmerk']);
    }//end testAssembleDossierIncludesChildren()
}//end class
