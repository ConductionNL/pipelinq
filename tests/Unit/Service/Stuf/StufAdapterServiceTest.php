<?php

/**
 * Integration tests for StufAdapterService (creeerZaak, retry, vrijBericht).
 *
 * These tests wire real builder/parser/handler/mapper instances against
 * mocked StufHttpClient + StufRegisterAccess, so the end-to-end orchestration
 * (build envelope → send → parse Bv01 → persist mapping → retry on 503) is
 * exercised without touching OpenRegister or the network.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Stuf
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-002
 * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-004
 * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-007
 * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-009
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Stuf;

use OCA\Pipelinq\Service\Stuf\CircuitBreakerService;
use OCA\Pipelinq\Service\Stuf\ContactBetrokkeneMapper;
use OCA\Pipelinq\Service\Stuf\NeedsInputDispatcher;
use OCA\Pipelinq\Service\Stuf\StufAdapterService;
use OCA\Pipelinq\Service\Stuf\StufEnvelopeBuilder;
use OCA\Pipelinq\Service\Stuf\StufHttpClient;
use OCA\Pipelinq\Service\Stuf\StufMessageHandler;
use OCA\Pipelinq\Service\Stuf\StufMessageParser;
use OCA\Pipelinq\Service\Stuf\StufRegisterAccess;
use OCA\Pipelinq\Service\Stuf\StufVaultService;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for StufAdapterService.
 */
class StufAdapterServiceTest extends TestCase
{
    private StufAdapterService $adapter;

    /**
     * @var StufHttpClient&MockObject
     */
    private StufHttpClient $httpClient;

    /**
     * @var StufRegisterAccess&MockObject
     */
    private StufRegisterAccess $register;

    /**
     * @var CircuitBreakerService&MockObject
     */
    private CircuitBreakerService $circuitBreaker;

    /**
     * @var IJobList&MockObject
     */
    private IJobList $jobList;

    /**
     * In-memory register state.
     *
     * @var array
     */
    private array $savedObjects = [];

    /**
     * Fixture: endpoint.
     *
     * @return array
     */
    private function endpointFixture(): array
    {
        return [
            'id'                  => 'stuf-ep-test',
            'naam'                => 'Test',
            'ontvangerApplicatie' => 'Key2Zaken',
            'ontvangerOrganisatie' => 'Gemeente Test',
            'ontvangerGebruiker'  => 'pipelinq',
            'zenderApplicatie'    => 'Pipelinq',
            'zenderOrganisatie'   => 'Gemeente Test',
            'endpointUrl'         => 'https://test.example/stuf',
            'soapVersion'         => '1.1',
            'stufVersion'         => '0310',
            'sectormodel'         => 'ZKN',
            'authenticatie'       => [
                'type'              => 'wsse-usernametoken',
                'gebruikersnaam'    => 'pipelinq',
                'wachtwoordKluisRef' => 'vault://stuf/test',
            ],
            'zaaktypeMappings'    => ['evenementenvergunning' => 'Evenementenvergunning'],
            'zaakIdentificatieStrategie' => 'achteraf',
            'actief'              => true,
        ];
    }//end endpointFixture()

    /**
     * Set up.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $vault  = $this->createMock(StufVaultService::class);
        $vault->method('resolveSecret')->willReturn('secret');

        $builder        = new StufEnvelopeBuilder($vault, $logger);
        $parser         = new StufMessageParser($logger);
        $this->register = $this->createMock(StufRegisterAccess::class);

        // Make saveObject store the row + return a normalised array with an id.
        $captures = &$this->savedObjects;
        $this->register->method('saveObject')->willReturnCallback(
            function (string $schema, array $data) use (&$captures): array {
                $data['id']         = $data['id'] ?? 'id-'.uniqid('', true);
                $captures[$schema][] = $data;
                return $data;
            }
        );
        $this->register->method('findOne')->willReturn(null);
        $this->register->method('findAll')->willReturn([]);

        $handler              = new StufMessageHandler($this->register, $logger);
        $this->circuitBreaker = $this->createMock(CircuitBreakerService::class);
        $this->circuitBreaker->method('checkEndpoint')->willReturn(true);

        $needsInput = $this->createMock(NeedsInputDispatcher::class);
        $mapper     = new ContactBetrokkeneMapper($this->register, $logger);
        $this->httpClient = $this->createMock(StufHttpClient::class);
        $this->jobList    = $this->createMock(IJobList::class);

        $this->adapter = new StufAdapterService(
            $builder,
            $this->httpClient,
            $handler,
            $parser,
            $this->circuitBreaker,
            $mapper,
            $this->register,
            $needsInput,
            $this->jobList,
            $logger
        );
    }//end setUp()

    /**
     * @return void
     */
    public function testCreeerZaakHappyPathPersistsMapping(): void
    {
        $this->httpClient->expects($this->once())->method('send')->willReturn([
            'httpStatus'  => 200,
            'responseXml' => '<?xml version="1.0"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:stuf="http://www.egem.nl/StUF/StUF0301" xmlns:zkn="http://www.egem.nl/StUF/sector/zkn/0310"><soapenv:Body><stuf:Bv01><stuf:stuurgegevens><stuf:crossRefnummer>CROSS-1</stuf:crossRefnummer></stuf:stuurgegevens><stuf:antwoord><zkn:object><zkn:identificatie>ZAAK-2026-0008813</zkn:identificatie></zkn:object></stuf:antwoord></stuf:Bv01></soapenv:Body></soapenv:Envelope>',
            'durationMs'  => 120,
            'fout'        => null,
        ]);

        $this->circuitBreaker->expects($this->once())->method('resetEndpoint');

        $result = $this->adapter->creeerZaak(
            request: ['id' => 'req-1', 'type' => 'evenementenvergunning', 'omschrijving' => 'Tour'],
            endpoint: $this->endpointFixture()
        );

        $this->assertTrue($result['success']);
        $this->assertSame('ZAAK-2026-0008813', $result['zaakIdentificatie']);
        $this->assertNotSame('', $result['stufMessageId']);
        // The mapping was persisted.
        $this->assertNotEmpty($this->savedObjects[StufRegisterAccess::SCHEMA_MAPPING] ?? []);
        $mapping = end($this->savedObjects[StufRegisterAccess::SCHEMA_MAPPING]);
        $this->assertSame('ZAAK-2026-0008813', $mapping['externIdentificatie']);
    }//end testCreeerZaakHappyPathPersistsMapping()

    /**
     * @return void
     */
    public function testCreeerZaakWith503TriggersRetryAndRecordsFailure(): void
    {
        $this->httpClient->method('send')->willReturn([
            'httpStatus'  => 503,
            'responseXml' => '',
            'durationMs'  => 50,
            'fout'        => ['code' => 'HTTP_503', 'omschrijving' => 'Service Unavailable', 'details' => '', 'soort' => 'transient'],
        ]);
        $this->circuitBreaker->expects($this->once())->method('recordFailure');
        $this->jobList->expects($this->once())->method('add');

        $result = $this->adapter->creeerZaak(
            request: ['id' => 'req-2', 'type' => 'evenementenvergunning'],
            endpoint: $this->endpointFixture()
        );

        $this->assertFalse($result['success']);
        $this->assertSame('HTTP_503', $result['fout']['code']);
        // Exactly ONE outbound StufMessage row was persisted (retries are appended to the same row).
        $messages = ($this->savedObjects[StufRegisterAccess::SCHEMA_MESSAGE] ?? []);
        $outbound = array_filter($messages, fn ($m) => ($m['richting'] ?? '') === 'uitgaand');
        // The handler creates one row on send + one on recordRetry (which is the SAME id passed back through saveObject).
        // The recordRetry call updates `retries[]`, so we check by id uniqueness.
        $ids = array_unique(array_map(fn ($m) => $m['id'], $outbound));
        $this->assertCount(1, $ids);
    }//end testCreeerZaakWith503TriggersRetryAndRecordsFailure()

    /**
     * @return void
     */
    public function testVrijBerichtWithoutTemplateThrowsBeforeSend(): void
    {
        $this->httpClient->expects($this->never())->method('send');
        $this->expectException(\OCA\Pipelinq\Service\Stuf\VrijBerichtNotRegisteredException::class);
        $this->adapter->vrijBericht(
            name: 'doeIetsRaars',
            payload: [],
            endpoint: $this->endpointFixture()
        );
    }//end testVrijBerichtWithoutTemplateThrowsBeforeSend()

    /**
     * @return void
     */
    public function testGeefZaakDetailsTimeoutThrows(): void
    {
        $this->httpClient->method('send')->willReturn([
            'httpStatus'  => 0,
            'responseXml' => '',
            'durationMs'  => 30000,
            'fout'        => ['code' => 'TIMEOUT', 'omschrijving' => 'Timed out', 'details' => '', 'soort' => 'transient'],
        ]);

        $this->expectException(\OCA\Pipelinq\Service\Stuf\TimeoutException::class);
        $this->adapter->geefZaakDetails(zaakId: 'ZAAK-X', endpoint: $this->endpointFixture());
    }//end testGeefZaakDetailsTimeoutThrows()

    /**
     * @return void
     */
    public function testCircuitOpenBlocksCreeerZaak(): void
    {
        // Override default mock — circuit open this time.
        $this->circuitBreaker = $this->createMock(CircuitBreakerService::class);
        $this->circuitBreaker->method('checkEndpoint')->willReturn(false);
        $logger = $this->createMock(LoggerInterface::class);
        $vault  = $this->createMock(StufVaultService::class);
        $vault->method('resolveSecret')->willReturn('s');
        $builder    = new StufEnvelopeBuilder($vault, $logger);
        $parser     = new StufMessageParser($logger);
        $handler    = new StufMessageHandler($this->register, $logger);
        $mapper     = new ContactBetrokkeneMapper($this->register, $logger);
        $needsInput = $this->createMock(NeedsInputDispatcher::class);
        $needsInput->expects($this->once())->method('dispatch')->with('stuf_circuit_open', $this->anything());

        $adapter = new StufAdapterService(
            $builder,
            $this->httpClient,
            $handler,
            $parser,
            $this->circuitBreaker,
            $mapper,
            $this->register,
            $needsInput,
            $this->jobList,
            $logger
        );

        $this->expectException(\OCA\Pipelinq\Service\Stuf\CircuitOpenException::class);
        $adapter->creeerZaak(
            request: ['id' => 'req-3', 'type' => 'evenementenvergunning'],
            endpoint: $this->endpointFixture()
        );
    }//end testCircuitOpenBlocksCreeerZaak()
}//end class
