<!-- ⚠️ EXTENSION NOTICE (auto-inserted by fix_extension_artifacts.py)
     Parent capability: client-management (Client Management)
     This spec extends the existing `client-management` capability. Do NOT define new entities or build new CRUD — reuse what `client-management` already provides. Your job is to add configuration, seed data, or workflow templates on top of that capability.
-->

# Design: contacts-sync

## Architecture Overview

Frontend-only change. No new API endpoints. No schema modifications — `contact.client` (UUID reference to the parent client object) already exists in the data model.

Three UI enhancements across two existing views:

1. **ClientDetail.vue** — Add "Link contact" button to the contacts panel that opens a search dialog for linking an existing contact. Fix `#actions` → `#header-actions` (ADR-018).
2. **ContactDetail.vue** — Replace the static "Client" info field with a dedicated `CnDetailCard` titled "Organization" that shows the linked client and exposes inline link/unlink actions via `#header-actions`.
3. **Two new dialog components** — `ContactLinkDialog.vue` (find contact → link to org) and `ContactLinkOrganizationDialog.vue` (find org → link to contact).

## Key Design Decisions

### 1. Link Existing Contact Dialog

**Decision**: Create `ContactLinkDialog.vue` — a modal with a search input backed by the existing contact `objectStore.findObjects()`. On selection, calls `contactStore.saveObject({ ...contact, client: clientId })`.

**Rationale**: The existing objectStore already supports full-text search via OpenRegister's `IndexService`. No custom API endpoint is needed. This follows the established dialog pattern (`CnFormDialog` base).

**Already-linked contacts**: Contacts already linked to this client are filtered out of search results (client-side filter after fetch, or by passing `client: clientId` as a negative filter).

### 2. Organization Card on ContactDetail

**Decision**: Replace the static "Client" info row in the `CnDetailCard` "Contact Information" section with a dedicated `CnDetailCard` titled "Organization". The card uses `#header-actions` for link/unlink buttons.

**Rationale**: A dedicated card follows the same pattern as the existing "Relationships" and "Contactmomenten" cards. It makes the organization link a first-class association rather than a form field. Inline actions avoid the UX friction of entering full edit mode for a single-field change.

**Link direction**: `fetchUses` semantics — the contact object references the client. The card displays the resolved client name by loading the client object by UUID from the client objectStore.

### 3. Header Actions Slot (ADR-018 Fix)

**Decision**: In ClientDetail.vue contacts panel, change `<template #actions>` → `<template #header-actions>`.

**Rationale**: ADR-018 mandates `header-actions` as the canonical slot name. The `#actions` alias is deprecated. This is a one-line fix applied in the same change to avoid a separate PR.

### 4. No New API Endpoints

**Decision**: All operations use the existing generic `objectStore.saveObject()` and `objectStore.findObjects()`.

**Rationale**: No domain-specific business logic is required beyond setting/clearing a single reference field. Creating a custom endpoint would duplicate ObjectService functionality (ADR-012).

## Reuse Analysis

| Service / Component | Usage in this change |
|---|---|
| `objectStore.saveObject()` | Link: set `contact.client = clientId`; Unlink: set `contact.client = null` |
| `objectStore.findObjects()` | Search contacts in link dialog; load client name by UUID |
| `CnDetailCard` | Organization card on ContactDetail; contacts panel on ClientDetail |
| `#header-actions` slot | Link/unlink buttons on Organization card; Add/Link buttons on contacts panel |
| `CnFormDialog` | Base pattern for link dialog modals |
| `NcDialog` | Confirmation dialog for unlink (never `window.confirm()`) |

No new OpenRegister services, PHP files, or backend routes are required.

## Seed Data

No new schemas are introduced by this change. This is a frontend-only UI enhancement of an existing schema relationship. Per company ADR (Seed Data section): changes that only modify frontend components do not require seed data. The existing `contact` and `client` seed objects in `pipelinq_register.json` provide sufficient test data for verifying the link/unlink UI.

## File Structure

```
src/
  components/
    ContactLinkDialog.vue                 ← NEW: search contacts + link to org
    ContactLinkOrganizationDialog.vue     ← NEW: search clients + link to contact
  views/clients/
    ClientDetail.vue                      ← UPDATE: "Link contact" button, #header-actions fix
  views/contacts/
    ContactDetail.vue                     ← UPDATE: Organization CnDetailCard with link/unlink
```

## Seed Data Examples

The following existing seed objects in `pipelinq_register.json` support testing:

**contact objects** (Dutch values, illustrating the linking scenarios):

```json
{
  "@self": { "register": "pipelinq", "schema": "contact", "slug": "contact-jansen-pieter" },
  "name": "Pieter Jansen",
  "email": "p.jansen@vanderbergh.nl",
  "phone": "+31 20 512 3456",
  "role": "Hoofd Inkoop",
  "client": null
}
```

```json
{
  "@self": { "register": "pipelinq", "schema": "contact", "slug": "contact-de-vries-anke" },
  "name": "Anke de Vries",
  "email": "a.devries@gemeentewestland.nl",
  "phone": "+31 174 675 000",
  "role": "Projectleider",
  "client": "@ref:client-gemeente-westland"
}
```

```json
{
  "@self": { "register": "pipelinq", "schema": "contact", "slug": "contact-mulder-thomas" },
  "name": "Thomas Mulder",
  "email": "t.mulder@reisbureauoranje.nl",
  "phone": "+31 10 345 6789",
  "role": "Accountmanager",
  "client": null
}
```

**client objects** (organization type):

```json
{
  "@self": { "register": "pipelinq", "schema": "client", "slug": "client-gemeente-westland" },
  "name": "Gemeente Westland",
  "type": "organization",
  "email": "info@gemeentewestland.nl",
  "phone": "+31 174 675 000",
  "address": "Verdilaan 7, 2671 SZ Naaldwijk",
  "industry": "Lokale overheid"
}
```

```json
{
  "@self": { "register": "pipelinq", "schema": "client", "slug": "client-vanderbergh-consultancy" },
  "name": "Van der Bergh Consultancy B.V.",
  "type": "organization",
  "email": "info@vanderbergh.nl",
  "phone": "+31 20 512 3400",
  "address": "Keizersgracht 482, 1017 EG Amsterdam",
  "industry": "Consultancy"
}
```

```json
{
  "@self": { "register": "pipelinq", "schema": "client", "slug": "client-reisbureauoranje" },
  "name": "Reisbureau Oranje",
  "type": "organization",
  "email": "info@reisbureauoranje.nl",
  "phone": "+31 10 345 6700",
  "address": "Coolsingel 77, 3012 AG Rotterdam",
  "industry": "Reizen en toerisme"
}
```

## Trade-offs

**Single organization per contact**: `contact.client` is a single UUID — contacts link to one organization. This matches vCard ORG semantics and the existing schema. Multi-organization support is explicitly out of scope.

**Client-side filtering of already-linked contacts**: Rather than a custom backend filter, already-linked contacts are excluded client-side after fetch. At typical contact list sizes (< 1000) this is adequate. If performance becomes an issue in V2, a backend filter parameter can be added.

**Dialog vs inline select**: A modal dialog is used over an inline select to prevent layout disruption in the detail cards and to follow the established `CnFormDialog` pattern used throughout Pipelinq.
