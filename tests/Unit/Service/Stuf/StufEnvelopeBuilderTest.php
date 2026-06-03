<?php

/**
 * Unit tests for StufEnvelopeBuilder.
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

use OCA\Pipelinq\Exception\PayloadTooLargeException;
use OCA\Pipelinq\Exception\ZaaktypeNotMappedException;
use OCA\Pipelinq\Service\Stuf\StufCredentialResolver;
use OCA\Pipelinq\Service\Stuf\StufEnvelopeBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for StufEnvelopeBuilder.
 */
class StufEnvelopeBuilderTest extends TestCase
{

    /**
     * The credential resolver mock.
     *
     * @var StufCredentialResolver&MockObject
     */
    private StufCredentialResolver $credentials;

    /**
     * The logger mock.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface $logger;

    /**
     * The builder under test.
     *
     * @var StufEnvelopeBuilder
     */
    private StufEnvelopeBuilder $builder;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->credentials = $this->createMock(StufCredentialResolver::class);
        $this->logger      = $this->createMock(LoggerInterface::class);
        $this->builder     = new StufEnvelopeBuilder(
            credentials: $this->credentials,
            logger: $this->logger
        );
    }//end setUp()

    /**
     * A representative endpoint config.
     *
     * @return array<string, mixed> The endpoint config.
     */
    private function endpoint(): array
    {
        return [
            'id'                   => 'amersfoort-key2zaken',
            'zenderOrganisatie'    => 'Gemeente Amersfoort',
            'zenderApplicatie'     => 'Pipelinq',
            'ontvangerOrganisatie' => 'Gemeente Amersfoort',
            'ontvangerApplicatie'  => 'Key2Zaken',
            'ontvangerGebruiker'   => 'pipelinq',
            'soapVersion'          => '1.1',
            'zaaktypeMappings'     => ['evenementenvergunning' => 'Evenementenvergunning'],
            'authenticatie'        => [
                'type'               => 'wsse-usernametoken',
                'gebruikersnaam'     => 'pipelinq_amersfoort',
                'wachtwoordKluisRef' => 'vault://stuf/amersfoort/key2zaken',
            ],
        ];
    }//end endpoint()

    /**
     * Lk01 contains the required stuurgegevens, namespaces and zaaktype.
     *
     * @return void
     */
    public function testBuildLk01ContainsStuurgegevens(): void
    {
        $this->credentials->method('resolve')->willReturn('geheim');

        $xml = $this->builder->buildLk01CreeerZaak(
            request: ['id' => 'req-1', 'type' => 'evenementenvergunning', 'title' => 'Tour de France'],
            endpoint: $this->endpoint()
        );

        $this->assertStringContainsString('soapenv:Envelope', $xml);
        $this->assertStringContainsString('zkn:zakLk01', $xml);
        $this->assertStringContainsString('stuf:referentienummer', $xml);
        $this->assertStringContainsString('stuf:tijdstipBericht', $xml);
        $this->assertStringContainsString('Evenementenvergunning', $xml);
        $this->assertStringContainsString('http://www.egem.nl/StUF/sector/zkn/0310', $xml);
        $this->assertStringContainsString('http://www.egem.nl/StUF/StUF0301', $xml);
    }//end testBuildLk01ContainsStuurgegevens()

    /**
     * The referentienummer is a fresh 26-char ULID, unique per call.
     *
     * @return void
     */
    public function testReferentienummerIsUniqueUlid(): void
    {
        $a = $this->builder->generateReferentienummer();
        $b = $this->builder->generateReferentienummer();

        $this->assertSame(26, strlen($a));
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $a);
        $this->assertNotSame($a, $b);
    }//end testReferentienummerIsUniqueUlid()

    /**
     * tijdstipBericht is 17 digits (yyyyMMddHHmmssSSS).
     *
     * @return void
     */
    public function testTimestampFormat(): void
    {
        $ts = $this->builder->currentTimestampStuf();

        $this->assertMatchesRegularExpression('/^\d{17}$/', $ts);
    }//end testTimestampFormat()

    /**
     * Documents are embedded as base64 with no line wrapping.
     *
     * @return void
     */
    public function testDocumentBase64NoLineWrap(): void
    {
        $this->credentials->method('resolve')->willReturn('geheim');
        $bytes = random_bytes(2048);

        $xml = $this->builder->buildLk01CreeerZaak(
            request: ['id' => 'req-1', 'type' => 'evenementenvergunning'],
            endpoint: $this->endpoint(),
            zaakId: null,
            betrokkenen: [],
            documents: [['bestandsnaam' => 'aanvraag.pdf', 'formaat' => 'application/pdf', 'inhoud' => $bytes]]
        );

        $expected = base64_encode($bytes);
        $this->assertStringContainsString('aanvraag.pdf', $xml);
        $this->assertStringContainsString($expected, $xml);
        $this->assertStringNotContainsString("\n".substr($expected, 0, 4)."\n", $xml);
    }//end testDocumentBase64NoLineWrap()

    /**
     * A betrokkene BSN is rendered as bg:inp.bsn.
     *
     * @return void
     */
    public function testInitiatorBsnRendered(): void
    {
        $this->credentials->method('resolve')->willReturn('geheim');

        $xml = $this->builder->buildLk01CreeerZaak(
            request: ['id' => 'req-1', 'type' => 'evenementenvergunning'],
            endpoint: $this->endpoint(),
            zaakId: null,
            betrokkenen: [['bsn' => '123456789', 'naam' => 'van der Velde']]
        );

        $this->assertStringContainsString('bg:inp.bsn', $xml);
        $this->assertStringContainsString('123456789', $xml);
        $this->assertStringContainsString('zkn:heeftAlsInitiator', $xml);
    }//end testInitiatorBsnRendered()

    /**
     * WSSE UsernameToken credentials are injected from the vault at build time.
     *
     * @return void
     */
    public function testWsseCredentialsInjectedFromVault(): void
    {
        $this->credentials->expects($this->atLeastOnce())
            ->method('resolve')
            ->with('vault://stuf/amersfoort/key2zaken')
            ->willReturn('s3cr3t-from-vault');

        $xml = $this->builder->buildLk01CreeerZaak(
            request: ['id' => 'req-1', 'type' => 'evenementenvergunning'],
            endpoint: $this->endpoint()
        );

        $this->assertStringContainsString('wsse:Security', $xml);
        $this->assertStringContainsString('wsse:UsernameToken', $xml);
        $this->assertStringContainsString('pipelinq_amersfoort', $xml);
        $this->assertStringContainsString('s3cr3t-from-vault', $xml);
    }//end testWsseCredentialsInjectedFromVault()

    /**
     * An unmapped request type raises before any envelope is produced.
     *
     * @return void
     */
    public function testUnmappedZaaktypeThrows(): void
    {
        $this->expectException(ZaaktypeNotMappedException::class);

        $this->builder->buildLk01CreeerZaak(
            request: ['id' => 'req-1', 'type' => 'onbekend-type'],
            endpoint: $this->endpoint()
        );
    }//end testUnmappedZaaktypeThrows()

    /**
     * An over-ceiling document payload raises before transmission.
     *
     * @return void
     */
    public function testOversizePayloadThrows(): void
    {
        $this->credentials->method('resolve')->willReturn('geheim');
        $this->expectException(PayloadTooLargeException::class);

        $this->builder->buildLk01CreeerZaak(
            request: ['id' => 'req-1', 'type' => 'evenementenvergunning'],
            endpoint: $this->endpoint(),
            zaakId: null,
            betrokkenen: [],
            documents: [['bestandsnaam' => 'big.bin', 'inhoud' => str_repeat('x', 200)]],
            payloadCeiling: 100
        );
    }//end testOversizePayloadThrows()

    /**
     * The built envelope is well-formed XML that re-parses cleanly.
     *
     * @return void
     */
    public function testEnvelopeIsWellFormed(): void
    {
        $this->credentials->method('resolve')->willReturn('geheim');

        $xml = $this->builder->buildLk01CreeerZaak(
            request: ['id' => 'req-1', 'type' => 'evenementenvergunning', 'title' => 'Tour & Taxis <test>'],
            endpoint: $this->endpoint()
        );

        $doc = new \DOMDocument();
        $this->assertTrue($doc->loadXML($xml), 'Envelope must be well-formed XML');
    }//end testEnvelopeIsWellFormed()
}//end class
