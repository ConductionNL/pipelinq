# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Shillinq time-intake billing handoff** (`time-billing-handoff-emit`): the
  real emit side of the delegated time-approval-workflow. A manager-facing
  "Send to billing" action (`POST /api/billing/handoff/{clientId}`) batches a
  client's approved, un-billed time entries for a period and posts them
  same-instance, in the acting user's session, to shillinq's
  `POST /apps/shillinq/api/billing/time-intake` (`TimeBillingHandoffService`).
  Idempotent via a deterministic UUIDv5 `batchId` (client + period + sorted
  entry ids) so a re-send replays to the same draft invoice
  (`duplicated:true`) instead of double-billing. Outcome is traceable on every
  entry (`billingSyncStatus`/`billingBatchId`/`billingInvoiceId`, new
  `timeEntry` overlay fields); a transient failure notifies administrators and
  `BillingHandoffRetryJob` (TimedJob, 15-min poll) re-notifies outstanding
  failures — the guaranteed re-send is the manual action, re-triggered in
  session context. A 422 (unresolvable `organisationRef`) surfaces the
  unmapped client by name and is never blind-retried. New `client` overlay
  field `shillinqOrganisationRef` maps a client to its shillinq
  customer/organisation. Gated behind an off-by-default
  `shillinq_time_intake_enabled` admin flag; the existing Shillinq deep-link
  remains the fallback when shillinq is absent/disabled or the flag is off.
  Seed archetypes (municipality, consultancy, travel agency) demonstrate the
  un-billed batch, already-billed exclusion, and unmapped-client cases.
- **CRM MCP tool surface** (`crm-mcp-tool-surface`): agent-addressable CRM
  tools for the Nextcloud Hub Assistant / AI Chat Companion, extending
  `PipelinqToolProvider` from 2 to 11 tools. New read tools: `listClients`,
  `searchClients`, `getClient` (with a live 360 summary — open-ticket count,
  open-lead count/value, recent contactmomenten), `listLeads`, `searchLeads`,
  `getLead` (with `qualificationScore`, `weightedValue`, `winProbability`,
  and its activity timeline), and `pipelineForecast` (per-stage totals over
  open leads). New write tools: `createLead` and `logContactmoment`, both
  RBAC-guarded through the existing `ObjectService`/`TicketService` write
  path with `create` authorization enforced. Sovereign, no-per-seat-premium
  alternative to Salesforce/HubSpot/Zoho AI copilots (ConductionNL/pipelinq#342).
- **First-party marketing-email open/click tracking** (`marketing-email-open-click-tracking`):
  a pipelinq-hosted open pixel (`GET /api/blast/track/open/{token}`) and
  click-redirect (`GET /api/blast/track/click/{token}`) so open/click rates
  populate on the base tier even without a webhook-capable ESP. Tokens are
  HMAC-SHA256 signed and PII-free (`TrackingLinkService`, mirroring
  `PortalController::signLink()`); the click endpoint trusts its redirect
  target only after signature verification, so it cannot be used as an open
  redirector. Recorded opens/clicks reuse the existing `blastDelivery`
  fields and `BlastService::updateBlastTotals()` roll-up — no new schema.
  Feature-flagged via `blast.first_party_tracking` (default off; flag off
  preserves today's provider-webhook-only render path byte-for-byte).
- **Lead scoring — win probability** (`lead-scoring-win-probability`): declarative
  `winProbability` calculation on the `lead` schema (`x-openregister-calculations`,
  `materialise: false`) — the lead's stage-denormalised `probability` decayed by
  inactivity (full ≤14 days, 80% ≤30 days, 50% ≤60 days, 25% beyond), recomputed
  fresh on every read so a stalling deal visibly cools with no write. Surfaced
  declaratively on the `LeadDetail` Deal widget and as a colour-banded `Leads`
  index column reusing the existing `lead-probability` cell widget — no new Vue
  component, no service class, no PHP. Seed leads (municipality, consultancy,
  travel agency) span the hot/warm/cold bands.
- **StUF-ZKN/BG adapter** (`stuf-zkn-bg-adapter`): SOAP 1.1 + StUF 0310
  bridge to legacy zaaksystemen (Centric Key2Zaken, Atos PinkRoccade, ...)
  enabling municipalities without ZGW REST APIs to use Pipelinq as a
  modern KCC front-end. Implements the four core ZKN operations
  (`creeerZaak`, `actualiseerZaak`, `geefZaakDetails`,
  `genereerZaakIdentificatie`) plus `vrijeBerichten`. Per-call audit log
  (`StufMessage`), bidirectional mapping (`ZaaksysteemMapping`),
  WSSE UsernameToken + mutual TLS authentication, document base64
  embedding with a 25 MiB ceiling, retry with exponential backoff
  (5s, 30s, 2m, 10m) reusing the same referentienummer for idempotency,
  per-endpoint circuit breaker (4 failures → 5 min cooldown) with
  needs-input escalation to admins. Admin UI under
  `Settings → StUF endpoints` and `Settings → StUF audit log`.
- **BSN-validatie + BRP-lookup (HaalCentraal Personen v2.0)**: complete
  integration with RvIG's HaalCentraal BRP API
  (`bsn-validatie-en-brp-lookup`). Adds five OpenRegister schemas
  (`bsnValidatie`, `brpLookupVerzoek`, `brpPersoon`, `bsnAuditRecord`,
  `optOutVlag`) plus three new contact fields (`verifiedBSN`,
  `brpPersoonId`, `geheimhouding`). Backend services: client-side
  11-proef (`BsnValidationService`, mirrored in `src/services/bsnValidation.js`),
  OAuth2 + mTLS REST client (`HaalCentraalClient`), TTL-based response cache
  with HMAC-verified webhook invalidation (`BrpCacheService` +
  `BrpMutationWebhookListener`), immutable audit-trail with 5-year retention
  and RTBF pseudonymisation (`BsnAuditService`), and opt-out / geheimhouding
  handling (`OptOutService`). REST surface (`BrpController`,
  `BrpAdminController`): lookup, validate, reveal-address, opt-out create,
  webhook, monitor, settings + webhook-secret rotation. Three background
  jobs: daily certificate health-check with admin notifications when expiry
  is within 30 days, daily SLA monitor producing the admin BRP-Monitor tile,
  daily retention sweep that deletes expired `brpPersoon` records and resets
  the linked contact. Vue UI: BSN input + lookup button + Persoon detail
  panel on contact-detail, doelbinding modal in its own file (ADR-004),
  admin BRP-Monitor view at `/admin/brp-monitor`. Dutch + English
  translations (~60 strings). 22 unit tests cover 11-proef, masking,
  SHA-256 hashing, IP anonymisation, webhook HMAC signature verification,
  and the unconfigured-fallback paths. ADR-005-compliant throughout: raw BSN
  is **never** persisted, logged, or returned to the UI; the schemas store
  only the SHA-256 hash, logs and audit records carry the `***XXXX*` mask,
  and `hash_equals()` guards every signature comparison.

- **POS Bookkeeping**: Automated Z-report generation and idempotent submission
  to Shillinq (pos-end-of-day-bookkeeping-post). Adds three OpenRegister
  schemas (`posZReport`, `posJournalEntryOutbound`, `glAccountMapping`),
  `PosBookkeepingService` for the four-stage pipeline (aggregate → stage
  with deterministic SHA256(zReportId+date) idempotency key → POST to
  `/api/JournalEntry` with `X-Idempotency-Key` + Bearer → emit
  CloudEvent), a manager-gated `/api/pos-bookkeeping/post` endpoint, an
  admin settings panel (daily Z-report time, Shillinq endpoint +
  sensitive bearer token, alert email, max retry attempts), a daily
  `GenerateZReportJob` (TimedJob) and an on-demand `PosRetryBackoffJob`
  with 1min/5min/15min/1hr exponential backoff (max 5 attempts; 4xx
  fails terminally + sends alert, 5xx / timeout schedules retry). UI
  ships a "Boekhoudkundige Afhandeling" sidebar entry plus a Z-report
  list + detail with GL line items + submission timeline.
- **Burgerportaal MijnOverheid Berichtenbox bridge**
  (burgerportaal-mijnoverheid-bridge) — connects Pipelinq zaak status
  transitions to the citizen's MijnOverheid mailbox via the Logius
  Berichtenbox-koppelvlak (BBK) 1.7 API, with email fallback after 5 Dutch
  working days unread and inbound-reply ingestion as new Contactmomenten on
  the parent zaak. Ships five new OR schemas (berichtenboxMessage / Reply /
  Template, mailboxResolution, deliveryAuditLog), the BerichtenboxService
  state machine + LogiusConnector (OAuth 2.0 client-credentials, BBK 1.7
  payload validation, RSA-SHA256 PKI-overheid request signing, webhook
  HMAC verification), the EncryptionService for AES-256-GCM BSN crypto
  with HMAC-SHA256 index hashing (constant-time compare via hash_equals
  per ADR-005), DispatchQueuedMessagesJob (5-min, exponential-backoff
  retries) + FallbackEmailJob (daily, DutchHolidayCalendar-aware), a
  zaakafhandelapp event-bus listener, four endpoints (two HMAC-verified
  Logius webhooks + two admin ops endpoints), three seed templates, full
  user/admin documentation at docs/Integrations/berichtenbox-integration.md,
  and 58 unit + 5 integration tests covering BBK 1.7 conformance, the full
  state machine including retry semantics, crypto round-trips with vault
  precedence, the Dutch holiday calendar, and webhook signature handling.

- Marketing segmentation and blast campaigns (marketing-segmentation-and-blast,
  11-slice chain — this entry covers the user-visible feature; slice 10
  ships docs, slice 11 the manual verification + pre-merge review
  checklist):
  - **Rule-based segments:** the `segment` schema and `SegmentService`
    validate and evaluate AND/OR rule trees against contact/customer
    data, estimate audience size with TTL caching, and project the
    per-recipient send list (`getMembersForBlast`). Rules are live
    queries — every send re-evaluates against current data, picking
    up newly-qualifying contacts automatically.
  - **Multi-channel sends:** the `blast` schema and `BlastService`
    orchestrate email and SMS sends through pluggable provider
    connectors (SendGrid, SES, Twilio); `BlastSendJob` drains
    scheduled blasts every 5 minutes; deliveries dispatch with a
    configurable per-second rate limit (default 100/s) to respect
    provider quotas.
  - **Compliance enforcement (GDPR / CAN-SPAM):** `ComplianceService`
    validates every template against per-channel requirements
    (`{{unsubscribe_url}}` merge field, sender address, `STOP`
    keyword for SMS), runs a per-segment consent preflight before
    a blast leaves draft, and routes unsubscribe / complaint webhook
    events into a contact's consent record so a single click on the
    List-Unsubscribe header removes them from every future segment.
  - **A/B testing:** a blast can declare a second template variant
    and a split percentage; `BlastService::sliceMembersForAb` does a
    deterministic hash-bucket split so the same recipient lands in
    the same arm on every re-evaluation; `PerformanceDashboard`
    surfaces the chi-square significance test once both arms have at
    least 100 deliveries.
  - **Revenue attribution:** `AttributionService` records click
    events from tracked links and links blast deliveries to
    downstream deals on a first-click model within a configurable
    attribution window (default 30 days); the **Attribution** tab on
    the performance dashboard reports the attributed deal count and
    summed attributed EUR per blast.
  - **REST surface:** `GET`/`POST /api/segments`,
    `GET /api/segments/{id}`, `GET /api/segments/{id}/members`,
    `POST /api/segments/{id}/size`; `GET`/`POST /api/templates`,
    `GET`/`PATCH /api/templates/{id}`; `GET`/`POST /api/blasts`,
    `GET`/`PATCH /api/blasts/{id}`, `POST /api/blasts/{id}/send`,
    `POST /api/blasts/{id}/cancel`, `GET /api/blasts/{id}/deliveries`,
    `GET /api/blasts/{id}/attribution`; provider webhooks at
    `POST /api/blast-webhooks/sendgrid|ses|twilio`
    (HMAC-verified, `#[PublicPage]`).
  - **Frontend (manifest v2):** new **Marketing** navigation group
    with **Blasts** (list + 6-step wizard with consent-preflight
    modal), **Blast monitor** (live delivery log) and **Blast
    performance** (Overview / A-B / Attribution tabs).
  - **Docs:** user guide at `docs/user/marketing-blasts.md`
    (English) and `docs/user/marketing-blasts.nl.md` (Dutch) — covers
    segment building, compliant template authoring, the send
    workflow, A/B testing, and monitoring / attribution.
  - **i18n:** Dutch and English translations for all marketing
    strings.
- Sales forecast roll-up and categories (forecast-roll-up-and-categories) —
  category-driven forecasting that aggregates existing lead/pipeline data
  (ADR-022) into server-authoritative roll-ups (ADR-005):
  - **Forecast category on deals:** the `lead` schema gains `forecast_category`
    (commit, best_case, pipeline, closed_won, closed_lost, omitted) and
    `commit_justification`. `DealCreatedListener` defaults new leads to
    `pipeline`; `DealUpdatedListener` rejects reopening a closed deal's
    category and enforces a justification (min length) on large commits.
  - **Schemas:** new `forecastSnapshot`, `forecastOverride` and `salesQuota`
    schemas plus seed objects, all shipped as the `register.d/50-forecast.json`
    fragment (ADR-037).
  - **Weekly snapshots:** `ForecastSnapshotJob` generates immutable rep -> team
    -> division -> company snapshots for the open fiscal period, with currency
    normalization, partial-failure tracking and an admin notification on error.
  - **Roll-up math:** `ForecastService`/`ForecastRollupService` compute
    override-applied roll-ups, accuracy scores and trailing-four-quarter
    averages; `QuotaService` resolves quotas and validates the quota hierarchy.
  - **Manager overrides:** managers may override a rep/team/division commit or
    best-case with an audited reason; `ForecastAccessPolicy` gates reads,
    overrides and quota changes (IDOR-safe, manager-group scoped).
  - **REST API:** `GET /api/forecast/snapshots` (JSON/CSV export with
    calculation audit + pagination), `POST`/`DELETE /api/forecast/overrides`,
    and admin-only `GET`/`PUT /api/settings/forecast`.
  - **Frontend (manifest v2):** forecast dashboard, trend view, lead forecast
    tab, and the commit-justification and override modals (in `src/modals/`).
  - **i18n:** Dutch and English translations for all forecast strings.

## [0.2.28] - 2026-06-01

### Security

- POS lifecycle guard adoption (pos-lifecycle-guard-adoption) — closes a
  confirmed IDOR (ADR-005) and removes the bespoke POS state machines (ADR-031):
  - **IDOR fix:** `PosTransactionController::confirm/settle/park/resume` no
    longer drive a transaction on bare session auth. Transitions now route
    through OpenRegister's `TransitionEngine`, which runs per-object
    `LifecycleGuardInterface` guards: confirm/settle/park/resume require the
    transaction's own cashier, a POS-group member, or an admin; a non-owner is
    denied (HTTP 403). Any authenticated user could previously drive any
    transaction by UUID.
  - **Declarative lifecycle:** `posTransaction`
    (draft/parked/confirmed/settled/refunded) and `posRefund`
    (pending/completed/rejected) now declare `x-openregister-lifecycle` in
    `pipelinq_register.json` instead of hand-written PHP `setStatus` +
    `isManager`. The engine enforces the transition table and fires
    `ObjectTransitionedEvent` (audit + notifications).
  - **Guards** (`lib/Lifecycle/`): `PosAccessPolicy` (owner/group/admin +
    manager predicates), `PosTransactionAccessGuard`,
    `PosTransactionConfirmGuard` (access + non-empty cart + server-recompute),
    `PosTransactionRefundGuard` (manager-only), `PosRefundManagerGuard`
    (manager-only + cumulative over-refund cap on `complete`). Registered
    fail-closed in `Application::register()`.
  - **Endpoint hardening:** receipt preview/email/print now assert per-object
    access; the product barcode/price surface is gated to POS operators; the
    cross-object BTW `taxReport` is restricted to POS managers/admins. All
    return HTTP 403 otherwise.
  - Server-authoritative money (computeTotals / proportional refund recompute)
    and the over-refund cap are preserved as the documented ADR-031 guard
    exception. The hand-rolled `isManager` and manual status mutation are
    removed from both services.

## [0.2.27] - 2026-05-31

### Added

- POS refund + return (pos-refund-return, builds on pos-transaction-core + pos-nl-btw-engine):
  - New OpenRegister schemas `refundReason` (DefinedTerm), `posRefund`
    (schema:Order, type=refund) and `posRefundLine` (schema:OrderItem,
    type=refund-line) with seed data (6 reasons, 4 refunds, 5 refund lines).
  - `PosRefundService` computing every refund amount server-side from each
    original `posTransactionLine`'s persisted tax (proportional, ratio clamped
    to [0,1]); client-supplied amounts are never trusted.
  - Cumulative over-refund cap: confirm sums all already-completed refunds for
    the same transaction and rejects when the running total would exceed the
    original transaction total.
  - Manager-gated (fail-closed) confirm / reject lifecycle; rejected and
    completed refunds are immutable.
  - Fire-and-forget CloudEvents on confirm: `pipelinq.TransactionRefund.completed`
    (accounting reversal with negative amounts) and per-restocked-line
    `shillinq.StockMovement` (positive quantity); silent no-op when no consumer.
  - IDOR-safe `PosRefundController` (`POST /api/pos-refunds/{id}/confirm|reject`,
    authenticated, scoped to this app's own posRefund schema).
  - Refund list / detail / form views with original-line selection, partial
    quantities, per-line reason + restock toggle, real-time refund totals; an
    isolated reject dialog; a "Retouren" navigation entry; and a
    "Retour registreren" button on the transaction detail.
  - Dutch + English translations; `PosRefundServiceTest` (16 tests) covering
    proportional refunds, the over-refund cap (incl. cumulative), the
    manager-gate fail-closed, reject-without-events, and the reversal/stock
    event shapes.

### Fixed

- Removed a dead `is_array()` guard in `ProductCatalogService` flagged by PHPStan.

## [0.2.26] - 2026-05-31

### Added

- POS barcode scanning (pos-barcode-scan, builds on pos-product-catalogue):
  - `useBarcodeScanner` composable: HID keyboard-wedge detection (rapid-burst
    + Enter terminator; ≥4 chars and ≤50 ms average inter-key delay; never
    hijacks ordinary form typing; buffer resets after 200 ms idle) and camera
    scanning via the browser `BarcodeDetector` API (feature-detected, graceful
    HID-only fallback, stream torn down on scan/unmount). The HID timing logic
    is a pure, unit-testable `createHidBufferReducer`.
  - `useBarcodeProductLookup` composable: validates the untrusted scanned value
    then resolves it through the IDOR-safe server endpoint, mapping the response
    to `{ product, variant, variantIndex, status }`.
  - Unified `BarcodeScanner.vue` (always-visible HID input, feature-detected
    camera button + viewfinder overlay, loading/found/error status) wired into
    `ProductBarcodeSearch.vue` with found/not-found/ambiguous handling, variant
    query passthrough, and a 5-second auto-clearing empty state.
  - nl + en translations for all scan-feedback strings.
  - 5 Dutch seed products with EAN-13 barcodes (incl. variant-level barcodes).

### Changed

- `ProductCatalogService::lookupByBarcode` now resolves variant barcodes to a
  zero-based `variantIndex` (parent product + variant), excludes inactive
  variants from scan results, preserves top-level barcode priority, and
  validates the scanned (untrusted) barcode for length + charset before any
  lookup. The `POST /api/products/barcode-lookup` response now returns
  `{ product, variantIndex }`.

### Security

- Scanned barcodes are treated as untrusted input: rejected unless non-empty,
  ≤ 64 chars, and within the `[A-Za-z0-9 .-]` charset — on both the client and
  the server — before any lookup. Lookups remain scoped to the app's own
  register + product schema (no IDOR).

## [0.2.25] - 2026-05-31

### Added

- POS Receipt Engine (builds on pos-transaction-core / pos-nl-btw-engine):
  - `receiptTemplate` and `receiptPrintLog` OpenRegister schemas (customizable
    header/footer/company/layout templates; append-only audit log of every print/email).
  - `ReceiptService` renders a transaction to plain-text, HTML and an ESC/POS thermal
    byte stream, reusing the persisted `invoiceBreakdown` for BTW lines (tax is never
    re-derived). Template rendering is injection/SSRF-safe (no expression evaluation).
  - Legal-invoice variant auto-selected for sales ≥ EUR 100: full per-rate BTW breakdown
    plus a Dutch compliance footer (VAT id, KvK, invoice number, dates).
  - `InvoiceSequenceService` allocates gap-free, race-safe, non-forgeable sequential
    legal invoice numbers (`YYYY-NNNNNN`, compare-and-set, year reset).
  - `ReceiptDeliveryService` + `PosReceiptController` expose per-transaction
    `receipt/preview`, `receipt/email` and `receipt/print` endpoints (authenticated,
    app-register-scoped — no IDOR; email constrained to the linked customer — no spam).
  - Frontend: isolated `PrintReceiptModal` / `EmailReceiptModal` + `ReceiptPreviewPane`,
    wired into the POS transaction detail view; nl + en translations.

### Security

- Legal invoice numbers are server-allocated and verified against the immutable
  receipt audit log, so a client-forged `invoiceNumber` on a transaction cannot be
  honoured.

### Fixed

- Cleared a pre-existing PHPStan strict-comparison finding in `ProductCatalogService`
  and a `vue/no-reserved-keys` ESLint error in `LeadDetail.vue`.

## [0.2.24] - 2026-05-31

### Added

- POS NL BTW engine (builds on pos-transaction-core / pos-product-catalogue):
  - Per-rate `invoiceBreakdown` array on `posTransaction` with Dutch GL descriptions
    (`Nultarief (0%)` / `Verlaagd tarief (9%)` / `Standaardtarief (21%)`) that shillinq
    consumes to post one GL line per BTW rate; included in the confirmed CloudEvent.
  - `GET /api/pos-transactions/tax-report` — per-rate BTW compliance report aggregating
    every fiscally-final transaction (confirmed/settled/refunded) and netting out refunds,
    optionally filtered by `?status=`.
  - End-to-end tax-inclusive vs tax-exclusive pricing via a `priceMode` field (`excl` default).
    In `incl` mode the net base is extracted out of the entered price
    (`net = gross / (1 + rate/100)`); the per-rate base is always tax-exclusive so the GL
    split is identical regardless of entry mode. Server-authoritative — client totals are
    never trusted; `taxRate` is clamped 0–100 and `priceMode` is allow-listed.
  - `TaxBreakdownCard.vue` showing the tax summary (Belastingaangifte) and invoice
    breakdown (Factuurverdeling) tables on the transaction detail; price-mode toggle on the
    form and price-mode label on detail/totals.
  - Mixed-rate seed transactions (0%/9%/21%, incl. one `incl`-mode cart) and 9 line items.

### Changed

- `PosTransactionService::recalculateLine` / `computeTotals` now accept a `priceMode` and
  emit `net` + `invoiceBreakdown`; the confirmed CloudEvent payload carries `invoiceBreakdown`
  and `priceMode`.

## [0.2.23] - 2026-05-31

### Added

- POS product catalogue: extended the `product` schema into a POS-grade product
  master while keeping the flat CRM fields backward-compatible. New optional
  properties: `barcode` (EAN/UPC, `schema:gtin`), `btwClass` (Dutch BTW class
  enum `hoog`/`laag`/`nul`/`vrijgesteld`, facetable), `duration` (service
  minutes), `variants` (size × colour matrix with per-variant SKU, price
  override, barcode and status), `modifierGroups` (configurable add-ons with
  per-option price adjustments), and `priceTiers` (quantity-break pricing).
- `ProductCatalogService` — server-authoritative catalogue resolution: BTW
  class → tax-rate mapping (fail-closed to 21%), effective unit-price resolution
  across quantity tiers and per-variant overrides, variant SKU uniqueness, and a
  barcode lookup scoped to this app's own register + product schema (IDOR-safe).
- `ProductCatalogController` endpoints `POST /api/products/barcode-lookup` and
  `POST /api/products/resolve-price` with per-user authentication and
  app-schema-scoped access; effective price and BTW rate resolved server-side,
  never trusted from the client.
- Product frontend: variant matrix editor (`ProductVariantPanel` + isolated
  `ProductVariantDialog`), `ModifierGroupPanel`, `PriceTierTable`, BTW-class
  selector with `taxRate` auto-sync, barcode + duration fields, and a
  scan-to-navigate `ProductBarcodeSearch` view backed by the scoped lookup API.
- Five Dutch product seed objects demonstrating variants, modifier groups,
  price tiers, the `vrijgesteld` BTW class and service duration.
- Dutch + English translations for all new catalogue UI strings.

## [0.2.22] - 2026-05-31

### Added

- POS transaction core (Kassabon): two OpenRegister schemas — `posTransaction`
  (`schema:Order`) with lifecycle `draft → parked → confirmed → settled →
  refunded`, server-computed subtotal, total discount, per-BTW-rate tax
  breakdown and grand total; and `posTransactionLine` (`schema:OrderItem`) with
  quantity, discount and computed `taxAmount`/`lineTotal`. Includes seed data.
- `PosTransactionService` with server-authoritative total/tax calculation,
  lifecycle transitions (confirm/settle/refund/park/resume) and a
  `pipelinq.PosTransaction.confirmed` CloudEvent emitted fire-and-forget to
  Shillinq on confirmation. Refund is manager-gated (admin or configured
  `pos_manager_group`, fail-closed).
- `PosTransactionController` lifecycle endpoints under `/api/pos-transactions/{id}/*`
  with per-user authentication and app-schema-scoped object access (IDOR-safe).
- POS frontend: list, detail (context-sensitive lifecycle actions, per-rate tax
  breakdown, totals; isolated refund dialog) and a cart editor with real-time
  totals; "Kassabon" navigation entry. Dutch + English translations.

## [0.2.20] - 2026-05-31

### Added

- Consume the OpenRegister time-tracker leaf (`integration-time-tracker`) for
  hour capture against clients, leads and requests (hydra ADR-022): add
  `time-tracker` to the `linkedTypes` of the `client`, `lead` and `request`
  schemas, place the leaf's `CnTimeTrackerTab` + `CnTimeTrackerCard` on those
  detail pages via the app manifest (ADR-024), and declare the `timemanager`
  runtime dependency. No bespoke time subsystem is introduced; approval and
  invoicing remain out of scope (handed to shillinq).

## [0.2.21] - 2026-05-31

### Added

- Delegate the timesheet approval + invoicing lifecycle to shillinq
  (`time-approval-workflow`, hydra ADR-022): declare shillinq as the owner of
  submit → approve → reject → lock → edit-request → invoice in the OpenSpec
  coordination manifest (`openspec/manifest.yaml`: soft delegation dependency +
  `consumes: invoice-from-time-and-expense`), and surface a footer deep-link to
  shillinq's billing/approval surface in `src/manifest.json`. Captured hours
  remain reachable by shillinq via the existing time-tracker OR links; Pipelinq
  builds no timesheet schema, service, controller, view, or approval state. The
  pipelinq → shillinq approval CloudEvent emit is deferred until shillinq ships
  an approval/invoice route or event consumer.

## [0.2.19] - 2026-05-31

### Changed

- Completed the PHPStan burn-down, CI-integration, and documentation slice
  (`pipelinq-quality-phpstan-burndown`, split from `pipelinq-legacy-quality-cleanup`
  per ADR-032). PHPStan runs clean at level 5; the `phpstan-baseline.neon` was
  resynced to drop seven stale entries (three unused constants now wired up, a
  CsrfTokenManager invalid-type, and two logic warnings) that no longer fire,
  leaving 22 intentional, documented tracked-debt entries (issue #496).
- Pinned the strict static-analysis gate toggles (phpcs/phpmd/psalm/phpstan/
  phpmetrics/frontend/eslint) explicitly in `.github/workflows/code-quality.yml`
  so the strict-gate posture is self-documenting and cannot regress if the
  shared `ConductionNL/.github` workflow defaults change.
- Documented the strict gate suite (incl. `composer check:strict`), the
  per-PR + weekly-cron CI wiring, and the completed legacy-quality cleanup in
  the README.

### Added

- Weekly smoke-test cron (`code-quality.yml`, Mondays 06:00 UTC) that re-runs
  the full strict gate suite against `development` to catch drift between PRs.

## [0.2.18] - 2026-05-31

### Added

- OpenSpec coordination manifest at `openspec/manifest.yaml` (Phase 8 of the
  manifest + i18n + tenancy slice). Declares `tier: 3` (frontend exemplar),
  `dependencies: ["openregister"]`, the six consumed shared specs
  (`contacts-actions`, `register-resolver-service`, `pluggable-integration-registry`,
  `i18n-source-of-truth`, `i18n-api-language-negotiation`, `multi-tenancy-context`),
  the OR `min-version: "1.0.2"` pin, and the `object-store-exemplar` role.
  Phase 9 runtime adoption of multi-tenancy + i18n is deferred until the
  nextcloud-vue and OpenRegister prerequisites ship. Per Hydra `adopt-app-manifest`
  / ADR-024.
- Calculation annotations (`x-openregister-calculations`) on the `lead` schema,
  declaring derived/computed fields per ADR-031 instead of service code:
  `qualificationScore` (backend score 0-100, `materialise: true`, mirrors the
  lead-management default scoring criteria — resolves the spec's frontend-vs-backend
  open question in favour of a backend calculation), `daysSinceActivity`
  (staleness, virtual, diffs `@self.updated` against `now`), `daysInStage`
  (aging, virtual, diffs `stageEnteredAt`/`@self.created` against `now`), and
  `weightedValue` (`value * probability / 100` for the Pipeline Value KPI).
  Validated clean against OpenRegister's `CalculationAnnotationValidator`.
- Backing lead properties: `qualificationScore` (integer), `stageEnteredAt`
  (date-time, aging input), and `description` (scoring input).
- Archival retention annotations (`x-openregister-archival.retention`) on
  `kennisartikel` (`P7Y` archived knowledge-base versions), `task` (`P2Y`
  completed task / callback history), and `contactmoment` (`P2Y` resolved
  client contact log). Each carries condition-based rules with reasons;
  DPO sign-off on exact periods is pending. Validated clean against
  OpenRegister's `ArchivalAnnotationValidator`.
- Unit tests asserting both annotation families are well-formed
  (`RegisterAnnotationsTest::testCalculationAnnotationsAreWellFormed` and
  `testArchivalAnnotationsAreWellFormed`).

### Changed

- `lead-management` spec: the Lead Qualification Scoring requirement now states
  the score is a backend `x-openregister-calculations.qualificationScore`
  materialised on save, read by the frontend.
- Moved eleven hardcoded magic-number/URL constants to admin-config (Phase 7 of
  OR-adoption), all with behavior-preserving defaults: background-job timing
  (`pipelinq.queue_overflow.poll_interval_seconds`,
  `pipelinq.task_expiry.poll_interval_seconds` / `.escalation_threshold_seconds`
  / `.in_progress_grace_seconds`, `pipelinq.task_escalation.threshold_hours`),
  business hours (`pipelinq.task.business_hour_start` / `.business_hour_end`),
  prospect-discovery cache TTL (`pipelinq.prospect_discovery.cache_ttl_seconds`),
  and the KVK / OpenCorporates API base URLs (`pipelinq.kvk.api_base_url`,
  `pipelinq.opencorporates.api_base_url`) so EU/regional tenants can point at
  alternate endpoints. The API-URL keys are admin-only (written via the
  `#[AuthorizedAdminSetting]`-gated SettingsController), so no SSRF surface is
  introduced. `SettingsService` gained a `TUNABLE_DEFAULTS` registry plus typed
  `getIntValue`/`getStringValue` getters. (The audit's cited
  `KennisbankReviewJob` review-interval constant no longer exists — kennisbank
  was migrated to XWiki — so nothing was migrated for it.)
- `contacts-sync` spec rewritten to consume OpenRegister's `contacts-actions`
  integration provider (`ContactMatchingService`, registered via
  `pluggable-integration-registry`) for contact matching/scoring instead of a
  bespoke algorithm; added a graceful-degradation requirement for when the
  provider is absent (matching is skipped, write-back/import still complete).
- `lead-management` spec: cited the `x-openregister-calculations.weightedValue`
  annotation on the Pipeline Value reporting scenario and resolved the stale
  "frontend vs backend scoring" open question in favour of the OR calculation
  engine; field-table enums (`source`, `priority`) intentionally retained.
- `pipelinq-or-adoption` change spec: declared `src/store/modules/object.js` as
  the canonical `createObjectStore` exemplar (no code change — the live file
  already matches the declared plugin list), cross-linked the
  `openregister-integration` exemplar spec, and corrected the `adr-000`
  reference to its real path `openspec/architecture/adr-000-data-model.md`.
- Archived openspec change `pipelinq-or-spec-rewrites`
  (`openspec/changes/archive/2026-05-31-pipelinq-or-spec-rewrites/`).

## [0.2.17] - 2026-05-31

### Added

- Lifecycle annotations (`x-openregister-lifecycle`) on the `lead`, `request`,
  `complaint`, `calendarLink` and `kennisartikel` schemas (the `task` schema
  already carried one). Each declares `field` / `initial` / `final` plus
  per-transition `from` / `to`, a human-readable `description` (state-transition
  rule) and an `authorization` block (assignee/author field + optional sales
  group). The kennisartikel `visibility` enum (`intern` / `openbaar`) stays a
  separate orthogonal field, not part of the lifecycle.
- Notification annotations (`x-openregister-notifications`) with `transition`
  triggers on `lead` (`win` → leadWon, `lose` → leadLost), `task`
  (`complete` → taskCompleted, `expire` → taskExpired), `request`
  (`complete` → requestCompleted) and `complaint` (`resolve` →
  complaintResolved). Every `trigger.action` key equals a lifecycle transition
  **name** (not a destination state) so OpenRegister's
  AnnotationNotificationDispatcher matches it against
  `ObjectTransitionedEvent::getAction()`.
- `RegisterAnnotationsTest` — asserts every lifecycle annotation is well-formed
  (field/initial/transitions resolve to declared enum states, each transition
  documented) and that every notification transition `action` resolves to a
  declared transition name and never to a destination state.

### Notes

- Behaviour-preserving: on-wire status values are unchanged and the existing
  imperative `NotificationService::send()` and `ActivityService` paths are
  retained. The new transition-triggered notification rules stay **dormant**
  until pipelinq routes its status changes through OpenRegister's
  `TransitionEngine` (today status is written directly via `saveObject`, which
  does not dispatch `ObjectTransitionedEvent`). `ActivityService::setSubject()`
  is intentionally not migrated — it feeds the Nextcloud Activity stream, a
  surface the notification-annotation runtime does not replace.
- Implements openspec change `pipelinq-or-lifecycle-notification` (Phase 2
  lifecycle + Phase 3 notification annotation migration).

## [0.2.16] - 2026-05-31

### Changed

- Introduced `RegisterResolverService` and migrated the eight
  `$appConfig->getValueString(APP_ID, 'register', '')` call sites in
  `QueueService` (4), `DefaultQueueService` (2), `ContactVcardService` (1) and
  `ContactVcardWriterService` (1) to `RegisterResolverService::resolve(...)`.
  Behavior-preserving: every logical name resolves to the same instance-scoped
  `register` config value, now request-scoped memoised. Phase 1 of the
  pipelinq OR-abstractions adoption (openspec change
  `pipelinq-or-register-resolver`). 16 register reads in 11 other files remain
  for a follow-up slice.
- PHPMD burn-down: cleared all 36 above-baseline PHPMD violations via
  behavior-preserving refactors — extracted methods to cut CyclomaticComplexity /
  NPathComplexity / ExcessiveMethodLength across PublicSurveyController,
  PublicFormController, SchedulesController, ActivityTimelineService,
  RoutingService and ScheduledTaskService; renamed short variables; added missing
  `use` imports; reshaped `if/else` to early-returns; converted
  `neutralizeCsvCell` array-callables to first-class callables to clear the
  UnusedPrivateMethod false positive; documented residual class-level
  complexity/coupling with `@SuppressWarnings`. `composer phpmd` now exits 0
  above baseline.

### Notes

- The PHPMD baseline (`phpmd.baseline.xml`) is intentionally retained: it still
  suppresses 21 pre-existing violations in files outside this slice's scope.
  Deleting the baseline and dropping `--baseline-file` is deferred to a follow-up
  change `pipelinq-quality-phpmd-baseline-empty`.
