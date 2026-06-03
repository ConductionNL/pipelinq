<?php

/**
 * Pipelinq StufEnvelopeBuilder.
 *
 * Constructs valid SOAP 1.1 + StUF 0310 envelopes (Lk01/Lk02/Lv01/Du01) with
 * stuurgegevens header, WSSE security, betrokkenen and base64 document binding.
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
 * @spec openspec/changes/stuf-zkn-bg-adapter/tasks.md#2.2
 * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#req-stuf-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Stuf;

use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use OCA\Pipelinq\Exception\PayloadTooLargeException;
use OCA\Pipelinq\Exception\ZaaktypeNotMappedException;
use Psr\Log\LoggerInterface;

/**
 * Builds StUF 0310 SOAP envelopes from pipelinq Request/Contact data.
 *
 * The stuurgegevens header carries the routing identity (zender/ontvanger),
 * a freshly-generated ULID referentienummer (idempotency key) and the
 * millisecond-precision tijdstipBericht in Europe/Amsterdam. The body carries
 * the zaak, its betrokkenen (mapped from Contacts) and any documents embedded
 * as base64. WSSE UsernameToken credentials are injected into the SOAP header.
 */
class StufEnvelopeBuilder
{
    /**
     * StUF 0310 namespace URIs.
     */
    private const NS_SOAPENV = 'http://schemas.xmlsoap.org/soap/envelope/';
    private const NS_STUF    = 'http://www.egem.nl/StUF/StUF0301';
    private const NS_ZKN     = 'http://www.egem.nl/StUF/sector/zkn/0310';
    private const NS_BG      = 'http://www.egem.nl/StUF/sector/bg/0310';
    private const NS_WSSE    = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';

    /**
     * Default pre-base64 document payload ceiling in bytes (25 MiB).
     *
     * @var int
     */
    private const DEFAULT_PAYLOAD_CEILING = (25 * 1024 * 1024);

    /**
     * Constructor.
     *
     * @param StufCredentialResolver $credentials The vault credential resolver.
     * @param LoggerInterface        $logger      The logger.
     */
    public function __construct(
        private StufCredentialResolver $credentials,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Build an Lk01 creeerZaak envelope from a Request.
     *
     * @param array<string, mixed> $request        The pipelinq Request object array.
     * @param array<string, mixed> $endpoint       The resolved StufEndpoint config.
     * @param string|null          $zaakId         Pre-allocated zaak ID, or null for server-allocation.
     * @param array<int, array>    $betrokkenen    Betrokkene specs (each with bsn/naam/rol).
     * @param array<int, array>    $documents      Document specs (each with bestandsnaam/formaat/inhoud).
     * @param int                  $payloadCeiling The pre-base64 payload ceiling in bytes.
     *
     * @return string The serialised SOAP envelope.
     *
     * @throws ZaaktypeNotMappedException If the request type has no zaaktype mapping.
     * @throws PayloadTooLargeException   If embedded documents exceed the ceiling.
     */
    public function buildLk01CreeerZaak(
        array $request,
        array $endpoint,
        ?string $zaakId=null,
        array $betrokkenen=[],
        array $documents=[],
        int $payloadCeiling=self::DEFAULT_PAYLOAD_CEILING,
    ): string {
        $zaaktype = $this->resolveZaaktype(request: $request, endpoint: $endpoint);
        $this->assertPayloadWithinCeiling(documents: $documents, ceiling: $payloadCeiling);

        [$doc, $body] = $this->newEnvelope(endpoint: $endpoint, berichtCode: 'Lk01', entiteittype: 'ZAK');

        $zakLk01 = $doc->createElementNS(self::NS_ZKN, 'zkn:zakLk01');
        $body->appendChild($zakLk01);

        $stuurgegevens = $this->buildStuurgegevens(
            doc: $doc,
            endpoint: $endpoint,
            berichtCode: 'Lk01',
            entiteittype: 'ZAK',
            functie: 'creeerZaak'
        );
        $zakLk01->appendChild($stuurgegevens);

        $object = $doc->createElementNS(self::NS_ZKN, 'zkn:object');
        $object->setAttributeNS(self::NS_STUF, 'stuf:entiteittype', 'ZAK');
        $object->setAttributeNS(self::NS_STUF, 'stuf:scope', 'alles');
        $object->setAttributeNS(self::NS_STUF, 'stuf:verwerkingssoort', 'T');
        $zakLk01->appendChild($object);

        if ($zaakId !== null && $zaakId !== '') {
            $object->appendChild($this->textEl(doc: $doc, namespace: self::NS_ZKN, qualified: 'zkn:identificatie', value: $zaakId));
        }

        $omschrijving = (string) ($request['title'] ?? ($request['subject'] ?? 'Aanvraag'));
        $object->appendChild($this->textEl(doc: $doc, namespace: self::NS_ZKN, qualified: 'zkn:omschrijving', value: $omschrijving));
        $object->appendChild(
            $this->textEl(doc: $doc, namespace: self::NS_ZKN, qualified: 'zkn:startdatum', value: $this->stufDate(date: new DateTimeImmutable('now')))
        );

        $zaaktypeEl = $doc->createElementNS(self::NS_ZKN, 'zkn:isVan');
        $gerel      = $doc->createElementNS(self::NS_ZKN, 'zkn:gerelateerde');
        $gerel->appendChild($this->textEl(doc: $doc, namespace: self::NS_ZKN, qualified: 'zkn:omschrijving', value: $zaaktype));
        $zaaktypeEl->appendChild($gerel);
        $object->appendChild($zaaktypeEl);

        foreach ($betrokkenen as $betrokkene) {
            $object->appendChild($this->buildInitiator(doc: $doc, betrokkene: $betrokkene));
        }

        foreach ($documents as $document) {
            $object->appendChild($this->buildDocument(doc: $doc, document: $document));
        }

        return $this->serialise(doc: $doc);
    }//end buildLk01CreeerZaak()

    /**
     * Build an Lk02 actualiseerZaak envelope.
     *
     * @param array<string, mixed> $endpoint    The resolved endpoint config.
     * @param string               $zaakId      The external zaak identificatie.
     * @param array<string, mixed> $wijzigingen Field changes (key => new value).
     *
     * @return string The serialised SOAP envelope.
     */
    public function buildLk02ActualiseerZaak(array $endpoint, string $zaakId, array $wijzigingen): string
    {
        [$doc, $body] = $this->newEnvelope(endpoint: $endpoint, berichtCode: 'Lk02', entiteittype: 'ZAK');

        $zakLk02 = $doc->createElementNS(self::NS_ZKN, 'zkn:zakLk02');
        $body->appendChild($zakLk02);

        $zakLk02->appendChild(
            $this->buildStuurgegevens(
                doc: $doc,
                endpoint: $endpoint,
                berichtCode: 'Lk02',
                entiteittype: 'ZAK',
                functie: 'actualiseerZaak'
            )
        );

        $object = $doc->createElementNS(self::NS_ZKN, 'zkn:object');
        $object->setAttributeNS(self::NS_STUF, 'stuf:entiteittype', 'ZAK');
        $object->setAttributeNS(self::NS_STUF, 'stuf:verwerkingssoort', 'W');
        $object->appendChild($this->textEl(doc: $doc, namespace: self::NS_ZKN, qualified: 'zkn:identificatie', value: $zaakId));
        $zakLk02->appendChild($object);

        foreach ($wijzigingen as $key => $value) {
            if (is_scalar($value) === true && preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', (string) $key) === 1) {
                $object->appendChild(
                    $this->textEl(doc: $doc, namespace: self::NS_ZKN, qualified: 'zkn:'.$key, value: (string) $value)
                );
            }
        }

        return $this->serialise(doc: $doc);
    }//end buildLk02ActualiseerZaak()

    /**
     * Build an Lv01 geefZaakDetails vraag envelope.
     *
     * @param array<string, mixed> $endpoint          The resolved endpoint config.
     * @param string               $zaakId            The zaak identificatie to query.
     * @param array<int, string>   $gewensteElementen The desired response elements.
     *
     * @return string The serialised SOAP envelope.
     */
    public function buildLv01GeefDetails(array $endpoint, string $zaakId, array $gewensteElementen=[]): string
    {
        [$doc, $body] = $this->newEnvelope(endpoint: $endpoint, berichtCode: 'Lv01', entiteittype: 'ZAK');

        $zakLv01 = $doc->createElementNS(self::NS_ZKN, 'zkn:zakLv01');
        $body->appendChild($zakLv01);

        $zakLv01->appendChild(
            $this->buildStuurgegevens(
                doc: $doc,
                endpoint: $endpoint,
                berichtCode: 'Lv01',
                entiteittype: 'ZAK',
                functie: 'geefZaakDetails'
            )
        );

        $gelijk = $doc->createElementNS(self::NS_ZKN, 'zkn:gelijk');
        $gelijk->setAttributeNS(self::NS_STUF, 'stuf:entiteittype', 'ZAK');
        $gelijk->appendChild($this->textEl(doc: $doc, namespace: self::NS_ZKN, qualified: 'zkn:identificatie', value: $zaakId));
        $zakLv01->appendChild($gelijk);

        $scope = $doc->createElementNS(self::NS_ZKN, 'zkn:scope');
        $obj   = $doc->createElementNS(self::NS_ZKN, 'zkn:object');
        $obj->setAttributeNS(self::NS_STUF, 'stuf:entiteittype', 'ZAK');
        foreach ($gewensteElementen as $element) {
            if (preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $element) === 1) {
                $gewenst = $doc->createElementNS(self::NS_ZKN, 'zkn:'.$element);
                $gewenst->setAttributeNS(self::NS_STUF, 'stuf:noValue', 'geenWaarde');
                $obj->appendChild($gewenst);
            }
        }

        $scope->appendChild($obj);
        $zakLv01->appendChild($scope);

        return $this->serialise(doc: $doc);
    }//end buildLv01GeefDetails()

    /**
     * Build a Du01 genereerZaakIdentificatie envelope (pre-allocation).
     *
     * @param array<string, mixed> $endpoint The resolved endpoint config.
     *
     * @return string The serialised SOAP envelope.
     */
    public function buildDu01GenereerZaakId(array $endpoint): string
    {
        [$doc, $body] = $this->newEnvelope(endpoint: $endpoint, berichtCode: 'Du01', entiteittype: 'ZAK');

        $du01 = $doc->createElementNS(self::NS_ZKN, 'zkn:genereerZaakIdentificatie_Du01');
        $body->appendChild($du01);

        $du01->appendChild(
            $this->buildStuurgegevens(
                doc: $doc,
                endpoint: $endpoint,
                berichtCode: 'Du01',
                entiteittype: 'ZAK',
                functie: 'genereerZaakIdentificatie'
            )
        );

        return $this->serialise(doc: $doc);
    }//end buildDu01GenereerZaakId()

    /**
     * Build a vrijBericht (Du01) envelope from a template and payload.
     *
     * @param array<string, mixed> $endpoint The resolved endpoint config.
     * @param string               $name     The vrijBericht template name.
     * @param array<string, mixed> $payload  The payload field values.
     *
     * @return string The serialised SOAP envelope.
     */
    public function buildVrijBericht(array $endpoint, string $name, array $payload): string
    {
        [$doc, $body] = $this->newEnvelope(endpoint: $endpoint, berichtCode: 'Du01', entiteittype: 'ZAK');

        $vrij = $doc->createElementNS(self::NS_ZKN, 'zkn:'.$this->safeName(name: $name).'_Du01');
        $body->appendChild($vrij);

        $vrij->appendChild(
            $this->buildStuurgegevens(
                doc: $doc,
                endpoint: $endpoint,
                berichtCode: 'Du01',
                entiteittype: 'ZAK',
                functie: $name
            )
        );

        $parameters = $doc->createElementNS(self::NS_ZKN, 'zkn:parameters');
        foreach ($payload as $key => $value) {
            if (is_scalar($value) === true) {
                $parameters->appendChild(
                    $this->textEl(doc: $doc, namespace: self::NS_ZKN, qualified: 'zkn:'.$this->safeName(name: (string) $key), value: (string) $value)
                );
            }
        }

        $vrij->appendChild($parameters);

        return $this->serialise(doc: $doc);
    }//end buildVrijBericht()

    /**
     * Generate a fresh ULID referentienummer for idempotency.
     *
     * @return string A 26-char Crockford base-32 ULID.
     */
    public function generateReferentienummer(): string
    {
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

        $timeMs = (int) round((microtime(true) * 1000));
        $time   = '';
        for ($i = 0; $i < 10; $i++) {
            $time   = $alphabet[($timeMs % 32)].$time;
            $timeMs = intdiv($timeMs, 32);
        }

        $random = '';
        for ($i = 0; $i < 16; $i++) {
            $random .= $alphabet[random_int(0, 31)];
        }

        return $time.$random;
    }//end generateReferentienummer()

    /**
     * Current timestamp in StUF format (Europe/Amsterdam, yyyyMMddHHmmssSSS).
     *
     * @return string The 17-digit timestamp.
     */
    public function currentTimestampStuf(): string
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Amsterdam'));

        return $now->format('YmdHis').substr($now->format('u'), 0, 3);
    }//end currentTimestampStuf()

    /**
     * Build the shared stuurgegevens header element.
     *
     * @param DOMDocument          $doc          The owning document.
     * @param array<string, mixed> $endpoint     The resolved endpoint config.
     * @param string               $berichtCode  The bericht code (Lk01, ...).
     * @param string               $entiteittype The entity type (ZAK, ...).
     * @param string|null          $functie      The operation (creeerZaak, ...).
     *
     * @return DOMElement The stuurgegevens element.
     */
    public function buildStuurgegevens(
        DOMDocument $doc,
        array $endpoint,
        string $berichtCode,
        string $entiteittype,
        ?string $functie,
    ): DOMElement {
        $stuur = $doc->createElementNS(self::NS_ZKN, 'zkn:stuurgegevens');
        $stuur->appendChild($doc->createElementNS(self::NS_STUF, 'stuf:berichtcode', $berichtCode));

        $zender = $doc->createElementNS(self::NS_STUF, 'stuf:zender');
        $zender->appendChild(
            $this->textEl(doc: $doc, namespace: self::NS_STUF, qualified: 'stuf:organisatie', value: (string) ($endpoint['zenderOrganisatie'] ?? ''))
        );
        $zender->appendChild(
            $this->textEl(doc: $doc, namespace: self::NS_STUF, qualified: 'stuf:applicatie', value: (string) ($endpoint['zenderApplicatie'] ?? ''))
        );
        $stuur->appendChild($zender);

        $ontvangerOrganisatie = (string) ($endpoint['ontvangerOrganisatie'] ?? '');
        $ontvangerApplicatie  = (string) ($endpoint['ontvangerApplicatie'] ?? '');
        $ontvangerGebruiker   = (string) ($endpoint['ontvangerGebruiker'] ?? '');

        $ontvanger = $doc->createElementNS(self::NS_STUF, 'stuf:ontvanger');
        $ontvanger->appendChild(
            $this->textEl(doc: $doc, namespace: self::NS_STUF, qualified: 'stuf:organisatie', value: $ontvangerOrganisatie)
        );
        $ontvanger->appendChild(
            $this->textEl(doc: $doc, namespace: self::NS_STUF, qualified: 'stuf:applicatie', value: $ontvangerApplicatie)
        );
        $ontvanger->appendChild(
            $this->textEl(doc: $doc, namespace: self::NS_STUF, qualified: 'stuf:gebruiker', value: $ontvangerGebruiker)
        );
        $stuur->appendChild($ontvanger);

        $referentienummer = $this->generateReferentienummer();
        $tijdstip         = $this->currentTimestampStuf();
        $stuur->appendChild(
            $this->textEl(doc: $doc, namespace: self::NS_STUF, qualified: 'stuf:referentienummer', value: $referentienummer)
        );
        $stuur->appendChild(
            $this->textEl(doc: $doc, namespace: self::NS_STUF, qualified: 'stuf:tijdstipBericht', value: $tijdstip)
        );
        $stuur->appendChild(
            $this->textEl(doc: $doc, namespace: self::NS_STUF, qualified: 'stuf:entiteittype', value: $entiteittype)
        );
        if ($functie !== null && $functie !== '') {
            $stuur->appendChild($this->textEl(doc: $doc, namespace: self::NS_STUF, qualified: 'stuf:functie', value: $functie));
        }

        return $stuur;
    }//end buildStuurgegevens()

    /**
     * Resolve the zaaktype omschrijving for a request from the endpoint mapping.
     *
     * @param array<string, mixed> $request  The pipelinq Request array.
     * @param array<string, mixed> $endpoint The resolved endpoint config.
     *
     * @return string The mapped zaaktype omschrijving.
     *
     * @throws ZaaktypeNotMappedException If no mapping exists for the request type.
     */
    private function resolveZaaktype(array $request, array $endpoint): string
    {
        $type     = (string) ($request['type'] ?? '');
        $mappings = ($endpoint['zaaktypeMappings'] ?? []);

        if ($type !== '' && is_array($mappings) === true && isset($mappings[$type]) === true) {
            return (string) $mappings[$type];
        }

        throw new ZaaktypeNotMappedException(message: 'No zaaktype mapping for request type "'.$type.'".');
    }//end resolveZaaktype()

    /**
     * Assert the combined document payload is within the configured ceiling.
     *
     * @param array<int, array> $documents The document specs.
     * @param int               $ceiling   The pre-base64 byte ceiling.
     *
     * @return void
     *
     * @throws PayloadTooLargeException If the combined payload exceeds the ceiling.
     */
    private function assertPayloadWithinCeiling(array $documents, int $ceiling): void
    {
        $total = 0;
        foreach ($documents as $document) {
            $inhoud = (string) ($document['inhoud'] ?? '');
            $total += strlen($inhoud);
        }

        if ($total > $ceiling) {
            throw new PayloadTooLargeException(
                message: 'Document payload '.$total.' bytes exceeds ceiling '.$ceiling.' bytes; use a DMS-direct URL.'
            );
        }
    }//end assertPayloadWithinCeiling()

    /**
     * Build a heeftAlsInitiator betrokkene element from a betrokkene spec.
     *
     * @param DOMDocument          $doc        The owning document.
     * @param array<string, mixed> $betrokkene The betrokkene spec (bsn/naam).
     *
     * @return DOMElement The heeftAlsInitiator element.
     */
    private function buildInitiator(DOMDocument $doc, array $betrokkene): DOMElement
    {
        $initiator = $doc->createElementNS(self::NS_ZKN, 'zkn:heeftAlsInitiator');
        $gerel     = $doc->createElementNS(self::NS_ZKN, 'zkn:gerelateerde');

        $nps = $doc->createElementNS(self::NS_ZKN, 'zkn:natuurlijkPersoon');
        $nps->setAttributeNS(self::NS_STUF, 'stuf:entiteittype', 'NPS');

        $bsn = (string) ($betrokkene['bsn'] ?? '');
        if ($bsn !== '') {
            $nps->appendChild($this->textEl(doc: $doc, namespace: self::NS_BG, qualified: 'bg:inp.bsn', value: $bsn));
        }

        $naam = (string) ($betrokkene['naam'] ?? '');
        if ($naam !== '') {
            $nps->appendChild($this->textEl(doc: $doc, namespace: self::NS_BG, qualified: 'bg:geslachtsnaam', value: $naam));
        }

        $gerel->appendChild($nps);
        $initiator->appendChild($gerel);

        return $initiator;
    }//end buildInitiator()

    /**
     * Build a heeftRelevant document element with base64 content.
     *
     * @param DOMDocument          $doc      The owning document.
     * @param array<string, mixed> $document The document spec (bestandsnaam/formaat/inhoud).
     *
     * @return DOMElement The heeftRelevant element.
     */
    private function buildDocument(DOMDocument $doc, array $document): DOMElement
    {
        $relevant = $doc->createElementNS(self::NS_ZKN, 'zkn:heeftRelevant');
        $gerel    = $doc->createElementNS(self::NS_ZKN, 'zkn:gerelateerde');

        $edc = $doc->createElementNS(self::NS_ZKN, 'zkn:enkelvoudigDocument');
        $edc->setAttributeNS(self::NS_STUF, 'stuf:entiteittype', 'EDC');

        $bestandsnaam = (string) ($document['bestandsnaam'] ?? 'document');
        $formaat      = (string) ($document['formaat'] ?? 'application/octet-stream');
        $inhoud       = (string) ($document['inhoud'] ?? '');

        $edc->appendChild($this->textEl(doc: $doc, namespace: self::NS_STUF, qualified: 'stuf:bestandsnaam', value: $bestandsnaam));
        $edc->appendChild($this->textEl(doc: $doc, namespace: self::NS_STUF, qualified: 'stuf:formaat', value: $formaat));
        // The base64_encode call never inserts line breaks, satisfying the no-line-wrapping rule.
        $edc->appendChild($this->textEl(doc: $doc, namespace: self::NS_STUF, qualified: 'stuf:bestandsinhoud', value: base64_encode($inhoud)));

        $gerel->appendChild($edc);
        $relevant->appendChild($gerel);

        return $relevant;
    }//end buildDocument()

    /**
     * Create a fresh SOAP envelope with the WSSE header and an empty body.
     *
     * @param array<string, mixed> $endpoint     The resolved endpoint config.
     * @param string               $berichtCode  The bericht code (for logging).
     * @param string               $entiteittype The entity type (for logging).
     *
     * @return array{0: DOMDocument, 1: DOMElement} The document and its body element.
     */
    private function newEnvelope(array $endpoint, string $berichtCode, string $entiteittype): array
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = false;

        $envelope = $doc->createElementNS(self::NS_SOAPENV, 'soapenv:Envelope');
        $doc->appendChild($envelope);

        $header = $doc->createElementNS(self::NS_SOAPENV, 'soapenv:Header');
        $this->appendWsseSecurity(doc: $doc, header: $header, endpoint: $endpoint);
        $envelope->appendChild($header);

        $body = $doc->createElementNS(self::NS_SOAPENV, 'soapenv:Body');
        $envelope->appendChild($body);

        $this->logger->debug(
            'StUF envelope scaffolded',
            ['berichtcode' => $berichtCode, 'entiteittype' => $entiteittype]
        );

        return [$doc, $body];
    }//end newEnvelope()

    /**
     * Append a WSSE UsernameToken security header sourced from the vault.
     *
     * @param DOMDocument          $doc      The owning document.
     * @param DOMElement           $header   The SOAP header element.
     * @param array<string, mixed> $endpoint The resolved endpoint config.
     *
     * @return void
     */
    private function appendWsseSecurity(DOMDocument $doc, DOMElement $header, array $endpoint): void
    {
        $auth = ($endpoint['authenticatie'] ?? []);
        if (is_array($auth) === false) {
            return;
        }

        $username = (string) ($auth['gebruikersnaam'] ?? '');
        $passRef  = (string) ($auth['wachtwoordKluisRef'] ?? '');
        if ($username === '' || $passRef === '') {
            return;
        }

        // Resolve the password from the vault at build time; never inline it in config.
        $password = $this->credentials->resolve($passRef);
        if ($password === null) {
            $this->logger->error(
                'StUF WSSE password unresolved; envelope built without UsernameToken',
                ['endpoint' => ($endpoint['id'] ?? ($endpoint['naam'] ?? 'unknown'))]
            );
            return;
        }

        $security = $doc->createElementNS(self::NS_WSSE, 'wsse:Security');
        $token    = $doc->createElementNS(self::NS_WSSE, 'wsse:UsernameToken');
        $token->appendChild($this->textEl(doc: $doc, namespace: self::NS_WSSE, qualified: 'wsse:Username', value: $username));
        $token->appendChild($this->textEl(doc: $doc, namespace: self::NS_WSSE, qualified: 'wsse:Password', value: $password));
        $security->appendChild($token);
        $header->appendChild($security);
    }//end appendWsseSecurity()

    /**
     * Serialise the document to a SOAP envelope string.
     *
     * @param DOMDocument $doc The document to serialise.
     *
     * @return string The XML string.
     */
    private function serialise(DOMDocument $doc): string
    {
        $xml = $doc->saveXML();

        if ($xml === false) {
            return '';
        }

        return $xml;
    }//end serialise()

    /**
     * Sanitise free text for safe placement inside an element (defence in depth).
     *
     * DOMDocument already escapes text-node content; this additionally strips
     * control characters that are illegal in XML 1.0.
     *
     * @param string $value The raw value.
     *
     * @return string The cleaned value.
     */
    private function xmlText(string $value): string
    {
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? '';
    }//end xmlText()

    /**
     * Reduce a name to a safe XML NCName fragment.
     *
     * @param string $name The raw name.
     *
     * @return string The sanitised local name.
     */
    private function safeName(string $name): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_]/', '', $name) ?? '';

        if ($clean === '') {
            return 'bericht';
        }

        return $clean;
    }//end safeName()

    /**
     * Format a date in StUF date format (yyyyMMdd).
     *
     * @param DateTimeImmutable $date The date.
     *
     * @return string The formatted date.
     */
    private function stufDate(DateTimeImmutable $date): string
    {
        return $date->format('Ymd');
    }//end stufDate()

    /**
     * Create a namespaced element whose text content is set via a text node.
     *
     * Passing the value through DOMDocument::createElementNS()'s third argument
     * treats `&`/`<` as markup (and warns on unterminated entities). Building the
     * text node separately lets DOMDocument escape special characters correctly,
     * which is essential for free-text values like a zaak omschrijving.
     *
     * @param DOMDocument $doc       The owning document.
     * @param string      $namespace The namespace URI.
     * @param string      $qualified The qualified element name (prefix:local).
     * @param string      $value     The raw text value (cleaned of control chars).
     *
     * @return DOMElement The element with its text content set.
     */
    private function textEl(DOMDocument $doc, string $namespace, string $qualified, string $value): DOMElement
    {
        $element = $doc->createElementNS($namespace, $qualified);
        $element->appendChild($doc->createTextNode($this->xmlText(value: $value)));

        return $element;
    }//end textEl()
}//end class
