# Proposal: contacts-sync

## Problem

Pipelinq stores contact persons (`contact`) and client organizations (`client`) with a `contact.client` field linking them. The current UI has two gaps:

1. **No way to link existing contacts to an organization** — the "Add contact" button on ClientDetail only creates new contacts. Existing contact persons cannot be associated with an organization after creation without entering full edit mode.
2. **No inline link/unlink on contact detail** — ContactDetail.vue shows the linked client as a static text field. To change or remove the organization link, the user must open the full edit form.

CRM agents managing organization accounts frequently need to associate existing contacts with a client (e.g., new hire, role change) or reassign a contact when someone moves organizations.

## Proposed Change

- Add a "Link contact" action to `ClientDetail.vue` that opens a search dialog for selecting an existing contact and linking it to the organization
- Add an inline "Organization" card to `ContactDetail.vue` with link/unlink actions that do not require entering full edit mode
- Fix `ClientDetail.vue` contacts panel to use `#header-actions` slot (ADR-018 compliance)

## Affected Projects
- [x] Project: `pipelinq` — Frontend-only: new dialog components, updates to two existing detail views

## Scope

### In Scope
- Link an existing contact to a client organization from the organization's detail view
- Link a contact to an organization from the contact's detail view (when currently unlinked)
- Unlink a contact from an organization from the contact's detail view (inline, no edit mode)
- ADR-018 `#header-actions` slot compliance for the contacts panel in ClientDetail.vue

### Out of Scope
- Bulk linking/unlinking of contacts (V1)
- Automatic relationship inference via AI (Enterprise)
- Many-to-many contact-organization membership (contacts link to one client by schema design)
- Contact deduplication across organizations (V1)
- Nextcloud Contacts (IManager) sync — handled by archived contacts-sync change

## Approach

Frontend-only. No schema changes required — `contact.client` already exists as a UUID reference field. Link and unlink operations call `contactStore.saveObject()` to update `contact.client`. The search dialog for linking uses the existing contact and client objectStore search capability. Two new small dialog components are introduced.

## Cross-Project Dependencies
None. All data operations use the existing OpenRegister ObjectService via the frontend store.

## Rollback Strategy
Frontend-only changes. New dialog components can be removed. Modifications to existing views are additive (new `CnDetailCard` section and button).

## Open Questions
None.
