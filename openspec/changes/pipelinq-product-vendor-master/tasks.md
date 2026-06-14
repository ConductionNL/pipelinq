# Tasks — Pipelinq Product & Vendor Master

## Phase 0: Deduplication Check (ADR-012)

- [x] Confirm pipelinq already owns a canonical `product` schema — VERIFIED in `lib/Settings/pipelinq_register.json` (~line 1195): name, sku, unitPrice, cost, category, type, status, unit, taxRate, btwClass, barcode, variants, modifierGroups, priceTiers, image. Accompanied by `productCategory` and `leadProduct`.
- [x] Confirm the MDM fragment already extends `product` — VERIFIED `lib/Settings/register.d/90-master-data-management.json` adds `masterEntityRef`/`isMasterRecord` to `product` (and `contact`/`client`). → we extend, not duplicate.
- [x] Confirm pipelinq has NO concrete supplier/vendor schema — VERIFIED only `masterEntity` with `entityType: vendor` exists (a golden-record envelope, not an operational supplier profile). → `supplier` is genuinely new.
- [x] Confirm the contact-sync pattern exists — VERIFIED `openspec/specs/contacts-sync/spec.md`, `lib/Service/ContactVcardService.php`, `ContactSyncController`, `contactsUid` on `client`/`contact`. → supplier identity reuses it; no new sync engine, no party schema.
- [x] Confirm cross-app dispatch uses OR WebhookService / integration registry — VERIFIED `30-expense-shillinq-ap.json`, `60-project-ledger.json`, `90-time-wip.json`, and `contacts-sync` consuming `pluggable-integration-registry`. → publish read surface via ADR-019 registry, no bespoke HTTP (ADR-022).
- [x] Conclusion: EXTEND `product` (1 fragment), ADD `supplier` (1 fragment), declare 1 integration provider, add 1 ingest repair. No capability re-implemented.

## Phase 1: Extend the product master (REQ-PVM-001, 002)

- [ ] Add `lib/Settings/register.d/92-product-supply-master.json` extending the `product` schema with supply-side master-data fields: `productId` (canonical UUID key), `gtin`, `manufacturer`, `unitOfMeasure`, `weight`, `dimensions`, `hazardClass`, `preferredSupplier` (`contactsUid`), `stockTracked`, `consumableBy` (default `["shillinq"]`). All additive, `visible:false` by default.
- [ ] Assert `productId` defaults to the OR object UUID (declarative default per ADR-031) so the FK shillinq stores never drifts.
- [ ] Register the fragment's schema list under the `pipelinq` register; verify no override clobbers existing `product` fields (variants/priceTiers/btwClass intact).
- [ ] Verify `leadProduct`, POS fragments, and the MDM extension still resolve against the same `product` slug (no second register).

## Phase 2: Add the supplier commercial master (REQ-PVM-003, 004)

- [ ] Add `lib/Settings/register.d/91-supplier-commercial-master.json` defining the new `supplier` schema keyed by required `contactsUid`: `supplierId`, `displayName`, `category`, `status`, `termsOfTrade`, `leadTimeDays`, `catalog[]` (`productId`,`supplierSku`,`listPrice`,`currency`,`moq`), `preferred`, `masterEntityRef`, `notes`. NO financial fields (IBAN/credit/payment-method stay in shillinq AP).
- [ ] Register `supplier` under the `pipelinq` register; add icon/title; mark `category`/`status`/`preferred` facetable.
- [ ] Wire supplier identity to the existing contact-sync: on create/update, ensure the linked NC contact exists via `ContactVcardService` and store `contactsUid`; keep `displayName` denormalised from the contact (contact authoritative).
- [ ] Add a `Suppliers` nav entry + page bound to schema `supplier` in a `src/manifest.d/*.json` fragment (consume OR object surface, no bespoke controller).

## Phase 3: Consumption interface via integration registry (REQ-PVM-005, 006)

- [ ] Declare the product + supplier master as an integration provider on OpenRegister's `pluggable-integration-registry` (ADR-019), exposing `getProduct(productId)` and `resolveSupplier(contactsUid)`.
- [ ] Implement a thin `lib/Service/ProductVendorProviderService.php` for the registry handshake only (no CRUD wrapper over ObjectService, ADR-022); project a master view that omits CRM-private fields (`cost`, margin) unless the consumer is authorised.
- [ ] Specify the graceful-degradation contract (provider absent → consumer falls back to cached FK + warning), identical to `contacts-sync`.
- [ ] Document `productId` and `contactsUid` as the stable FK keys shillinq references.

## Phase 4: Ingest migration from shillinq (REQ-PVM-007, 008)

- [ ] Add `lib/Repair/IngestProductVendorMaster.php` (idempotent) that accepts the `shillinq-product-vendor-to-pipelinq` export.
- [ ] Products: match on `sku`/`barcode`; fill-only empty master fields (never overwrite pipelinq pricing); create when missing; preserve unmapped shillinq fields under MDM `sourceRecord.rawAttributes` (no data dropped). Return `{shillinqRef → productId}`.
- [ ] Vendors: resolve/create an NC contact (match KvK/VAT/email via `contacts-actions` provider, else create) → `contactsUid`; create/fill a `supplier`; route financial fields back as the shillinq AP payload. Return `{shillinqVendorRef → contactsUid}`.
- [ ] Write one MDM `sourceRecord` per ingested object (`sourceSystem: shillinq-products` / `shillinq-vendors`) so golden-record/provenance/dedup tooling picks them up.
- [ ] Emit the two FK maps for shillinq to store on its stock-keeping / AP records.

## Phase 5: Verification

- [ ] `openspec validate pipelinq-product-vendor-master --strict` passes.
- [ ] Hydra gates pass (spdx, route-auth on the provider service, no redundant-controller, notification-dialect, spec-coverage on changed methods).
- [ ] Confirm CRM/POS unaffected: existing product UI, `leadProduct` valuation, and POS variants/modifiers still work.
- [ ] Round-trip with the counterpart change: ingest a sample shillinq export, confirm FK maps returned, confirm shillinq can `getProduct`/`resolveSupplier` through the registry.
