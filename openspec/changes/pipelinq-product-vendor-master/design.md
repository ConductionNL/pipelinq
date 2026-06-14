# Design — pipelinq product & vendor master

## Context

pipelinq is the CRM / sales / commercial app. The shillinq refactor relocates product **definitions** and vendor/supplier **master data** out of accounting and into pipelinq, leaving shillinq with stock-keeping and an AP/financial vendor profile only. pipelinq already holds a mature `product` catalog (CRM + POS) and a contact-sync capability; it lacks a commercial supplier profile. This change closes that gap and declares the consumption + ingest surface that the counterpart `shillinq-product-vendor-to-pipelinq` relies on.

## Key decisions

### D1 — EXTEND the existing `product` schema; do not add a product register

The canonical `product` schema lives in `lib/Settings/pipelinq_register.json`. It is referenced by `leadProduct` (pipeline valuation), POS (`variants`, `modifierGroups`, `priceTiers`, `btwClass`, `barcode`), and the MDM extension. Introducing a second product register would split the catalog and break those references (ADR-012). We therefore add the **supply-side master-data fields** as an extension fragment `lib/Settings/register.d/92-product-supply-master.json` that targets the same `product` schema slug (the established override pattern, see how `90-master-data-management.json` extends `contact`/`client`/`product`).

New `product` fields (all additive, all `visible:false` by default to keep the CRM UI uncluttered):

- `productId` (string, UUID) — **canonical stable key** that shillinq references. Defaults to the OR object UUID; explicit so it survives MDM merges and is the documented FK in the integration contract.
- `gtin` (string) — global trade item number (distinct from the POS `barcode` scan field; GS1 master attribute).
- `manufacturer` (string) — maker / brand owner (schema:manufacturer).
- `unitOfMeasure` (string) — canonical UoM for stock-keeping (each, kg, litre, box); shillinq valuation/lots key off this.
- `weight` (number, kg) and `dimensions` (object: length/width/height/unit) — logistics master data.
- `hazardClass` (string) — ADR/UN dangerous-goods class for warehousing.
- `preferredSupplier` (string) — `contactsUid` of the default `supplier` (soft reference; procurement default).
- `stockTracked` (boolean) — whether shillinq should hold stock for this product (lets services opt out).
- `consumableBy` (array) — downstream app slugs allowed to consume this product over the registry (default `["shillinq"]`).

The existing `cost` field already covers standard cost; `unitPrice`/`priceTiers` already cover sell-side pricing. We do **not** duplicate those.

### D2 — ADD a new `supplier` schema keyed by `contactsUid`; identity stays a NC Contact

A supplier's *identity* (name, address, KvK, VAT, contact persons) is a **Nextcloud Contact** per the "Contact is a Nextcloud entity" rule. pipelinq's `supplier` schema holds only the **commercial profile** and links to that contact by `contactsUid` — exactly mirroring how `client`/`contact` already carry `contactsUid` and sync via `ContactVcardService`. We do NOT invent a party/organisation schema.

`supplier` schema (new, in `lib/Settings/register.d/91-supplier-commercial-master.json`):

- `contactsUid` (string, **required**) — FK to the NC addressbook contact = the supplier identity and the key shillinq AP resolves on.
- `supplierId` (string, UUID) — pipelinq-stable key (OR object UUID) for internal references.
- `displayName` (string) — denormalised contact name for list views (kept in sync from the contact; the contact is authoritative).
- `category` (string, enum: goods / services / both) — facetable.
- `status` (string, enum: active / inactive / blocked) — facetable.
- `termsOfTrade` (object) — incoterm, paymentTermDays, currency, minimumOrderValue, discountPercent.
- `leadTimeDays` (integer) — default procurement lead time.
- `catalog` (array of objects) — supplied products: `{ productId, supplierSku, listPrice, currency, moq }`. `productId` references the pipelinq `product` master.
- `preferred` (boolean, facetable) — whether this is a preferred supplier.
- `masterEntityRef` (string) — optional FK into the MDM `masterEntity` (entityType `vendor`), so the supplier can join the golden-record layer.
- `notes` (string).

Note explicitly: the **financial** AP fields (IBAN, payment method, credit limit, tax-withholding) are **NOT** on this schema — they remain shillinq's `Vendor` AP profile, keyed by the same `contactsUid`. pipelinq owns *commercial* master data; shillinq owns *financial* AP data; both hang off the one NC contact.

### D3 — Consumption interface via the ADR-019 integration registry, not a bespoke HTTP API

Per ADR-022, pipelinq does not expose a hand-rolled product/supplier REST controller for shillinq. Instead the masters are published as an **integration provider** through OpenRegister's `pluggable-integration-registry` (the same registry pipelinq already consumes for `contacts-actions`). The provider exposes two read operations:

- `getProduct(productId)` → the product master record (definition + master-data fields, excluding CRM-private fields like `cost` unless the consumer is authorised).
- `resolveSupplier(contactsUid)` → the supplier commercial profile + the NC contact identity fields.

shillinq calls these through the registry (graceful-degradation contract identical to `contacts-sync`: if the provider is absent, shillinq falls back to its cached FK values and logs a warning). The provider is declared in schema metadata (`x-openregister-integration`) per ADR-031 where possible; the genuine read-resolution exception lives in a thin `ProductVendorProviderService` registered with the registry (PHP only for the registry-handshake, no CRUD wrapper over ObjectService).

### D4 — Ingest migration accepts the shillinq export without dropping fields

The counterpart `shillinq-product-vendor-to-pipelinq` exports two object sets: shillinq product master-data and shillinq vendor master-data. A `lib/Repair/IngestProductVendorMaster.php` repair step (idempotent, ADR-019/data-migration pattern) maps them:

- **Products:** match on existing `sku`/`barcode`; if found, fill empty master-data fields only (no overwrite of pipelinq-authoritative pricing); if not found, create a `product` with the shillinq fields mapped onto the extended schema. Any shillinq field with no pipelinq target is preserved under `sourceRecord.rawAttributes` (MDM) so nothing is dropped. Set/confirm `productId` and return the mapping `{shillinqRef → productId}` for shillinq to store as its FK.
- **Vendors:** for each exported vendor, ensure an NC contact exists (reuse `ContactVcardService`; match on KvK/VAT/email, else create) to obtain `contactsUid`; create/fill a `supplier` keyed by that `contactsUid`; route financial fields (IBAN, payment terms) back as the AP-profile payload shillinq keeps. Return `{shillinqVendorRef → contactsUid}`.

The repair writes a `sourceRecord` (sourceSystem `shillinq-products` / `shillinq-vendors`) per ingested object so the MDM golden-record layer and provenance/dedup tooling pick them up automatically.

## Alternatives considered

- **New `pipelinqProduct` register** — rejected (ADR-012): duplicates the catalog, breaks `leadProduct`/POS/MDM references.
- **A pipelinq `party`/`organisation` schema for suppliers** — rejected ("Contact is a Nextcloud entity"): the NC addressbook + `contactsUid` is the identity store; we add only the commercial profile.
- **Bespoke pipelinq REST endpoints for shillinq** — rejected (ADR-022): cross-app reads go through the integration registry.
- **Keeping the supplier financial fields in pipelinq** — rejected (interface contract #1): AP/financial profile stays shillinq's; pipelinq holds commercial master only.

## Migration / rollout

1. Deploy the two fragments (product extension + supplier schema) — purely additive, no data change, no break to CRM/POS/leadProduct.
2. Register the integration provider; shillinq begins resolving `productId`/`contactsUid` through it (cached FKs keep working if the provider lags).
3. Run the ingest repair once shillinq has produced its export; verify the returned FK maps; shillinq then repoints its stock-keeping/AP records to `productId`/`contactsUid`.
4. shillinq demotes its `Vendors` nav to a financial-profile view and stops editing master data (its counterpart change).

## Risks

- **FK-key drift:** if `productId` is not pinned to the OR UUID it could diverge from shillinq's stored FK. Mitigation: `productId` defaults to and is asserted equal to the object UUID; the ingest repair returns the authoritative map.
- **Duplicate suppliers on ingest:** two shillinq vendors mapping to one KvK. Mitigation: contact matching via the existing `contacts-actions` provider before creating a new contact; MDM `masterEntity` dedup as backstop.
- **Provider unavailable:** shillinq must not hard-fail. Mitigation: graceful-degradation contract identical to `contacts-sync` (cached FK + warning).
- **Over-exposure of CRM-private fields:** `cost`/margin must not leak to shillinq. Mitigation: the provider projects a master view that omits CRM-private fields unless the consumer is explicitly authorised.
