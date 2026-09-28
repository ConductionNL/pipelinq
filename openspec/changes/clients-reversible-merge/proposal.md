---
kind: code
depends_on: []
---

# Proposal: clients-reversible-merge

## Summary

Merge two client records that turn out to be one, and undo that merge within
thirty days if it was a mistake: both clients come back, and the leads, tickets,
contacts and contracts that moved go back to where they were. OpenRegister's
merge engine already previews, executes and reverses merges. pipelinq never
told it that a client can be merged, so none of that reaches a client.

## Motivation

One row of the pipelinq capability matrix (`openspec/parity/capabilities.json`,
compared 2026-09-24), decided `build` by the OpenSpec pass of 2026-09-27. It is
in the core area of the matrix (clients).

**`clients-unmerge`**, "Undo a merge of two client records that should never
have been merged". Rated no, built.state none. Matrix evidence: "merging runs on
OpenRegister's merge (lib/Listener/ObjectsMergedSyncListener.php syncs the
Nextcloud contact afterwards); no unmerge exists in pipelinq or in OpenRegister's
routes (only objects revert, openregister appinfo/routes.php:1444, for one
object's own history)". Note: "the merged-away record can at best be restored
from OpenRegister's deleted objects, with its links not moved back". Demand:
feature request,
https://community.hubspot.com/t5/HubSpot-Ideas/Un-merging-contacts/idi-p/321397.
No competitor rates it yes: hubspot-crm, espocrm, odoo-crm and kiss no, pipedrive
unknown.

The matrix evidence is half wrong, found by the OpenSpec pass read of
2026-09-28: OpenRegister does have a reversal, `merge#reverse`
(`appinfo/routes.php:657`, `POST /api/objects/merge/{id}/reverse`), but only for
schemas that declare `x-openregister-merge`. In pipelinq only `masterEntity`
declares it (`lib/Settings/register.d/90-master-data-management.json:105`,
`reversalWindowDays: 30`); `client` and `contact` do not. So a client cannot be
merged reversibly, and the row stays a gap owned by pipelinq's register.

## What changes

- `client` and `contact` declare `x-openregister-merge` with a thirty day
  reversal window, so OpenRegister's merge engine accepts them.
- ClientDetail and ContactDetail get Merge into another record: pick the record
  that stays, see the preview of which values win, confirm.
- A listener moves every pipelinq reference to the merged-away record onto the
  record that stays, and records which rows it moved, so a reversal moves
  exactly those rows back.
- The record that stays shows a Merges section with Undo while the window is
  open. The merged-away record shows where it went.

## Out of scope

- Detecting duplicates. OpenRegister's duplicate detection and MDM surface stay
  as they are (ADR-045, archived `mdm-consume-or-surface`).
- Undo after thirty days. After the window the merge is final; the date is shown.

## Impact

- `lib/Settings/pipelinq_register.json` (`client`, `contact`) or a new fragment:
  `x-openregister-merge` and a merged status value.
- New `lib/Listener/ClientMergeReferenceListener.php`, registered for
  OpenRegister's `ObjectsMergedEvent`.
- ClientDetail and ContactDetail: Merge action and Merges section.
