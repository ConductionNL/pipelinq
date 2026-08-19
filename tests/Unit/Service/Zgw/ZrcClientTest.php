<?php

/**
 * Unit tests for ZrcClient.
 *
 * Covers REQ-ZGW-002 (createZaak → ZgwResourceMapping), REQ-ZGW-009
 * (PATCH preserves ETag, 412 → OptimisticLockException with both states),
 * REQ-ZGW-010 (idempotent linkInitiator) and contact identification.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Zgw
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#req-zgw-002
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#req-zgw-009
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#req-zgw-010
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Zgw;

use OCA\Pipelinq\Service\Zgw\AcClient;
use OCA\Pipelinq\Service\Zgw\OptimisticLockException;
use OCA\Pipelinq\Service\Zgw\ZgwApiClient;
use OCA\Pipelinq\Service\Zgw\ZgwRegisterAccess;
use OCA\Pipelinq\Service\Zgw\ZrcClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ZrcClient.
 *
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#req-zgw-002
 */
class ZrcClientTest extends TestCase
{
    /**
     * The ZGW endpoint payload used across tests.
     *
     * @var array<string, mixed>
     */
    private array $endpoint;

    /**
     * Captured saves emitted via ZgwRegisterAccess::save().
     *
     * @var array<int, array{schema:string, data:array<string,mixed>, uuid:?string}>
     */
    private array $saves = [];


    /**
     * Set up.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->endpoint = [
            'id'           => 'zgw-ep-zoetermeer-openzaak',
            'gemeenteCode' => '0637',
            'clientId'     => 'zgw-client-zoetermeer',
            'componenten'  => [
                'zrc' => 'https://open-zaak.zoetermeer.nl/zaken/api/v1',
                'drc' => 'https://open-zaak.zoetermeer.nl/documenten/api/v1',
                'brc' => 'https://open-zaak.zoetermeer.nl/besluiten/api/v1',
                'ztc' => 'https://open-zaak.zoetermeer.nl/catalogi/api/v1',
                'ac'  => 'https://open-zaak.zoetermeer.nl/autorisaties/api/v1',
                'nrc' => 'https://open-notificaties.zoetermeer.nl/api/v1',
            ],
        ];
    }//end setUp()


    /**
     * Build the collaborators with controllable responses.
     *
     * @param array<int, array<string, mixed>> $responses Responses from callComponent (FIFO).
     * @param bool                             $grantScope When false, AcClient::require() raises.
     *
     * @return ZrcClient
     */
    private function buildClient(array $responses, bool $grantScope = true): ZrcClient
    {
        $api = $this->createMock(ZgwApiClient::class);
        $api->method('callComponent')->willReturnOnConsecutiveCalls(...$responses);

        $registers = $this->createMock(ZgwRegisterAccess::class);
        $registers->method('findClientForEndpoint')->willReturn([
            'clientIdentifier'  => 'pipelinq-zoetermeer',
            'secretKluisRef'    => 'vault://zgw/zoetermeer/client-secret',
            'userId'            => 'pipelinq',
            'userRepresentation' => 'Pipelinq backend',
        ]);
        $registers->method('save')->willReturnCallback(
            function (string $schema, array $data, ?string $uuid = null): array {
                $this->saves[] = ['schema' => $schema, 'data' => $data, 'uuid' => $uuid];
                return $data;
            }
        );

        $ac = $this->createMock(AcClient::class);
        if ($grantScope === true) {
            $ac->method('require')->willReturnCallback(static fn () => null);
        } else {
            $ac->method('require')->willThrowException(
                new \OCA\Pipelinq\Service\Zgw\InsufficientScopeException('zaken.aanmaken', 'https://ztc/zaaktype/1')
            );
        }

        return new ZrcClient($api, $registers, $ac, $this->createMock(LoggerInterface::class));
    }//end buildClient()


    /**
     * createZaak persists URL mapping (REQ-ZGW-002).
     *
     * @return void
     */
    public function testCreateZaakPersistsMapping(): void
    {
        $zaakUrl = 'https://open-zaak.zoetermeer.nl/zaken/api/v1/zaken/3f9a4f1e-1a0d-4d10-9b22-c1ef0b8fbb2a';

        $client = $this->buildClient([
            ['status' => 201, 'headers' => ['location' => $zaakUrl, 'etag' => 'W/"a1b2c3"'], 'body' => []],
        ]);

        $mapping = $client->createZaak(
            $this->endpoint,
            [
                'bronorganisatie'              => '002564440',
                'zaaktype'                     => 'https://ztc/zaaktype/1',
                'verantwoordelijkeOrganisatie' => '002564440',
                'startdatum'                   => '2026-05-21',
                'registratiedatum'             => '2026-05-21',
                'omschrijving'                 => 'Aanvraag evenementenvergunning Stadshart Run',
            ],
            'req-2026-evenement-zoetermeer-0456'
        );

        self::assertSame('zaak', $mapping['zgwResourceType']);
        self::assertSame($zaakUrl, $mapping['zgwUrl']);
        self::assertSame('3f9a4f1e-1a0d-4d10-9b22-c1ef0b8fbb2a', $mapping['zgwUuid']);
        self::assertSame('W/"a1b2c3"', $mapping['etag']);
        self::assertSame('zgw-ep-zoetermeer-openzaak', $mapping['endpointId']);
        self::assertSame('req-2026-evenement-zoetermeer-0456', $mapping['pipelinqId']);

        self::assertCount(1, $this->saves);
        self::assertSame(ZgwRegisterAccess::SCHEMA_MAPPING, $this->saves[0]['schema']);
    }//end testCreateZaakPersistsMapping()


    /**
     * Missing scope blocks createZaak (REQ-ZGW-006 cross-check).
     *
     * @return void
     */
    public function testMissingScopeBlocksCreateZaak(): void
    {
        $client = $this->buildClient([
            ['status' => 201, 'headers' => [], 'body' => []],
        ], grantScope: false);

        $this->expectException(\OCA\Pipelinq\Service\Zgw\InsufficientScopeException::class);
        $client->createZaak(
            $this->endpoint,
            ['zaaktype' => 'https://ztc/zaaktype/1'],
            'req-zoetermeer-test'
        );
    }//end testMissingScopeBlocksCreateZaak()


    /**
     * 412 → OptimisticLockException carrying both states (REQ-ZGW-009).
     *
     * @return void
     */
    public function testUpdateZaak412RaisesOptimisticLockException(): void
    {
        $api = $this->createMock(ZgwApiClient::class);
        $api->method('callComponent')->willReturnCallback(
            static function (
                string $componentUrl,
                string $method,
                string $path,
                array $client,
                ?array $body = null,
                array $extraHeaders = [],
                array $query = [],
            ): array {
                if ($method === 'PATCH') {
                    throw new OptimisticLockException(
                        'precond-failed',
                        staleRepresentation: [],
                        freshRepresentation: []
                    );
                }
                if ($method === 'GET') {
                    return [
                        'status'  => 200,
                        'headers' => ['etag' => 'W/"server-fresh"'],
                        'body'    => ['omschrijving' => 'Server-updated', 'zaaktype' => 'https://ztc/zaaktype/1'],
                    ];
                }
                return ['status' => 200, 'headers' => [], 'body' => []];
            }
        );

        $registers = $this->createMock(ZgwRegisterAccess::class);
        $registers->method('findClientForEndpoint')->willReturn([
            'clientIdentifier'  => 'pipelinq-zoetermeer',
            'secretKluisRef'    => 'vault://x',
            'userId'            => 'pipelinq',
            'userRepresentation' => 'Pipelinq',
        ]);
        $ac = $this->createMock(AcClient::class);
        $ac->method('require')->willReturnCallback(static fn () => null);

        $client = new ZrcClient($api, $registers, $ac, $this->createMock(LoggerInterface::class));

        $mapping = [
            'zgwUrl'         => 'https://open-zaak.zoetermeer.nl/zaken/api/v1/zaken/aaa',
            'zgwResourceType' => 'zaak',
            'etag'           => 'W/"stale"',
            'zaaktype'       => 'https://ztc/zaaktype/1',
        ];

        try {
            $client->updateZaak($this->endpoint, $mapping, ['omschrijving' => 'Local edit']);
            self::fail('expected OptimisticLockException');
        } catch (OptimisticLockException $e) {
            self::assertSame(['omschrijving' => 'Local edit'], $e->staleRepresentation);
            self::assertSame('Server-updated', $e->freshRepresentation['omschrijving']);
            self::assertNotSame('', $e->getMessage());
        }
    }//end testUpdateZaak412RaisesOptimisticLockException()


    /**
     * linkInitiator: existing rol → skip POST (REQ-ZGW-010 idempotency).
     *
     * @return void
     */
    public function testLinkInitiatorIdempotentSkipsPost(): void
    {
        $existingRolUrl = 'https://open-zaak.zoetermeer.nl/zaken/api/v1/rollen/77ee44aa-1234-4d10-9b22-aabbccddeeff';
        $api = $this->createMock(ZgwApiClient::class);
        $api->expects(self::once())
            ->method('callComponent')
            ->willReturnCallback(
                static function (string $componentUrl, string $method, string $path, array $client): array {
                    if ($method !== 'GET' || $path !== '/rollen') {
                        throw new \RuntimeException('unexpected '.$method.' '.$path);
                    }
                    return [
                        'status'  => 200,
                        'headers' => [],
                        'body'    => [
                            'results' => [[
                                'url'                     => 'https://open-zaak.zoetermeer.nl/zaken/api/v1/rollen/77ee44aa-1234-4d10-9b22-aabbccddeeff',
                                'betrokkeneIdentificatie' => ['inpBsn' => '123456789'],
                            ]],
                        ],
                    ];
                }
            );

        $registers = $this->createMock(ZgwRegisterAccess::class);
        $registers->method('findClientForEndpoint')->willReturn([
            'clientIdentifier'  => 'pipelinq-zoetermeer',
            'secretKluisRef'    => 'vault://x',
            'userId'            => 'pipelinq',
            'userRepresentation' => 'Pipelinq',
        ]);
        $ac     = $this->createMock(AcClient::class);
        $client = new ZrcClient($api, $registers, $ac, $this->createMock(LoggerInterface::class));

        $url = $client->linkInitiator(
            $this->endpoint,
            ['zgwUrl' => 'https://open-zaak.zoetermeer.nl/zaken/api/v1/zaken/abc'],
            ['bsn' => '123456789', 'naam' => 'Jeroen van der Velde'],
            'https://ztc/roltype/initiator'
        );

        self::assertSame($existingRolUrl, $url);
    }//end testLinkInitiatorIdempotentSkipsPost()


    /**
     * contactIdentification picks the right betrokkeneType per identification.
     *
     * @return void
     */
    public function testContactIdentificationPicksRightType(): void
    {
        [$type, $ident] = ZrcClient::contactIdentification(['bsn' => '123456789']);
        self::assertSame('natuurlijk_persoon', $type);
        self::assertSame(['inpBsn' => '123456789'], $ident);

        [$type, $ident] = ZrcClient::contactIdentification(['rsin' => '002564440']);
        self::assertSame('niet_natuurlijk_persoon', $type);
        self::assertSame(['innNnpId' => '002564440'], $ident);

        [$type, $ident] = ZrcClient::contactIdentification(['name' => 'Acme Stichting']);
        self::assertSame('niet_natuurlijk_persoon', $type);
        self::assertSame(['statutaireNaam' => 'Acme Stichting'], $ident);
    }//end testContactIdentificationPicksRightType()


}//end class
