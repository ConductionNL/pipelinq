# Proposal: contacts-sync

## Summary

Sync Pipelinq clients and contacts with Nextcloud's native Contacts app via `IManager`. Write-back on save (Pipelinq → Contacts) and import on demand (Contacts → Pipelinq).

## Motivation

Pipelinq stores clients and contacts in OpenRegister, completely separate from Nextcloud Contacts. Users who already have contacts in Nextcloud must re-enter them. Users who create CRM contacts don't see them in their address book. This creates duplicate data entry and fragmented contact information.

## Affected Projects
- [x] Project: `pipelinq` — New PHP service, schema update, API endpoints, frontend import UI

## Scope
### In Scope
- Write-back: auto-sync client/contact saves to a "Pipelinq CRM" addressbook via IManager
- Import: search Nextcloud Contacts and create Pipelinq clients/contacts from results
- Linking: track Nextcloud contact UID on Pipelinq objects to prevent duplicates
- Sync status indicator on detail views

### Out of Scope
- Real-time two-way sync (listening for Nextcloud Contact changes) — V1
- Bulk import/export (CSV, vCard file) — V1
- Duplicate detection/merge — V1
- CardDAV subscription from external sources — Enterprise

## Approach

New `ContactSyncService` using Nextcloud's `IManager` to create/update vCards. Add `contactsUid` field to client and contact schemas as the link key. On each Pipelinq save, if `contactsUid` exists → update the vCard, otherwise → create new and store the UID back. Import endpoint searches Nextcloud Contacts and returns results for user selection.

## Cross-Project Dependencies
- OpenRegister: schema update (add `contactsUid` field) via repair step

## Rollback Strategy
New PHP files can be removed. Schema field addition is additive (no breaking change). Frontend import UI is a new component.

## Open Questions
None.
