# Tasks: stuf-zkn-bg-adapter

## 1. Data Model & Schema Registration

- [x] 1.1 Add `StufEndpoint` schema to `lib/Settings/pipelinq_register.json` with all properties (id, naam, gemeenteCode, ontvangerApplicatie, ontvangerOrganisatie, ontvangerGebruiker, zenderApplicatie, zenderOrganisatie, endpointUrl, soapVersion, stufVersion, sectormodel, authenticatie object, tlsClientCertRef, zaakIdentificatieStrategie, actief, aangemaakt)
- [x] 1.2 Add `StufMessage` schema to `lib/Settings/pipelinq_register.json` with all properties (id, endpointId, richting, berichtSoort, entiteittype, functie, referentienummer, crossRefnummer, zaakIdentificatie, envelopeXml, responseEnvelopeXml, httpStatus, duurMs, fout, verzondenOp, ontvangenOp, gerelateerdeRequestId, gerelateerdeContactId, status, retries array)
- [x] 1.3 Add `ZaaksysteemMapping` schema to `lib/Settings/pipelinq_register.json` with all properties (id, pipelinqEntiteit, pipelinqId, externEntiteit, externIdentificatie, endpointId, laatsteSynchronisatie, synchronisatieStatus, openstaandeWijzigingen array)
- [x] 1.4 Register all three schemas in the register's `schemas` array in `pipelinq_register.json`
- [x] 1.5 Add seed data for StufEndpoint examples (2 gemeente endpoints: Amersfoort Key2Zaken + Rotterdam PinkRoccade with realistic authentication refs)
- [x] 1.6 Add seed data for StufMessage examples (2-3 examples covering Lk01 outbound, Bv01 response, Lk02 inbound)
- [x] 1.7 Add seed data for ZaaksysteemMapping examples (2-3 examples covering request→zaak mapping, contact→NPS mapping)

## 2. Backend Core Services

- [x] 2.1 Create `lib/Service/StufAdapterService.php` with method stubs:
  - `creeerZaak(Request $request, StufEndpoint $endpoint, ?array $opts): array` — orchestrates Lk01 build, send, response parse, mapping persistence
  - `actualiseerZaak(Request $request, StufEndpoint $endpoint): array` — orchestrates Lk02 build and send
  - `geefZaakDetails(string $zaakId, StufEndpoint $endpoint): ?array` — sends Lv01, waits for La01 (30s timeout), returns Zaak object
  - `vrijBericht(string $name, array $payload, StufEndpoint $endpoint): array` — validates payload against template, builds Du01, sends
  - `genereerZaakIdentificatie(StufEndpoint $endpoint): string` — sends Du01, parses La01, returns zaak ID

- [x] 2.2 Create `lib/Service/StufEnvelopeBuilder.php` for SOAP construction:
  - `buildLk01CreeerZaak(Request $request, StufEndpoint $endpoint, ?string $zaakId): string` — builds Lk01 with stuurgegevens, zaaktype, betrokkenen, documents
  - `buildLk02ActualiseerZaak(Request $request, ZaaksysteemMapping $mapping): string` — builds Lk02 with updated fields
  - `buildLv01GeefDetails(string $zaakId, StufEndpoint $endpoint, array $gewensteElementen): string` — builds Lv01 query
  - `buildDu01GenereerZaakId(StufEndpoint $endpoint): string` — builds Du01 for pre-allocation
  - Helper: `buildStuurgegevens(string $berichtCode, StufEndpoint $endpoint, string $entiteittype, ?string $functie): array` — common header
  - Helper: `generateReferentienummer(): string` — ULID generation
  - Helper: `currentTimestampStuf(): string` — Europe/Amsterdam, yyyyMMddHHmmssSSS format

- [x] 2.3 Create `lib/Service/StufHttpClient.php` for transport:
  - `send(StufEndpoint $endpoint, string $envelopeXml, ?int $timeoutSeconds = 30): array` — POST to endpoint URL, return [httpStatus, responseXml, durationMs]
  - Load WSSE credentials from vault (via IAppConfig)
  - Load TLS client certificate from vault
  - Enforce server certificate verification (DO NOT disable)
  - Return timing info for audit logging

- [x] 2.4 Create `lib/Service/StufMessageHandler.php` for audit logging:
  - `logOutbound(StufEndpoint $endpoint, string $envelopeXml, string $referentienummer, string $berichtSoort, ?string $zaakId, ?string $requestId): StufMessage` — persist outbound envelope with status="verzonden"
  - `logInbound(string $responseXml, string $crossRefnummer): ?StufMessage` — find matching outbound by crossRefnummer, update status and responseEnvelopeXml
  - `recordRetry(StufMessage $msg, int $attempt, int $httpStatus, ?array $fout, int $durationMs)` — append to retries[] array
  - `transitionStatus(StufMessage $msg, string $newStatus)` — verzonden → bevestigd / fout

- [x] 2.5 Create `lib/Service/StufMessageParser.php` for response parsing:
  - `parseBevestiging(string $responseXml): array` — extract Bv01 confirmation data (referentienummer, zaakIdentificatie if present)
  - `parseZaakDetails(string $responseXml): array` — extract La01 Zaak object (identificatie, omschrijving, startdatum, einddatum, statussen, betrokkenen)
  - `parseError(string $responseXml): array` — extract Fo02 error (code, omschrijving, details)
  - `extractNamespaceValue(string $xml, string $xpath, string $namespace): ?string` — helper for XML parsing with StUF namespaces

- [x] 2.6 Create `lib/Service/CircuitBreakerService.php` for failure isolation:
  - `checkEndpoint(StufEndpoint $endpoint): bool` — return false if circuit is open
  - `recordFailure(StufEndpoint $endpoint)` — increment failure count, open circuit if threshold (4) reached
  - `resetEndpoint(StufEndpoint $endpoint)` — reset failure count to 0
  - `isCircuitOpen(StufEndpoint $endpoint): bool` — check if open and cooldown has passed
  - Store state in IAppConfig (failure count per endpoint ID, open timestamp)

- [x] 2.7 Create `lib/Service/ContactBetrokkeneMapper.php` for entity mapping:
  - `linkContact(Contact $contact, string $betrokkeneId, StufEndpoint $endpoint)` — create or update ZaaksysteemMapping with externEntiteit=NPS
  - `findOrCreateBetrokkene(Contact $contact, StufEndpoint $endpoint): string` — call geefBetrokkene Lv01 first, create new if not found, return betrokkeneId
  - `getContactMapping(Contact $contact, StufEndpoint $endpoint): ?ZaaksysteemMapping` — retrieve existing mapping by contact ID
  - Helper: `bsnFromContact(Contact $contact): ?string` — extract BSN if present

## 3. API Endpoints

- [x] 3.1 Create `lib/Controller/StufController.php` with routes:
  - `POST /api/stuf/outbound` — accept request with endpointId, berichtNaam, payload; call adapter.vrijBericht(); return {success, referentienummer, stufMessageId}
  - `POST /api/stuf/inkomend` — receive raw SOAP envelope; parse; match to outbound; update mappings; return 200
  - `GET /api/stuf/endpoints` — list all StufEndpoint objects (admin only)
  - `GET /api/stuf/messages?endpointId=xxx&limit=50` — query StufMessage by filters (admin only)

- [x] 3.2 Register routes in `appinfo/routes.php`:
  - POST /index.php/apps/pipelinq/api/stuf/outbound
  - POST /index.php/apps/pipelinq/api/stuf/inkomend (marked #[PublicPage] to accept inbound notifications without user session)
  - GET /index.php/apps/pipelinq/api/stuf/endpoints
  - GET /index.php/apps/pipelinq/api/stuf/messages

- [x] 3.3 Implement authorization checks:
  - Endpoints protected with admin check except /api/stuf/inkomend (public)
  - /api/stuf/inkomend validates WSSE signature or other mutual auth to prevent spoofing

## 4. Frontend — Admin Configuration & Audit

- [x] 4.1 Create `src/views/admin/StufEndpoints.vue` for endpoint management:
  - List all StufEndpoint objects in a data table (naam, gemeenteCode, soapVersion, actief status)
  - Create button opens a form dialog with fields: naam, gemeenteCode, ontvangerApplicatie, endpointUrl, soapVersion, stufVersion, sectormodel, zaakIdentificatieStrategie
  - Edit button opens form dialog with existing values
  - Delete button (soft-delete via toggle actief=false)
  - Test Connection button sends a test Du01 and shows response/error
  - Authentication config form: type dropdown (wsse-usernametoken), gebruikersnaam field, vault reference field for password and TLS cert

- [x] 4.2 Create `src/views/admin/StufAuditLog.vue` for audit log viewing:
  - Query StufMessage with filters: date range, endpointId dropdown, berichtSoort, status
  - Display table: date, direction, berichtSoort, functie, status, httpStatus, duration
  - Click row to expand and show full envelope XML (formatted, collapsible)
  - Show retry history (poging, timestamp, httpStatus) if retries[] present
  - Show fout object if status=fout
  - Export button to download visible rows as CSV/JSON

- [x] 4.3 Add navigation:
  - Add menu entries to admin sidebar: "StUF Eindpunten" (endpoints) and "StUF Audit Log"
  - Link from main settings page

## 5. Integration with Request & Contact Flows

- [x] 5.1 Add method to Request service/controller:
  - `registerZaak(Request $request, StufEndpoint $endpoint, ?array $opts): array` — public wrapper for adapter.creeerZaak(); called from request creation workflow
  - On success, create ZaaksysteemMapping and update request status
  - On error, log StufMessage and raise needs-input

- [x] 5.2 Add method to Contact service/controller:
  - `syncContactToBetrokkene(Contact $contact, StufEndpoint $endpoint)` — call mapper.findOrCreateBetrokkene() and link via ZaaksysteemMapping

- [x] 5.3 Add detail view integrations (UI):
  - Request detail: show linked zaak ID if mapped; button to "Register to Zaaksysteem" if not yet mapped
  - Contact detail: show linked betrokkene ID if mapped

## 6. Retry, Idempotency & Resilience

- [x] 6.1 Implement retry queue in StufAdapterService:
  - On transient error (HTTP 5xx, timeout), enqueue retry job with exponential backoff (5s, 30s, 2m, 10m)
  - Reuse same referentienummer across retries for idempotency
  - Record each attempt via MessageHandler.recordRetry()

- [x] 6.2 Implement circuit breaker:
  - CircuitBreakerService.checkEndpoint() called before sending any message
  - On 4th failure, open circuit for 5 minutes (store open timestamp in IAppConfig)
  - Raise needs-input event with endpoint ID and last error

- [x] 6.3 Implement timeout handling for sync queries:
  - geefZaakDetails defaults to 30s timeout
  - StufHttpClient enforces timeout via cURL or Guzzle
  - On timeout, do NOT retry; raise TimeoutException and needs-input event

## 7. Validation & Error Handling

- [x] 7.1 Add zaaktype mapping table to endpoint config:
  - StufEndpoint includes zaaktypeMappings: {request_type → zkn:omschrijving}
  - StufEnvelopeBuilder validates request.type against mapping before build
  - Raise ZaaktypeNotMappedException if not found (pre-send validation)

- [x] 7.2 Add document size validation:
  - StufEnvelopeBuilder checks total payload size pre-base64 against limit (default 25 MiB)
  - Raise PayloadTooLargeException before transmission
  - Advice caller to use alternate channel (DMS-direct URL)

- [x] 7.3 Add SOAP fault parsing:
  - StufMessageParser extracts stuf:fout from Fo02 envelopes
  - Distinguish transient (5xx, network) from permanent (StUF064, validation) errors
  - Log separately for circuit breaker logic vs. needs-input escalation

## 8. Logging & Observability

- [x] 8.1 Add debug-level logging to all services:
  - StufEnvelopeBuilder: log built envelope (truncated, first 500 chars)
  - StufHttpClient: log request URI, method, response status, duration
  - StufMessageParser: log parsed object counts (betrokkenen, zaak fields)
  - CircuitBreakerService: log circuit state transitions

- [x] 8.2 Graceful degradation:
  - Catch vault load exceptions (credentials missing) and log ERROR with endpoint ID
  - Catch TLS cert load exceptions and log ERROR
  - Do NOT transmit envelope if credentials/certs unavailable

- [x] 8.3 Add per-endpoint health check:
  - Admin audit log should show endpoint health (last 5 messages: success/fail rate)
  - Endpoint list view shows status badge (ok, circuit_open, error)

## 9. Testing & QA

- [x] 9.1 Unit tests for StufEnvelopeBuilder:
  - buildLk01CreeerZaak generates valid XML with all required stuurgegevens
  - referentienummer is unique ULID per call
  - tijdstipBericht matches expected format
  - Document base64 encoding is correct (no line wrapping)

- [x] 9.2 Unit tests for StufMessageParser:
  - parseBevestiging extracts Bv01 data correctly
  - parseZaakDetails returns Zaak object with all fields
  - parseError extracts Fo02 code and omschrijving

- [x] 9.3 Unit tests for CircuitBreakerService:
  - recordFailure increments count
  - At 4 failures, checkEndpoint returns false
  - After cooldown, circuit resets
  - resetEndpoint clears count on success

- [x] 9.4 Integration tests:
  - Mock StufHttpClient with sample SOAP responses
  - creeerZaak flow: Request → Lk01 build → HTTP call → Bv01 parse → ZaaksysteemMapping created
  - Retry flow: 503 response → wait 5s → retry with same referentienummer → success
  - Contact mapping: findOrCreateBetrokkene → Lv01 query → create if not found → ZaaksysteemMapping persisted

- [x] 9.5 Manual QA against test zaaksysteem:
  - Set up endpoint pointing to VNG StUF testbed
  - Execute full flow: createRequest → register to zaak → check zaaksysteem
  - Verify audit log entries (StufMessage rows)
  - Verify envelope XSD validation passes

## 10. Documentation & Knowledge Transfer

- [x] 10.1 Add README.md to app docs:
  - Architecture overview (SOAP, StUF 0310, message patterns)
  - Endpoint configuration steps
  - Troubleshooting guide (circuit breaker, timeout, auth errors)
  - Link to VNG StUF standards

- [x] 10.2 Add inline code comments:
  - StufEnvelopeBuilder: explain stuurgegevens header structure
  - StufHttpClient: explain WSSE injection and TLS cert loading
  - CircuitBreakerService: explain failure threshold and cooldown
  - ContactBetrokkeneMapper: explain deduplication logic (query before create)

## 11. Verification & Deployment

- [x] 11.1 Run `npm run build` and verify no TypeScript/Vue compilation errors
- [x] 11.2 Verify schemas are correctly registered in OpenRegister instance
- [x] 11.3 Run test suite: `npm run test` or `php -d memory_limit=-1 vendor/bin/phpunit tests/`
- [x] 11.4 Verify endpoint configuration UI renders at `/admin/stuf-endpoints` without errors
- [x] 11.5 Verify audit log UI renders at `/admin/stuf-audit-log` without errors
- [x] 11.6 Verify API routes respond:
  - POST /api/stuf/outbound (admin auth required)
  - POST /api/stuf/inkomend (public)
  - GET /api/stuf/endpoints (admin auth required)
  - GET /api/stuf/messages (admin auth required)
- [x] 11.7 Manual smoke test: create Request, call registerZaak(), verify StufMessage created
- [x] 11.8 Update app version in `appinfo/info.xml` and CHANGELOG.md

## 12. Seed Data Generation Task

- [x] 12.1 Verify seed data in pipelinq_register.json loads on install (via importFromApp pipeline)
- [x] 12.2 Confirm StufEndpoint examples are visible in admin UI after fresh install
- [x] 12.3 Confirm StufMessage and ZaaksysteemMapping examples load (for demo purposes)
- [x] 12.4 Update seed data gemeente codes and system names to match actual test environments (if different from mock data)
