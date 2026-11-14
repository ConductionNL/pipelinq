---
kind: code
---

# Proposal: product-service-catalogue-public

## Summary

Spec `product-service-catalog` describes a catalogue of the services a municipality offers, with SDG audience, UPL reference, tariff tables (leges), scheduled publication and a public read-only API residents' websites can use. Its front matter says `done`, but the `product` schema in `lib/Settings/pipelinq_register.json` holds only the commercial product fields (name, sku, unitPrice, priceTiers, vatClass, variants and so on): no audience, no UPL reference, no tariff table, no publication dates, and no public read path. This is the build plan.

The catalogue reuses the one `product` schema (a service is a product of type `service`) instead of a second schema, and the public read goes through OpenRegister's publication of objects (ADR-022) rather than a pipelinq controller. The resident-facing pages themselves belong to the portal (portaliq) or the publication app (opencatalogi), which read that public API; pipelinq does not draw public pages.

## The row this covers

Source: pipelinq `openspec/parity/capabilities.json`.

- **prod-service-catalogue** Publish the services you offer, with their fees, in a catalogue residents can read (specified). Boards: PqProduct, PqProducten.

## What changes

1. Additive schema fields on `product` for the service catalogue.
2. Publication through OpenRegister: a published, current service is readable without login; concept and archived ones are not.
3. The product page and list per PqProduct and PqProducten show the catalogue fields for a service.
4. UPL reference list import.

## Screens

PqProduct, PqProducten (see `screens.md`). Resident-facing pages are out of scope here.

## Out of scope

- The public page design (portaliq or opencatalogi).
- Bundles, analytics and versioning requirements of the spec; they follow once the base fields exist (later changes).
