# Client Management

Manages persons and organizations as CRM clients, with linked contact persons and optional sync to Nextcloud's native Contacts app.

## Specs

- `openspec/specs/client-management/spec.md`
- `openspec/specs/contacts-sync/spec.md`

## Features

### Client CRUD (MVP)

Full create, read, update, and delete for client records. Clients represent persons or organizations and are the central entity that leads, requests, and contacts link to.

- Client list view with search, sort, and filter
- Client detail view with summary stats and linked entities (contacts, leads, requests)
- Client types: person and organization
- Fields: name, email, phone, website, address, type, notes

### Contact Person Management (MVP)

Contact persons are individuals linked to a client organization, representing specific people within that organization.

- Contact CRUD linked to a client
- Contact list view with search and client name resolution
- Contact detail view with client navigation link
- Fields: name, role, email, phone
- Batch client name resolution in list view (avoids N+1 queries)

### Nextcloud Contacts Sync (MVP)

Two-way sync between Pipelinq clients/contacts and Nextcloud's native Contacts app via IManager, eliminating duplicate data entry.

- Write-back sync: saving a client/contact pushes to Nextcloud Contacts
- Import from Contacts: pull existing contacts into Pipelinq
- Sync status indicator: badge showing "Synced with Contacts" on synced entities
- `contactsUid` field tracks the link between Pipelinq and Contacts records

### Orphaned Reference Handling (MVP)

When a linked entity (e.g., a client linked to a contact) is deleted, the UI shows `[Deleted client]` placeholders instead of broken references or empty fields.

### Planned (V1)

- Duplicate detection (name/email matching)
- Import from CSV/vCard
- Export to CSV/vCard/PDF
- Contact segmentation with tags
- Contact merge

### Planned (Enterprise)

- Hierarchical organizations (parent/child)
- BSN/KVK number lookup (Dutch government identity verification)
