<?php

/**
 * Pipelinq StufMessageParser.
 *
 * XXE-safe parser for StUF SOAP responses (Bv01 bevestiging, La01 antwoord,
 * Fo02 foutbericht). External entities and DTDs are rejected outright.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Stuf
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/stuf-zkn-bg-adapter/tasks.md#2.5
 * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#req-stuf-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Stuf;

use DOMDocument;
use DOMXPath;
use OCA\Pipelinq\Exception\StufException;
use Psr\Log\LoggerInterface;

/**
 * Parses StUF SOAP response envelopes into typed arrays.
 *
 * Every parse goes through {@see self::loadXxeSafe()} which loads the XML with
 * external-entity expansion disabled and rejects any document that declares a
 * DOCTYPE (the XXE / billion-laughs attack surface). This guarantees an inbound
 * envelope can never read local files or trigger entity expansion (ADR-005).
 */
class StufMessageParser
{
    /**
     * StUF 0310 namespace URIs (matching the builder).
     */
    private const NS_STUF = 'http://www.egem.nl/StUF/StUF0301';
    private const NS_ZKN  = 'http://www.egem.nl/StUF/sector/zkn/0310';
    private const NS_BG   = 'http://www.egem.nl/StUF/sector/bg/0310';

    /**
     * Constructor.
     *
     * @param LoggerInterface $logger The logger.
     */
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Parse a Bv01 bevestiging response.
     *
     * @param string $responseXml The response envelope XML.
     *
     * @return array{referentienummer: ?string, crossRefnummer: ?string, zaakIdentificatie: ?string} The confirmation data.
     *
     * @throws StufException If the XML is unsafe or unparseable.
     */
    public function parseBevestiging(string $responseXml): array
    {
        $xpath = $this->loadXxeSafe(xml: $responseXml);

        $result = [
            'referentienummer'  => $this->firstValue(xpath: $xpath, query: '//stuf:stuurgegevens/stuf:referentienummer'),
            'crossRefnummer'    => $this->firstValue(xpath: $xpath, query: '//stuf:stuurgegevens/stuf:crossRefnummer'),
            'zaakIdentificatie' => $this->firstValue(xpath: $xpath, query: '//zkn:identificatie'),
        ];

        $this->logger->debug('StUF Bv01 parsed', ['hasZaakId' => ($result['zaakIdentificatie'] !== null)]);

        return $result;
    }//end parseBevestiging()

    /**
     * Parse a La01 antwoord into a Zaak object.
     *
     * @param string $responseXml The response envelope XML.
     *
     * @return array<string, mixed> The hydrated Zaak object.
     *
     * @throws StufException If the XML is unsafe or unparseable.
     */
    public function parseZaakDetails(string $responseXml): array
    {
        $xpath = $this->loadXxeSafe(xml: $responseXml);

        $betrokkenen = [];
        $bsnNodes    = $xpath->query('//zkn:heeftAlsInitiator//bg:inp.bsn');
        if ($bsnNodes !== false) {
            foreach ($bsnNodes as $node) {
                $betrokkenen[] = ['bsn' => trim($node->textContent)];
            }
        }

        $statussen   = [];
        $statusNodes = $xpath->query('//zkn:heeft//zkn:gerelateerde//zkn:omschrijving');
        if ($statusNodes !== false) {
            foreach ($statusNodes as $node) {
                $statussen[] = trim($node->textContent);
            }
        }

        $zaak = [
            'identificatie'        => $this->firstValue(xpath: $xpath, query: '//zkn:object/zkn:identificatie'),
            'omschrijving'         => $this->firstValue(xpath: $xpath, query: '//zkn:object/zkn:omschrijving'),
            'startdatum'           => $this->firstValue(xpath: $xpath, query: '//zkn:object/zkn:startdatum'),
            'einddatum'            => $this->firstValue(xpath: $xpath, query: '//zkn:object/zkn:einddatum'),
            'zaaktypeOmschrijving' => $this->firstValue(xpath: $xpath, query: '//zkn:isVan/zkn:gerelateerde/zkn:omschrijving'),
            'statussen'            => $statussen,
            'betrokkenen'          => $betrokkenen,
        ];

        $this->logger->debug(
            'StUF La01 parsed',
            ['betrokkenen' => count($betrokkenen), 'statussen' => count($statussen)]
        );

        return $zaak;
    }//end parseZaakDetails()

    /**
     * Parse a Fo02 foutbericht.
     *
     * @param string $responseXml The response envelope XML.
     *
     * @return array{code: ?string, omschrijving: ?string, details: ?string, transient: bool} The error data.
     *
     * @throws StufException If the XML is unsafe or unparseable.
     */
    public function parseError(string $responseXml): array
    {
        $xpath = $this->loadXxeSafe(xml: $responseXml);

        $code = $this->firstValue(xpath: $xpath, query: '//stuf:fout/stuf:code');

        $error = [
            'code'         => $code,
            'omschrijving' => $this->firstValue(xpath: $xpath, query: '//stuf:fout/stuf:omschrijving'),
            'details'      => $this->firstValue(xpath: $xpath, query: '//stuf:fout/stuf:details'),
            'transient'    => $this->isTransientCode(code: $code),
        ];

        $this->logger->debug('StUF Fo02 parsed', ['code' => $code, 'transient' => $error['transient']]);

        return $error;
    }//end parseError()

    /**
     * Find a ZaaksysteemMapping key from an inbound kennisgeving envelope.
     *
     * @param string $responseXml The inbound envelope XML.
     *
     * @return array{zaakIdentificatie: ?string, berichtSoort: ?string, referentienummer: ?string, crossRefnummer: ?string} The match keys.
     *
     * @throws StufException If the XML is unsafe or unparseable.
     */
    public function parseInbound(string $responseXml): array
    {
        $xpath = $this->loadXxeSafe(xml: $responseXml);

        return [
            'zaakIdentificatie' => $this->firstValue(xpath: $xpath, query: '//zkn:object/zkn:identificatie'),
            'berichtSoort'      => $this->firstValue(xpath: $xpath, query: '//stuf:stuurgegevens/stuf:berichtcode'),
            'referentienummer'  => $this->firstValue(xpath: $xpath, query: '//stuf:stuurgegevens/stuf:referentienummer'),
            'crossRefnummer'    => $this->firstValue(xpath: $xpath, query: '//stuf:stuurgegevens/stuf:crossRefnummer'),
        ];
    }//end parseInbound()

    /**
     * Load XML into an XPath context with external entities disabled.
     *
     * Hardening (ADR-005):
     *  - LIBXML_NONET forbids network access during parsing.
     *  - LIBXML_NOENT is NOT set, so entity references are never substituted.
     *  - Any DOCTYPE declaration is rejected before parsing, killing the
     *    classic XXE and billion-laughs vectors at the door.
     *
     * @param string $xml The raw XML to parse.
     *
     * @return DOMXPath The XPath context bound to the StUF namespaces.
     *
     * @throws StufException If the XML declares a DOCTYPE or fails to parse.
     */
    private function loadXxeSafe(string $xml): DOMXPath
    {
        if ($xml === '') {
            throw new StufException(message: 'Empty StUF response envelope.');
        }

        // Reject any DOCTYPE outright — no legitimate StUF envelope carries one,
        // and its presence is the entry point for XXE / entity-expansion attacks.
        if (preg_match('/<!DOCTYPE/i', $xml) === 1) {
            $this->logger->warning('Rejected StUF envelope containing a DOCTYPE declaration (possible XXE).');
            throw new StufException(message: 'StUF envelope with DOCTYPE rejected.');
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $doc = new DOMDocument();
            // No LIBXML_NOENT: entity references are left unexpanded. LIBXML_NONET
            // blocks any network fetch. LIBXML_DTDLOAD/DTDVALID are intentionally absent.
            $loaded = $doc->loadXML($xml, (LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING));
            if ($loaded === false || $doc->documentElement === null) {
                throw new StufException(message: 'Malformed StUF response envelope.');
            }

            // Defensive: if a DTD slipped through tokenisation, reject it.
            if ($doc->doctype !== null) {
                throw new StufException(message: 'StUF envelope with DOCTYPE rejected.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }//end try

        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('stuf', self::NS_STUF);
        $xpath->registerNamespace('zkn', self::NS_ZKN);
        $xpath->registerNamespace('bg', self::NS_BG);

        return $xpath;
    }//end loadXxeSafe()

    /**
     * Return the trimmed text content of the first node matching an xpath.
     *
     * @param DOMXPath $xpath The XPath context.
     * @param string   $query The xpath query.
     *
     * @return string|null The value, or null when no node matches.
     */
    private function firstValue(DOMXPath $xpath, string $query): ?string
    {
        $nodes = $xpath->query($query);
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $value = trim($nodes->item(0)->textContent);
        if ($value === '') {
            return null;
        }

        return $value;
    }//end firstValue()

    /**
     * Classify a StUF fout code as transient (retryable) or permanent.
     *
     * @param string|null $code The stuf:fout code.
     *
     * @return bool True when the error is transient.
     */
    private function isTransientCode(?string $code): bool
    {
        if ($code === null) {
            return false;
        }

        // StUF052 = timing/asynchroon nog niet verwerkt; treat as transient.
        $transient = ['StUF052', 'StUF051'];

        return in_array($code, $transient, true);
    }//end isTransientCode()
}//end class
