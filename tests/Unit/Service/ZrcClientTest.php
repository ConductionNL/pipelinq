<?php

/**
 * Unit tests for ZrcClient (createZaak mapping, ETag/412, rol idempotency, scope).
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Exception\DoubleWritePathException;
use OCA\Pipelinq\Exception\InsufficientScopeException;
use OCA\Pipelinq\Exception\OptimisticLockException;
use OCA\Pipelinq\Service\AcClient;
use OCA\Pipelinq\Service\ZgwApiClient;
use OCA\Pipelinq\Service\ZgwCoexistenceValidator;
use OCA\Pipelinq\Service\ZgwObjectRepository;
use OCA\Pipelinq\Service\ZrcClient;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ZrcClient.
 */
class ZrcClientTest extends TestCase
{
    /**
     * Endpoint fixture.
     *
     * @return array<string, mixed> The endpoint.
     */
    private function endpoint(): array
    {
        return [
            'id'           => 'zgw-ep-zoetermeer-openzaak',
            'clientId'     => 'zgw-client-zoetermeer',
            'gemeenteCode' => '0637',
            'componenten'  => ['zrc' => 'https://zrc/api/v1'],
        ];
    }//end endpoint()

    /**
     * A no-op coexistence validator mock (single write path; never raises).
     *
     * @return ZgwCoexistenceValidator&MockObject The validator mock.
     */
    private function coexistence(): ZgwCoexistenceValidator
    {
        return $this->createMock(ZgwCoexistenceValidator::class);
    }//end coexistence()

    /**
     * Client fixture.
     *
     * @return array<string, mixed> The client.
     */
    private function client(): array
    {
        return ['clientIdentifier' => 'pipelinq-zoetermeer'];
    }//end client()

    /**
     * createZaak captures the Location header into a persisted mapping with etag.
     *
     * @return void
     */
    public function testCreateZaakPersistsUrlMapping(): void
    {
        $api = $this->createMock(ZgwApiClient::class);
        $api->method('callComponent')->willReturn(
            [
                'status'  => 201,
                'body'    => [],
                'headers' => ['Location' => ['https://zrc/api/v1/zaken/abc-123']],
                'etag'    => 'W/"e1"',
            ]
        );

        $repo = $this->createMock(ZgwObjectRepository::class);
        $repo->expects($this->once())->method('save')->willReturnCallback(
            static fn(string $entity, array $data): array => array_merge(['@self' => ['uuid' => 'm1']], $data)
        );

        $ac = $this->createMock(AcClient::class);
        $ac->expects($this->once())->method('requireScope')
            ->with($this->anything(), $this->anything(), $this->anything(), 'zaken.aanmaken');

        $zrc     = new ZrcClient($api, $repo, $ac, $this->coexistence());
        $mapping = $zrc->createZaak(
            $this->endpoint(),
            $this->client(),
            ['zaaktype' => 'https://ztc/zaaktypen/x', 'omschrijving' => 'Evenementenvergunning'],
            'req-0456'
        );

        $this->assertSame('https://zrc/api/v1/zaken/abc-123', $mapping['zgwUrl']);
        $this->assertSame('zaak', $mapping['zgwResourceType']);
        $this->assertSame('abc-123', $mapping['zgwUuid']);
        $this->assertSame('W/"e1"', $mapping['etag']);
        $this->assertSame('req-0456', $mapping['pipelinqId']);
    }//end testCreateZaakPersistsUrlMapping()

    /**
     * createZaak is blocked before any HTTP call when the scope is missing.
     *
     * @return void
     */
    public function testCreateZaakBlockedByMissingScope(): void
    {
        $api = $this->createMock(ZgwApiClient::class);
        $api->expects($this->never())->method('callComponent');

        $ac = $this->createMock(AcClient::class);
        $ac->method('requireScope')->willThrowException(
            new InsufficientScopeException('zaken.aanmaken', 'https://ztc/zaaktypen/x')
        );

        $zrc = new ZrcClient($api, $this->createMock(ZgwObjectRepository::class), $ac, $this->coexistence());

        $this->expectException(InsufficientScopeException::class);
        $zrc->createZaak($this->endpoint(), $this->client(), ['zaaktype' => 'https://ztc/zaaktypen/x'], 'req-1');
    }//end testCreateZaakBlockedByMissingScope()

    /**
     * createZaak is blocked before any scope or HTTP call when both a StUF and a
     * ZGW write path are active for the gemeente (REQ-ZGW-008 double-write guard).
     *
     * @return void
     */
    public function testCreateZaakBlockedByDoubleWritePath(): void
    {
        $api = $this->createMock(ZgwApiClient::class);
        $api->expects($this->never())->method('callComponent');

        $ac = $this->createMock(AcClient::class);
        $ac->expects($this->never())->method('requireScope');

        $coexistence = $this->createMock(ZgwCoexistenceValidator::class);
        $coexistence->method('validateWritePath')->willThrowException(
            new DoubleWritePathException(
                message: 'Dubbele schrijf-koppeling voor gemeente 0637.',
                gemeenteCode: '0637',
                conflictingEndpoints: ['zgw:a', 'stuf:b']
            )
        );

        $zrc = new ZrcClient($api, $this->createMock(ZgwObjectRepository::class), $ac, $coexistence);

        $this->expectException(DoubleWritePathException::class);
        $zrc->createZaak($this->endpoint(), $this->client(), ['zaaktype' => 'https://ztc/zaaktypen/x'], 'req-1');
    }//end testCreateZaakBlockedByDoubleWritePath()

    /**
     * updateZaak sends If-Match and surfaces a 412 as OptimisticLockException.
     *
     * @return void
     */
    public function testUpdateZaak412RaisesOptimisticLock(): void
    {
        $api = $this->createMock(ZgwApiClient::class);
        // First call (PATCH) returns 412; second call (GET fresh) returns body.
        $api->method('callComponent')->willReturnOnConsecutiveCalls(
            ['status' => 412, 'body' => [], 'headers' => [], 'etag' => ''],
            ['status' => 200, 'body' => ['omschrijving' => 'server-side waarde'], 'headers' => [], 'etag' => 'W/"new"']
        );

        $zrc     = new ZrcClient($api, $this->createMock(ZgwObjectRepository::class), $this->createMock(AcClient::class), $this->coexistence());
        $mapping = ['zgwUrl' => 'https://zrc/api/v1/zaken/abc', 'etag' => 'W/"old"'];

        try {
            $zrc->updateZaak($this->endpoint(), $this->client(), $mapping, ['omschrijving' => 'nieuwe waarde']);
            $this->fail('Expected OptimisticLockException');
        } catch (OptimisticLockException $e) {
            $this->assertSame(['omschrijving' => 'nieuwe waarde'], $e->getStaleRepresentation());
            $this->assertSame('server-side waarde', $e->getFreshRepresentation()['omschrijving']);
            $this->assertSame('omschrijving', $e->getConflictingField());
        }
    }//end testUpdateZaak412RaisesOptimisticLock()

    /**
     * linkInitiator returns an existing rol URL without POSTing (idempotent).
     *
     * @return void
     */
    public function testLinkInitiatorIdempotentSkipsPost(): void
    {
        $api = $this->createMock(ZgwApiClient::class);
        // GET /rollen returns an existing rol matching the BSN.
        $api->expects($this->once())->method('callComponent')->willReturn(
            [
                'status'  => 200,
                'body'    => ['results' => [['url' => 'https://zrc/api/v1/rollen/existing', 'betrokkeneIdentificatie' => ['inpBsn' => '123456789']]]],
                'headers' => [],
                'etag'    => '',
            ]
        );

        $zrc = new ZrcClient($api, $this->createMock(ZgwObjectRepository::class), $this->createMock(AcClient::class), $this->coexistence());
        $url = $zrc->linkInitiator(
            $this->endpoint(),
            $this->client(),
            ['zgwUrl' => 'https://zrc/api/v1/zaken/abc'],
            ['bsn' => '123456789', 'role' => 'Aanvrager'],
            'https://ztc/roltypen/initiator'
        );

        $this->assertSame('https://zrc/api/v1/rollen/existing', $url);
    }//end testLinkInitiatorIdempotentSkipsPost()

    /**
     * linkInitiator POSTs a new rol with inpBsn when none exists yet.
     *
     * @return void
     */
    public function testLinkInitiatorCreatesRolWithBsn(): void
    {
        $captured = [];
        $api      = $this->createMock(ZgwApiClient::class);
        $api->method('callComponent')->willReturnCallback(
            function (string $url, string $method, string $path, array $client, ?array $body=null) use (&$captured): array {
                if ($method === 'GET') {
                    return ['status' => 200, 'body' => ['results' => []], 'headers' => [], 'etag' => ''];
                }

                $captured = $body;
                return ['status' => 201, 'body' => ['url' => 'https://zrc/api/v1/rollen/new'], 'headers' => [], 'etag' => ''];
            }
        );

        $zrc = new ZrcClient($api, $this->createMock(ZgwObjectRepository::class), $this->createMock(AcClient::class), $this->coexistence());
        $url = $zrc->linkInitiator(
            $this->endpoint(),
            $this->client(),
            ['zgwUrl' => 'https://zrc/api/v1/zaken/abc'],
            ['bsn' => '123456789'],
            'https://ztc/roltypen/initiator'
        );

        $this->assertSame('https://zrc/api/v1/rollen/new', $url);
        $this->assertSame('natuurlijk_persoon', $captured['betrokkeneType']);
        $this->assertSame(['inpBsn' => '123456789'], $captured['betrokkeneIdentificatie']);
    }//end testLinkInitiatorCreatesRolWithBsn()
}//end class
