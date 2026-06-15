# Design: stuf-zkn-bg-adapter

## Architecture

### Data Model (OpenRegister Schemas)

Three new schemas are added to `pipelinq_register.json`. Entity definitions match ADR-000 exactly.

#### StufEndpoint

Configuration profile for one zaaksysteem connection. Multiple endpoints can coexist (e.g., a gemeente running both Key2Zaken and PinkRoccade during transition).

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| id | string | Yes | Unique identifier for this endpoint (e.g., stuf-ep-amersfoort-key2zaken) |
| naam | string | Yes | Human-readable name (e.g., Gemeente Amersfoort - Centric Key2Zaken) |
| gemeenteCode | string | Yes | 4-digit gemeente code (e.g., 0307 for Amersfoort) |
| ontvangerApplicatie | string | Yes | Target zaaksysteem application name |
| ontvangerOrganisatie | string | Yes | Target organization (gemeente) |
| ontvangerGebruiker | string | Yes | Target system user identifier |
| zenderApplicatie | string | Yes | Pipelinq identifier |
| zenderOrganisatie | string | Yes | Sending organization (gemeente) |
| endpointUrl | string | Yes | SOAP endpoint URL (e.g., https://stuf.gemeente.nl/CGS/StUFZKN/2.04/OntvangAsynchroon) |
| soapVersion | string | Yes | SOAP version (1.1 or 1.2) |
| stufVersion | string | Yes | StUF version (0310) |
| sectormodel | string | Yes | Sectormodel (ZKN for cases) |
| authenticatie | object | Yes | Authentication config with type, gebruikersnaam, wachtwoordKluisRef |
| tlsClientCertRef | string | No | Vault reference to client certificate for mutual TLS |
| zaakIdentificatieStrategie | string | No | ID allocation strategy: vooraf (pre-allocated) or achteraf (server-assigned) |
| actief | boolean | No | Whether this endpoint is active |
| aangemaakt | string (date-time) | No | Timestamp when endpoint was created |

#### StufMessage

Append-only audit log entry for every SOAP envelope sent or received. Required by ADR-026 (per-call audit) and gemeente AVG accountability.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| id | string | Yes | Unique identifier (e.g., stuf-msg-2026-05-21-08-44-12-a7c3) |
| endpointId | string | Yes | Reference to the StufEndpoint used |
| richting | string | Yes | Direction: uitgaand (outbound) or inkomend (inbound) |
| berichtSoort | string | Yes | Message type code (Lk01, Lk02, Lk03, Bv01, Lv01, La01, Du01, Fo02) |
| entiteittype | string | No | Entity type (ZAK for case, NPS for natural person, NNP for organization) |
| functie | string | No | Operation (creeerZaak, actualiseerZaak, geefZaakDetails, zetStatus, etc.) |
| referentienummer | string | No | Outbound referentienummer (unique per envelope, ULID) |
| crossRefnummer | string | No | Inbound crossRefnummer (for matching pairs) |
| zaakIdentificatie | string | No | Zaak ID if known |
| envelopeXml | string | Yes | Full SOAP envelope XML (request) |
| responseEnvelopeXml | string | No | Full SOAP envelope XML (response) |
| httpStatus | integer | No | HTTP response status code |
| duurMs | integer | No | Wall-clock duration in milliseconds |
| fout | object | No | Error details if failed (code, omschrijving, details) |
| verzondenOp | string (date-time) | Yes | When the message was sent |
| ontvangenOp | string (date-time) | No | When the response was received |
| gerelateerdeRequestId | string | No | UUID of related pipelinq Request, if applicable |
| gerelateerdeContactId | string | No | UUID of related pipelinq Contact, if applicable |
| status | string | No | Status: verzonden (sent), bevestigd (confirmed), fout (error) |
| retries | array | No | Retry history: each entry has poging (attempt #), timestamp, httpStatus, fout |

#### ZaaksysteemMapping

Bidirectional link between pipelinq's internal ID and the zaaksysteem identifier. Survives across updates and is the authoritative source for the external ID.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| id | string | Yes | Unique identifier (e.g., map-evenement-tour-amersfoort) |
| pipelinqEntiteit | string | Yes | Type of pipelinq entity: request or contact |
| pipelinqId | string | Yes | UUID of the pipelinq entity |
| externEntiteit | string | Yes | External entity type: ZAK (zaak), NPS (natuurlijk persoon), NNP (niet-natuurlijk persoon) |
| externIdentificatie | string | Yes | External identifier (zaak ID or betrokkene ID) |
| endpointId | string | Yes | Reference to StufEndpoint used |
| laatsteSynchronisatie | string (date-time) | No | Timestamp of last sync |
| synchronisatieStatus | string | No | Status: in_sync, fout, geannuleerd |
| openstaandeWijzigingen | array | No | Pending changes not yet sent to zaaksysteem |

### Backend

#### StufAdapterService (`lib/Service/StufAdapterService.php`)

Main service orchestrating the adapter operations.

| Method | Signature | Description |
|--------|-----------|-------------|
| creeerZaak | `(Request $request, StufEndpoint $endpoint, ?array $opts): array` | Create a zaak from a Request; returns zaak identificatie |
| actualiseerZaak | `(Request $request, StufEndpoint $endpoint): array` | Update an existing zaak; returns confirmation |
| geefZaakDetails | `(string $zaakId, StufEndpoint $endpoint): ?array` | Query zaak details; returns Zaak object with status, betrokkenen |
| vrijBericht | `(string $name, array $payload, StufEndpoint $endpoint): array` | Send custom message (e.g., zetStatus) |
| genereerZaakIdentificatie | `(StufEndpoint $endpoint): string` | Request pre-allocated zaak ID via Du01 |

#### StufEnvelopeBuilder (`lib/Service/StufEnvelopeBuilder.php`)

Constructs valid SOAP 1.1 + StUF 0310 envelopes.

| Method | Signature | Description |
|--------|-----------|-------------|
| buildLk01CreeerZaak | `(Request $request, ?string $zaakId): string` | Build Lk01 creeerZaak envelope |
| buildLk02ActualiseerZaak | `(Request $request, ZaaksysteemMapping $mapping): string` | Build Lk02 update envelope |
| buildLv01GeefDetails | `(string $zaakId, array $gewensteElementen): string` | Build Lv01 query envelope |
| buildDu01GenereerZaakId | `(): string` | Build Du01 ID generation request |

#### StufMessageHandler (`lib/Service/StufMessageHandler.php`)

Manages audit logging and retry logic.

| Method | Signature | Description |
|--------|-----------|-------------|
| logOutbound | `(StufEndpoint $endpoint, string $envelopeXml, ?string $zaakId, ?string $requestId): StufMessage` | Create StufMessage for outbound |
| logInbound | `(string $responseXml, string $referentienummer): ?StufMessage` | Match inbound to outbound, update status |
| recordRetry | `(StufMessage $msg, int $attempt, int $httpStatus, ?array $fout)` | Record retry attempt |

#### StufHttpClient (`lib/Service/StufHttpClient.php`)

Sends SOAP envelopes and handles transport.

| Method | Signature | Description |
|--------|-----------|-------------|
| send | `(StufEndpoint $endpoint, string $envelopeXml): array` | POST envelope to endpoint, return [httpStatus, responseXml, durationMs] |

#### StufMessageParser (`lib/Service/StufMessageParser.php`)

Parses SOAP responses and extracts data.

| Method | Signature | Description |
|--------|-----------|-------------|
| parseBevestiging | `(string $responseXml): array` | Extract Bv01 confirmation data |
| parseZaakDetails | `(string $responseXml): array` | Extract Zaak object from La01 |
| parseError | `(string $responseXml): array` | Extract Fo02 error details |

#### CircuitBreakerService (`lib/Service/CircuitBreakerService.php`)

Prevents cascading failures.

| Method | Signature | Description |
|--------|-----------|-------------|
| checkEndpoint | `(StufEndpoint $endpoint): bool` | Return false if circuit is open |
| recordFailure | `(StufEndpoint $endpoint)` | Increment failure count; open circuit if threshold reached |
| resetEndpoint | `(StufEndpoint $endpoint)` | Reset failure count (on success) |

#### ContactBetrokkeneMapper (`lib/Service/ContactBetrokkeneMapper.php`)

Manages Contact ↔ betrokkene mapping with duplicate prevention.

| Method | Signature | Description |
|--------|-----------|-------------|
| linkContact | `(Contact $contact, string $betrokkeneId, StufEndpoint $endpoint)` | Create or update ZaaksysteemMapping |
| findOrCreateBetrokkene | `(Contact $contact, StufEndpoint $endpoint): string` | Query zaaksysteem for existing betrokkene; create if not found |

### API Endpoints

#### POST /api/stuf/outbound

Send a custom StUF message (vrijBericht).

Request body:
```json
{
  "endpointId": "stuf-ep-amersfoort-key2zaken",
  "berichtNaam": "zetStatus",
  "payload": {
    "zaakIdentificatie": "ZAAK-2026-0008812",
    "statusType": "in_behandeling",
    "datumStatusGezet": "2026-05-21T09:00:00+02:00"
  }
}
```

Response:
```json
{
  "success": true,
  "referentienummer": "01HXXXXXX...",
  "stufMessageId": "stuf-msg-2026-05-21-09-00-12-abc123"
}
```

#### POST /api/stuf/inkomend

Receive inbound notifications and responses (kennisgevingen, bevestigingen). Validates referentienummer/crossRefnummer, updates StufMessage and ZaaksysteemMapping status, triggers needs-input on error.

Request: Raw SOAP envelope

Response: HTTP 200 with minimal acknowledgement

#### GET /api/stuf/endpoints

List all configured StufEndpoint profiles.

#### GET /api/stuf/messages?endpointId=xxx&limit=50

Query StufMessage audit log with filtering and pagination.

### Frontend

#### Admin: Endpoint Configuration (new page `/admin/stuf-endpoints`)

- List configured endpoints with active status, gemeente code, zaaksysteem name
- Create new endpoint with form (connection details, WSSE credentials, TLS cert reference)
- Edit endpoint
- Test connection button (sends test Du01, captures response)
- Delete endpoint (soft-delete recommended)

#### Admin: Audit Log Viewer (new page `/admin/stuf-audit-log`)

- Query StufMessage by date range, endpoint, entity type, status
- Display full envelope XML (collapsible)
- View retry history for failed messages
- Export audit log to CSV/JSON

## Files Changed

### New Files
- `lib/Service/StufAdapterService.php` — Main adapter orchestration
- `lib/Service/StufEnvelopeBuilder.php` — SOAP envelope construction
- `lib/Service/StufMessageHandler.php` — Audit log and retry management
- `lib/Service/StufHttpClient.php` — HTTP transport
- `lib/Service/StufMessageParser.php` — Response parsing
- `lib/Service/CircuitBreakerService.php` — Failure isolation
- `lib/Service/ContactBetrokkeneMapper.php` — Contact mapping with duplicate prevention
- `lib/Controller/StufController.php` — API endpoints
- `src/views/admin/StufEndpoints.vue` — Endpoint configuration UI
- `src/views/admin/StufAuditLog.vue` — Audit log viewer
- `lib/Settings/pipelinq_register.json` — Updated with StufEndpoint, StufMessage, ZaaksysteemMapping schemas

### Modified Files
- `appinfo/routes.php` — Register POST /api/stuf/outbound, POST /api/stuf/inkomend, GET /api/stuf/endpoints, GET /api/stuf/messages routes
- `lib/Settings/pipelinq_register.json` — Add three new schemas and seed data

## Seed Data

### StufEndpoint examples (Dutch values)

```json
[
  {
    "@self": {
      "register": "pipelinq",
      "schema": "StufEndpoint",
      "slug": "amersfoort-key2zaken"
    },
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
    "zaakIdentificatieStrategie": "achteraf",
    "actief": true,
    "aangemaakt": "2026-04-12T09:14:00+02:00"
  },
  {
    "@self": {
      "register": "pipelinq",
      "schema": "StufEndpoint",
      "slug": "rotterdam-pinkroccade"
    },
    "id": "stuf-ep-rotterdam-pinkroccade",
    "naam": "Gemeente Rotterdam - Atos PinkRoccade",
    "gemeenteCode": "0599",
    "ontvangerApplicatie": "PinkRoccade",
    "ontvangerOrganisatie": "Gemeente Rotterdam",
    "ontvangerGebruiker": "pipelinq_rotterdam",
    "zenderApplicatie": "Pipelinq",
    "zenderOrganisatie": "Gemeente Rotterdam",
    "endpointUrl": "https://zaak.rotterdam.nl/StUFZKN/0310/Asynchroon",
    "soapVersion": "1.1",
    "stufVersion": "0310",
    "sectormodel": "ZKN",
    "authenticatie": {
      "type": "wsse-usernametoken",
      "gebruikersnaam": "pipelinq",
      "wachtwoordKluisRef": "vault://stuf/rotterdam/pinkroccade"
    },
    "zaakIdentificatieStrategie": "vooraf",
    "actief": true,
    "aangemaakt": "2026-04-15T14:22:00+02:00"
  }
]
```

### StufMessage examples (Dutch values)

```json
[
  {
    "@self": {
      "register": "pipelinq",
      "schema": "StufMessage",
      "slug": "msg-evenement-tour-20260521-084412"
    },
    "id": "stuf-msg-2026-05-21-08-44-12-a7c3",
    "endpointId": "stuf-ep-amersfoort-key2zaken",
    "richting": "uitgaand",
    "berichtSoort": "Lk01",
    "entiteittype": "ZAK",
    "functie": "creeerZaak",
    "referentienummer": "01HXXXXXXXXXXXXXXXXXXXXXX",
    "zaakIdentificatie": "ZAAK-2026-0008812",
    "envelopeXml": "<soapenv:Envelope xmlns:soapenv=\"http://schemas.xmlsoap.org/soap/envelope/\" ... >",
    "responseEnvelopeXml": "<soapenv:Envelope xmlns:soapenv=\"http://schemas.xmlsoap.org/soap/envelope/\" ... >",
    "httpStatus": 200,
    "duurMs": 1240,
    "verzondenOp": "2026-05-21T08:44:12+02:00",
    "ontvangenOp": "2026-05-21T08:44:13+02:00",
    "gerelateerdeRequestId": "req-2026-aanvraag-evenementenvergunning-0123",
    "status": "bevestigd"
  },
  {
    "@self": {
      "register": "pipelinq",
      "schema": "StufMessage",
      "slug": "msg-contact-sync-20260521-150305"
    },
    "id": "stuf-msg-2026-05-21-15-03-05-def9",
    "endpointId": "stuf-ep-rotterdam-pinkroccade",
    "richting": "inkomend",
    "berichtSoort": "Lk02",
    "entiteittype": "ZAK",
    "functie": "actualiseerZaak",
    "crossRefnummer": "01HYYYYYYYYYYYYYYYYYYYYYY",
    "zaakIdentificatie": "ZAAK-2026-0012340",
    "responseEnvelopeXml": "<soapenv:Envelope ... >",
    "httpStatus": 200,
    "verzondenOp": null,
    "ontvangenOp": "2026-05-21T15:03:05+02:00",
    "gerelateerdeRequestId": "req-2026-aanvraag-vergunning-5678",
    "status": "bevestigd"
  }
]
```

### ZaaksysteemMapping examples (Dutch values)

```json
[
  {
    "@self": {
      "register": "pipelinq",
      "schema": "ZaaksysteemMapping",
      "slug": "map-request-evenement-tour"
    },
    "id": "map-evenement-tour-amersfoort",
    "pipelinqEntiteit": "request",
    "pipelinqId": "req-2026-aanvraag-evenementenvergunning-0123",
    "externEntiteit": "ZAK",
    "externIdentificatie": "ZAAK-2026-0008812",
    "endpointId": "stuf-ep-amersfoort-key2zaken",
    "laatsteSynchronisatie": "2026-05-21T08:44:13+02:00",
    "synchronisatieStatus": "in_sync",
    "openstaandeWijzigingen": []
  },
  {
    "@self": {
      "register": "pipelinq",
      "schema": "ZaaksysteemMapping",
      "slug": "map-contact-jeroen-veldermeer"
    },
    "id": "map-jeroen-vandermeer-amersfoort",
    "pipelinqEntiteit": "contact",
    "pipelinqId": "contact-jeroen-vandermeer-uuid",
    "externEntiteit": "NPS",
    "externIdentificatie": "987654321",
    "endpointId": "stuf-ep-amersfoort-key2zaken",
    "laatsteSynchronisatie": "2026-05-21T08:44:13+02:00",
    "synchronisatieStatus": "in_sync",
    "openstaandeWijzigingen": []
  }
]
```

## Reuse Analysis

- **OpenRegister CRUD** — Use ObjectService for schema-driven create/read/update/delete of StufEndpoint, StufMessage, ZaaksysteemMapping. NO custom Entity classes.
- **Full-text search** — Use IndexService for querying StufMessage audit log by date, endpoint, entity type, status.
- **Audit trail** — Use built-in AuditTrailService for tracking changes to endpoint configuration and mappings.
- **File attachment** — Pipelinq Document entities are fetched via ObjectService relations; base64 encoding is adapter-specific logic (not reusable OpenRegister component).
- **Locking** — Use ObjectService.lockObject() to prevent concurrent modifications to ZaaksysteemMapping during sync.
- **Logging** — Use Pipelinq's standard ILogger for debug traces; full envelope audit via StufMessage (not syslog).

## Deduplication Check

- Similar capability search: No existing Pipelinq modules handle SOAP construction, WSSE authentication, StUF 0310 message formatting, or zaaksysteem mapping.
- Comparison to zgw-api-bridge (sister module): ZGW uses REST/OpenAPI; StUF uses SOAP/XML. Both manage external system mappings but with fundamentally different protocols. Both can coexist; gemeente config selects one per active endpoint.
- Retry/circuit breaker: Implemented here as StUF-specific; no general retry middleware in Pipelinq scope (OpenRegister/Nextcloud handle standard HTTP retries; StUF has domain-specific concerns like referentienummer reuse).
- Conclusion: No duplication. Adapter is domain-specific to StUF/zaaksysteem integration.
