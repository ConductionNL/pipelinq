# Spec: stuf-zkn-bg-adapter

## Purpose

Define functional requirements for integrating Pipelinq with legacy zaaksystemen (case management systems) via StUF 0310 protocol. Enable municipalities without ZGW REST APIs to use Pipelinq as a modern KCC front-end while maintaining back-office integration. Ensure audit compliance via per-call envelope logging.

---

## Requirements

### REQ-STUF-001: SOAP Envelope Construction

The system MUST construct valid SOAP 1.1 envelopes that comply with StUF 0310 message header rules.

#### Scenario: outbound creeerZaak envelope passes XSD validation

- GIVEN a pipelinq request "req-2026-aanvraag-evenementenvergunning-0123" with type "evenementenvergunning" and assigned endpoint stuf-ep-amersfoort-key2zaken
- WHEN the system builds the Lk01 creeerZaak envelope via StufEnvelopeBuilder::buildLk01CreeerZaak()
- THEN the envelope MUST validate against the official StUF-ZKN 0310 zkn0310_msg_zs-dms.xsd schema
- AND the stuurgegevens.referentienummer MUST be a freshly-generated ULID
- AND the tijdstipBericht MUST be the current timestamp in Europe/Amsterdam at millisecond precision (yyyyMMddHHmmssSSS)
- AND all StUF namespace declarations (xmlns:stuf, xmlns:zkn, xmlns:bg) MUST be present
- AND every element MUST carry proper scope/mutatie attributes per StUF 0310 rules

#### Scenario: incoming kennisgeving from PinkRoccade is parsed

- GIVEN an inbound Lk02 actualiseerZaak envelope from PinkRoccade containing zkn:object/zkn:identificatie = "ZAAK-2026-0008812" with new zkn:einddatum
- WHEN pipelinq receives the envelope on POST /api/stuf/inkomend
- THEN the system MUST locate the ZaaksysteemMapping by externIdentificatie="ZAAK-2026-0008812"
- AND update the linked pipelinq Request's relevant status field
- AND persist a StufMessage with richting="inkomend", berichtSoort="Lk02", and crossRefnummer set to the inbound referentienummer

---

### REQ-STUF-002: Kennisgeving (Asynchronous Notification) Flow

For asynchronous notifications (Lk01 creeer, Lk02 actualiseer, Lk03 verwijder) the system MUST send the envelope and accept a Bv01 acknowledgement.

#### Scenario: Bv01 bevestiging closes the kennisgeving

- GIVEN an outbound Lk01 creeerZaak has been sent via StufHttpClient::send() and a StufMessage row exists with status "verzonden"
- WHEN the zaaksysteem responds with a Bv01 envelope containing referentienummer matching the outbound message
- THEN the StufMessage MUST transition status to "bevestigd"
- AND the corresponding ZaaksysteemMapping MUST have synchronisatieStatus set to "in_sync"
- AND the responseEnvelopeXml MUST be captured in the StufMessage row

#### Scenario: Fo02 foutbericht surfaces functional error

- GIVEN an outbound Lk02 actualiseerZaak envelope
- WHEN the zaaksysteem responds with a Fo02 foutbericht containing stuf:code = "StUF064" (entiteit niet aanwezig)
- THEN the StufMessage MUST capture the fout object with code, omschrijving, and details
- AND the ZaaksysteemMapping MUST be flagged with synchronisatieStatus="fout"
- AND a needs-input event MUST be raised to configured beheerder role(s)

#### Scenario: Bv01 with server-allocated zaak ID

- GIVEN endpoint stuf-ep-rotterdam-pinkroccade has zaakIdentificatieStrategie="achteraf"
- WHEN an Lk01 creeerZaak is sent without zkn:identificatie and the Bv01 response includes stuf:antwoord with zkn:identificatie="ZAAK-2026-0008813"
- THEN the system MUST parse the Bv01 via StufMessageParser::parseBevestiging()
- AND create a ZaaksysteemMapping with externIdentificatie="ZAAK-2026-0008813"
- AND link it to the Request that triggered the creeerZaak

---

### REQ-STUF-003: Vraag/Antwoord (Synchronous Query) Flow

For synchronous queries (Lv01 vraag → La01 antwoord) the system MUST block up to configurable timeout (default 30s) waiting for La01 response.

#### Scenario: geefZaakDetails returns hydrated Zaak object

- GIVEN endpoint stuf-ep-amersfoort-key2zaken is configured
- WHEN the caller invokes adapter.geefZaakDetails("ZAAK-2026-0008812")
- THEN the system MUST send a Lv01 with object/zkn:identificatie="ZAAK-2026-0008812" and appropriate gewensteElementen
- AND wait up to 30s for a La01 response
- AND parse the La01 via StufMessageParser::parseZaakDetails()
- AND return a hydrated Zaak object with identificatie, omschrijving, startdatum, einddatum, statussen[], zaaktype.omschrijving, betrokkenen[] if present

#### Scenario: timeout escalates to needs-input

- GIVEN the endpoint is reachable but slow
- WHEN geefZaakDetails sends Lv01 and the zaaksysteem does not respond within 30s
- THEN the system MUST NOT retry the synchronous call automatically
- AND MUST raise a needs-input event including the StufMessage id
- AND the calling workflow MUST receive a TimeoutException

---

### REQ-STUF-004: creeerZaak Operation

The system MUST expose adapter.creeerZaak(request, opts) which constructs an Lk01, maps Request fields to zaak, attaches Contacts as betrokkenen, and includes Documents as base64.

#### Scenario: evenementenvergunning request creates zaak with initiator

- GIVEN a Request "req-2026-aanvraag-evenementenvergunning-0123" with type "evenementenvergunning", linked Contact "Jeroen van der Velde" (BSN 123456789), and one PDF "aanvraagformulier.pdf"
- WHEN adapter.creeerZaak(request, {includeDocuments: true}) is called
- THEN the Lk01 envelope MUST contain:
  - zkn:zaaktype/zkn:omschrijving="Evenementenvergunning" (mapped from request.type)
  - zkn:heeftAlsInitiator with bg:inp.bsn="123456789"
  - zkn:heeftRelevant document with stuf:bestandsnaam="aanvraagformulier.pdf" and bestandsinhoud base64-encoded
- AND the envelope MUST be sent via StufHttpClient::send()
- AND a StufMessage MUST be created with gerelateerdeRequestId pointing to the Request

#### Scenario: zaaktype not configured raises domain error before send

- GIVEN a Request with type "onbekend-type" that does not exist in the configured zaaktype-mapping table
- WHEN adapter.creeerZaak is called
- THEN a ZaaktypeNotMappedException MUST be raised
- AND no SOAP envelope MUST be transmitted
- AND no StufMessage MUST be created

---

### REQ-STUF-005: genereerZaakIdentificatie Operation

The system MUST support both pre-allocation and post-allocation of zaak identificatie.

#### Scenario: pre-allocated ID used in creeerZaak

- GIVEN endpoint stuf-ep-rotterdam-pinkroccade has zaakIdentificatieStrategie="vooraf"
- WHEN adapter.creeerZaak(request) is invoked
- THEN the system MUST first send Du01 genereerZaakIdentificatie via StufHttpClient::send()
- AND parse the returned zkn:identificatie="ZAAK-2026-0008812" from La01 response
- AND include this identificatie in the subsequent Lk01 creeerZaak
- AND persist ZaaksysteemMapping with externIdentificatie="ZAAK-2026-0008812" before the Bv01 is received (anticipatory)

#### Scenario: server-allocated ID captured from acknowledgement

- GIVEN endpoint with zaakIdentificatieStrategie="achteraf"
- WHEN adapter.creeerZaak sends the Lk01 with no identificatie
- AND the Bv01 returns referentienummer + stuf:antwoord with zkn:identificatie="ZAAK-2026-0008813"
- THEN ZaaksysteemMapping MUST be created using this server-issued identificatie

---

### REQ-STUF-006: Document Binding via Base64

The adapter MUST support attaching documents as base64-encoded content inside the StUF envelope.

#### Scenario: PDF attached to creeerZaak as base64

- GIVEN a Document "aanvraagformulier.pdf" of 1.4 MiB application/pdf attached to the request
- WHEN the document is embedded in an Lk01 creeerZaak envelope via StufEnvelopeBuilder
- THEN the envelope MUST contain one zkn:heeftRelevant with:
  - stuf:bestandsnaam="aanvraagformulier.pdf"
  - stuf:formaat="application/pdf"
  - stuf:bestandsinhoud equal to base64 encoding of the file bytes (no line wrapping)

#### Scenario: 40 MiB attachment is rejected pre-send

- GIVEN a Document of 40 MiB attached to a request
- WHEN adapter.creeerZaak is invoked with includeDocuments=true and default payload ceiling (25 MiB pre-base64)
- THEN a PayloadTooLargeException MUST be raised before any SOAP transmission
- AND the calling workflow MUST be advised to use alternate channels (e.g., DMS-direct URL)

---

### REQ-STUF-007: vrijeBerichten (Free Messages)

The system MUST expose adapter.vrijBericht(name, payload) for non-standard gemeente interactions.

#### Scenario: zetStatus vrijBericht updates zaak status

- GIVEN a vrijBericht template "zetStatus" registered on endpoint stuf-ep-amersfoort-key2zaken with required fields zaakIdentificatie, statusType, datumStatusGezet
- WHEN adapter.vrijBericht("zetStatus", {zaakIdentificatie: "ZAAK-2026-0008812", statusType: "in_behandeling", datumStatusGezet: "2026-05-21T09:00:00+02:00"}) is called
- THEN the system MUST construct the envelope from the template
- AND populate the fields with the provided payload
- AND send as Du01
- AND persist the resulting StufMessage with functie="zetStatus"

#### Scenario: unknown vrijBericht name raises immediately

- GIVEN no template is registered for "doeIetsRaars"
- WHEN adapter.vrijBericht("doeIetsRaars", {...}) is called
- THEN a VrijBerichtNotRegisteredException MUST be raised
- AND no SOAP traffic MUST occur

---

### REQ-STUF-008: Per-Call Audit Log

Every outbound and inbound envelope MUST result in exactly one StufMessage row.

#### Scenario: inbound bevestiging links to outbound envelope

- GIVEN an outbound Lk01 StufMessage with referentienummer "01HXXXXXX..."
- WHEN the matching inbound Bv01 arrives carrying crossRefnummer="01HXXXXXX..."
- THEN both StufMessage rows (outbound and inbound) MUST be retrievable via query on referentienummer or crossRefnummer
- AND the outbound row's status MUST transition from "verzonden" to "bevestigd"
- AND the inbound row MUST have crossRefnummer set to match the outbound referentienummer

#### Scenario: audit log survives mapping deletion

- GIVEN a ZaaksysteemMapping is deleted (e.g., zaak vernietigd)
- WHEN the deletion is committed
- THEN the StufMessage rows referencing the mapping via gerelateerdeRequestId/gerelateerdeContactId MUST remain in the audit log
- AND remain queryable for GDPR accountability

---

### REQ-STUF-009: Retry, Idempotency and Circuit Breaker

For transient failures on kennisgevingen the adapter MUST retry with exponential backoff and guarantee idempotency.

#### Scenario: 503 on first attempt, success on second

- GIVEN an outbound Lk01 creeerZaak
- WHEN the first POST returns HTTP 503
- AND CircuitBreakerService::recordFailure() increments failure count
- AND retries fire at 5s, 30s, 2m, 10m intervals
- AND the retry at 5s later returns Bv01 200
- THEN exactly one StufMessage row MUST exist (not two)
- AND the retries[] array MUST capture the 503 detail with attempt, timestamp, httpStatus
- AND the final status MUST be "bevestigd"
- AND the same referentienummer MUST be reused across retries for idempotency

#### Scenario: four-attempt failure trips circuit breaker

- GIVEN four consecutive 5xx responses for the same envelope
- WHEN the fourth attempt fails
- THEN CircuitBreakerService::checkEndpoint(endpoint) MUST return false
- AND all subsequent sends to that endpoint within 5 minutes MUST short-circuit with CircuitOpenException
- AND a needs-input event MUST be raised with the endpoint id and last fout payload

#### Scenario: circuit breaker resets after cooldown

- GIVEN the circuit breaker is open for endpoint stuf-ep-amersfoort-key2zaken
- WHEN 5 minutes have passed since the circuit opened
- THEN CircuitBreakerService MUST reset the failure count
- AND subsequent requests to that endpoint MUST proceed normally

---

### REQ-STUF-010: Contact ↔ Betrokkene Mapping

The adapter MUST maintain bidirectional mapping between pipelinq Contact entities and zaaksysteem betrokkene (NPS or NNP).

#### Scenario: existing betrokkene reused on second request

- GIVEN Contact "Jeroen van der Velde" (BSN 123456789) was previously linked to ZAAK-2026-0008812
- AND a ZaaksysteemMapping exists with pipelinqEntiteit="contact", externEntiteit="NPS", externIdentificatie="NPS-id-xyz"
- WHEN a new Request from the same Contact triggers creeerZaak
- THEN the Lk01 MUST reference the existing betrokkene identificatie via bg:inp.bsn="123456789"
- AND no duplicate NPS MUST be created in the zaaksysteem
- AND the ZaaksysteemMapping MUST be reused

#### Scenario: unknown BSN triggers lookup before create

- GIVEN a new Contact "Yasmine Achahbar" (BSN 987654321) with no existing ZaaksysteemMapping
- WHEN adapter.creeerZaak is invoked via ContactBetrokkeneMapper::findOrCreateBetrokkene()
- THEN the system MUST first send Lv01 geefBetrokkene filtering on bg:inp.bsn="987654321"
- AND if no NPS is returned in La01, include a full bg:NPS element with persoonsgegevens in the Lk01
- AND the resulting NPS identificatie MUST be persisted in a new ZaaksysteemMapping
- AND subsequent Requests from the same Contact MUST reuse the NPS identificatie

---

### REQ-STUF-011: Endpoint Configuration and Authentication

StufEndpoint profiles MUST support WSSE UsernameToken and mutual TLS authentication.

#### Scenario: WSSE credentials are injected into envelope header

- GIVEN a StufEndpoint with authenticatie.type="wsse-usernametoken" and wachtwoordKluisRef="vault://stuf/amersfoort/key2zaken"
- WHEN an Lk01 envelope is built via StufEnvelopeBuilder::buildLk01CreeerZaak()
- THEN the SOAP header MUST include a wsse:Security element
- AND the wsse:UsernameToken MUST contain the fetched username and password
- AND the password MUST be retrieved from the vault reference at envelope send time (not stored plaintext)

#### Scenario: mutual TLS certificate is used for client authentication

- GIVEN a StufEndpoint with tlsClientCertRef="vault://pki/pipelinq-amersfoort.pem"
- WHEN StufHttpClient::send() initiates the HTTPS connection
- THEN the client certificate MUST be loaded from the vault reference
- AND used for mutual TLS handshake (client authentication)
- AND server certificate verification MUST NOT be disabled

---

### REQ-STUF-012: Needs-Input Escalation

When the adapter cannot proceed (circuit open, permanent error, timeout), it MUST raise a needs-input event for the configured beheerder.

#### Scenario: circuit breaker open raises needs-input

- GIVEN the circuit breaker is open for endpoint stuf-ep-amersfoort-key2zaken
- WHEN a request tries to send an Lk01 to that endpoint
- THEN CircuitBreakerService::checkEndpoint() returns false
- AND a needs-input event MUST be raised with type "stuf_circuit_open" and endpoint id
- AND the beheerder MUST receive a notification (via ADR-031 crashes-to-needs-input)

#### Scenario: permanent error escalates

- GIVEN a Fo02 foutbericht with stuf:code indicating a permanent error (e.g., "StUF064" - entiteit niet aanwezig)
- WHEN the ZaaksysteemMapping is flagged with synchronisatieStatus="fout"
- THEN a needs-input event MUST be raised
- AND the beheerder MUST be prompted to investigate or manually correct the mapping
