<?php

/**
 * Unit tests for RedactionService (own-data guard + field redaction).
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

use OCA\Pipelinq\Service\Avg\AvgRepository;
use OCA\Pipelinq\Service\Avg\RedactionService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once __DIR__.'/AvgTestSupport.php';

/**
 * Tests for RedactionService.
 */
class RedactionServiceTest extends TestCase
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
     * @var RedactionService
     */
    private RedactionService $service;

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
                => ($key === 'register' ? 'reg' : $key)
        );
        $this->repository = AvgRepositoryFactory::build($this->objectService, $appConfig);
        $this->service    = new RedactionService(repository: $this->repository, logger: new NullLogger());
    }//end setUp()

    /**
     * Seed an evidence item with the given preview, returning its id.
     *
     * @param string $preview The content preview.
     *
     * @return string The item id.
     */
    private function seedItem(string $preview): string
    {
        $saved = $this->objectService->saveObject(
            object: ['verzoekId' => 'v1', 'inhoudPreview' => $preview],
            extend: [],
            register: 'reg',
            schema: AvgRepository::SCHEMA_BEWIJS_ITEM
        );

        return (string) $saved['@self']['id'];
    }//end seedItem()

    /**
     * A third-party field is redacted and a RedactieActie is recorded.
     *
     * @return void
     */
    public function testRedactsThirdPartyField(): void
    {
        $itemId  = $this->seedItem('{"handhaver":{"naam":"J.C. de Boer"}}');
        $request = ['verzoekerNaam' => 'M.W. van der Berg', 'verzoekerBsn' => '123456782'];

        $result = $this->service->applyRedaction(
            request: $request,
            bewijsItemId: $itemId,
            fieldPath: '$.handhaver.naam',
            ground: 'bescherming-rechten-derden',
            replacement: '',
            userId: 'handler1'
        );

        $this->assertTrue($result['item']['geredigeerd']);
        $this->assertStringContainsString('geredigeerd', (string) $result['item']['inhoudPreview']);
        $this->assertStringNotContainsString('de Boer', (string) $result['item']['inhoudPreview']);

        $redactions = $this->repository->findAll(schemaKey: AvgRepository::SCHEMA_REDACTIE_ACTIE);
        $this->assertCount(1, $redactions);
    }//end testRedactsThirdPartyField()

    /**
     * Redacting the citizen's own data without the art. 23 ground is refused.
     *
     * @return void
     */
    public function testRefusesOwnDataWithoutArt23(): void
    {
        $itemId  = $this->seedItem('{"verzoeker":{"naam":"M.W. van der Berg"}}');
        $request = ['verzoekerNaam' => 'M.W. van der Berg', 'verzoekerBsn' => '123456782'];

        $this->expectException(OCSBadRequestException::class);
        $this->service->applyRedaction(
            request: $request,
            bewijsItemId: $itemId,
            fieldPath: '$.verzoeker.naam',
            ground: 'bescherming-rechten-derden',
            replacement: '',
            userId: 'handler1'
        );
    }//end testRefusesOwnDataWithoutArt23()

    /**
     * The citizen's own data CAN be redacted with the explicit art. 23 ground.
     *
     * @return void
     */
    public function testAllowsOwnDataWithArt23Ground(): void
    {
        $itemId  = $this->seedItem('{"verzoeker":{"naam":"M.W. van der Berg"}}');
        $request = ['verzoekerNaam' => 'M.W. van der Berg', 'verzoekerBsn' => '123456782'];

        $result = $this->service->applyRedaction(
            request: $request,
            bewijsItemId: $itemId,
            fieldPath: '$.verzoeker.naam',
            ground: RedactionService::GROUND_OWN_DATA,
            replacement: '',
            userId: 'handler1'
        );

        $this->assertTrue($result['item']['geredigeerd']);
    }//end testAllowsOwnDataWithArt23Ground()

    /**
     * isOwnData flags the BSN appearing in the field value.
     *
     * @return void
     */
    public function testIsOwnDataMatchesBsn(): void
    {
        $request = ['verzoekerNaam' => 'X', 'verzoekerBsn' => '123456782'];
        $this->assertTrue($this->service->isOwnData(
            fieldPath: '$.iets.bsn',
            current: 'bsn is 123456782',
            request: $request
        ));
        $this->assertFalse($this->service->isOwnData(
            fieldPath: '$.handhaver.naam',
            current: 'iemand anders',
            request: $request
        ));
    }//end testIsOwnDataMatchesBsn()
}//end class
