---
kind: code
depends_on: [clients-bulk-edit-and-undo]
---

# Proposal: clients-registry-numbers-and-enrichment

## Summary

Keep a company's official numbers on its client record, KvK, RSIN, VAT, DUNS
and OIN, and see for each one whether it was checked and when. Then fill the
empty fields of many companies at once from the KvK register, previewed first
and undoable afterwards. Today a KvK number exists only on a prospect card and
nothing is ever filled in for an existing client.

## Motivation

Two rows of the pipelinq capability matrix (`openspec/parity/capabilities.json`,
compared 2026-09-24), both decided `build` by the OpenSpec pass of 2026-09-27.
Both are in the core area of the matrix (clients).

**`clients-identifiers`**, "Keep several official numbers on a company, such as
KvK, VAT and DUNS, each checked". Rated no, built.state none. Matrix evidence:
"the client schema holds name, type, email, phone, address, website, industry,
notes, contactsUid (lib/Settings/pipelinq_register.json, schema client) and no
register.d fragment adds a KvK, VAT or DUNS number; KvK numbers appear only on
prospects (src/components/ProspectCard.vue:34) and receipts; the only number
checked is the BSN (lib/Service/BsnValidationService.php)". Demand: changelog,
https://www.odoo.com/odoo-20-release-notes. Every competitor rates it partial:
hubspot-crm, pipedrive, espocrm, odoo-crm and kiss.

**`clients-bulk-enrichment`**, "Fill in missing company and person details from
public data for many records at once". Rated no, built.state none. Matrix
evidence: "KvK and OpenCorporates are searched to discover prospects
(lib/Service/ProspectDiscoveryService.php:114, ProspectsView) and BRP per person
on request (lib/Service/HaalCentraalClient.php:136); no job or action fills
missing company or person details for many existing client records at once".
Demand: changelog, https://www.pipedrive.com/en/product-updates. Three
competitors rate it yes:

- hubspot-crm: https://knowledge.hubspot.com/records/enrich-your-contact-and-company-data,
  "Enrich contacts and companies using data from HubSpot's commercial dataset.
  Enrich records individually or in bulk".
- pipedrive: https://support.pipedrive.com/en/article/data-enrichment, "Bulk
  enrichment for people and organizations"; "Only empty fields will be
  enriched".
- odoo-crm: source read, `addons/crm_iap_enrich/models/crm_lead.py:129-150`
  enriches leads in batches from the company's email domain.

The two rows share one change because enrichment needs the KvK number that
`clients-identifiers` adds: without it, a bulk fill has to guess a company by
name, and a wrong match writes a stranger's address onto a client.

## What changes

- An organisation client carries a list of identifiers, each with a scheme
  (KvK, RSIN, VAT, DUNS, OIN), a value, a check status and the time it was
  checked.
- The format of each scheme is validated on save. KvK is checked against the
  KvK register through OpenRegister's KvK leaf; VAT through shillinq's VIES
  service when shillinq is installed.
- ClientDetail shows an Identifiers section with a Check action per number.
- A new bulk action, Fill in from KvK, on the Clients list fills only empty
  fields of selected organisations that carry a KvK number. It runs as an
  OpenRegister bulk job, so it is previewed and can be undone from the Bulk
  changes page of `clients-bulk-edit-and-undo`.

## Out of scope

- Enriching persons. Person data from the BRP may be read only for a stated
  purpose and one person at a time (`HaalCentraalClient`); a bulk fill of
  person records is not built.
- Commercial data providers (Apollo, ZoomInfo, Clearbit). The archived
  proposal `2026-03-15-add-product-and-prospect-widget` already lists paid
  enrichment APIs as a non-goal.
- A DUNS register check. There is no open DUNS register; DUNS is checked on
  format only.

## Impact

- `lib/Settings/register.d/` new fragment adding `identifiers` to `client`.
- New `lib/Service/ClientIdentifierService.php` (format rules, KvK and VAT checks).
- New `lib/BulkAction/FillFromKvkAction.php`, registered on OpenRegister's
  `BulkActionRegistrationEvent` in `lib/AppInfo/Application.php`.
- `src/manifest.json` ClientDetail section and a Clients bulk action.
