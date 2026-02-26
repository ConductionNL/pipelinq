## Context

Pipelinq has basic request CRUD via a simple `RequestList.vue` (table with search/pagination) and `RequestDetail.vue` (form). The frontend talks directly to OpenRegister API for object storage. Backend is minimal — only a `RequestChannelController` for SystemTag-based channel management exists. The current implementation is missing:

- `channel` field in the OpenRegister schema (UI field exists but data isn't persisted)
- Status lifecycle enforcement (any transition is allowed)
- Assignment UI (no user picker)
- Filtering/sorting beyond basic search
- Priority visual indicators
- Pipeline integration (schema fields exist but no kanban rendering of request cards)
- Request-to-case conversion

## Goals / Non-Goals

**Goals:**
- Full request list view with filtering (status, priority, assignee, channel), sorting, and pagination
- Status lifecycle enforcement with allowed transitions in the UI
- User assignment via Nextcloud user picker
- Priority badges with color coding across all views
- Channel field persistence (add to OpenRegister schema)
- Request cards on pipeline kanban boards (visually distinct from lead cards)
- Request-to-case conversion flow (V1)
- Rebuilt detail view with proper layout sections

**Non-Goals:**
- Activity timeline / audit trail (depends on OpenRegister event system — future work)
- Dashboard request widgets (separate `dashboard` spec)
- Notification system for assignments (future work)
- Request-to-case: full Procest integration (stub the conversion endpoint; Procest app may not exist yet)

## Decisions

### 1. Frontend-driven status transitions (no backend controller)

**Decision**: Enforce status transitions in the Vue frontend. The generic `objectStore.saveObject()` writes to OpenRegister, which has no transition logic. We add a `requestStatusService.js` utility that validates transitions before saving.

**Rationale**: Pipelinq's thin-client architecture means all CRUD goes through OpenRegister API. Adding a custom backend controller just for status validation would break the pattern. Frontend validation is sufficient because OpenRegister is not a public API — it's behind Nextcloud auth.

**Alternative considered**: Backend `RequestController` with transition logic. Rejected because it adds backend complexity for something the frontend can handle, and OpenRegister objects don't have update hooks.

### 2. Nextcloud user picker for assignment

**Decision**: Use `@nextcloud/vue`'s `NcSelect` with user search or a custom user picker that queries `/ocs/v2.php/cloud/users` for user lookup. Store the user UID string in `assignedTo`.

**Rationale**: Nextcloud has built-in user search. The `assignedTo` field stores a UID string, not a reference to an OpenRegister object. This matches how Nextcloud apps reference users.

### 3. Add `channel` to OpenRegister schema

**Decision**: Add `channel` as a string property to the request schema in `pipelinq_register.json`. Run repair step to update the schema. The channel dropdown in `RequestDetail.vue` already reads from `requestChannelsStore` — we just need the schema to persist the value.

**Rationale**: Without the schema field, OpenRegister silently drops the `channel` value on save. This is a bug fix.

### 4. Enhanced list view with filter bar

**Decision**: Add a filter bar component above the request table with NcSelect dropdowns for status, priority, assignee, and channel. Filters are applied as query parameters to `objectStore.fetchCollection()` which passes them to OpenRegister's `_search` API.

**Rationale**: OpenRegister supports server-side filtering via query params. This is more efficient than client-side filtering for large datasets.

### 5. Request cards on kanban — reuse lead card component

**Decision**: Create a generic `PipelineCard.vue` component that renders differently based on entity type. Lead cards show value; request cards show status. Both show priority badge, assignee avatar, and title. Entity type is distinguished by a `[LEAD]` or `[REQ]` badge.

**Rationale**: Leads and requests share the same pipeline/stage fields. A single card component with conditional rendering is cleaner than separate components.

### 6. Request-to-case conversion — stub endpoint

**Decision**: Add a "Convert to case" button on the detail view. Since Procest may not be installed, the conversion checks if Procest is available. If not, show a message. If available, call Procest's API to create a case, then update the request status to `converted` and store the case reference.

**Rationale**: The conversion flow is defined in the spec but Procest integration is a cross-app dependency. Stubbing allows the UI to be built now and connected later.

### 7. Rebuilt detail view with sections

**Decision**: Replace the current form-only `RequestDetail.vue` with a two-column layout:
- Left: Core info (title, description, status badge, priority badge, channel, category, requestedAt)
- Right: Assignment section (user picker), Pipeline section (stage indicator), Actions (Convert, Delete)
- Bottom: Client section (linked client info)

Use edit mode toggle: view mode shows read-only data with Edit button; edit mode shows the form.

## Risks / Trade-offs

**[Frontend-only validation]** → Status transitions are not enforced at the API level. A direct API call to OpenRegister could bypass transition rules. Mitigation: This is acceptable for internal Nextcloud use. If needed later, add an OpenRegister event listener.

**[Channel field migration]** → Existing requests created before the schema update won't have a `channel` value. Mitigation: The field is optional with no default. Existing requests simply show no channel, which is correct.

**[Procest dependency]** → Request-to-case conversion depends on Procest being installed. Mitigation: Check for Procest availability at runtime. Show "Procest not installed" message if unavailable. The button is always visible on eligible requests.

**[OpenRegister filter support]** → Server-side filtering depends on OpenRegister's query parameter support for all fields. Mitigation: If a field isn't filterable server-side, fall back to client-side filtering.
