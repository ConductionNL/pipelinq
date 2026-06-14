---
status: draft
---
# StUF ZKN + BG adapter for zaaksysteem integration

## Purpose

Pipelinq is positioned as the modern KCC (Klantcontactcentrum) and request-management application for Dutch municipalities, replacing or augmenting legacy "midoffice" front-ends. The overwhelming majority of Dutch gemeenten still run a zaaksysteem (case management system) that exposes its integration layer over StUF — the Standaard Uitwisselings Formaat — specifically StUF-ZKN 0310 for cases and StUF-BG 0310 for persons. Without a StUF adapter, pipelinq cannot register a citizen request as a zaak in the back-office, cannot synchronise contact persons against the BRP-derived persoonsgegevens already held in the zaaksysteem, and cannot deliver documents that the zaakbehandelaar will see in their existing workflow. This spec defines the SOAP-over-HTTP envelope handling, the kennisgeving (notification) and vraag/antwoord (synchronous query) message patterns, the four core ZKN service operations (creeerZaak, actualiseerZaak, genereerZaakIdentificatie, vrijeBerichten), the base64 document binding, and the per-call audit log that the gemeente's CISO will require for traceability. The adapter is the bridge that makes pipelinq usable in any of the ~250 municipalities that have not yet migrated to the modern ZGW REST APIs.

## Data Model

The StUF adapter introduces three persistence entities in the pipelinq register: a `StufEndpoint` (connection profile per gemeente), a `StufMessage` (every envelope sent or received, for audit), and a `ZaaksysteemMapping` (the bidirectional link between a pipelinq request/contact and the externally-generated zaak/betrokkene identifiers). Existing pipelinq entities (`Request`, `Contact`, `Document`) gain referencing fields but are not redefined here.

### StufEndpoint

Configuration for one zaaksysteem connection. Multiple endpoints can coexist (e.g. a gemeente running both Centric Key2Zaken and Atos PinkRoccade for different domains during a transition).

```json
{
  "id": "stuf-ep-amersfoort-key2zaken",
  "naam": "Gemeente Amersfoort - Centric Key2Zaken",
  "gemeenteCode": "0307",
  "ontvangerApplicatie": "Key2Zaken",
  "ontvangerOrganisatie": "Gemeente Amersfoort",
  "ontvangerGebruiker": "pipelinq",
  "zenderApplicatie": "Pipelinq",
  "zenderOrganisatie": "Gemeente Amersfoort",
  "endpointUrl": "https://stuf.amersfoort.nl/CGS/StUFZKN/2.04/OntvangAsynchroon",
  "soapVersion": "1.1",
  "stufVersion": "0310",
  "sectormodel": "ZKN",
  "authenticatie": {
    "type": "wsse-usernametoken",
    "gebruikersnaam": "pipelinq_amersfoort",
    "wachtwoordKluisRef": "vault://stuf/amersfoort/key2zaken"
  },
  "tlsClientCertRef": "vault://pki/pipelinq-amersfoort.pem",
  "actief": true,
  "aangemaakt": "2026-04-12T09:14:00+02:00"
}
```

### StufMessage

Append-only log entry for every envelope. Required by ADR-026 (per-call audit) and by the gemeente for AVG accountability.

```json
{
  "id": "stuf-msg-2026-05-21-08-44-12-a7c3",
  "endpointId": "stuf-ep-amersfoort-key2zaken",
  "richting": "uitgaand",
  "berichtSoort": "Lk01",
  "entiteittype": "ZAK",
  "functie": "creeerZaak",
  "referentienummer": "PLQ-2026-0001234",
  "crossRefnummer": null,
  "zaakIdentificatie": "ZAAK-2026-0008812",
  "envelopeXml": "<soapenv:Envelope ...>",
  "responseEnvelopeXml": "<soapenv:Envelope ...>",
  "httpStatus": 200,
  "duurMs": 1240,
  "fout": null,
  "verzondenOp": "2026-05-21T08:44:12+02:00",
  "ontvangenOp": "2026-05-21T08:44:13+02:00",
  "gerelateerdeRequestId": "req-2026-aanvraag-evenementenvergunning-0123"
}
```

### ZaaksysteemMapping

Bidirectional link between pipelinq's internal id and the zaaksysteem identifier. Survives across updates and is the only place where the foreign id is canonical.

```json
{
  "id": "map-evenement-tour-amersfoort",
  "pipelinqEntiteit": "request",
  "pipelinqId": "req-2026-aanvraag-evenementenvergunning-0123",
  "externEntiteit": "ZAK",
  "externIdentificatie": "ZAAK-2026-0008812",
  "endpointId": "stuf-ep-amersfoort-key2zaken",
  "laatsteSynchronisatie": "2026-05-21T08:44:13+02:00",
  "synchronisatieStatus": "in_sync",
  "openstaandeWijzigingen": []
}
```

A `Contact` ↔ `betrokkene` mapping looks the same with `pipelinqEntiteit: "contact"` and `externEntiteit: "NPS"` (natuurlijk persoon) or `"NNP"` (niet-natuurlijk persoon).

## Requirements

### Requirement: REQ-STUF-001 SOAP envelope construction

The system MUST construct valid SOAP 1.1 envelopes that comply with the StUF 0310 message header rules: `stuurgegevens` block containing berichtcode (Lk01, Lk02, Lv01, La01, Du01, Bv01...), zender, ontvanger, referentienummer (unique per envelope), tijdstipBericht (yyyyMMddHHmmssSSS), entiteittype, and functie where applicable. The envelope MUST include both StUF and the relevant sectormodel namespace declarations (xmlns:stuf, xmlns:zkn for ZKN, xmlns:bg for BG) and use the StUF-prescribed scope/mutatie attributes on every element.

#### Scenario: outbound creeerZaak envelope passes XSD validation
- GIVEN a pipelinq request "req-2026-aanvraag-evenementenvergunning-0123" with type "evenementenvergunning" and assigned endpoint stuf-ep-amersfoort-key2zaken
- WHEN the system builds the Lk01 creeerZaak envelope
- THEN the envelope validates against the official StUF-ZKN 0310 zkn0310_msg_zs-dms.xsd schema with zero errors AND the stuurgegevens.referentienummer is a freshly-generated ULID AND the tijdstipBericht is the current timestamp in Europe/Amsterdam at millisecond precision

#### Scenario: incoming kennisgeving from Atos PinkRoccade is parsed
- GIVEN an inbound Lk02 actualiseerZaak envelope from PinkRoccade containing zkn:object/zkn:identificatie = "ZAAK-2026-0008812" with new zkn:einddatum
- WHEN pipelinq receives the envelope on POST /api/stuf/inkomend
- THEN the system MUST locate the ZaaksysteemMapping by externIdentificatie AND update the linked pipelinq Request's afhandelDatum field AND persist a StufMessage with richting="inkomend" and crossRefnummer set to the inbound referentienummer

### Requirement: REQ-STUF-002 Kennisgeving (asynchronous notification) flow

For asynchronous notifications (Lk01 creeer, Lk02 actualiseer, Lk03 verwijder) the system MUST send a `Lk01/02/03` envelope and accept a `Bv01` acknowledgement, treating any other response as a failure. The acknowledgement MUST be persisted with crossRefnummer matching the outbound referentienummer. Retries are governed by REQ-STUF-009.

#### Scenario: Bv01 bevestiging closes the kennisgeving
- GIVEN an outbound Lk01 creeerZaak has been sent and the StufMessage row exists with status "verzonden"
- WHEN the zaaksysteem responds with a Bv01 envelope referencing the outbound referentienummer
- THEN the StufMessage status MUST transition to "bevestigd" AND the corresponding ZaaksysteemMapping synchronisatieStatus MUST be set to "in_sync"

#### Scenario: Fo02 foutbericht surfaces functional error
- GIVEN an outbound Lk02 actualiseerZaak
- WHEN the zaaksysteem responds with a Fo02 foutbericht containing stuf:code = "StUF064" (entiteit niet aanwezig)
- THEN the StufMessage MUST capture the fout object with code, omschrijving and details AND the ZaaksysteemMapping MUST be flagged synchronisatieStatus="fout" AND a needs-input notification MUST be raised to the configured beheerder role (no silent retry, per ADR-031 crashes-to-needs-input)

### Requirement: REQ-STUF-003 Vraag/antwoord (synchronous query) flow

For synchronous queries (Lv01 vraag → La01 antwoord) the system MUST block up to a configurable timeout (default 30s) waiting for the La01 response. The Lv01 MUST include the gelijk/vanaf/totEnMet selection scope and the gewensteElementen list to constrain payload size. The La01 response MUST be parsed into typed objects, not returned as raw XML to callers.

#### Scenario: geefZaakDetails returns hydrated Zaak object
- GIVEN endpoint stuf-ep-amersfoort-key2zaken is configured AND the caller invokes adapter.geefZaakDetails("ZAAK-2026-0008812")
- WHEN the system sends a Lv01 with object/zkn:identificatie="ZAAK-2026-0008812" and receives a La01 within 30s
- THEN the method MUST return a Zaak object populated with identificatie, omschrijving, startdatum, einddatum, statussen[], zaaktype.omschrijving, and any betrokkenen[] present in the response

#### Scenario: timeout escalates to needs-input
- GIVEN the same configuration
- WHEN the zaaksysteem does not respond within the 30s timeout
- THEN the system MUST NOT retry the synchronous call automatically AND MUST raise needs-input with the StufMessage id AND the calling pipelinq workflow MUST receive a TimeoutException it can present to the behandelaar

### Requirement: REQ-STUF-004 creeerZaak operation

The system MUST expose adapter.creeerZaak(request, opts) which constructs an Lk01 with functie="creeerZaak", maps pipelinq Request fields onto zkn:object (zaaktype, omschrijving, startdatum=today, registratiedatum=today, toelichting from request.beschrijving), attaches all linked Contact entities as zkn:heeftAlsInitiator betrokkenen, and includes any pipelinq Documents as embedded base64 (see REQ-STUF-006) when opts.includeDocuments is true. On Bv01 acknowledgement the system MUST persist a ZaaksysteemMapping linking the Request to the (already-known or freshly-generated, see REQ-STUF-005) zaak identificatie.

#### Scenario: evenementenvergunning request creates zaak with initiator
- GIVEN a Request "req-2026-aanvraag-evenementenvergunning-0123" with type "evenementenvergunning", linked Contact "Jeroen van der Velde" (BSN 123456789), and one PDF attachment "aanvraagformulier.pdf"
- WHEN adapter.creeerZaak is called with includeDocuments=true
- THEN the Lk01 envelope MUST contain zkn:zaaktype/zkn:omschrijving="Evenementenvergunning", zkn:heeftAlsInitiator with bg:inp.bsn="123456789", and one zkn:heeftRelevant document with stuf:bestandsnaam="aanvraagformulier.pdf" and bestandsinhoud base64-encoded

#### Scenario: zaaktype not configured raises domain error before send
- GIVEN a Request with type "onbekend-type" that does not exist in the configured zaaktype-mapping table
- WHEN adapter.creeerZaak is called
- THEN no SOAP envelope MUST be transmitted AND a ZaaktypeNotMappedException MUST be raised AND a StufMessage MUST NOT be created (pre-send validation failure, audited separately as a pipelinq error event)

### Requirement: REQ-STUF-005 genereerZaakIdentificatie operation

The system MUST support both pre-allocation and post-allocation of the zaak identificatie. When the gemeente requires pipelinq to mint the id, the adapter MUST send a Du01 with functie="genereerZaakIdentificatie" before the Lk01 creeerZaak and use the returned id in the subsequent creation. When the gemeente mints the id server-side, the adapter MUST omit zkn:identificatie from the Lk01 and persist whatever identificatie is returned in the Bv01 or via the subsequent kennisgeving.

#### Scenario: pre-allocated id used in creeerZaak
- GIVEN endpoint stuf-ep-amersfoort-key2zaken has zaakIdentificatieStrategie="vooraf"
- WHEN adapter.creeerZaak is invoked for the evenementenvergunning request
- THEN the system MUST first send Du01 genereerZaakIdentificatie AND parse the returned zkn:identificatie="ZAAK-2026-0008812" AND include this identificatie in the subsequent Lk01 creeerZaak AND persist the ZaaksysteemMapping with this id before receiving the Bv01

#### Scenario: server-allocated id captured from acknowledgement
- GIVEN endpoint with zaakIdentificatieStrategie="achteraf"
- WHEN adapter.creeerZaak sends the Lk01 with no identificatie and the Bv01 returns referentienummer plus a stuf:antwoord with zkn:identificatie="ZAAK-2026-0008813"
- THEN the system MUST persist the ZaaksysteemMapping using this server-issued identificatie

### Requirement: REQ-STUF-006 Document binding via base64

The adapter MUST support attaching binary documents as base64-encoded content inside the StUF envelope (no MTOM/XOP in the 0310 baseline; SwA/MTOM only via explicit endpoint capability flag, out of scope for this spec). Each document MUST carry bestandsnaam, formaat (mime-type), and bestandsinhoud (base64). The system MUST enforce a configurable per-envelope payload ceiling (default 25 MiB pre-base64, ~33 MiB post-base64) and reject the call rather than transmit oversized envelopes.

#### Scenario: PDF attached to creeerZaak as base64
- GIVEN a Document "aanvraagformulier.pdf" of 1.4 MiB application/pdf attached to the request
- WHEN the document is embedded in an Lk01 creeerZaak envelope
- THEN the envelope MUST contain one zkn:heeftRelevant with stuf:bestandsnaam="aanvraagformulier.pdf", stuf:formaat="application/pdf", and stuf:bestandsinhoud equal to the base64 encoding of the file bytes (no line wrapping that would invalidate decoding by Java consumers)

#### Scenario: 40 MiB attachment is rejected pre-send
- GIVEN a Document of 40 MiB attached to a request
- WHEN adapter.creeerZaak is invoked with includeDocuments=true and the default payload ceiling
- THEN a PayloadTooLargeException MUST be raised before any SOAP transmission AND the calling workflow MUST be advised to use a separate large-document channel (e.g. the gemeente's DMS-direct URL, configured per endpoint)

### Requirement: REQ-STUF-007 vrijeBerichten (free messages)

The system MUST expose adapter.vrijBericht(name, payload) for the vrijeBerichten extension point that gemeenten use for non-standard interactions (e.g. zetStatus, ontvangBetaling, koppelInitiator). The adapter MUST allow per-endpoint registration of vrijBericht templates (XSD or XML skeleton) and validate the payload against the registered template before sending.

#### Scenario: zetStatus vrijBericht updates zaak status
- GIVEN a vrijBericht template "zetStatus" registered on the endpoint with required fields zaakIdentificatie, statusType, datumStatusGezet
- WHEN adapter.vrijBericht("zetStatus", {zaakIdentificatie: "ZAAK-2026-0008812", statusType: "in_behandeling", datumStatusGezet: "2026-05-21T09:00:00+02:00"}) is called
- THEN the system MUST construct the envelope from the template, populate the fields, send as Du01, and persist the resulting StufMessage with functie="zetStatus"

#### Scenario: unknown vrijBericht name raises immediately
- GIVEN no template is registered for "doeIetsRaars"
- WHEN adapter.vrijBericht("doeIetsRaars", {...}) is called
- THEN a VrijBerichtNotRegisteredException MUST be raised AND no SOAP traffic MUST occur

### Requirement: REQ-STUF-008 Per-call audit log

Every outbound and inbound envelope MUST result in exactly one StufMessage row. The row MUST capture the full envelope XML (request and response when applicable), HTTP status, wall-clock duration in ms, and a cross-reference to the related pipelinq entity (request, contact, document) where one exists. The audit log MUST be append-only — updates to an existing row are limited to status transitions (verzonden → bevestigd / fout) and addition of the response envelope.

#### Scenario: inbound bevestiging links to outbound envelope
- GIVEN an outbound Lk01 StufMessage with referentienummer "01HXXXXXX..."
- WHEN the matching inbound Bv01 arrives carrying crossRefnummer="01HXXXXXX..."
- THEN both StufMessage rows MUST be retrievable via a single query on referentienummer/crossRefnummer AND the outbound row's status MUST transition from "verzonden" to "bevestigd"

#### Scenario: audit log survives mapping deletion
- GIVEN a ZaaksysteemMapping is deleted (e.g. zaak vernietigd onder selectielijst)
- WHEN the deletion is committed
- THEN the StufMessage rows referencing the mapping MUST remain in the audit log AND their gerelateerdeRequestId MUST remain populated (audit log is independent of the operational mapping table for AVG accountability)

### Requirement: REQ-STUF-009 Retry, idempotency and circuit breaker

For transient transport failures (HTTP 5xx, network timeouts on kennisgevingen, not on synchronous vraag/antwoord) the adapter MUST retry with exponential backoff (default: 4 attempts at 5s, 30s, 2m, 10m) and MUST guarantee idempotency by reusing the same referentienummer across retry attempts. After all retries are exhausted the adapter MUST open a circuit breaker per endpoint for a cooldown period (default 5 minutes) and surface a needs-input event.

#### Scenario: 503 on first attempt, success on second
- GIVEN an outbound Lk01 creeerZaak
- WHEN the first POST returns HTTP 503 and the retry 5s later returns Bv01
- THEN exactly one StufMessage row MUST exist (not two) AND its fout field MUST capture the 503 detail in a retries[] array AND its final status MUST be "bevestigd"

#### Scenario: four-attempt failure trips circuit breaker
- GIVEN four consecutive 5xx responses for the same envelope
- WHEN the fourth attempt fails
- THEN the endpoint circuit breaker MUST open AND all subsequent sends to that endpoint within 5 minutes MUST short-circuit with CircuitOpenException without making HTTP calls AND a needs-input event MUST be raised with the endpoint id and the last fout payload

### Requirement: REQ-STUF-010 Mapping pipelinq Contact ↔ betrokkene

The adapter MUST maintain a bidirectional mapping between pipelinq Contact entities and the zaaksysteem betrokkene (natuurlijk persoon NPS or niet-natuurlijk persoon NNP). Identity is matched primarily on BSN for NPS and on RSIN/KvK-nummer for NNP. When no match exists the adapter MUST query the zaaksysteem via Lv01 geefBetrokkene before creating a new betrokkene, to avoid duplicates in the BRP-derived persoonsregister.

#### Scenario: existing betrokkene reused on second request
- GIVEN Contact "Jeroen van der Velde" (BSN 123456789) was previously linked to ZAAK-2026-0008812 and the ZaaksysteemMapping exists with externEntiteit=NPS
- WHEN a new Request from the same Contact triggers creeerZaak
- THEN the Lk01 MUST reference the existing betrokkene identificatie via bg:inp.bsn="123456789" AND no duplicate NPS MUST be created in the zaaksysteem

#### Scenario: unknown BSN triggers lookup before create
- GIVEN a new Contact "Yasmine Achahbar" (BSN 987654321) with no existing mapping
- WHEN adapter.creeerZaak is invoked
- THEN the system MUST first send Lv01 geefBetrokkene filtering on bg:inp.bsn="987654321" AND if no NPS is returned, the Lk01 MUST include a full bg:NPS element with persoonsgegevens AND the resulting NPS identificatie MUST be persisted in a new ZaaksysteemMapping

## Standards & Sources

- **StUF 0301/0310** — Standaard Uitwisselings Formaat, beheerd door VNG Realisatie. Publicatie: https://www.gemmaonline.nl/index.php/Standaard_Uitwisselings_Formaat_StUF
- **StUF-ZKN 0310** — Sectormodel Zaken. Officiele XSD's via VNG Realisatie GitHub: ConductionNL/stuf-archive en VNG-Realisatie/StUF-standaard.
- **StUF-BG 0310** — Sectormodel Basisgegevens (persoonsgegevens, niet-natuurlijke personen). Wordt mee ge-importeerd in elke ZKN-envelop.
- **GEMMA** — Gemeentelijke Model Architectuur (referentiearchitectuur), in het bijzonder de katern Zaakgericht Werken: https://www.gemmaonline.nl/
- **RGBZ 2.0** — Referentiemodel Gemeentelijke Basisgegevens Zaken; de informatiemodellen onder StUF-ZKN.
- **NORA** — Nederlandse Overheid Referentie Architectuur, principes rond logging en accountability (PR23, PR24).
- **Common Ground** — VNG-principes voor herbruikbare componenten; deze adapter is expliciet een tijdelijke brug naar legacy, niet de doelarchitectuur.
- **NEN-ISO/IEC 27001 + BIO** — informatiebeveiliging gemeenten; de WSSE UsernameToken + mutual TLS vereisten volgen uit BIO-controls voor systeem-koppelingen.
- **AVG / UAVG** — verwerkingsgrondslag voor BSN-doorgifte in de betrokkene-mapping; vereist DPIA-vermelding in de pipelinq onboarding.

## Cross-app integration

- **request-management** (pipelinq) — Request entiteit is de bron voor creeerZaak; statuswijzigingen op de Request worden vertaald naar Lk02 actualiseerZaak of zetStatus vrijBericht. Spec: pipelinq/openspec/specs/request-management/spec.md.
- **contactmomenten** (pipelinq) — Contact entiteit voert de bidirectionele mapping naar betrokkene; contactmomenten leveren de toelichtingen die in zkn:toelichting terechtkomen. Spec: pipelinq/openspec/specs/contactmomenten/spec.md.
- **openconnector** (Conduction core) — De adapter draait als een gateway-mapping binnen openconnector wanneer pipelinq als ExApp draait; de OC source/mapping configuratie kan een StufEndpoint genereren. Spec: openconnector/openspec/specs/source-mapping/spec.md.
- **openregister** (Conduction core) — StufEndpoint, StufMessage en ZaaksysteemMapping zijn schemas in het pipelinq register; de standaard OR audit log staat los van de StufMessage audit log (de laatste bevat de volledige envelope, de eerste alleen object-mutaties).
- **docudesk** (DMS) — Documenten die als base64 mee gaan kunnen alternatief via een docudesk DMS-direct URL geleverd worden voor envelopes boven de payload ceiling.
- **zgw-api-bridge** (pipelinq, zustermodule) — Voor gemeenten met moderne ZGW REST APIs is dat de voorkeurspad; per gemeente kiest de configuratie precies een van beide actief (REQ-STUF-001 endpoint actief flag wisselt met ZGW-equivalent).

## Target users

- **Zaakbehandelaar (gemeente Amersfoort, Centric Key2Zaken)** — ziet de door pipelinq aangemaakte zaak verschijnen in haar vertrouwde Key2Zaken-werkvoorraad zonder dat ze pipelinq zelf hoeft te openen. Verwacht dat bijlagen direct openbaar zijn in het DMS en dat statuswijzigingen die zij in Key2Zaken doorvoert binnen minuten zichtbaar zijn in de pipelinq Request.
- **KCC-medewerker (gemeente, pipelinq frontend)** — registreert een evenementenvergunning-aanvraag in pipelinq tijdens een telefoongesprek, ziet binnen 5 seconden de toegekende ZAAK-identificatie en kan deze aan de beller doorgeven.
- **Functioneel beheerder zaaksysteem** — configureert de StufEndpoint per gemeente, beheert de zaaktype-mapping en de vrijBericht templates, ontvangt de needs-input notificaties bij circuit-breaker openingen.
- **CISO / Privacy Officer gemeente** — eist het volledige envelope-audit log per BSN-doorgifte; gebruikt de StufMessage tabel als bewijslast in DPIA en AVG-verwerkingsregister.
- **Conduction implementatieconsultant** — gebruikt de adapter in pilots met gemeenten die nog geen ZGW REST hebben; verwacht dat dezelfde pipelinq-installatie naast StUF-gemeenten ook ZGW-gemeenten kan bedienen via de zgw-api-bridge zustermodule.
- **VNG Realisatie reviewer** — bij certificering van de pipelinq StUF-koppeling tegen de StUF-testbed verwacht dat alle envelopes XSD-valid zijn, dat referentienummer-uniciteit gegarandeerd is en dat de adapter een complete StUF-testbed run groen krijgt.
