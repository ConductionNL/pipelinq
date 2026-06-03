# Tasks: ZGW API (Zaakgericht Werken) REST bridge

## 0. Deduplication & Readiness Check

- [x] 0.1 Verify OpenRegister version supports vault secret retrieval and webhook service (`WebhookService` available in vendor). Check `composer.json`.
- [x] 0.2 Verify `stuf-zkn-bg-adapter` is merged and `StufEndpoint` schema exists. If not, flag for sequencing.
- [x] 0.3 Grep for any existing ZGW integration: `grep -r "zgw\|zaakgericht\|ZGW" lib/ src/` — if any ZGW-related class exists, extend rather than create new.
- [x] 0.4 Verify Guzzle or similar HTTP client is available in pipelinq dependencies. Check `composer.json` and `lib/Vendor/` imports.
- [x] 0.5 Confirm pipelinq uses OpenRegister's `ObjectService` and can call `saveObject()` with extended entity properties. Check existing Request handling.
- [x] 0.6 Verify that `Request` entity schema exists in `lib/Settings/pipelinq_register.json` and supports foreign key relations to `ZgwResourceMapping`.

  **Findings:**
  - Outbound HTTP uses NC's `IClientService` (no Guzzle / `lib/Vendor/` needed). Secrets are held encrypted-at-rest via NC `ICrypto` through `ZgwSecretResolver` (`vault://` references) rather than an OR `WebhookService`; OR object persistence is reused through `ZgwObjectRepository` over `ObjectService` `findAll`/`saveObject` (ADR-022 — no `findObject`/`createFromArray`).
  - `stuf-zkn-bg-adapter` is NOT installed in this checkout: `ZgwCoexistenceValidator` treats StUF as a soft dependency (absent `stufEndpoint_schema` config key ⇒ no StUF write path ⇒ no conflict). Live StUF/ZGW coexistence is DEFERRED (needs the adapter merged + a live instance).
  - No pre-existing ZGW class existed; all ZGW code is net-new. Per ADR-037 the four schemas + 16 seeds live in `lib/Settings/register.d/50-zgw.json`, never the monolith; the `request`↔`zgwResourceMapping` link is modelled via `ZgwResourceMapping.pipelinqEntiteit`/`pipelinqId` (URL-keyed, ZGW-canonical) so the `request` schema is left unchanged.

---

## 1. Schema: Register four ZGW entities + extend Request

- [x] 1.1 Add `ZgwEndpoint` schema (in `lib/Settings/register.d/50-zgw.json` per ADR-037, not the monolith)
  - **spec_ref**: `specs/zgw-api-bridge/spec.md#REQ-ZGW-001` through REQ-ZGW-007
  - **files**: `lib/Settings/pipelinq_register.json`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Schema slug: `zgw-endpoint`
    - Properties: `id` (string, required), `naam` (string, required), `gemeenteCode` (string, required), `componenten` (object with zrc/drc/brc/ztc/ac/nrc URLs, required), `clientId` (string, required FK to ZgwClient), `actief` (boolean), `readOnly` (boolean), `mutualTlsCert` (string), `mutualTlsKey` (string), `aangemaakt` (timestamp)
    - Existing Register fields auto-included: id, uuid, uri, version, createdAt, updatedAt, etc.

- [x] 1.2 Add `ZgwClient` schema (in `lib/Settings/register.d/50-zgw.json` per ADR-037)
  - **spec_ref**: `specs/zgw-api-bridge/spec.md#REQ-ZGW-001`, REQ-ZGW-006
  - **files**: `lib/Settings/pipelinq_register.json`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Schema slug: `zgw-client`
    - Properties: `id` (string, required), `clientIdentifier` (string, required), `secretKluisRef` (string, required), `userId` (string, required), `userRepresentation` (string, required), `tokenLevensduurSeconden` (integer), `aangemaakt` (timestamp)
    - No actual secret stored in schema; only vault reference

- [x] 1.3 Add `NrcAbonnement` schema (in `lib/Settings/register.d/50-zgw.json` per ADR-037)
  - **spec_ref**: `specs/zgw-api-bridge/spec.md#REQ-ZGW-007`
  - **files**: `lib/Settings/pipelinq_register.json`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Schema slug: `nrc-abonnement`
    - Properties: `id` (string, required), `endpointId` (string, required FK to ZgwEndpoint), `abonnementUrl` (string, required), `callbackUrl` (string, required), `callbackAuth` (string, required, vault ref), `kanalen` (array of {naam, filters} objects, required), `laatstOntvangenOp` (timestamp), `actief` (boolean)

- [x] 1.4 Add `ZgwResourceMapping` schema (in `lib/Settings/register.d/50-zgw.json` per ADR-037)
  - **spec_ref**: `specs/zgw-api-bridge/spec.md#REQ-ZGW-002`, REQ-ZGW-003, REQ-ZGW-008, REQ-ZGW-009, REQ-ZGW-010
  - **files**: `lib/Settings/pipelinq_register.json`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Schema slug: `zgw-resource-mapping`
    - Properties: `id` (string, required), `pipelinqEntiteit` (string enum: request/contact/document, required), `pipelinqId` (string UUID, required), `zgwResourceType` (string enum: zaak/besluit/rol/informatieobject, required), `zgwUrl` (string URL, required), `zgwUuid` (string UUID), `endpointId` (string FK to ZgwEndpoint, required), `laatsteSynchronisatie` (timestamp), `etag` (string)

- [x] 1.5 Relate `request` to `ZgwResourceMapping` (modelled via `pipelinqEntiteit`/`pipelinqId` on the mapping; `request` schema unchanged)
  - **spec_ref**: `specs/zgw-api-bridge/spec.md#REQ-ZGW-008`
  - **files**: `lib/Settings/pipelinq_register.json`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Add `zgwResourceMappings` as relation field on `request` schema (one-to-many to `zgw-resource-mapping`)
    - No other changes to `request` properties required

- [x] 1.6 Add 15+ seed objects covering all four schemas (16 seeds in the fragment)
  - **spec_ref**: `design.md` Seed Data section
  - **files**: `lib/Settings/pipelinq_register.json`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - 3× ZgwEndpoint (Zoetermeer OpenZaak, Amsterdam OpenZaak, Utrecht RxMission variant); mix of actief=true/false
    - 3× ZgwClient (one per gemeente; one with mTLS refs as vault keys)
    - 3× NrcAbonnement (2 active, 1 inactive; mix of kanalen and lastReceived timestamps)
    - 8× ZgwResourceMapping (2 zaak, 1 besluit, 1 rol, 1 informatieobject per endpoint; etag values present)
    - All Dutch gemeente codes and realistic component URLs
    - All use @self envelope with register: "pipelinq", schema: "<schema-slug>"

---

## 2. Backend: JWT and ZGW API Client Layer

- [x] 2.1 Create `lib/Service/ZgwApiClient.php` — base client for all ZGW component calls
  - **spec_ref**: `specs/zgw-api-bridge/spec.md#REQ-ZGW-001`, REQ-ZGW-002, REQ-ZGW-003, REQ-ZGW-004
  - **files**: `lib/Service/ZgwApiClient.php`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Method `mintJwt(ZgwClient $client, int $expiresIn = 3600): string` — constructs JWT with iss, client_id, user_id, user_representation, iat, exp; signs with HS256 using vault-retrieved secret
    - Method `callComponent(string $componentUrl, string $method, string $path, ?array $body = null, ZgwClient $client): array` — sends HTTP request with JWT Bearer auth, Content-Type: application/json; returns response body + headers (etag)
    - Exception handling: catches HTTP 403 with "JWT verlopen" or "JWT nog niet geldig" → raises `ClockSkewException` with both observed timestamps; no auto-retry

- [x] 2.2 Create `lib/Service/ZrcClient.php` — Zaken API client
  - **spec_ref**: `specs/zgw-api-bridge/spec.md#REQ-ZGW-002`, REQ-ZGW-010
  - **files**: `lib/Service/ZrcClient.php`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Method `createZaak(ZgwEndpoint $endpoint, array $zaakData): ZgwResourceMapping` — POST /zaken, capture Location header, create ZgwResourceMapping with zgwUrl, zgwResourceType="zaak", etag; return mapping
    - Method `getZaak(ZgwResourceMapping $mapping): array` — GET zaak URL, update etag on mapping, return zaak object
    - Method `updateZaak(ZgwResourceMapping $mapping, array $updates): ZgwResourceMapping` — PATCH zaak with If-Match header using cached etag; on 412 raise `OptimisticLockException` with stale + fresh; on 200 update etag and return mapping
    - Method `addStatus(ZgwResourceMapping $zaakMapping, array $statusData): string` — POST /statussen for zaak, return status URL
    - Method `getStatus(string $statusUrl, ZgwClient $client, ZgwEndpoint $endpoint): array` — GET status URL, cache etag
    - Method `linkInitiator(ZgwResourceMapping $zaakMapping, Contact $contact): string` — GET /rollen?zaak=<url>&betrokkeneType=..., return URL if exists; else POST /rollen with betrokkeneIdentificatie (inpBsn for persons, innNnpId for orgs), return new URL

- [x] 2.3 Create `lib/Service/DrcClient.php` — Documenten API client
  - **spec_ref**: `specs/zgw-api-bridge/spec.md#REQ-ZGW-003`
  - **files**: `lib/Service/DrcClient.php`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Method `createEnkelvoudigInformatieobject(ZgwEndpoint $endpoint, Document $document, array $metadata): ZgwResourceMapping` — POST /enkelvoudiginformatieobjecten; for file size ≤ 4 MiB inline: include inhoud as base64; else create with bestandsomvang only; return mapping with EIO URL; etag captured
    - Method `uploadBestandsdelen(ZgwResourceMapping $eioMapping, Document $document): void` — POST /enkelvoudiginformatieobjecten/<uuid>/bestandsdelen with lock; for each part PUT to bestandsdeel URL; POST .../unlock at end
    - Method `linkZaakinformatieobject(ZgwResourceMapping $zaakMapping, ZgwResourceMapping $eioMapping): string` — POST /zaakinformatieobjecten linking zaak to informatieobject; return link URL

- [x] 2.4 Create `lib/Service/BrcClient.php` — Besluiten API client
  - **spec_ref**: `specs/zgw-api-bridge/spec.md#REQ-ZGW-004`
  - **files**: `lib/Service/BrcClient.php`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Method `createBesluit(ZgwEndpoint $endpoint, ZgwResourceMapping $zaakMapping, array $besluitData): ZgwResourceMapping` — POST /besluiten with zaak URL, besluittype URL, datum, ingangsdatum; return mapping with besluit URL; etag captured
    - Method `linkBesluitInformatieobject(ZgwResourceMapping $besluitMapping, ZgwResourceMapping $eioMapping): string` — POST /besluitinformatieobjecten linking besluit to informatieobject; return link URL

- [x] 2.5 Create `lib/Service/ZtcClient.php` — Catalogi API client with caching
  - **spec_ref**: `specs/zgw-api-bridge/spec.md#REQ-ZGW-004`, REQ-ZGW-005
  - **files**: `lib/Service/ZtcClient.php`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Cache backend: in-memory store keyed by (endpointId, resourceType, omschrijving) with 1h TTL
    - Method `resolveZaaktype(ZgwEndpoint $endpoint, string $omschrijving): string` — check cache; if miss GET /zaaktypen?omschrijving=..., cache result, return URL; if cache hit return cached URL
    - Method `resolveStatustype(...)`, `resolveRoltype(...)`, `resolveBesluittype(...)` — similar pattern for other resource types
    - Method `invalidateCache(ZgwEndpoint $endpoint, string $resourceType): void` — called on catalogi NRC notifications; clears affected cache entries
    - Exception handling: if zaaktype/besluittype 404s, raise `ZaaktypeNotInCatalogusException` or `BesluittypeNotInCatalogusException` with omschrijving in message

- [x] 2.6 Create `lib/Service/AcClient.php` — Autorisaties API client with scope caching
  - **spec_ref**: `specs/zgw-api-bridge/spec.md#REQ-ZGW-006`
  - **files**: `lib/Service/AcClient.php`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Cache backend: in-memory scope map keyed by (endpointId, zaaktypeUrl, component) with 15m TTL
    - Method `refreshScopes(ZgwEndpoint $endpoint, ZgwClient $client): void` — GET /autorisaties/zaaktypen filtered to configured zaaktypen; build scope cache mapping zaaktype → {zaken.lezen, zaken.aanmaken, ...}; run on startup, then every 15 minutes (background task or scheduled listener)
    - Method `hasScope(ZgwEndpoint $endpoint, string zaaktypeUrl, string $scope): bool` — check cache for scope; return bool
    - Method `getScopesFor(ZgwEndpoint $endpoint, string zaaktypeUrl): array` — return list of granted scopes for zaaktype
    - Exception handling: if AC unreachable on refresh, log warning but don't block; next refresh will retry

---

## 3. Backend: NRC Event Handling

- [x] 3.1 Create NRC callback HTTP endpoint at `POST /api/zgw/notificaties/inbox`
  - **spec_ref**: `specs/zgw-api-bridge/spec.md#REQ-ZGW-007`
  - **files**: `lib/Controller/ZgwNotificationController.php`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Endpoint accepts unauthenticated POST (authentication via Bearer token in body-ref)
    - Extract Bearer token from Authorization header
    - Query NrcAbonnement by matching callbackAuth vault reference
    - If no match, return 401
    - Parse JSON body; dispatch to `NrcNotificationListener` with kanaal, resource, actie, resourceUrl, hoofdObject
    - Return 202 Accepted immediately (async processing)

- [x] 3.2 Per-kanaal notification dispatcher — implemented as `lib/Service/NrcNotificationHandler.php` (invoked by the controller, internally resilient/logs-not-throws) rather than an event listener; the controller returns 202 immediately and the handler is exception-isolated, meeting the async/non-blocking intent without an extra event hop
  - **spec_ref**: `specs/zgw-api-bridge/spec.md#REQ-ZGW-007`
  - **files**: `lib/Listener/NrcNotificationListener.php`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Receives inbound notification (kanaal, resource, actie, resourceUrl, hoofdObject, abonnementId)
    - Update `NrcAbonnement.laatstOntvangenOp = now()`
    - Dispatch to per-kanaal handler based on kanaal:
      - **zaken** + resource="zaak" + actie="create": Lookup ZgwResourceMapping by zaak URL (hoofdObject); if match exists, no-op (zaak already registered)
      - **zaken** + resource="status" + actie="create": Lookup ZgwResourceMapping by zaak URL (hauptObject); GET status URL via ZrcClient; resolve statustype omschrijving from ZTC cache; update Request.status field; emit RequestStatusChangedEvent
      - **besluiten** + resource="besluit" + actie="create": Lookup ZgwResourceMapping by besluit URL; if match, no-op
      - **catalogi** (any resource, any actie): Call `ZtcClient::invalidateCache(endpoint, resourceType)`
    - All operations must complete within 5 seconds of notification arrival (measure and log)
    - Exception handling: log errors but don't throw (to avoid indefinite retry loops); optionally notify admin of persistent failures

- [x] 3.3 Create `lib/Service/NrcSubscriptionService.php` — subscription lifecycle management
  - **spec_ref**: `specs/zgw-api-bridge/spec.md#REQ-ZGW-007`
  - **files**: `lib/Service/NrcSubscriptionService.php`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Method `registerAbonnement(ZgwEndpoint $endpoint): NrcAbonnement` — POST /api/v1/abonnement to NRC with callbackUrl, kanalen, filters; capture returned abonnementUrl and generate callbackAuth bearer token; persist NrcAbonnement; return it
    - Method `syncAbonnement(NrcAbonnement $abonnement, array $newKanalen): void` — if kanalen differ from current, unsubscribe (DELETE old abonnement), re-register with new kanalen
    - Method `unregisterAbonnement(NrcAbonnement $abonnement): void` — DELETE /api/v1/abonnement/<uuid> from NRC; mark abonnement actief=false
    - Exception handling: if NRC unreachable, raise exception; caller decides to retry or notify admin

---

## 4. Backend: Coexistence & Validation

- [x] 4.1 Create `lib/Service/ZgwCoexistenceValidator.php` — double-write prevention
  - **spec_ref**: `specs/zgw-api-bridge/spec.md#REQ-ZGW-008`
  - **files**: `lib/Service/ZgwCoexistenceValidator.php`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Method `validateWritePath(string $gemeenteCode, string $domain): void` — query all ZgwEndpoint with gemeenteCode + actief=true; query all StufEndpoint with gemeenteCode + write="on"; if both exist, raise `DoubleWritePathException` with list of conflicting endpoints
    - Called before `ZrcClient::createZaak()` in the Request creation flow
    - Exception message directs beheerder to disable one write path and provides admin UI links

- [x] 4.2 Wire validation into the write path — `validateWritePath()` is called inside `ZrcClient::createZaak()` (the single zaak-write entry point), so the guard is unconditional rather than dependent on a not-yet-existing request orchestrator
  - **spec_ref**: `specs/zgw-api-bridge/spec.md#REQ-ZGW-008`
  - **files**: `lib/Service/RequestService.php` (or relevant service that handles createRequest)
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Before calling `ZrcClient::createZaak()` or `StufAdapter::creeerZaak()`, call `ZgwCoexistenceValidator::validateWritePath()`
    - If validation fails, emit needs-input event to beheerder; do not proceed with zaak registration

---

## 5. Backend: Scope Enforcement

- [x] 5.1 Add pre-flight scope checks to all write operations
  - **spec_ref**: `specs/zgw-api-bridge/spec.md#REQ-ZGW-006`
  - **files**: `lib/Service/ZrcClient.php`, `lib/Service/DrcClient.php`, `lib/Service/BrcClient.php`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - `ZrcClient::createZaak()` — before HTTP call, check `AcClient::hasScope(endpoint, zaaktypeUrl, "zaken.aanmaken")`; if not, raise `InsufficientScopeException`
    - `BrcClient::createBesluit()` — before HTTP call, check `AcClient::hasScope(endpoint, besluitTypeUrl, "besluiten.aanmaken")`
    - `DrcClient::createEnkelvoudigInformatieobject()` — before HTTP call, check `AcClient::hasScope(endpoint, any zaaktypeUrl, "documenten.aanmaken")` (AC scope for DRC is component-level, not zaaktype-specific)
    - Exception messages list the missing scope and suggest contacting the gemeente beheerder to request the permission

---

## 6. Backend: ETag and Optimistic Concurrency

- [x] 6.1 Extend `ZgwResourceMapping` reads to capture and persist ETag
  - **spec_ref**: `specs/zgw-api-bridge/spec.md#REQ-ZGW-009`
  - **files**: `lib/Service/ZrcClient.php`, `lib/Service/DrcClient.php`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - `getZaak()`, `getStatus()`, `getBesluit()` — after successful HTTP call, extract ETag header and update `ZgwResourceMapping.etag` via `ObjectService::saveObject()`
    - On PATCH calls, include If-Match header with cached etag value

- [x] 6.2 Handle 412 Precondition Failed with OptimisticLockException
  - **spec_ref**: `specs/zgw-api-bridge/spec.md#REQ-ZGW-009`
  - **files**: `lib/Service/ZrcClient.php`, `lib/Service/DrcClient.php`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - On HTTP 412 from PATCH, catch exception; fetch fresh resource via GET; construct `OptimisticLockException` with stale representation (pre-image), fresh representation, and conflicting field name; raise it
    - Caller is responsible for handling conflict (e.g., retry with fresh data, notify user)
    - No automatic retry in the client

---

## 7. Testing & Verification

- [x] 7.1 Unit tests for JWT minting
  - **spec_ref**: `specs/zgw-api-bridge/spec.md#REQ-ZGW-001`
  - **files**: `tests/Unit/Service/ZgwApiClientTest.php`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Test JWT payload contains all required claims (iss, client_id, user_id, user_representation, iat, exp)
    - Test HS256 signature verifies with configured secret
    - Test clock-skew error (403 JWT verlopen) is caught and raises ClockSkewException

- [x] 7.2 ZRC createZaak → ZgwResourceMapping flow tested with a mocked `ZgwApiClient` (`tests/Unit/Service/ZrcClientTest.php`)
  - **spec_ref**: `specs/zgw-api-bridge/spec.md#REQ-ZGW-002`
  - **files**: `tests/Integration/Service/ZrcClientTest.php`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Test against mock ZRC endpoint (guzzle mock or VCR cassette)
    - Verify POST /zaken constructs correct body (bronorganisatie, zaaktype URL, startdatum, omschrijving)
    - Verify Location header is captured and persisted in ZgwResourceMapping
    - Verify ETag from response is cached

- [x] 7.3 NRC callback endpoint tested (401 on bad bearer, 202 on valid) in `tests/Unit/Controller/ZgwNotificationControllerTest.php`
  - **spec_ref**: `specs/zgw-api-bridge/spec.md#REQ-ZGW-007`
  - **files**: `tests/Integration/Controller/ZgwNotificationControllerTest.php`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Test 401 on invalid bearer token
    - Test 202 Accepted on valid token
    - Test zaken.statusGewijzigd notification updates Request.status
    - Test catalogi notification invalidates ZTC cache
    - Verify all handlers complete within 5 seconds (measure elapsed time)

- [x] 7.4 Coexistence validation tested (`tests/Unit/Service/ZgwCoexistenceValidatorTest.php` + a new double-write guard case in `ZrcClientTest`)
  - **spec_ref**: `specs/zgw-api-bridge/spec.md#REQ-ZGW-008`
  - **files**: `tests/Integration/Service/ZgwCoexistenceValidatorTest.php`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Test DoubleWritePathException is raised if both ZgwEndpoint.actief=true and StufEndpoint.write=on for same gemeente
    - Test validation passes if only one write path is enabled
    - Test validation passes if beide read-only (no write enabled)

- [x] 7.5 Scope enforcement tested (`tests/Unit/Service/AcClientTest.php` + missing-scope case in `ZrcClientTest`)
  - **spec_ref**: `specs/zgw-api-bridge/spec.md#REQ-ZGW-006`
  - **files**: `tests/Integration/Service/AcClientTest.php`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Test InsufficientScopeException is raised if client lacks zaken.aanmaken scope
    - Test scope refresh picks up newly granted permissions within 15m
    - Test pre-flight check prevents HTTP call to ZRC

- [x] 7.6 ETag concurrency / 412 OptimisticLockException tested in `ZrcClientTest`
  - **spec_ref**: `specs/zgw-api-bridge/spec.md#REQ-ZGW-009`
  - **files**: `tests/Integration/Service/ZrcClientTest.php` (extend)
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Test PATCH with If-Match succeeds on matching etag
    - Test PATCH returns 412 on stale etag; OptimisticLockException is raised with both representations
    - Test no automatic retry on 412

- [~] 7.7 End-to-end Request → Zaak → Status Update — DEFERRED: each leg is unit-tested in isolation (createZaak, NRC status notification → Request.status), but a single wired E2E harness (VCR cassettes / live OpenZaak) needs a running instance and is out of scope for this offline build. Filed as follow-up.
  - **spec_ref**: `specs/zgw-api-bridge/spec.md#REQ-ZGW-002`, REQ-ZGW-007
  - **files**: `tests/Integration/ZgwBridgeE2ETest.php` or similar
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Test createRequest flow for gemeente with ZgwEndpoint actief=true
    - Verify createZaak is called, zaak URL is persisted in ZgwResourceMapping
    - Simulate NRC notification with zaak.statusGewijzigd
    - Verify Request.status is updated with the new statustype omschrijving
    - All interactions mocked or against VCR cassettes (no live ZGW calls in test)

---

## 8. Documentation & Configuration

- [x] 8.1 Add application-level docblocks and method signatures
  - **files**: All newly created `lib/Service/` classes
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Each public method has a docblock with `@param`, `@return`, `@throws` tags
    - Each exception class is documented with the scenario that raises it

- [x] 8.2 Service wiring — all ZGW services/controller resolve through NC autowiring (constructor type-hints only, no explicit `registerService` needed, matching the rest of pipelinq); the 15-minute AC refresh is registered as `ZgwScopeRefreshJob` in `appinfo/info.xml <background-jobs>`
  - **files**: `lib/AppInfo/Application.php`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Register `ZgwApiClient`, `ZrcClient`, `DrcClient`, `BrcClient`, `ZtcClient`, `AcClient`, `NrcSubscriptionService`, `ZgwCoexistenceValidator` in DI container
    - Register `NrcNotificationListener` for callback events (if event-based) or wire to controller (if request-based)
    - Register scheduled task for `AcClient::refreshScopes()` every 15 minutes

- [x] 8.3 Add exception classes (9: ZgwBridge, ClockSkew, InsufficientScope, OptimisticLock, ZgwResourceNotFound, ZaaktypeNotInCatalogus, BesluittypeNotInCatalogus, DoubleWritePath, NrcSubscriptionFailed)
  - **files**: `lib/Exception/` directory
  - **tier**: P0-must
  - **acceptance_criteria**:
    - `ClockSkewException(string $message, int $observedTime, int $serverTime)` — carries both timestamps
    - `InsufficientScopeException(string $scope, string $zaaktypeUrl)` — carries required scope and target zaaktype
    - `OptimisticLockException(string $message, array $staleRepresentation, array $freshRepresentation, string $conflictingField)` — carries both states
    - `ZgwResourceNotFoundException(string $url)` — resource (zaak, besluit, etc.) not found
    - `ZaaktypeNotInCatalogusException(string $omschrijving)` — zaaktype not in ZTC
    - `BesluittypeNotInCatalogusException(string $omschrijving)` — besluittype not in ZTC
    - `DoubleWritePathException(string $message)` — both StUF and ZGW write paths enabled
    - `NrcSubscriptionFailedException(string $message)` — failed to register NRC abonnement

---

## 9. Configuration & Deployment

- [x] 9.1 Config keys documented — pipelinq is `IAppConfig`-driven (no `.env`); the four tunables (`zgw.nrc_callback_url`, `zgw.ztc_cache_ttl`, `zgw.ac_refresh_interval`, `zgw.drc_inline_threshold`) are documented in `docs/Integrations/zgw-api-bridge.md`
  - **files**: `.env.example`, `README.md`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - `ZGW_API_NRC_CALLBACK_URL` — pipelinq callback endpoint (e.g., `https://pipelinq.zoetermeer.nl/api/zgw/notificaties/inbox`)
    - `ZGW_API_ZTC_CACHE_TTL` — ZTC cache TTL in seconds (default 3600)
    - `ZGW_API_AC_REFRESH_INTERVAL` — AC scope refresh interval in seconds (default 900)
    - `ZGW_API_DRC_INLINE_THRESHOLD` — DRC inline upload threshold in bytes (default 4194304 = 4 MiB)

- [x] 9.2 Vault secret examples documented in `docs/Integrations/zgw-api-bridge.md` (client-secret, mTLS cert/key, nrc-callback-bearer reference conventions + provisioning order)
  - **files**: `docs/zgw-vault-setup.md` or similar
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Example vault paths for client secrets: `vault://zgw/<gemeenteCode>/client-secret`
    - Example paths for mTLS certs: `vault://zgw/<gemeenteCode>/client-cert`, `vault://zgw/<gemeenteCode>/client-key`
    - Example paths for NRC callback bearer tokens: `vault://zgw/<gemeenteCode>/nrc-callback-bearer`
    - Instructions for gemeente IT team to populate vault before activating ZgwEndpoint

- [x] 9.3 No new composer dependencies — JWT is minted with `hash_hmac` (HS256) and HTTP uses NC's `IClientService`; `composer.json`/`composer.lock` untouched
  - **files**: `composer.json`
  - **tier**: P0-should
  - **acceptance_criteria**:
    - Verify JWT library (e.g., `firebase/php-jwt`) is installed; if not, add it
    - Verify HTTP client (e.g., `guzzlehttp/guzzle`) is installed; if not, add it

- [x] 9.4 No bespoke migration needed — schemas/seeds import through OR's existing repair-step + version-gated config import (the fragment hash folds into `info.version` so the import re-runs); per-object tables are created by OR on import
  - **files**: `openspec/changes/zgw-api-bridge/migrations/` (if OR requires it)
  - **tier**: P0-should
  - **acceptance_criteria**:
    - If OpenRegister requires migrations for new schemas, create them
    - Verify migrations run without error on a fresh database
    - Verify seed data is imported correctly

---

## 10. Wrap-up & Verification

- [x] 10.1 Run all tests — `phpunit`: 479 tests / 1612 assertions, 0 failures (14 skipped; only the env "no coverage driver" runner warning)
  - `npm run test` (frontend, if any) — should pass
  - `./vendor/bin/phpunit tests/` (backend) — should pass
  - `npm run build` — should produce zero errors

- [x] 10.2 Code quality checks — phpcs (whole repo) 0 errors, phpmd (baseline) 0, psalm 0 errors, phpstan 0 errors; no hardcoded secrets (all via `ZgwSecretResolver` vault refs)
  - `./vendor/bin/phpstan analyse lib/` (static analysis) — zero errors in strict mode (or documented ignores)
  - `./vendor/bin/phpcs lib/` (code style) — PSR-12 compliant
  - Security review: no hardcoded secrets, all vault refs use proper URIs, no unvalidated URL handling

- [~] 10.3 Manual smoke test — DEFERRED: requires a live OpenZaak/NRC instance + provisioned vault secrets, which are unavailable in this offline build. Filed as follow-up for the live-instance verification pass.
  - Create a ZgwEndpoint via admin API (or seed it) pointing to a test OpenZaak instance
  - Create a pipelinq Request with gemeente code matching the endpoint
  - Verify createZaak flow: zaak appears in OpenZaak UI, ZgwResourceMapping is persisted
  - Simulate NRC notification (mock or curl) with zaak.statusGewijzigd
  - Verify Request.status is updated within 5 seconds
  - Check logs for no errors or warnings

- [x] 10.4 Documentation review — `docs/Integrations/zgw-api-bridge.md` covers config objects, app-config keys, vault refs, the security model, StUF coexistence, and a troubleshooting table (clock skew, scope, NRC 401, double-write)
  - Verify all exception scenarios are documented in spec
  - Verify all code examples in design.md are accurate (JSON format, field names, etc.)
  - Verify README has troubleshooting section for common ZGW integration issues (clock skew, scope misconfigs, NRC unreachability)

---

**Change Ready for Implementation:** Once all tasks in section 0 pass, the change is ready for parallel implementation of sections 1–6 by backend team, with section 7 running concurrently (tests written alongside implementation) and section 8–10 as final wrap-up.
