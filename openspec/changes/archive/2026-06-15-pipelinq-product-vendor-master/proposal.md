# Proposal: pipelinq-product-vendor-master

kind: extension + integration-surface (ADR-012 deduplication, ADR-022 apps-consume-or-abstractions, ADR-019 integration-registry, ADR-037 modular-config-fragments, ADR-031 schema-declarative-business-logic)

## Summary

The shillinq fleet refactor moves **product definitions** and **vendor/supplier master data** out of the accounting app and makes **pipelinq the canonical owner**. shillinq retains only stock-keeping (StockLevels, movements, lots/batches, barcode scanning, FIFO/AVG valuation, COGS GL posting, reorder rules) and the **AP/financial** vendor profile (payment terms, IBAN, credit limit). It references a product by `productId` and a supplier by `contactsUid` — it no longer owns the definitions.

This change makes pipelinq the canonical master for both, per **CROSS-APP INTERFACE CONTRACT #1**:

1. **Product master.** pipelinq already owns a rich canonical `product` schema (the CRM/POS catalog: SKU, pricing, variants, modifiers, price tiers, btwClass, barcode). This change **extends that existing schema** — it does NOT create a parallel product register — adding the supply-side master-data fields (`gtin`, `manufacturer`, `unitOfMeasure`, `weight`, `dimensions`, `hazardClass`, `preferredSupplier`, `productId` canonical key, `consumableBy`) needed by shillinq inventory, and publishes the schema for cross-app consumption through the **ADR-019 integration registry**. `productId` is the stable FK key shillinq references.
2. **Supplier/vendor commercial master.** A supplier's *identity* is a **Nextcloud Contact** (OCP\Contacts / addressbook, keyed by `contactsUid`) per the "Contact is a Nextcloud entity" rule. pipelinq today has NO concrete commercial-supplier schema (only an MDM `masterEntity` with `entityType: vendor`). This change **adds a new `supplier` schema** in a `register.d` fragment — the commercial supplier profile (catalog membership, terms-of-trade, category, lead time, preferred-product list) keyed by `contactsUid`, reusing the existing pipelinq contact-sync pattern (`ContactVcardService`, `contactsUid`, `IManager`). It does **not** invent a parallel party/contact schema.
3. **Consumption interface.** shillinq reads a Product by `productId` and resolves a Supplier by `contactsUid` through the integration registry. shillinq keeps the AP/financial vendor profile and all stock-keeping.
4. **Ingest migration.** Accept the product + vendor master-data objects exported by the counterpart change `shillinq-product-vendor-to-pipelinq`, map them onto pipelinq's master(s) without dropping fields, and establish the FK keys (`productId`, `contactsUid`) that shillinq will reference afterwards.

**Capability → owner table**

| Capability | Owner (after) | Key | Notes |
|---|---|---|---|
| Product definition / attributes / SKU / pricing / catalog | **pipelinq** (`product`, extended) | `productId` | EXTEND existing schema; do not duplicate |
| Product category tree | **pipelinq** (`productCategory`, existing) | uuid | unchanged |
| Supplier identity (name, address, KvK) | **Nextcloud Contact** (addressbook) | `contactsUid` | reuse NC contact, not a new party schema |
| Supplier commercial profile (catalog, terms-of-trade, category, lead time) | **pipelinq** (`supplier`, NEW) | `contactsUid` | keyed to the NC contact |
| Supplier **AP/financial** profile (payment terms, IBAN, credit) | **shillinq** (`Vendor`, kept) | `contactsUid` | financial-profile view only |
| Stock-keeping (levels, lots, valuation, COGS, reorder) | **shillinq** (kept) | `productId` | references pipelinq product |

## Dedup rationale (ADR-012)

Phase 0 verified against the live pipelinq code:

- **`product`** master already exists in `lib/Settings/pipelinq_register.json` (lines ~1195) with name, sku, unitPrice, cost, category, type, status, unit, taxRate, btwClass, barcode, variants, modifierGroups, priceTiers, image; `productCategory` and `leadProduct` accompany it. The MDM fragment `lib/Settings/register.d/90-master-data-management.json` already extends `product` with `masterEntityRef`/`isMasterRecord`. → We **EXTEND** `product`; we do NOT add a second product register (would duplicate the catalog and break `leadProduct`, POS, and MDM references).
- **No concrete supplier/vendor schema** exists in pipelinq today — only the MDM `masterEntity` with `entityType: vendor` (a golden-record envelope, not an operational supplier profile). The supplier commercial master is therefore a **genuinely new** schema, added in a fragment, keyed by `contactsUid` and reusing the existing contact-sync pattern rather than a new party/contact schema.
- The **contact-sync** capability (`openspec/specs/contacts-sync/spec.md`, `ContactVcardService`, `ContactSyncController`, `contactsUid`) already wires pipelinq objects to NC addressbook entries — the supplier identity reuses it directly; no new sync engine.
- Cross-app dispatch already follows the OR WebhookService / integration-registry pattern (`30-expense-shillinq-ap.json`, `60-project-ledger.json`, `90-time-wip.json`); the product/supplier read surface is published through the same **ADR-019 integration registry**, not a bespoke pipelinq HTTP API (ADR-022).

No capability is re-implemented; this change extends one schema, adds one schema, and declares one read/ingest integration surface.

**Depends on:**
- `shillinq-product-vendor-to-pipelinq` (counterpart — exports the product + vendor master-data objects this change ingests; agrees on `productId` / `contactsUid` FK keys verbatim).
- pipelinq `contacts-sync` (implemented) — supplier identity reuses `contactsUid` + `ContactVcardService`.
- pipelinq `master-data-management` (`90-master-data-management.json`) — `masterEntity`/`sourceRecord` golden-record layer the ingested objects optionally feed into (`sourceSystem: shillinq-vendors` / `shillinq-products`).
- OpenRegister `pluggable-integration-registry` (ADR-019) — the cross-app read surface shillinq consumes.
