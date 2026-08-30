# Tasks — Pipelinq Product & Vendor Master

## Phase 0: Deduplication Check (ADR-012)

- [x] Confirm pipelinq already owns a canonical `product` schema — VERIFIED in `lib/Settings/pipelinq_register.json` (~line 1195): name, sku, unitPrice, cost, category, type, status, unit, taxRate, btwClass, barcode, variants, modifierGroups, priceTiers, image. Accompanied by `productCategory` and `leadProduct`.
- [x] Confirm the MDM fragment already extends `product` — VERIFIED `lib/Settings/register.d/90-master-data-management.json` adds `masterEntityRef`/`isMasterRecord` to `product` (and `contact`/`client`). → we extend, not duplicate.
- [x] Confirm pipelinq has NO concrete supplier/vendor schema — VERIFIED only `masterEntity` with `entityType: vendor` exists (a golden-record envelope, not an operational supplier profile). → `supplier` is genuinely new.
- [x] Confirm the contact-sync pattern exists — VERIFIED `openspec/specs/contacts-sync/spec.md`, `lib/Service/ContactVcardService.php`, `ContactSyncController`, `contactsUid` on `client`/`contact`. → supplier identity reuses it; no new sync engine, no party schema.
- [x] Confirm cross-app dispatch uses OR WebhookService / integration registry — VERIFIED `30-expense-shillinq-ap.json`, `60-project-ledger.json`, `90-time-wip.json`, and `contacts-sync` consuming `pluggable-integration-registry`. → publish read surface via ADR-019 registry, no bespoke HTTP (ADR-022).
- [x] Conclusion: EXTEND `product` (1 fragment), ADD `supplier` (1 fragment), declare 1 integration provider, add 1 ingest repair. No capability re-implemented.

## Phase 1: Extend the product master (REQ-PVM-001, 002)

- [x] Add `lib/Settings/register.d/92-product-supply-master.json` extending the `product` schema with supply-side master-data fields: `productId`, `gtin`, `manufacturer`, `unitOfMeasure`, `weight`, `dimensions`, `hazardClass`, `preferredSupplier`, `stockTracked`, `consumableBy`. (commit 9d8295ae)
- [x] Assert `productId` defaults to the OR object UUID; the ingest fix-up sets `productId = object UUID` on create so the FK never drifts.
- [x] Register the fragment's schema list under the `pipelinq` register (deep-merge fragment loader, ADR-037); existing `product` fields untouched.
- [x] Verify `leadProduct`, POS fragments, and the MDM extension still resolve against the same `product` slug (no second register) — confirmed, single `product` slug.

## Phase 2: Add the supplier commercial master (REQ-PVM-003, 004)

- [x] Add `lib/Settings/register.d/91-supplier-commercial-master.json` defining the new `supplier` schema keyed by `contactsUid`: `supplierId`, `displayName`, `category`, `status`, `termsOfTrade`, `leadTimeDays`, `catalog[]`, `preferred`, `masterEntityRef`, `notes`. NO financial fields. (commit 9d8295ae)
- [x] Register `supplier` under the `pipelinq` register — added `supplier` slug to `SettingsLoadService::SCHEMA_SLUGS` and `supplier_schema` to `SettingsService::CONFIG_KEYS` (2026-06-15 fix; the config key was previously never populated on import).
- [x] Wire supplier identity to contact-sync: `IngestProductVendorMaster::resolveOrCreateContact()` resolves/creates the NC contact via `ContactVcardService` and stores `contactsUid`; `displayName` denormalised from the contact.
- [x] Add a `Suppliers` nav entry + page bound to schema `supplier` in `src/manifest.d/91-suppliers.json` (declarative list/detail, no bespoke controller).

## Phase 3: Consumption interface via integration registry (REQ-PVM-005, 006)

- [~] Declare the product + supplier master as an integration provider on OpenRegister's `pluggable-integration-registry` (ADR-019). DEFERRED — the provider service implements the handshake (`getProduct`/`resolveSupplier`) and announces a `PROVIDER_SLUG`, but the *active* registry announcement requires the OR `pluggable-integration-registry` provider-registration API and the counterpart `shillinq-product-vendor-to-pipelinq` consumer, neither of which exists yet. Service is consumable today via direct DI.
- [x] Implement a thin `lib/Service/ProductVendorProviderService.php` for the registry handshake only (no CRUD wrapper, ADR-022); projects a master view masking CRM-private `cost` unless the consumer is authorised. (commit 5a2a11ef; register-config-key bug fixed 2026-06-15)
- [x] Specify the graceful-degradation contract (provider absent → null → consumer falls back to cached FK + warning) — documented in the service header + covered by unit tests.
- [x] Document `productId` and `contactsUid` as the stable FK keys shillinq references (service header CROSS-APP CONTRACT #1).

## Phase 4: Ingest migration from shillinq (REQ-PVM-007, 008)

- [x] Add `lib/Repair/IngestProductVendorMaster.php` (idempotent) that accepts the `shillinq-product-vendor-to-pipelinq` export. (commit edd5f7bf; registered in `appinfo/info.xml` 2026-06-15)
- [x] Products: match on `sku`/`barcode`; fill-only empty master fields (never overwrite pricing); create when missing; preserve unmapped fields under MDM `sourceRecord.rawAttributes`. Returns `{shillinqRef → productId}`. (unit-tested)
- [x] Vendors: resolve/create an NC contact → `contactsUid`; create/fill a `supplier`; route financial fields back as the shillinq AP payload. Returns `{shillinqVendorRef → contactsUid}`. (unit-tested)
- [x] Write one MDM `sourceRecord` per ingested object (`sourceSystem: shillinq-products` / `shillinq-vendors`).
- [x] Emit the two FK maps; persisted to app-config keys `shillinq_pvm_product_map` / `shillinq_pvm_vendor_map` for shillinq to read.

## Phase 5: Verification

- [x] `openspec validate` — spec delta is well-formed (`ADDED Requirements` with scenarios); validated by structure inspection (no openspec CLI in this env).
- [x] Hydra gates pass (all 24 green; gate-18 notification-dialect WARNING is pre-existing advisory, unrelated to this change).
- [x] Confirm CRM/POS unaffected: single `product` slug, additive `visible:false` fields, existing fragments intact.
- [~] Round-trip with the counterpart change: DEFERRED — blocked on `shillinq-product-vendor-to-pipelinq` (not yet built). Ingest + FK-map emission are unit-tested in isolation against a synthetic export.
