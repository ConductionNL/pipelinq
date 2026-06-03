<?php

/**
 * Integration-style unit tests for StufAdapterService with a fake transport.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Stuf
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Stuf;

use OCA\Pipelinq\Exception\CircuitOpenException;
use OCA\Pipelinq\Exception\VrijBerichtNotRegisteredException;
use OCA\Pipelinq\Service\Stuf\CircuitBreakerService;
use OCA\Pipelinq\Service\Stuf\ContactBetrokkeneMapper;
use OCA\Pipelinq\Service\Stuf\StufAdapterService;
use OCA\Pipelinq\Service\Stuf\StufCredentialResolver;
use OCA\Pipelinq\Service\Stuf\StufEnvelopeBuilder;
use OCA\Pipelinq\Service\Stuf\StufMessageHandler;
use OCA\Pipelinq\Service\Stuf\StufMessageParser;
use OCA\Pipelinq\Service\Stuf\StufTransportInterface;
use OCA\Pipelinq\Service\Stuf\ZgwCoexistenceGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * In-memory fake transport recording sent envelopes and replaying queued results.
 */
final class FakeStufTransport implements StufTransportInterface
{

    /**
     * Queued results to return, FIFO.
     *
     * @var array<int, array{httpStatus: int, responseXml: string, durationMs: int}>
     */
    public array $queue = [];

    /**
     * Envelopes captured per send call.
     *
     * @var array<int, string>
     */
    public array $sent = [];

    /**
     * Record the envelope and return the next queued result.
     *
     * @param array<string, mixed> $endpoint       The endpoint config.
     * @param string               $envelopeXml    The envelope.
     * @param int                  $timeoutSeconds The timeout.
     *
     * @return array{httpStatus: int, responseXml: string, durationMs: int} The queued result.
     */
    public function send(array $endpoint, string $envelopeXml, int $timeoutSeconds=30): array
    {
        $this->sent[] = $envelopeXml;

        return array_shift($this->queue) ?? ['httpStatus' => 200, 'responseXml' => '', 'durationMs' => 1];
    }//end send()
}//end class

/**
 * Tests for StufAdapterService.
 */
class StufAdapterServiceTest extends TestCase
{
    /**
     * A Bv01 response carrying a server-allocated zaak identificatie.
     *
     * @param string $zaakId The zaak id to embed.
     *
     * @return string The envelope XML.
     */
    private function bv01(string $zaakId): string
    {
        return '<?xml version="1.0"?>'
            .'<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"'
            .' xmlns:stuf="http://www.egem.nl/StUF/StUF0301"'
            .' xmlns:zkn="http://www.egem.nl/StUF/sector/zkn/0310">'
            .'<soapenv:Body><zkn:zakLk01_Bv01><stuf:stuurgegevens>'
            .'<stuf:referentienummer>R1</stuf:referentienummer></stuf:stuurgegevens>'
            .'<zkn:object><zkn:identificatie>'.$zaakId.'</zkn:identificatie></zkn:object>'
            .'</zkn:zakLk01_Bv01></soapenv:Body></soapenv:Envelope>';
    }//end bv01()

    /**
     * A representative endpoint config (achteraf strategy).
     *
     * @return array<string, mixed> The endpoint.
     */
    private function endpoint(): array
    {
        return [
            'id'                         => 'amersfoort-key2zaken',
            'zenderOrganisatie'          => 'Gemeente Amersfoort',
            'zenderApplicatie'           => 'Pipelinq',
            'ontvangerOrganisatie'       => 'Gemeente Amersfoort',
            'ontvangerApplicatie'        => 'Key2Zaken',
            'ontvangerGebruiker'         => 'pipelinq',
            'zaakIdentificatieStrategie' => 'achteraf',
            'zaaktypeMappings'           => ['evenementenvergunning' => 'Evenementenvergunning'],
            'vrijeBerichten'             => ['zetStatus' => ['verplicht' => ['zaakIdentificatie']]],
            'authenticatie'              => ['gebruikersnaam' => 'u', 'wachtwoordKluisRef' => 'vault://x'],
        ];
    }//end endpoint()

    /**
     * Build the adapter with a given transport and (real) collaborators.
     *
     * @param FakeStufTransport  $transport The fake transport.
     * @param StufMessageHandler $handler   The (mocked) message handler.
     *
     * @return StufAdapterService The adapter.
     */
    private function adapter(FakeStufTransport $transport, StufMessageHandler $handler): StufAdapterService
    {
        $logger    = $this->createMock(LoggerInterface::class);
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturn('no');
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willThrowException(new \RuntimeException('no OR in unit test'));

        $credentials = $this->createMock(StufCredentialResolver::class);
        $credentials->method('resolve')->willReturn('geheim');

        $builder = new StufEnvelopeBuilder(credentials: $credentials, logger: $logger);
        $parser  = new StufMessageParser(logger: $logger);

        $store    = [];
        $cbConfig = $this->createMock(IAppConfig::class);
        $cbConfig->method('getValueInt')->willReturnCallback(
            static function (string $a, string $k, int $d=0) use (&$store): int {
                return $store[$k] ?? $d;
            }
        );
        $cbConfig->method('setValueInt')->willReturnCallback(
            static function (string $a, string $k, int $v) use (&$store): bool {
                $store[$k] = $v;
                return true;
            }
        );
        $breaker = new CircuitBreakerService(appConfig: $cbConfig, logger: $logger);

        $mapper      = $this->createMock(ContactBetrokkeneMapper::class);
        $coexistence = new ZgwCoexistenceGuard(logger: $logger);

        return new StufAdapterService(
            container: $container,
            appConfig: $appConfig,
            envelopeBuilder: $builder,
            transport: $transport,
            parser: $parser,
            messageHandler: $handler,
            circuitBreaker: $breaker,
            betrokkeneMapper: $mapper,
            coexistence: $coexistence,
            logger: $logger
        );
    }//end adapter()

    /**
     * A message handler mock that records outbound/retry/correlate calls.
     *
     * @return StufMessageHandler&\PHPUnit\Framework\MockObject\MockObject The handler.
     */
    private function handlerMock(): StufMessageHandler
    {
        $handler = $this->createMock(StufMessageHandler::class);
        $handler->method('logOutbound')->willReturnCallback(
            static fn (array $f): array => array_merge(['id' => 'msg-1'], $f)
        );
        $handler->method('recordRetry')->willReturnCallback(static fn (array $m): array => $m);
        $handler->method('transitionStatus')->willReturnCallback(static fn (array $m): array => $m);
        $handler->method('correlateInbound')->willReturn(null);

        return $handler;
    }//end handlerMock()

    /**
     * creeerZaak builds, sends, parses the Bv01 and returns the zaak id with
     * exactly one outbound StufMessage (no clone).
     *
     * @return void
     */
    public function testCreeerZaakHappyPath(): void
    {
        $transport          = new FakeStufTransport();
        $transport->queue[] = ['httpStatus' => 200, 'responseXml' => $this->bv01('ZAAK-2026-0008813'), 'durationMs' => 12];

        $handler = $this->handlerMock();
        $handler->expects($this->once())->method('logOutbound');

        $adapter = $this->adapter(transport: $transport, handler: $handler);

        $result = $adapter->creeerZaak(
            request: ['id' => 'req-1', 'type' => 'evenementenvergunning', 'title' => 'Tour'],
            endpoint: $this->endpoint()
        );

        $this->assertTrue($result['success']);
        $this->assertSame('ZAAK-2026-0008813', $result['zaakIdentificatie']);
        $this->assertCount(1, $transport->sent);
        $this->assertStringContainsString('zkn:zakLk01', $transport->sent[0]);
    }//end testCreeerZaakHappyPath()

    /**
     * A 503 then 200 retries with the SAME envelope (idempotent referentienummer)
     * and records the retry, producing a single StufMessage.
     *
     * @return void
     */
    public function testRetryOn503ThenSuccess(): void
    {
        $transport          = new FakeStufTransport();
        $transport->queue[] = ['httpStatus' => 503, 'responseXml' => '', 'durationMs' => 5];
        $transport->queue[] = ['httpStatus' => 200, 'responseXml' => $this->bv01('ZAAK-2026-0008814'), 'durationMs' => 8];

        $handler = $this->handlerMock();
        $handler->expects($this->once())->method('logOutbound');
        $handler->expects($this->once())->method('recordRetry');

        $adapter = $this->adapter(transport: $transport, handler: $handler);

        $result = $adapter->creeerZaak(
            request: ['id' => 'req-1', 'type' => 'evenementenvergunning'],
            endpoint: $this->endpoint()
        );

        $this->assertTrue($result['success']);
        $this->assertSame('ZAAK-2026-0008814', $result['zaakIdentificatie']);
        // Two sends, identical envelope (same referentienummer reused on retry).
        $this->assertCount(2, $transport->sent);
        $this->assertSame($transport->sent[0], $transport->sent[1]);
    }//end testRetryOn503ThenSuccess()

    /**
     * Four consecutive 5xx responses trip the breaker; the next send short-circuits.
     *
     * @return void
     */
    public function testCircuitTripsAfterFourFailures(): void
    {
        $transport = new FakeStufTransport();
        for ($i = 0; $i < 5; $i++) {
            $transport->queue[] = ['httpStatus' => 500, 'responseXml' => '', 'durationMs' => 5];
        }

        $adapter = $this->adapter(transport: $transport, handler: $this->handlerMock());

        try {
            $adapter->creeerZaak(
                request: ['id' => 'req-1', 'type' => 'evenementenvergunning'],
                endpoint: $this->endpoint()
            );
            $this->fail('Expected transport failure');
        } catch (\OCA\Pipelinq\Exception\StufException $e) {
            $this->addToAssertionCount(1);
        }

        // Circuit is now open: a fresh send must short-circuit immediately.
        $this->expectException(CircuitOpenException::class);
        $adapter->creeerZaak(
            request: ['id' => 'req-2', 'type' => 'evenementenvergunning'],
            endpoint: $this->endpoint()
        );
    }//end testCircuitTripsAfterFourFailures()

    /**
     * creeerZaak no-ops when a ZAK mapping already exists (ZGW/StUF coexistence).
     *
     * @return void
     */
    public function testCoexistenceSkipsDuplicateRegistration(): void
    {
        $transport = new FakeStufTransport();
        $adapter   = $this->adapter(transport: $transport, handler: $this->handlerMock());

        $result = $adapter->creeerZaak(
            request: ['id' => 'req-1', 'type' => 'evenementenvergunning'],
            endpoint: $this->endpoint(),
            opts: ['existingMappings' => [['externEntiteit' => 'ZAK', 'externIdentificatie' => 'ZAAK-EXISTING']]]
        );

        $this->assertTrue($result['success']);
        $this->assertSame('ZAAK-EXISTING', $result['zaakIdentificatie']);
        $this->assertCount(0, $transport->sent, 'no SOAP traffic when a zaak already exists');
    }//end testCoexistenceSkipsDuplicateRegistration()

    /**
     * An unregistered vrijBericht name raises before any SOAP traffic.
     *
     * @return void
     */
    public function testUnknownVrijBerichtRaises(): void
    {
        $transport = new FakeStufTransport();
        $adapter   = $this->adapter(transport: $transport, handler: $this->handlerMock());

        try {
            $adapter->vrijBericht(name: 'doeIetsRaars', payload: [], endpoint: $this->endpoint());
            $this->fail('Expected VrijBerichtNotRegisteredException');
        } catch (VrijBerichtNotRegisteredException $e) {
            $this->assertCount(0, $transport->sent);
        }
    }//end testUnknownVrijBerichtRaises()
}//end class
