# Proposal: client-management

## Problem

Pipelinq has basic client list and detail Vue components but they lack essential CRM functionality: no client-side validation, no type filtering, no proper empty states, no contact person management views, and no delete safety warnings. The existing `ClientList.vue` and `ClientDetail.vue` are scaffolds that need to be enhanced to match the MVP spec.

## Proposed Change

Enhance the existing client management frontend with proper validation, filtering, contact person CRUD, and improved UX. All data operations go through the existing generic object store which calls OpenRegister's API directly.

### Scope (MVP only)

**Client views:**
- Enhanced ClientList with type filter, search, sort, pagination controls, empty state
- Enhanced ClientDetail with linked entity sections (contacts, leads, requests) and delete warnings
- New ClientForm component with field validation (email, phone, URL, name length)

**Contact person views:**
- New ContactList with search, pagination, client name display
- New ContactDetail with client link navigation
- New ContactForm with validation (name required, client required)

**Navigation:**
- Add contact person routes to App.vue hash routing
- Ensure smooth navigation between client ↔ contact ↔ lead ↔ request

### Out of Scope
- Nextcloud Contacts sync (separate change)
- Activity timeline / audit trail display (needs OpenRegister audit system)
- Summary statistics panel (enhancement after core CRUD)
- Duplicate detection (V1)
- Import/export (V1)

## Impact

- **Files modified**: ~8-10 Vue/JS files
- **New files**: 3-4 Vue components (ContactList, ContactDetail, ContactForm, ClientForm)
- **Risk**: Low — enhancing existing frontend code, no backend changes needed (uses OpenRegister API directly)
