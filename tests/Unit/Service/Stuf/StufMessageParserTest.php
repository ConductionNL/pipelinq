<?php

/**
 * Unit tests for StufMessageParser.
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
 * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-001
 * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-002
 * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Stuf;

use OCA\Pipelinq\Service\Stuf\StufMessageParser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for StufMessageParser.
 */
class StufMessageParserTest extends TestCase
{
    private StufMessageParser $parser;

    /**
     * Set up.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $logger       = $this->createMock(LoggerInterface::class);
        $this->parser = new StufMessageParser($logger);
    }//end setUp()

    /**
     * @return void
     */
    public function testParseBevestigingExtractsCrossRefnummerAndZaakId(): void
    {
        $xml = '<?xml version="1.0"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:stuf="http://www.egem.nl/StUF/StUF0301" xmlns:zkn="http://www.egem.nl/StUF/sector/zkn/0310">
<soapenv:Body>
  <stuf:Bv01>
    <stuf:stuurgegevens>
      <stuf:berichtcode>Bv01</stuf:berichtcode>
      <stuf:crossRefnummer>01HXAMERSFOORTKZTOUR0001</stuf:crossRefnummer>
    </stuf:stuurgegevens>
    <stuf:antwoord>
      <zkn:object>
        <zkn:identificatie>ZAAK-2026-0008813</zkn:identificatie>
      </zkn:object>
    </stuf:antwoord>
  </stuf:Bv01>
</soapenv:Body></soapenv:Envelope>';

        $result = $this->parser->parseBevestiging($xml);
        $this->assertSame('01HXAMERSFOORTKZTOUR0001', $result['crossRefnummer']);
        $this->assertSame('ZAAK-2026-0008813', $result['zaakIdentificatie']);
    }//end testParseBevestigingExtractsCrossRefnummerAndZaakId()

    /**
     * @return void
     */
    public function testParseZaakDetailsReturnsHydratedObject(): void
    {
        $xml = '<?xml version="1.0"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:stuf="http://www.egem.nl/StUF/StUF0301" xmlns:zkn="http://www.egem.nl/StUF/sector/zkn/0310" xmlns:bg="http://www.egem.nl/StUF/sector/bg/0310">
<soapenv:Body>
  <zkn:zakLa01>
    <zkn:object>
      <zkn:identificatie>ZAAK-2026-0008812</zkn:identificatie>
      <zkn:omschrijving>Tour de Amersfoort</zkn:omschrijving>
      <zkn:startdatum>20260521</zkn:startdatum>
      <zkn:einddatum>20260523</zkn:einddatum>
      <zkn:zaaktype>
        <zkn:omschrijving>Evenementenvergunning</zkn:omschrijving>
      </zkn:zaaktype>
      <zkn:heeftStatus>
        <zkn:datumStatusGezet>20260521</zkn:datumStatusGezet>
        <zkn:statustype>in_behandeling</zkn:statustype>
      </zkn:heeftStatus>
      <zkn:heeftAlsInitiator>
        <zkn:gerelateerde>
          <bg:inp.bsn>123456789</bg:inp.bsn>
        </zkn:gerelateerde>
      </zkn:heeftAlsInitiator>
    </zkn:object>
  </zkn:zakLa01>
</soapenv:Body></soapenv:Envelope>';

        $zaak = $this->parser->parseZaakDetails($xml);
        $this->assertSame('ZAAK-2026-0008812', $zaak['identificatie']);
        $this->assertSame('Tour de Amersfoort', $zaak['omschrijving']);
        $this->assertSame('Evenementenvergunning', $zaak['zaaktype']['omschrijving']);
        $this->assertCount(1, $zaak['statussen']);
        $this->assertSame('in_behandeling', $zaak['statussen'][0]['statustype']);
        $this->assertCount(1, $zaak['betrokkenen']);
        $this->assertSame('heeftAlsInitiator', $zaak['betrokkenen'][0]['rol']);
        $this->assertSame('123456789', $zaak['betrokkenen'][0]['bsn']);
    }//end testParseZaakDetailsReturnsHydratedObject()

    /**
     * @return void
     */
    public function testParseErrorExtractsCodeOmschrijvingDetails(): void
    {
        $xml = '<?xml version="1.0"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:stuf="http://www.egem.nl/StUF/StUF0301">
<soapenv:Body>
  <stuf:Fo02>
    <stuf:fout>
      <stuf:code>StUF064</stuf:code>
      <stuf:omschrijving>Entiteit niet aanwezig</stuf:omschrijving>
      <stuf:details>Zaak ZAAK-2026-0009999 niet gevonden</stuf:details>
    </stuf:fout>
  </stuf:Fo02>
</soapenv:Body></soapenv:Envelope>';

        $err = $this->parser->parseError($xml);
        $this->assertSame('StUF064', $err['code']);
        $this->assertSame('Entiteit niet aanwezig', $err['omschrijving']);
        $this->assertSame('Zaak ZAAK-2026-0009999 niet gevonden', $err['details']);
        $this->assertSame('permanent', $err['soort']);
    }//end testParseErrorExtractsCodeOmschrijvingDetails()

    /**
     * @return void
     */
    public function testParseErrorClassifiesTransientStuf067(): void
    {
        $xml = '<?xml version="1.0"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:stuf="http://www.egem.nl/StUF/StUF0301"><soapenv:Body><stuf:Fo02><stuf:fout><stuf:code>StUF067</stuf:code><stuf:omschrijving>Server tijdelijk overbelast</stuf:omschrijving></stuf:fout></stuf:Fo02></soapenv:Body></soapenv:Envelope>';

        $err = $this->parser->parseError($xml);
        $this->assertSame('StUF067', $err['code']);
        $this->assertSame('transient', $err['soort']);
    }//end testParseErrorClassifiesTransientStuf067()

    /**
     * @return void
     */
    public function testEmptyInputReturnsParseError(): void
    {
        $err = $this->parser->parseError('');
        $this->assertSame('PARSE_ERROR', $err['code']);
    }//end testEmptyInputReturnsParseError()

    /**
     * @return void
     */
    public function testExtractNamespaceValueHelper(): void
    {
        $xml = '<?xml version="1.0"?><root xmlns:stuf="http://www.egem.nl/StUF/StUF0301"><stuf:referentienummer>ABC123</stuf:referentienummer></root>';
        $val = $this->parser->extractNamespaceValue($xml, '//stuf:referentienummer');
        $this->assertSame('ABC123', $val);
    }//end testExtractNamespaceValueHelper()
}//end class
