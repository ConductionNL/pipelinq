<?php

/**
 * Unit tests for DenialService (art. 23 validation + finalization).
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
use OCA\Pipelinq\Service\Avg\AvgRepository;
use OCA\Pipelinq\Service\Avg\DenialService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once __DIR__.'/AvgTestSupport.php';

/**
 * Tests for DenialService.
 */
class DenialServiceTest extends TestCase
{
    /**
     * The service under test.
     *
     * @var DenialService
     */
    private DenialService $service;

    /**
     * The repository.
     *
     * @var AvgRepository
     */
    private AvgRepository $repository;

    /**
     * A valid 100+ char motivation.
     *
     * @var string
     */
    private string $motivation;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $objectService = new FakeAvgObjectService();
        $appConfig     = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            static fn (string $app, string $key, string $default = '', bool $lazy = false): string
                => ($key === 'register' ? 'reg' : $key)
        );
        $this->repository = AvgRepositoryFactory::build($objectService, $appConfig);

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isAdmin')->willReturn(true);
        $access = new AvgAccessService(groupManager: $groupManager, appConfig: $appConfig);

        $this->service    = new DenialService(repository: $this->repository, access: $access, logger: new NullLogger());
        $this->motivation = str_repeat('De financiele administratie valt onder een wettelijke bewaarplicht. ', 3);
    }//end setUp()

    /**
     * A denial with a too-short motivation is rejected.
     *
     * @return void
     */
    public function testRejectsShortMotivation(): void
    {
        $this->expectException(OCSBadRequestException::class);
        $this->service->createOrUpdate(
            verzoekId: 'v1',
            input: [
                'weigering'        => 'gedeeltelijk',
                'grond'            => 'art-23-lid-1-sub-e',
                'toelichtingAvg23' => 'te kort',
            ],
            userId: 'handler1'
        );
    }//end testRejectsShortMotivation()

    /**
     * A denial with an invalid ground is rejected.
     *
     * @return void
     */
    public function testRejectsInvalidGround(): void
    {
        $this->expectException(OCSBadRequestException::class);
        $this->service->createOrUpdate(
            verzoekId: 'v1',
            input: [
                'weigering'        => 'geheel',
                'grond'            => 'art-99-onzin',
                'toelichtingAvg23' => $this->motivation,
            ],
            userId: 'handler1'
        );
    }//end testRejectsInvalidGround()

    /**
     * Finalization is blocked without an AP complaint reference.
     *
     * @return void
     */
    public function testFinalizeBlockedWithoutApReference(): void
    {
        $this->service->createOrUpdate(
            verzoekId: 'v1',
            input: [
                'weigering'        => 'geheel',
                'grond'            => 'art-23-lid-1-sub-d',
                'toelichtingAvg23' => $this->motivation,
            ],
            userId: 'handler1'
        );

        $this->expectException(OCSBadRequestException::class);
        $this->service->finalize(verzoekId: 'v1', userId: 'handler1');
    }//end testFinalizeBlockedWithoutApReference()

    /**
     * A valid denial finalizes, sets the signer, and becomes immutable.
     *
     * @return void
     */
    public function testFinalizeSucceedsWithApReferenceAndLocks(): void
    {
        $this->service->createOrUpdate(
            verzoekId: 'v1',
            input: [
                'weigering'        => 'geheel',
                'grond'            => 'art-23-lid-1-sub-d',
                'toelichtingAvg23' => $this->motivation,
                'verwijzingAp'     => 'https://autoriteitpersoonsgegevens.nl/melding-doen',
            ],
            userId: 'handler1'
        );

        $finalized = $this->service->finalize(verzoekId: 'v1', userId: 'handler1');
        $this->assertTrue($finalized['gefinaliseerd']);
        $this->assertSame('handler1', $finalized['ondertekendDoor']);

        // A finalized denial can no longer be updated.
        $this->expectException(OCSBadRequestException::class);
        $this->service->createOrUpdate(
            verzoekId: 'v1',
            input: [
                'weigering'        => 'gedeeltelijk',
                'grond'            => 'art-23-lid-1-sub-e',
                'toelichtingAvg23' => $this->motivation,
            ],
            userId: 'handler1'
        );
    }//end testFinalizeSucceedsWithApReferenceAndLocks()
}//end class
