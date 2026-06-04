# Proposal: ZGW API (Zaakgericht Werken) REST bridge

## Problem

Pipelinq's StUF-ZKN/BG adapter provides integration into the legacy SOAP estate of Dutch municipalities. However, the industry has migrated to the modern zaakgericht-werken (ZGW) stack: a set of OAuth2-secured REST APIs published by VNG Realisatie that every modern zaaksysteem (OpenZaak, RxMission, Decos JOIN ZGW-edition, Roxit Squit20/20 ZGW, etc.) implements. Three gaps persist:

1. **No first-class ZGW support** — Pipelinq lacks a native REST bridge to ZGW components (ZRC, DRC, BRC, ZTC, AC, NRC), forcing municipalities with modern zaaksystemen to either maintain dual integrations or forgo Pipelinq's benefits in ZGW-only deployments.

2. **No event-driven intake path** — The StUF adapter is request-response only; modern ZGW workflows rely on NRC (Notificaties Routerings Component) for real-time zaak lifecycle updates. Without NRC subscription and event handling, Pipelinq cannot keep Request status in sync with ZGW.

3. **Migration lock-in** — Municipalities transitioning from StUF to ZGW must choose one integration per gemeente/domain, with no clear coexistence path. A gemeente cannot run both backends during migration without creating duplicate zaak registrations.

## Solution

Implement a first-class ZGW API bridge that:

1. **JWT authentication** — Mint per-request JWTs per VNG-API-Common, handling client credentials from the vault, token claims, and clock-skew detection.

2. **Five-component client layer** — Implement typed resource clients for ZRC (zaken, statussen, rollen, zaakobjecten, resultaten, zaakinformatieobjecten), DRC (enkelvoudiginformatieobjecten with inline/multipart upload), BRC (besluiten, besluitinformatieobjecten), ZTC (zaaktypen, statustypen, roltypen, resultaattypen, besluittypen with caching), and AC (scope discovery and enforcement).

3. **Event-driven NRC integration** — Register NRC abonnement on first endpoint activation, maintain subscription in sync with configured kanalen/filters, and dispatch inbound notifications to per-kanaal handlers that update Request status and trigger workflows.

4. **Safe coexistence with StUF** — Enforce that exactly one of (StufEndpoint, ZgwEndpoint) is marked as the active write path per gemeente/domain. Detect double-registration attempts and block them before commit.

5. **Optimistic concurrency** — Capture ETags on read, use them on PATCH, surface 412 errors as exceptions without auto-retry.

## Scope

- Four register entities: `ZgwEndpoint`, `ZgwClient`, `NrcAbonnement`, `ZgwResourceMapping`
- JWT minting service per VNG-API-Common (HS256, required claims, 60s clock leeway)
- Typed clients for ZRC, DRC, BRC, ZTC, AC resource operations
- NRC subscription lifecycle: register on endpoint activation, maintain via NRC notifications
- ZTC caching layer (1h TTL, cache-invalidation on "catalogi" kanaal notifications)
- AC scope cache (15m refresh interval, pre-flight guards on operations)
- ETag capture and If-Match PATCH semantics
- Rol mapping: pipelinq Contact ↔ ZGW rol (betrokkeneIdentificatie for BSN/RSIN)
- Double-write prevention: flag when both stuf-zkn-bg-adapter and zgw-api-bridge are set write="on" for the same gemeente/domain
- Coexistence configuration: per-gemeente endpoint list with write="on"/"read="on" booleans
- 10+ seed ZgwEndpoint, ZgwClient, NrcAbonnement, ZgwResourceMapping objects with Dutch gemeente/component examples

**Depends on:** stuf-zkn-bg-adapter (for coexistence rules; no strict dependency), openregister (vault, webhook service, audit), openconnector (optional source/job gateway mapping)

## Affected Projects

- **pipelinq** (primary: bridge implementation, ZgwEndpoint/ZgwClient/NrcAbonnement/ZgwResourceMapping management, Request sync on NRC events)
- **openregister** (audit log for ZGW API calls, webhook service for NRC inbox)
- **openconnector** (optional: expose bridge as gateway source-mapping)

## Out of Scope

- Reverse sync (ZGW → pipelinq auto-discovery of zaaktypen)
- Bulk retroactive migration of existing StUF zaak references to ZGW URLs
- Unauthenticated NRC callback (callback auth is Bearer token per abonnement)
- Multi-region ZGW cloud deployments (single-endpoint per ZgwEndpoint assumed)
- Dutch API Strategie compliance audit (assumed via VNG Compliance Test Platform separate effort)

## Impact

- **Affected code**: New services in `lib/Service/ZgwApiClient.php`, `lib/Service/ZrcClient.php`, `lib/Service/DrcClient.php`, `lib/Service/BrcClient.php`, `lib/Service/ZtcClient.php`, `lib/Service/AcClient.php`, `lib/Listener/NrcNotificationListener.php`, ZgwEndpoint/ZgwClient/NrcAbonnement/ZgwResourceMapping schema definitions.
- **Request entity**: New foreign keys `ZgwResourceMapping` for bidirectional zaak/Request linking; no breaking changes to existing fields.
- **Breaking changes**: None — StUF adapter coexists unchanged; gemeente-level configuration determines write path.
- **Database migrations**: Four new register schemas (ZgwEndpoint, ZgwClient, NrcAbonnement, ZgwResourceMapping).

## Success Criteria

- ZgwEndpoint can be created via admin UI or API with all component URLs, clientId, and write="on"/"read="on" toggles
- createZaak flow: 1. Resolve zaaktype from ZTC via omschrijving. 2. Check AC scopes (zaken.aanmaken). 3. Mint JWT. 4. POST /zaken, capture Location as zgwUrl, create ZgwResourceMapping.
- attachDocument flow: For small files (≤4 MiB inline threshold) POST to DRC with base64 inhoud; for large files use multipart POST→bestandsdelen[]→PUT per part→unlock protocol.
- createBesluit flow: Resolve besluittype from ZTC, check AC scopes, POST /besluiten, optionally link EIO via besluitinformatieobjecten.
- NRC subscription: Register abonnement on endpoint activation, receive zaak.statusGewijzigd notifications, GET status URL from ZTC cache, update Request.status within 5s.
- Double-write prevention: Detect if both StufEndpoint and ZgwEndpoint are write="on" for same gemeente/domain, raise DoubleWritePathException, display needs-input event to beheerder.
- ETag semantics: Capture etag on GET, send If-Match on PATCH, handle 412 with OptimisticLockException carrying stale + fresh representations.
- Clock-skew detection: Catch 403 JWT-timing errors, raise ClockSkewException with observed timestamps, no automatic retry.
- AC scope validation: Pre-flight guards block createZaak/createBesluit/attachDocument if client lacks required scope on zaaktype/informatieobjecttype/besluittype.
