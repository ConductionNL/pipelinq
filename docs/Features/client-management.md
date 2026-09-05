# Client Management

Manages persons and organizations as CRM clients, with linked contact persons and optional sync to Nextcloud's native Contacts app.

## Specs

- `openspec/specs/client-management/spec.md`
- `openspec/specs/contacts-sync/spec.md`
- `openspec/changes/contact-channel-details/specs/contact-channel-details/spec.md`

## Features

### Client CRUD (MVP)

Full create, read, update, and delete for client records. Clients represent persons or organizations and are the central entity that leads, requests, and contacts link to.

- Client list view with search, sort, and filter
- Client detail view with summary stats and linked entities (contacts, leads, requests)
- Client types: person and organization
- Fields: name, email, phone, website, address, type, notes
- Typed channels: multiple emails and phone numbers (kind: work, private, mobile, whatsapp, other), each marked primary or verified
- Social profiles: LinkedIn, X, Mastodon, Bluesky, Facebook, Instagram, Threads, TikTok, YouTube, or other, with a handle, a profile URL, and whether Conduction follows or is followed
- Preferred channel, timezone, and language, so a mailing can reach a client on the channel and at the time they actually want it

### Contact Person Management (MVP)

Contact persons are individuals linked to a client organization, representing specific people within that organization.

- Contact CRUD linked to a client
- Contact list view with search and client name resolution
- Contact detail view with client navigation link
- Fields: name, role, email, phone
- Batch client name resolution in list view (avoids N+1 queries)
- Same typed emails, phones, social profiles, and channel preferences as clients (see above)

### Nextcloud Contacts Sync (MVP)

Two-way sync between Pipelinq clients/contacts and Nextcloud's native Contacts app via IManager, eliminating duplicate data entry.

- Write-back sync: saving a client/contact pushes to Nextcloud Contacts
- Import from Contacts: pull existing contacts into Pipelinq
- Sync status indicator: badge showing "Synced with Contacts" on synced entities
- `contactsUid` field tracks the link between Pipelinq and Contacts records
- Typed emails and phones map to multiple vCard `EMAIL`/`TEL` properties (with a `TYPE`); social profiles map to `X-SOCIALPROFILE`, both directions

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
