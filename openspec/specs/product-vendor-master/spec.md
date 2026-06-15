# Spec: product-vendor-master

**Status:** implemented (Phase 3 active registry-announcement + Phase 5 cross-app round-trip deferred — blocked on the `shillinq-product-vendor-to-pipelinq` counterpart)
**Scope:** pipelinq
**Tier:** MVP
**Depends on:** `shillinq-product-vendor-to-pipelinq` (counterpart export), pipelinq `contacts-sync` (implemented), pipelinq `master-data-management` (`masterEntity`/`sourceRecord`), OpenRegister `pluggable-integration-registry` (ADR-019)

## Purpose

pipelinq is the canonical owner of the Product master and the Supplier commercial master per CROSS-APP INTERFACE CONTRACT #1. It EXTENDS the existing `product` schema, ADDS a `supplier` schema keyed by `contactsUid` (identity = Nextcloud Contact), declares the registry read surface shillinq consumes, and ingests the shillinq master-data export without dropping fields.

## Requirements

### Requirement: REQ-PVM-001 — The system SHALL extend the existing `product` schema with supply-side master-data fields

pipelinq MUST add supply-side master-data fields to its **existing** canonical `product` schema (in `lib/Settings/pipelinq_register.json`) via the extension fragment `lib/Settings/register.d/92-product-supply-master.json`. It MUST NOT create a second product register. New fields: `productId`, `gtin`, `manufacturer`, `unitOfMeasure`, `weight`, `dimensions`, `hazardClass`, `preferredSupplier`, `stockTracked`, `consumableBy`. All additive; all `visible:false` by default.

@e2e exclude schema-only fragment — additive register.d metadata, no new UI surface; verified by schema-load + PHPUnit

#### Scenario: Product gains master-data fields without breaking the catalog
- GIVEN the existing `product` schema with name, sku, unitPrice, variants, priceTiers, btwClass
- WHEN the `92-product-supply-master.json` fragment is loaded
- THEN the `product` schema MUST also expose `productId`, `gtin`, `manufacturer`, `unitOfMeasure`, `weight`, `dimensions`, `hazardClass`, `preferredSupplier`, `stockTracked`, `consumableBy`
- AND the pre-existing fields (`variants`, `priceTiers`, `btwClass`, `barcode`) MUST remain unchanged
- AND `leadProduct`, the POS fragments, and the MDM `product` extension MUST still resolve against the same `product` slug (no second register exists)

#### Scenario: Service products opt out of stock tracking
- GIVEN a `product` with `type: service`
- WHEN it is saved with `stockTracked: false`
- THEN the registry consumption surface MUST report `stockTracked = false` so shillinq does not create stock records for it

### Requirement: REQ-PVM-002 — The system SHALL expose `productId` as the stable canonical key

The `product` master MUST carry a `productId` that defaults to the OpenRegister object UUID and is the documented foreign key shillinq stores and references. `productId` MUST survive MDM merges (it is preserved on the surviving record / recorded as an alias).

@e2e exclude data-key invariant — verified by PHPUnit on the schema default and the ingest repair

#### Scenario: productId equals the object UUID by default
- GIVEN a new `product` object created in pipelinq
- WHEN it is persisted
- THEN `productId` MUST equal the object's OpenRegister UUID
- AND any consumer (shillinq) that stored that `productId` MUST resolve the same product on a later read

#### Scenario: productId is stable across an MDM merge
- GIVEN two `product` objects A and B identified as duplicates with `productId` values pA and pB
- WHEN they are merged in the MDM layer with A surviving
- THEN the surviving product retains `productId = pA`
- AND pB MUST be recorded as an alias so a shillinq FK referencing pB still resolves to the surviving product

### Requirement: REQ-PVM-003 — The system SHALL own the Supplier commercial master keyed by `contactsUid`, with identity in a Nextcloud Contact

pipelinq MUST add a new `supplier` schema (fragment `lib/Settings/register.d/91-supplier-commercial-master.json`) holding the commercial supplier profile only — `supplierId`, `displayName`, `category`, `status`, `termsOfTrade`, `leadTimeDays`, `catalog[]`, `preferred`, `masterEntityRef`, `notes`. `contactsUid` is REQUIRED and is the supplier's identity link to a Nextcloud addressbook contact. pipelinq MUST NOT define a parallel party/organisation/contact schema, and MUST NOT hold AP/financial fields (IBAN, payment method, credit limit) — those remain shillinq's.

@e2e exclude schema-only fragment — additive register.d metadata; identity/sync covered by PHPUnit on ContactVcardService

#### Scenario: Supplier identity is a Nextcloud Contact
- GIVEN a new `supplier` is created in pipelinq
- WHEN it is saved
- THEN it MUST carry a `contactsUid` pointing at a Nextcloud addressbook contact (created/linked via the existing `ContactVcardService`)
- AND the supplier's name/address/KvK MUST live on that NC contact, not be duplicated as authoritative fields on `supplier`
- AND `displayName` on `supplier` MUST be a denormalised copy kept in sync from the contact (the contact is authoritative)

#### Scenario: Supplier carries no financial AP fields
- GIVEN the `supplier` schema
- WHEN its properties are inspected
- THEN it MUST NOT contain IBAN, payment-method, credit-limit, or tax-withholding fields
- AND those fields MUST remain on shillinq's `Vendor` AP profile keyed by the same `contactsUid`

### Requirement: REQ-PVM-004 — The system SHALL link a supplier catalog to products by `productId`

A `supplier.catalog[]` entry MUST reference a pipelinq `product` by `productId` (with `supplierSku`, `listPrice`, `currency`, `moq`), and `product.preferredSupplier` MUST reference a supplier by `contactsUid`.

@e2e exclude backend integration — catalog↔product `productId` resolution and `preferredSupplier` linkage are schema/object-relationship contracts with no UI surface; covered by PHPUnit

#### Scenario: Catalog entry references a product master
- GIVEN a `supplier` with a `catalog` entry `{ productId: pX, supplierSku: "S-99", listPrice: 12.50, currency: "EUR", moq: 24 }`
- WHEN the entry is saved
- THEN `productId` pX MUST resolve to an existing pipelinq `product`
- AND setting `product(pX).preferredSupplier` to that supplier's `contactsUid` MUST make the supplier the procurement default for that product

### Requirement: REQ-PVM-005 — The system SHALL publish the Product and Supplier masters as an integration-registry provider

pipelinq MUST expose the masters through OpenRegister's `pluggable-integration-registry` (ADR-019), NOT a bespoke HTTP controller (ADR-022). The provider MUST offer `getProduct(productId)` and `resolveSupplier(contactsUid)`. The provider service MUST be a thin registry handshake only (no CRUD wrapper over ObjectService) and MUST project a master view that omits CRM-private fields (`cost`, margin) unless the consumer is explicitly authorised.

@e2e exclude backend integration — registry provider is a PHP service; covered by PHPUnit

#### Scenario: shillinq reads a product through the registry
- GIVEN pipelinq has registered the product/supplier provider on `pluggable-integration-registry`
- WHEN shillinq calls `getProduct(productId = pX)`
- THEN the provider MUST return the `product` master definition and master-data fields for pX
- AND CRM-private fields (`cost`, margin) MUST be omitted unless the consumer is authorised

#### Scenario: shillinq resolves a supplier through the registry
- GIVEN a `supplier` exists with `contactsUid = uZ`
- WHEN shillinq calls `resolveSupplier(contactsUid = uZ)`
- THEN the provider MUST return the supplier commercial profile plus the NC contact identity fields
- AND it MUST NOT return AP/financial fields (those are shillinq-owned)

### Requirement: REQ-PVM-006 — The system SHALL define a graceful-degradation contract for absent provider/consumer

The consumption contract MUST degrade gracefully exactly as `contacts-sync` does: if the provider is not registered (or a read fails), the consumer (shillinq) MUST fall back to its cached FK values and log a warning rather than crash, and pipelinq writes MUST still succeed.

@e2e exclude backend integration — failure path; covered by PHPUnit

#### Scenario: Provider unavailable does not break the consumer
- GIVEN the product/supplier provider is NOT registered on `pluggable-integration-registry`
- WHEN shillinq attempts to resolve a `productId` or `contactsUid`
- THEN shillinq MUST log a warning ("product-vendor provider unavailable; using cached FK") and use its cached values
- AND pipelinq product/supplier creation and editing MUST continue to function normally

### Requirement: REQ-PVM-007 — The system SHALL ingest the shillinq product master-data export without dropping fields

pipelinq MUST accept the product master-data objects exported by `shillinq-product-vendor-to-pipelinq` via an idempotent `lib/Repair/IngestProductVendorMaster.php` step. It MUST match incoming products on `sku`/`barcode`; fill only empty master-data fields (never overwrite pipelinq-authoritative pricing); create the product when no match exists; and preserve any unmapped shillinq field under an MDM `sourceRecord.rawAttributes` so no data is dropped. It MUST return the FK map `{shillinqRef → productId}`.

@e2e exclude migration repair — backend repair step; covered by PHPUnit on a sample export

#### Scenario: Existing product is enriched, pricing untouched
- GIVEN a pipelinq `product` with `sku = "ABC-1"` and `unitPrice = 10.00`
- AND a shillinq export object with `sku = "ABC-1"`, a `manufacturer`, a `unitOfMeasure`, and a `costPrice = 6.00`
- WHEN the ingest repair runs
- THEN the existing product's empty `manufacturer` and `unitOfMeasure` MUST be filled from the export
- AND `unitPrice` MUST remain 10.00 (pipelinq-authoritative pricing is not overwritten)
- AND any shillinq field with no pipelinq target MUST be preserved under a `sourceRecord` with `sourceSystem = "shillinq-products"`
- AND the map MUST include `{ shillinqRef → productId }` for shillinq to store

#### Scenario: Unmatched product is created
- GIVEN a shillinq export object with `sku = "NEW-9"` and no matching pipelinq product
- WHEN the ingest repair runs
- THEN a new `product` MUST be created with the shillinq fields mapped onto the extended schema
- AND its `productId` MUST be returned in the FK map
- AND re-running the repair MUST be idempotent (no duplicate product is created)

### Requirement: REQ-PVM-008 — The system SHALL ingest the shillinq vendor master-data export onto the supplier master via Nextcloud Contacts

pipelinq MUST accept the vendor master-data export by resolving or creating a Nextcloud contact for each vendor (matching on KvK/VAT/email via the existing `contacts-actions` provider, else creating one through `ContactVcardService`) to obtain `contactsUid`; creating/filling a `supplier` keyed by that `contactsUid`; routing the financial fields (IBAN, payment terms) back as the AP payload shillinq retains; and writing a `sourceRecord` (`sourceSystem = "shillinq-vendors"`) per vendor. It MUST return the FK map `{shillinqVendorRef → contactsUid}`.

@e2e exclude migration repair — backend repair step; covered by PHPUnit on a sample export

#### Scenario: Vendor maps onto an NC contact and a supplier profile
- GIVEN a shillinq vendor export object with name "Leverancier B.V.", KvK "87654321", IBAN "NL00BANK0123456789", and paymentTermDays 30
- WHEN the ingest repair runs
- THEN a Nextcloud contact MUST be resolved (matched on KvK) or created, yielding `contactsUid = uV`
- AND a `supplier` MUST be created/filled keyed by `contactsUid = uV` with `category` and `termsOfTrade.paymentTermDays = 30`
- AND the financial fields (IBAN, payment terms) MUST be routed back to shillinq as the AP payload, NOT stored as authoritative on the pipelinq `supplier`
- AND the map MUST include `{ shillinqVendorRef → uV }` for shillinq to store on its AP/Vendor record

#### Scenario: Two vendor records with the same KvK do not duplicate the contact
- GIVEN two shillinq vendor export objects sharing KvK "87654321"
- WHEN the ingest repair runs
- THEN the `contacts-actions` provider MUST match both to the same NC contact (`contactsUid = uV`)
- AND at most one `supplier` MUST exist for `contactsUid = uV` (the second fills/updates rather than duplicates)
