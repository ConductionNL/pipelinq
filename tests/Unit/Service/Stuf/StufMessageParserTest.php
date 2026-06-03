<?php

/**
 * Unit tests for StufMessageParser, including XXE-safety.
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

use OCA\Pipelinq\Exception\StufException;
use OCA\Pipelinq\Service\Stuf\StufMessageParser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for StufMessageParser.
 */
class StufMessageParserTest extends TestCase
{

    /**
     * The parser under test.
     *
     * @var StufMessageParser
     */
    private StufMessageParser $parser;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->parser = new StufMessageParser(logger: $this->createMock(LoggerInterface::class));
    }//end setUp()

    /**
     * Wrap a body in a StUF SOAP envelope with the standard namespaces.
     *
     * @param string $body The inner body XML.
     *
     * @return string The full envelope.
     */
    private function envelope(string $body): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"'
            .' xmlns:stuf="http://www.egem.nl/StUF/StUF0301"'
            .' xmlns:zkn="http://www.egem.nl/StUF/sector/zkn/0310"'
            .' xmlns:bg="http://www.egem.nl/StUF/sector/bg/0310">'
            .'<soapenv:Body>'.$body.'</soapenv:Body></soapenv:Envelope>';
    }//end envelope()

    /**
     * parseBevestiging extracts the referentienummer and zaak identificatie.
     *
     * @return void
     */
    public function testParseBevestiging(): void
    {
        $xml = $this->envelope(
            '<zkn:zakLk01_Bv01><stuf:stuurgegevens>'
            .'<stuf:referentienummer>01HXXXXX</stuf:referentienummer>'
            .'<stuf:crossRefnummer>01HOUT</stuf:crossRefnummer>'
            .'</stuf:stuurgegevens><zkn:object><zkn:identificatie>ZAAK-2026-0008813</zkn:identificatie>'
            .'</zkn:object></zkn:zakLk01_Bv01>'
        );

        $result = $this->parser->parseBevestiging(responseXml: $xml);

        $this->assertSame('01HXXXXX', $result['referentienummer']);
        $this->assertSame('01HOUT', $result['crossRefnummer']);
        $this->assertSame('ZAAK-2026-0008813', $result['zaakIdentificatie']);
    }//end testParseBevestiging()

    /**
     * parseZaakDetails returns a hydrated Zaak object with betrokkenen.
     *
     * @return void
     */
    public function testParseZaakDetails(): void
    {
        $xml = $this->envelope(
            '<zkn:zakLa01><zkn:object>'
            .'<zkn:identificatie>ZAAK-2026-0008812</zkn:identificatie>'
            .'<zkn:omschrijving>Evenement</zkn:omschrijving>'
            .'<zkn:startdatum>20260501</zkn:startdatum>'
            .'<zkn:einddatum>20260601</zkn:einddatum>'
            .'<zkn:heeftAlsInitiator><zkn:gerelateerde><zkn:natuurlijkPersoon>'
            .'<bg:inp.bsn>123456789</bg:inp.bsn></zkn:natuurlijkPersoon></zkn:gerelateerde></zkn:heeftAlsInitiator>'
            .'</zkn:object></zkn:zakLa01>'
        );

        $zaak = $this->parser->parseZaakDetails(responseXml: $xml);

        $this->assertSame('ZAAK-2026-0008812', $zaak['identificatie']);
        $this->assertSame('Evenement', $zaak['omschrijving']);
        $this->assertSame('20260601', $zaak['einddatum']);
        $this->assertSame([['bsn' => '123456789']], $zaak['betrokkenen']);
    }//end testParseZaakDetails()

    /**
     * parseError extracts the Fo02 code and classifies permanence.
     *
     * @return void
     */
    public function testParseError(): void
    {
        $xml = $this->envelope(
            '<stuf:Fo02Bericht><stuf:body><stuf:fout>'
            .'<stuf:code>StUF064</stuf:code>'
            .'<stuf:omschrijving>Entiteit niet aanwezig</stuf:omschrijving>'
            .'</stuf:fout></stuf:body></stuf:Fo02Bericht>'
        );

        $error = $this->parser->parseError(responseXml: $xml);

        $this->assertSame('StUF064', $error['code']);
        $this->assertSame('Entiteit niet aanwezig', $error['omschrijving']);
        $this->assertFalse($error['transient']);
    }//end testParseError()

    /**
     * A transient fout code (StUF052) is classified as retryable.
     *
     * @return void
     */
    public function testTransientErrorCode(): void
    {
        $xml = $this->envelope('<stuf:fout><stuf:code>StUF052</stuf:code></stuf:fout>');

        $error = $this->parser->parseError(responseXml: $xml);

        $this->assertTrue($error['transient']);
    }//end testTransientErrorCode()

    /**
     * XXE: an external-entity payload MUST be rejected and never expanded.
     *
     * The classic file-read XXE: a DOCTYPE defining an entity that reads
     * /etc/passwd. The parser must reject the DOCTYPE outright and never leak
     * the file content into the parsed result.
     *
     * @return void
     */
    public function testXxeExternalEntityIsRejected(): void
    {
        $payload = '<?xml version="1.0"?>'
            .'<!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
            .'<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"'
            .' xmlns:stuf="http://www.egem.nl/StUF/StUF0301"'
            .' xmlns:zkn="http://www.egem.nl/StUF/sector/zkn/0310">'
            .'<soapenv:Body><zkn:object><zkn:identificatie>&xxe;</zkn:identificatie></zkn:object></soapenv:Body>'
            .'</soapenv:Envelope>';

        try {
            $this->parser->parseBevestiging(responseXml: $payload);
            $this->fail('Expected StufException for DOCTYPE/XXE payload');
        } catch (StufException $e) {
            $this->assertStringContainsString('DOCTYPE', $e->getMessage());
        }
    }//end testXxeExternalEntityIsRejected()

    /**
     * Billion-laughs (entity-expansion DoS) is rejected at the DOCTYPE gate.
     *
     * @return void
     */
    public function testBillionLaughsIsRejected(): void
    {
        $payload = '<?xml version="1.0"?>'
            .'<!DOCTYPE lolz [<!ENTITY lol "lol">'
            .'<!ENTITY lol2 "&lol;&lol;&lol;&lol;&lol;">]>'
            .'<root>&lol2;</root>';

        $this->expectException(StufException::class);
        $this->parser->parseInbound(responseXml: $payload);
    }//end testBillionLaughsIsRejected()

    /**
     * An empty envelope is rejected rather than silently returning nulls.
     *
     * @return void
     */
    public function testEmptyEnvelopeRejected(): void
    {
        $this->expectException(StufException::class);
        $this->parser->parseBevestiging(responseXml: '');
    }//end testEmptyEnvelopeRejected()

    /**
     * parseInbound surfaces the berichtcode and zaak identificatie for routing.
     *
     * @return void
     */
    public function testParseInbound(): void
    {
        $xml = $this->envelope(
            '<zkn:zakLk02><stuf:stuurgegevens>'
            .'<stuf:berichtcode>Lk02</stuf:berichtcode>'
            .'<stuf:referentienummer>01HIN</stuf:referentienummer>'
            .'</stuf:stuurgegevens><zkn:object>'
            .'<zkn:identificatie>ZAAK-2026-0008812</zkn:identificatie></zkn:object></zkn:zakLk02>'
        );

        $keys = $this->parser->parseInbound(responseXml: $xml);

        $this->assertSame('Lk02', $keys['berichtSoort']);
        $this->assertSame('ZAAK-2026-0008812', $keys['zaakIdentificatie']);
        $this->assertSame('01HIN', $keys['referentienummer']);
    }//end testParseInbound()
}//end class
