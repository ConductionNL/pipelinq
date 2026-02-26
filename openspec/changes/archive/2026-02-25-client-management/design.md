# Design: client-management

## Architecture Overview

This change is frontend-only. All data operations use the existing generic object store (`useObjectStore`) which calls OpenRegister's API directly. No PHP backend changes are needed.

```
ClientForm.vue (new)
    ↓ validates fields, calls
objectStore.saveObject('client', data)
    ↓ which calls
POST/PUT /apps/openregister/api/objects/pipelinq/client[/id]

ClientList.vue (enhanced)
    ↓ calls with type filter
objectStore.fetchCollection('client', { type: 'organization', _search: '...' })
    ↓ which calls
GET /apps/openregister/api/objects/pipelinq/client?type=organization&_search=...

ContactList/Detail/Form.vue (new)
    ↓ same pattern via objectStore for 'contact' type
```

## Key Design Decisions

### 1. Reusable ClientForm Component

**Decision**: Extract form fields from ClientDetail into a dedicated `ClientForm.vue` component used for both create and edit.

**Rationale**: The current ClientDetail mixes display and edit logic. A separate form component enables:
- Cleaner validation logic
- Reuse in both create (empty) and edit (pre-populated) modes
- Consistent UX between create and edit

### 2. Client-Side Validation

**Decision**: Validate in the Vue component before calling the store. OpenRegister also validates server-side.

**Rules**:
- `name`: required, max 255 chars
- `type`: required, must be "person" or "organization"
- `email`: optional, must match email regex if provided
- `phone`: optional, basic format check
- `website`: optional, must match URL pattern

### 3. Type Filter via Query Parameter

**Decision**: Use OpenRegister's `?type=person` or `?type=organization` query parameter for filtering.

**Rationale**: The object store's `fetchCollection` already supports arbitrary query params. No new API needed.

### 4. Contact Person Views

**Decision**: Create three new components — ContactList, ContactDetail, ContactForm — following the exact same pattern as client views.

**Routing**: Add `contacts` and `contact-detail` routes to App.vue hash routing.

### 5. Delete Warning Dialog

**Decision**: Before deleting a client, fetch linked contacts/leads/requests counts and show a confirmation dialog.

**Implementation**: Use `fetchCollection` with the client UUID as a filter to count linked entities, then show `NcDialog` with the counts.

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `src/views/clients/ClientForm.vue` | CREATE | Form component with validation for create/edit |
| `src/views/clients/ClientList.vue` | MODIFY | Add type filter, sort toggle, empty state, pagination controls |
| `src/views/clients/ClientDetail.vue` | MODIFY | Improve linked entity display, add delete warning dialog |
| `src/views/contacts/ContactList.vue` | CREATE | Contact person list with search and client name |
| `src/views/contacts/ContactDetail.vue` | CREATE | Contact detail with client link |
| `src/views/contacts/ContactForm.vue` | CREATE | Contact form with validation |
| `src/App.vue` | MODIFY | Add contact routes to hash routing |
| `src/navigation/MainMenu.vue` | MODIFY | Add "Contacts" navigation item |
