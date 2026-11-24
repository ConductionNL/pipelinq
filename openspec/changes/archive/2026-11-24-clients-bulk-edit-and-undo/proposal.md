---
kind: code
depends_on: []
---

# Proposal: clients-bulk-edit-and-undo

## Summary

Select fifty clients, set one field on all of them, and see the result before
anything is written. Undo that change later from a list of your bulk changes.
OpenRegister already runs bulk writes as previewed, reversible jobs; pipelinq
never offers them. This change puts both acts on the Clients and Contacts
lists.

## Motivation

Two rows of the pipelinq capability matrix (`openspec/parity/capabilities.json`,
compared 2026-09-24), both decided `build` by the OpenSpec pass of 2026-09-27.
Both are in the core area of the matrix (clients).

**`bulk-edit`**, "Change a field on fifty records at once, like a spreadsheet".
Rated no, built.state none. Matrix evidence: "no bulk edit in src/views/clients/
or src/components/". Note: "Attio and Pipedrive make this the centre of the
product. We edit one record at a time." Four competitors rate it yes:

- hubspot-crm: https://knowledge.hubspot.com/records/bulk-edit-records, select
  records or "Select all [number] [records]", then "Edit: select to edit the
  values of a specific property in these records".
- pipedrive: https://support.pipedrive.com/en/article/bulk-editing-and-filtering,
  "Once you've filtered your data, you can edit multiple items at once".
- espocrm: source read, `client/src/views/record/list-base.ts:402` mass action
  `massUpdate` sets one or more fields on every selected record.
- odoo-crm: source read, `odoo/addons/base/views/res_partner_views.xml:19`
  `multi_edit="1"` on the Contacts list.

**`clients-undo-bulk-change`**, "Undo a bulk change you made by mistake, even
weeks later". Rated no, built.state none. Matrix evidence: "pipelinq has no bulk
edit (existing row bulk-edit is no), so there is no bulk change to undo; per
object, OpenRegister's revert (openregister appinfo/routes.php:1444) rolls back
one record's history". Demand: changelog,
https://www.pipedrive.com/en/product-updates. Two competitors rate it yes:

- hubspot-crm: https://knowledge.hubspot.com/object-settings/restore-crm-changes,
  "Restore CRM changes by rolling records back to a previous point within the
  last 14 days".
- pipedrive: https://www.pipedrive.com/en/product-updates, "undo bulk edits,
  including lead-to-deal conversions, within 30 days".

## What changes

- The Clients and Contacts index pages become selectable and offer one bulk
  action, Change a field.
- The action opens a dialog that picks one property and a value, asks
  OpenRegister for a previewed bulk job, shows how many records change and how
  many are skipped, and commits only when the user confirms.
- A Bulk changes page lists the user's own bulk jobs on clients and contacts,
  with an Undo action while OpenRegister's reversal window is open.
- Undo asks OpenRegister for the reversal job, previews it the same way and
  commits on confirmation.

## Out of scope

- A reversal window longer than OpenRegister's seven days
  (`AbstractPropertyWriteAction::REVERSAL_WINDOW`). The row asks for "weeks";
  a longer window is an OpenRegister decision and is named in design.md as a
  follow-up, not built here.
- Bulk edit on leads, tickets and tasks. The same modal can be mounted there
  later; this change keeps to the two core-area lists.
- Inline cell editing in the table.

## Impact

- `src/manifest.json` pages Clients and Contacts: `selectable`, `bulkActions`.
- New modal `src/modals/BulkChangeFieldModal.vue`, registered in `src/registry.js`.
- New custom page BulkChanges, one menu entry under the clients group.
- No new schema. No PHP controller: the frontend calls OpenRegister's
  `/api/bulk-jobs` routes directly (ADR-022).
