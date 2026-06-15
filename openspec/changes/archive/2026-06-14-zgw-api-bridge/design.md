# Design: ZGW API (Zaakgericht Werken) REST bridge

## Architecture

### Overview

The ZGW API bridge sits between pipelinq's request-handling layer and the five ZGW component APIs (ZRC, DRC, BRC, ZTC, AC) plus NRC for event subscriptions. The architecture follows these patterns:

1. **Per-component typed clients** — Each ZGW API (ZRC, DRC, BRC, ZTC, AC) has a dedicated client class that handles HTTP marshalling, authentication, and error handling.
2. **JWT minting on every request** — Per VNG-API-Common, no token caching; each outbound call mints a fresh JWT via `ZgwApiClient`.
3. **Bidirectional resource mapping** — `ZgwResourceMapping` links pipelinq entities (Request, Contact, Document) by their pipelinq UUID to ZGW resource URLs (the canonical identifiers in ZGW).
4. **Event-driven updates** — NRC notifications POST to a pipelinq callback endpoint, which dispatches to per-kanaal handlers that update Request status and trigger workflows.
5. **Scope discovery and enforcement** — AC is queried on startup (15m refresh) to build a cache of allowed operations per zaaktype/informatieobjecttype/besluittype; operations are guarded client-side before HTTP calls.

### Data Layer

#### Extended Entities

**request** (existing, from `request-management`)

Gains optional foreign key references to ZGW resources:

| Property | Type | Description |
|----------|------|-------------|
| `zgwResourceMapping` | array | References to `ZgwResourceMapping` objects linking this Request to zaak/besluit URLs. One per ZGW endpoint. |

No schema change needed — this is a relation field managed by OpenRegister's relation engine.

---

#### New Entities

##### ZgwEndpoint

Purpose: Configuration profile for a single ZGW deployment per gemeente. Since ZRC/DRC/BRC/ZTC/AC may run on different hosts (common in hosted deployments), each endpoint lists all five component URLs plus the NRC URL.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| `id` | string | Yes | Human-readable identifier (e.g., `zgw-ep-zoetermeer-openzaak`) |
| `naam` | string | Yes | Display name (e.g., `Gemeente Zoetermeer - Open Zaak (Dimpact)`) |
| `gemeenteCode` | string | Yes | 4-digit gemeente code per CBS (e.g., `0637`) |
| `componenten` | object | Yes | Component URLs: `zrc`, `drc`, `brc`, `ztc`, `ac`, `nrc` (each a full base URL) |
| `clientId` | string | Yes | Reference to the `ZgwClient` id used for this endpoint |
| `actief` | boolean | No | Whether this endpoint is active for zaak creation/lookup (write="on") |
| `readOnly` | boolean | No | If true, read-only consumption; write operations are blocked |
| `mutualTlsCert` | string | No | Optional PEM-encoded client certificate for mTLS with this endpoint |
| `mutualTlsKey` | string | No | Optional PEM-encoded private key for mTLS (stored as vault reference) |
| `aangemaakt` | string | No | ISO 8601 creation timestamp |

Schema.org mapping: infrastructure entity with no published equivalent.

---

##### ZgwClient

Purpose: JWT credentials per VNG-API-Common spec. One client may be authorized on multiple endpoints.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| `id` | string | Yes | Human-readable identifier (e.g., `zgw-client-zoetermeer`) |
| `clientIdentifier` | string | Yes | VNG API-Common client identifier (e.g., `pipelinq-zoetermeer`); sent in JWT `iss` and `client_id` claims |
| `secretKluisRef` | string | Yes | Vault reference to the client secret (e.g., `vault://zgw/zoetermeer/client-secret`) |
| `userId` | string | Yes | VNG API-Common user ID for JWT `user_id` claim (e.g., `pipelinq`) |
| `userRepresentation` | string | Yes | VNG API-Common user representation for JWT `user_representation` claim (e.g., `Pipelinq backend (Conduction)`) |
| `tokenLevensduurSeconden` | integer | No | Token lifetime in seconds; default 3600. Endpoints may override via ZgwEndpoint.tokenOverrideSec. |
| `aangemaakt` | string | No | ISO 8601 creation timestamp |

Schema.org mapping: infrastructure credentials with no published equivalent.

---

##### NrcAbonnement

Purpose: Subscription state for a single NRC endpoint per ZgwEndpoint. Tracks the registered abonnement URL, callback bearer token, active kanalen/filters, and last-received notification timestamp.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| `id` | string | Yes | Human-readable identifier (e.g., `abon-zoetermeer-zaken`) |
| `endpointId` | string | Yes | Reference to the parent `ZgwEndpoint` id |
| `abonnementUrl` | string | Yes | Full URL of the registered NRC abonnement (returned by NRC on POST /api/v1/abonnement) |
| `callbackUrl` | string | Yes | Pipelinq callback endpoint URL (e.g., `https://pipelinq.zoetermeer.nl/api/zgw/notificaties/inbox`) |
| `callbackAuth` | string | Yes | Bearer token for NRC callback authentication (opaque, generated per abonnement; stored as vault reference) |
| `kanalen` | array | Yes | List of subscribed kanalen with per-kanaal filters. Each entry: `{ naam: "zaken", filters: { bronorganisatie: "002564440" } }` |
| `laatstOntvangenOp` | string | No | ISO 8601 timestamp of the last successfully processed NRC notification |
| `actief` | boolean | No | Whether this subscription is active (false if registration failed or disabled) |

Schema.org mapping: infrastructure subscription with no published equivalent.

---

##### ZgwResourceMapping

Purpose: Bidirectional link between pipelinq entities and ZGW resource URLs. ZGW uses URLs as canonical identifiers, not UUIDs; this mapping is essential for relating inbound NRC notifications (which reference the zaak URL) back to the originating pipelinq Request.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| `id` | string | Yes | Human-readable identifier (e.g., `map-evenement-tour-zoetermeer`) |
| `pipelinqEntiteit` | string | Yes | Entity type: `request`, `contact`, or `document` |
| `pipelinqId` | string | Yes | UUID of the pipelinq entity |
| `zgwResourceType` | string | Yes | ZGW resource type: `zaak`, `besluit`, `rol`, `informatieobject` |
| `zgwUrl` | string | Yes | Full URL of the ZGW resource (canonical identifier) |
| `zgwUuid` | string | No | UUID extracted from the `zgwUrl` (for convenience in lookups) |
| `endpointId` | string | Yes | Reference to the `ZgwEndpoint` id (may have multiple mappings per Request if synced to multiple ZGW instances) |
| `laatsteSynchronisatie` | string | No | ISO 8601 timestamp of last successful read from ZGW |
| `etag` | string | No | Most recent ETag from GET/PATCH on this resource (used for optimistic concurrency on subsequent PATCH calls) |

Schema.org mapping: infrastructure relation with no published equivalent.

---

### Backend

#### JWT Minting Service

**Class: `lib/Service/ZgwApiClient.php`**

Handles JWT creation and outbound HTTP calls.

**Method: `mintJwt(ZgwClient $client, int $expiresIn = 3600): string`**

1. Retrieve client secret from vault via `$client->secretKluisRef`
2. Construct JWT payload:
   ```json
   {
     "iss": "<client.clientIdentifier>",
     "client_id": "<client.clientIdentifier>",
     "user_id": "<client.userId>",
     "user_representation": "<client.userRepresentation>",
     "iat": <unix timestamp>,
     "exp": <unix timestamp + expiresIn>
   }
   ```
3. Sign with HS256 using the client secret
4. Return the JWT string

Clock skew: The minting service MUST NOT add leeway; the receiving component allows ±60s per VNG-API-Common. If a 403 is returned with "JWT verlopen" or "JWT nog niet geldig", catch it and raise `ClockSkewException` with both observed timestamps.

**Method: `callComponent(string $componentUrl, string $method, string $path, ?array $body = null, ZgwClient $client): array`**

1. Mint JWT via `mintJwt($client)`
2. Construct full URL: `$componentUrl . $path`
3. Send HTTP request with `Authorization: Bearer <JWT>` header, `Content-Type: application/json`, and (for POST/PATCH) the JSON-encoded body
4. On success: return response body + headers (including ETag)
5. On failure: catch HTTP exceptions and re-raise as domain exceptions (e.g., `ZgwResourceNotFoundException`, `InsufficientScopeException`, `ClockSkewException`)

---

#### Resource Clients (ZRC, DRC, BRC, ZTC, AC)

**Class: `lib/Service/ZrcClient.php`**

Typed client for Zaken API.

- `createZaak(ZgwEndpoint $endpoint, array $zaakData): ZgwResourceMapping` — POST /zaken, capture Location header, create mapping
- `getZaak(ZgwResourceMapping $mapping): array` — GET zaak URL, cache ETag
- `updateZaak(ZgwResourceMapping $mapping, array $updates): ZgwResourceMapping` — PATCH zaak, use If-Match with cached etag
- `addStatus(ZgwResourceMapping $zaakMapping, array $statusData): string` — POST /statussen, return status URL
- `getStatus(string $statusUrl, ZgwClient $client): array` — GET status, cache ETag
- `linkInitiator(ZgwResourceMapping $zaakMapping, Contact $contact): string` — GET /rollen?zaak=..., POST /rollen if not exists, return rol URL

All methods use `ZgwApiClient::callComponent()` internally.

**Class: `lib/Service/DrcClient.php`**

Typed client for Documenten API.

- `createEnkelvoudigInformatieobject(ZgwEndpoint $endpoint, Document $document, array $metadata): ZgwResourceMapping` — Determine inline vs. multipart based on file size; return mapping with EIO URL
- `uploadBestandsdelen(ZgwResourceMapping $eioMapping, Document $document): void` — For multipart: POST bestandsdelen[] list, PUT each part, POST .../unlock
- `linkZaakinformatieobject(ZgwResourceMapping $zaakMapping, ZgwResourceMapping $eioMapping): string` — POST /zaakinformatieobjecten linking zaak to EIO

**Class: `lib/Service/BrcClient.php`**

Typed client for Besluiten API.

- `createBesluit(ZgwEndpoint $endpoint, ZgwResourceMapping $zaakMapping, array $besluitData): ZgwResourceMapping` — POST /besluiten, return mapping
- `linkBesluitInformatieobject(ZgwResourceMapping $besluitMapping, ZgwResourceMapping $eioMapping): string` — POST /besluitinformatieobjecten linking besluit to EIO

**Class: `lib/Service/ZtcClient.php`**

Typed client for Catalogi API with caching.

- `resolveZaaktype(ZgwEndpoint $endpoint, string $omschrijving): string` — GET /zaaktypen?omschrijving=..., cache result for 1h (keyed by omschrijving + geldigheid window), return zaaktype URL
- `resolveStatustype(ZgwEndpoint $endpoint, string zaaktypeUrl, string $omschrijving): string` — Similar, for statustypen
- `resolveRoltype(ZgwEndpoint $endpoint, string zaaktypeUrl, string $omschrijving): string` — Similar, for roltypen
- `resolveBesluittype(ZgwEndpoint $endpoint, string $omschrijving): string` — Similar, for besluittypen
- `invalidateCache(ZgwEndpoint $endpoint, string $resourceType): void` — Called on "catalogi" kanaal NRC notifications; clears cache for affected resource type

**Class: `lib/Service/AcClient.php`**

Typed client for Autorisaties API with scope caching.

- `refreshScopes(ZgwEndpoint $endpoint, ZgwClient $client): void` — GET /autorisaties/zaaktypen (filtered by configured zaaktypen), build in-memory scope cache keyed by zaaktype+informatieobjecttype+besluittype+component. Run on startup, then every 15 minutes.
- `hasScope(ZgwEndpoint $endpoint, string zaaktypeUrl, string $scope): bool` — Check if configured client holds the scope for this zaaktype
- `getScopesFor(ZgwEndpoint $endpoint, string zaaktypeUrl): array` — Return list of scopes for this zaaktype

Pre-flight guards: Before calling `ZrcClient::createZaak()` or `BrcClient::createBesluit()` or `DrcClient::createEnkelvoudigInformatieobject()`, check `AcClient::hasScope()` and raise `InsufficientScopeException` if not authorized.

---

#### NRC Event Listener

**Class: `lib/Listener/NrcNotificationListener.php`**

Registered in `lib/AppInfo/Application.php` for the NRC callback controller (see API section below).

Responsibilities:

1. Receive inbound POST from NRC with bearer token and JSON body
2. Validate bearer token against the corresponding `NrcAbonnement.callbackAuth`
3. Dispatch to per-kanaal handler based on `body.kanaal`
4. Update `NrcAbonnement.laatstOntvangenOp = now()`

Per-kanaal handlers:

- **zaken** (resource="zaak", actie="create"): Create ZgwResourceMapping linking the new zaak URL to the originating Request (if one exists; zaak.eigenschappen or external lookup)
- **zaken** (resource="status", actie="create"): GET the status URL, resolve its statustype omschrijving from ZTC cache, update `Request.status` field. Trigger `RequestStatusChangedEvent` for workflows.
- **besluiten** (resource="besluit", actie="create"): Create ZgwResourceMapping linking the new besluit URL to the Request
- **catalogi** (any resource, any actie): Invalidate ZTC cache for the affected resource type via `ZtcClient::invalidateCache()`

All updates MUST complete within 5 seconds of notification arrival (per REQ-ZGW-007).

---

#### Double-Write Prevention

**Class: `lib/Service/ZgwCoexistenceValidator.php`**

Called before any createZaak flow:

1. Query all `ZgwEndpoint` objects for the same gemeente code where `actief=true`
2. Query all `StufEndpoint` objects (from stuf-zkn-bg-adapter) for the same gemeente code where write is enabled
3. If both count > 0, raise `DoubleWritePathException` with a message listing both endpoints and an instruction to the beheerder to disable one write path

Stored in a new `ZaaksysteemChoice` entity (optional): één entry per gemeente recording which write path is chosen (ZGW vs StUF); prevents races during migration.

---

### Frontend

No new frontend UI in scope for this change. Admin configuration of ZgwEndpoint, ZgwClient, NrcAbonnement is handled via the OpenRegister admin API (CRUD for schemas).

---

### Seed Data

#### Example ZgwEndpoint

```json
{
  "id": "zgw-ep-zoetermeer-openzaak",
  "naam": "Gemeente Zoetermeer - Open Zaak (Dimpact)",
  "gemeenteCode": "0637",
  "componenten": {
    "zrc": "https://open-zaak.zoetermeer.nl/zaken/api/v1",
    "drc": "https://open-zaak.zoetermeer.nl/documenten/api/v1",
    "brc": "https://open-zaak.zoetermeer.nl/besluiten/api/v1",
    "ztc": "https://open-zaak.zoetermeer.nl/catalogi/api/v1",
    "ac": "https://open-zaak.zoetermeer.nl/autorisaties/api/v1",
    "nrc": "https://open-notificaties.zoetermeer.nl/api/v1"
  },
  "clientId": "zgw-client-zoetermeer",
  "actief": true,
  "readOnly": false,
  "aangemaakt": "2026-04-12T09:14:00+02:00"
}
```

#### Example ZgwClient

```json
{
  "id": "zgw-client-zoetermeer",
  "clientIdentifier": "pipelinq-zoetermeer",
  "secretKluisRef": "vault://zgw/zoetermeer/client-secret",
  "userId": "pipelinq",
  "userRepresentation": "Pipelinq backend (Conduction)",
  "tokenLevensduurSeconden": 3600,
  "aangemaakt": "2026-04-12T09:14:00+02:00"
}
```

#### Example NrcAbonnement

```json
{
  "id": "abon-zoetermeer-zaken",
  "endpointId": "zgw-ep-zoetermeer-openzaak",
  "abonnementUrl": "https://open-notificaties.zoetermeer.nl/api/v1/abonnement/7a3b...",
  "callbackUrl": "https://pipelinq.zoetermeer.nl/api/zgw/notificaties/inbox",
  "callbackAuth": "vault://zgw/zoetermeer/nrc-callback-bearer",
  "kanalen": [
    {"naam": "zaken", "filters": {"bronorganisatie": "002564440"}},
    {"naam": "besluiten", "filters": {}}
  ],
  "laatstOntvangenOp": "2026-05-21T08:44:13+02:00",
  "actief": true
}
```

#### Example ZgwResourceMapping

```json
{
  "id": "map-evenement-tour-zoetermeer",
  "pipelinqEntiteit": "request",
  "pipelinqId": "req-2026-evenement-zoetermeer-0456",
  "zgwResourceType": "zaak",
  "zgwUrl": "https://open-zaak.zoetermeer.nl/zaken/api/v1/zaken/3f9a-...-c1",
  "zgwUuid": "3f9a4f1e-1a0d-4d10-9b22-c1ef0b8fbb2a",
  "endpointId": "zgw-ep-zoetermeer-openzaak",
  "laatsteSynchronisatie": "2026-05-21T08:44:13+02:00",
  "etag": "W/\"a1b2c3\""
}
```

Add 10+ additional seed objects covering:
- 3× ZgwEndpoint (different municipalities: Zoetermeer, Amsterdam, Utrecht)
- 3× ZgwClient (one per gemeente; one with mTLS cert refs)
- 3× NrcAbonnement (active, one with lastReceived recent, one inactive)
- 8× ZgwResourceMapping (zaak, besluit, rol, informatieobject variants; mix of recent and stale synchronisatie timestamps)
