---
status: draft
---

# Spec: ZGW API (Zaakgericht Werken) REST bridge

## Purpose

Define the integration requirements for pipelinq's bridge to the modern Dutch zaakgericht-werken (ZGW) REST API stack. This spec covers JWT authentication per VNG-API-Common, five-component client behavior (ZRC, DRC, BRC, ZTC, AC), NRC event subscription lifecycle, ZTC caching, AC scope enforcement, ETag-based optimistic concurrency, contact-to-rol mapping, and coexistence rules with the legacy StUF adapter.

**Main ADR refs**: [adr-000-data-model.md](../../../../architecture/adr-000-data-model.md)

**Feature tier**: P0-must

**Demand evidence**: Dutch gemeente integration stack; VNG Realisatie ZGW standaarden adoption

**Depends on**: OpenRegister (vault, webhook service, object persistence), stuf-zkn-bg-adapter (for coexistence validation)

---

## REQ-ZGW-001: OAuth2 / JWT client authentication

The bridge MUST mint per-request JWTs following the VNG-API-Common authentication profile: HS256 signed, issued by clientId, with claims `client_id`, `iss=clientId`, `user_id`, `user_representation`, `iat`, and a leeway of at most 60 seconds vs. the receiving component's clock. Tokens MUST be re-minted per request (no caching) per the VNG profile, unless the endpoint advertises `Authorization: Bearer` reuse via a documented override on the ZgwEndpoint.

### Scenario: JWT minted with required claims

- GIVEN `ZgwClient` "zgw-client-zoetermeer" with secret retrieved from the vault
- WHEN the bridge issues GET /zaken/api/v1/zaken/3f9a-...-c1 against ZRC
- THEN the Authorization header MUST be "Bearer <JWT>" AND the JWT payload MUST contain `client_id="pipelinq-zoetermeer"`, `iss="pipelinq-zoetermeer"`, `user_id="pipelinq"`, `user_representation="Pipelinq backend (Conduction)"`, `iat` within 60s of wall clock, and HS256 signature verifiable with the configured secret

### Scenario: clock-skew rejection surfaces clearly

- GIVEN the pipelinq host clock is 5 minutes ahead of the ZRC host
- WHEN the bridge calls GET /zaken/api/v1/zaken/3f9a-...-c1
- THEN the 403 with VNG fault code "JWT verlopen" or "JWT nog niet geldig" MUST be caught AND a ClockSkewException MUST be raised with both observed timestamps in the message AND no automatic retry MUST be performed

---

## REQ-ZGW-002: Zaken API (ZRC) resource client

The bridge MUST implement typed clients for the ZRC resources: zaken, statussen, rollen, zaakobjecten, resultaten, zaakinformatieobjecten. For zaak creation the bridge MUST construct a POST /zaken body containing bronorganisatie, zaaktype (full URL), verantwoordelijkeOrganisatie, startdatum, registratiedatum, and omschrijving; the response Location header MUST be persisted as the canonical zgwUrl on the ZgwResourceMapping.

### Scenario: createZaak persists URL mapping

- GIVEN endpoint `zgw-ep-zoetermeer-openzaak` AND zaaktype URL "https://open-zaak.zoetermeer.nl/catalogi/api/v1/zaaktypen/aa11-..." for "Evenementenvergunning"
- WHEN `bridge.createZaak({pipelinqRequestId: "req-2026-evenement-zoetermeer-0456", zaaktype: ..., omschrijving: "Aanvraag evenementenvergunning Stadshart Run", startdatum: "2026-05-21"})` is invoked
- THEN POST /zaken/api/v1/zaken MUST return 201 with Location header AND a ZgwResourceMapping MUST be persisted with `zgwUrl=<Location>`, `zgwResourceType="zaak"`, endpointId set, etag captured from response

### Scenario: addStatus appends to zaak

- GIVEN an existing ZgwResourceMapping for the zaak
- WHEN `bridge.addStatus({zaak: <url>, statustype: <statustype-url>, datumStatusGezet: "2026-05-21T09:00:00+02:00", statustoelichting: "Aanvraag ontvangen via pipelinq"})` is invoked
- THEN POST /zaken/api/v1/statussen MUST return 201 AND the latest status URL MUST be cached on the mapping (for change-detection on subsequent NRC notifications)

---

## REQ-ZGW-003: Documenten API (DRC) resource client

The bridge MUST implement createEnkelvoudigInformatieobject (EIO), which uploads document metadata with inhoud as base64-encoded inline content for files up to a configurable threshold (default 4 MiB), and uses the DRC multipart-upload large-file protocol (POST /enkelvoudiginformatieobjecten → bestandsdelen[] → PUT per part → unlock) for larger files. After creation, the bridge MUST POST a zaakinformatieobject to ZRC to link the EIO to the zaak.

### Scenario: small PDF uploaded inline and linked

- GIVEN a Document "aanvraagformulier.pdf" of 1.4 MiB attached to req-2026-evenement-zoetermeer-0456 AND the request already has a zaak URL
- WHEN `bridge.attachDocument` is called
- THEN POST /documenten/api/v1/enkelvoudiginformatieobjecten MUST include `inhoud` as base64 of the file bytes AND a subsequent POST /zaken/api/v1/zaakinformatieobjecten MUST link `informatieobject=<EIO url>` to `zaak=<zaak url>`

### Scenario: large file uses multipart protocol

- GIVEN a Document "dossier-vooronderzoek.pdf" of 22 MiB
- WHEN `bridge.attachDocument` is called with the default 4 MiB inline threshold
- THEN the EIO MUST be created with `bestandsomvang=22020096` and `inhoud` omitted AND the response `bestandsdelen[]` MUST be consumed by sequential PUT uploads of each part AND a final POST .../unlock MUST be issued with the lock id returned from creation AND only then MUST the zaakinformatieobject link be created

---

## REQ-ZGW-004: Besluiten API (BRC) resource client

The bridge MUST expose createBesluit and linkBesluitToZaak operations. Besluiten require a besluittype URL from the catalogus and reference the zaak by URL. When a besluit has a related informatieobject (the formal decision document), the bridge MUST also create a besluitinformatieobject linking the two.

### Scenario: vergunning verleend creates besluit with document

- GIVEN a zaak URL and a freshly-uploaded EIO "besluit-evenementenvergunning.pdf"
- WHEN `bridge.createBesluit({zaak: <url>, besluittype: <vergunning-verleend-url>, datum: "2026-06-15", ingangsdatum: "2026-06-15"})` is invoked followed by linkBesluitInformatieobject
- THEN POST /besluiten/api/v1/besluiten MUST return 201 AND POST /besluiten/api/v1/besluitinformatieobjecten MUST link `informatieobject=<EIO>` to `besluit=<besluit url>`

### Scenario: besluittype not in catalogus raises pre-flight

- GIVEN a besluittype URL that 404s on the ZTC
- WHEN `bridge.createBesluit` is invoked
- THEN the bridge MUST detect this via the ZTC cache (see REQ-ZGW-005) AND raise BesluittypeNotInCatalogusException without sending the POST to BRC

---

## REQ-ZGW-005: Catalogi API (ZTC) consumption and caching

The bridge MUST consume the ZTC to resolve zaaktypen, statustypen, roltypen, resultaattypen and besluittypen by omschrijving + zaaktype-version, returning URLs that are then used in ZRC/BRC calls. The bridge MUST cache ZTC responses keyed by omschrijving + geldigheid window with a default TTL of 1 hour and MUST invalidate the cache when a NRC notification on the "catalogi" kanaal is received.

### Scenario: zaaktype URL resolved from omschrijving

- GIVEN ZTC contains a published zaaktype with `omschrijving="Evenementenvergunning"` and currently-valid geldigheid
- WHEN `bridge.resolveZaaktype("Evenementenvergunning")` is invoked
- THEN GET /catalogi/api/v1/zaaktypen?omschrijving=Evenementenvergunning MUST return the zaaktype AND the URL MUST be cached for 1 hour AND subsequent resolveZaaktype calls within the TTL MUST NOT hit the ZTC

### Scenario: NRC catalogi notification invalidates cache

- GIVEN a cached zaaktype URL for "Evenementenvergunning"
- WHEN a NRC notification with `kanaal="catalogi"`, `resource="zaaktype"`, `actie="update"` arrives
- THEN the affected cache entry MUST be invalidated AND the next resolveZaaktype call MUST re-fetch from the ZTC

---

## REQ-ZGW-006: Autorisaties API (AC) consumption

The bridge MUST query the AC on startup and on a configurable refresh interval (default 15 minutes) to discover which scopes the configured ZgwClient holds per zaaktype/informatieobjecttype/besluittype/component. Operations MUST be guarded client-side: an attempt to createZaak on a zaaktype for which the client lacks `zaken.aanmaken` scope MUST fail before the HTTP call.

### Scenario: missing scope blocks createZaak

- GIVEN the AC reports that pipelinq-zoetermeer holds {zaken.lezen} on zaaktype "Evenementenvergunning" but not zaken.aanmaken
- WHEN `bridge.createZaak` is invoked for that zaaktype
- THEN an InsufficientScopeException MUST be raised AND no HTTP POST to ZRC MUST be sent AND the error message MUST list the missing scope by name

### Scenario: scope refresh picks up newly granted permission

- GIVEN the AC initially reports {zaken.lezen} only AND a beheerder grants zaken.aanmaken AND the 15-minute refresh elapses
- WHEN `bridge.createZaak` is invoked
- THEN the refreshed scope cache MUST permit the call AND the POST to ZRC MUST proceed

---

## REQ-ZGW-007: Notificaties (NRC) subscription lifecycle

The bridge MUST register an NrcAbonnement on first activation of a ZgwEndpoint and keep it in sync with the configured kanalen + filters. Inbound notifications POSTed to the callbackUrl MUST be authenticated via a Bearer token unique per abonnement, then dispatched to per-kanaal handlers that update the corresponding ZgwResourceMapping and trigger pipelinq workflows.

### Scenario: first activation registers abonnement

- GIVEN endpoint `zgw-ep-zoetermeer-openzaak` is configured with `kanalen=[zaken filtered by bronorganisatie=002564440, besluiten]`
- WHEN the endpoint is set `actief=true`
- THEN POST /api/v1/abonnement MUST be sent to the NRC AND the returned URL + the chosen callback bearer secret MUST be persisted on a new NrcAbonnement

### Scenario: inbound zaak.statusGewijzigd updates pipelinq Request

- GIVEN an NrcAbonnement is active AND a ZgwResourceMapping exists for zaak URL X linked to req-2026-evenement-zoetermeer-0456
- WHEN POST /api/zgw/notificaties/inbox arrives with Bearer matching the abonnement secret AND body {kanaal: "zaken", hoofdObject: X, resource: "status", actie: "create", resourceUrl: <status-url>}
- THEN the bridge MUST GET the status URL, resolve its statustype omschrijving from the ZTC cache, and update the pipelinq Request.status field within 5s of inbox arrival

---

## REQ-ZGW-008: Coexistence with stuf-zkn-bg-adapter

For a given gemeente, exactly one of (stuf-zkn-bg-adapter endpoint, zgw-api-bridge endpoint) MUST be marked as the active write path for a given zaaktype domain at any time. Read-only consumption of both MAY coexist during a migration. The bridge MUST detect a double-registration attempt (same pipelinq Request resulting in both a StUF zaak and a ZGW zaak) and raise an error before the second registration is committed.

### Scenario: gemeente in migration uses both for read, ZGW for write

- GIVEN gemeente Zoetermeer has both a StufEndpoint (legacy Centric, marked write="off", read="on") and a ZgwEndpoint (Open Zaak, marked write="on", read="on")
- WHEN a new pipelinq Request is created
- THEN `bridge.createZaak` MUST be invoked AND `adapter.creeerZaak` MUST NOT be invoked AND any inbound notifications from either backend MUST be merged into the same Request via the per-endpoint ZaaksysteemMapping/ZgwResourceMapping rows

### Scenario: misconfiguration with both write=on is rejected

- GIVEN both endpoints for Zoetermeer are accidentally set to `write="on"`
- WHEN the pipelinq routing service tries to register a new Request
- THEN a DoubleWritePathException MUST be raised before any external call AND the needs-input event MUST instruct the beheerder to disable one write path

---

## REQ-ZGW-009: ETags, optimistic concurrency and PATCH

The bridge MUST capture ETag/If-Match headers on read and use them on PATCH operations against ZRC and DRC resources. A 412 Precondition Failed MUST be surfaced as an OptimisticLockException carrying the stale + fresh representations, and MUST NOT be auto-retried.

### Scenario: PATCH zaak preserves ETag

- GIVEN bridge previously GET'd zaak URL X and persisted etag W/"a1b2c3"
- WHEN `bridge.updateZaak(X, {omschrijving: "Aanvraag evenementenvergunning Stadshart Run 2026"})` is invoked
- THEN PATCH /zaken/api/v1/zaken/<uuid> MUST include If-Match: W/"a1b2c3" AND on 200 the new ETag MUST replace the cached one on the ZgwResourceMapping

### Scenario: 412 surfaces with both versions

- GIVEN the same setup but the zaak has been modified by another client since
- WHEN PATCH returns 412 Precondition Failed
- THEN an OptimisticLockException MUST be raised with the local pre-image, the fresh server representation (fetched via a follow-up GET), and the conflicting field set AND no automatic re-PATCH MUST be attempted

---

## REQ-ZGW-010: Rol mapping pipelinq Contact ↔ ZGW rol

The bridge MUST register pipelinq Contact entities as rollen on the zaak using the appropriate roltype (typically "Initiator"). For natuurlijke personen the betrokkeneIdentificatie MUST carry inpBsn; for niet-natuurlijke personen the innNnpId (RSIN/KvK). The bridge MUST avoid duplicate rol creation by querying GET /rollen?zaak=<url>&betrokkeneType=natuurlijk_persoon before posting.

### Scenario: initiator rol created once

- GIVEN a Contact "Jeroen van der Velde" (BSN 123456789) linked to req-2026-evenement-zoetermeer-0456 AND the zaak just created AND roltype "Initiator" resolved from ZTC
- WHEN `bridge.linkInitiator` is invoked
- THEN GET /rollen?zaak=<url>&betrokkeneType=natuurlijk_persoon MUST be sent first; on empty results POST /rollen MUST include `betrokkeneType="natuurlijk_persoon"`, `betrokkeneIdentificatie={inpBsn: "123456789"}`, `roltype=<url>`, `roltoelichting="Aanvrager evenementenvergunning"`

### Scenario: idempotent re-link skips POST

- GIVEN the same setup but a rol for BSN 123456789 already exists on the zaak
- WHEN `bridge.linkInitiator` is invoked a second time
- THEN no POST MUST be sent AND the existing rol URL MUST be returned to the caller (treated as success)
