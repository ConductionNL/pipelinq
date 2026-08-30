# Design: klachtenregistratie

## Architecture Overview

This change follows the established Pipelinq thin-client pattern. All data operations use the existing generic object store (`useObjectStore`) which calls OpenRegister's API directly. Backend changes are limited to register schema configuration and settings.

```
ComplaintForm.vue (new)
    | validates fields, calls
objectStore.saveObject('complaint', data)
    | which calls
POST/PUT /apps/openregister/api/objects/pipelinq/complaint[/id]

ComplaintList.vue (new)
    | calls with filters
objectStore.fetchCollection('complaint', { status: 'new', _search: '...' })
    | which calls
GET /apps/openregister/api/objects/pipelinq/complaint?status=new&_search=...

ComplaintDetail.vue (new)
    | reads single object + linked client/contact
objectStore.fetchObject('complaint', id)
    | which calls
GET /apps/openregister/api/objects/pipelinq/complaint/{id}
```

## Key Design Decisions

### 1. Complaint Schema in Register Config

**Decision**: Add a `complaint` schema to `lib/Settings/pipelinq_register.json` following the exact same pattern as the `request` schema.

**Rationale**: Complaints share many structural similarities with requests (client/contact linking, status workflow, priority, channel). Using the same pattern ensures consistency and reuse of existing store infrastructure.

**Schema type**: `schema:ComplainAction` (Schema.org)

### 2. Status Lifecycle Service

**Decision**: Create `src/services/complaintStatus.js` following the exact same pattern as `src/services/requestStatus.js`.

**Transitions**:
- `new` -> `in_progress`
- `in_progress` -> `resolved`, `rejected`
- `resolved` -> (terminal)
- `rejected` -> (terminal)

**Rationale**: Simpler workflow than requests (no "converted" state). Terminal states require a resolution text. This mirrors the tender requirements for complaint tracking.

### 3. SLA Deadline Calculation

**Decision**: Calculate `slaDeadline` client-side at complaint creation time based on admin-configured SLA hours per category. Store deadline as ISO 8601 datetime.

**Rationale**: Server-side calculation would require a custom backend endpoint. Since Pipelinq is a thin client, we compute the deadline in the form component and save it as a property. OpenRegister stores it as-is.

**Implementation**: `SettingsService` stores SLA config as `complaint_sla_{category}` app config values. The form reads these when setting `slaDeadline`.

### 4. Audit Trail via Object History

**Decision**: Use OpenRegister's built-in audit log (`_auditTrail` on objects) for status change tracking rather than building a custom timeline.

**Rationale**: OpenRegister already tracks all object changes with timestamps, actors, and diffs. We render these as a timeline on the complaint detail, filtering for `status` field changes.

**Fallback**: If `_auditTrail` is not available, maintain a `statusHistory` array property on the complaint object itself (JSON array of `{ from, to, timestamp, actor }` entries).

### 5. View Components Follow Request Pattern

**Decision**: Create ComplaintList, ComplaintDetail, ComplaintForm, and ComplaintCreateDialog components mirroring the request views exactly.

**Rationale**: Consistency in UX and code structure. Users familiar with the request workflow will immediately understand complaints. Developers benefit from predictable file locations.

### 6. Dashboard Widget

**Decision**: Create `ComplaintsOverviewWidget.vue` in `src/views/widgets/` showing open and overdue complaint counts.

**Rationale**: The proposal specifically requires a dashboard widget. Follow the same card-based pattern as existing dashboard widgets (MyLeadsWidget, DealsOverviewWidget).

### 7. Client Detail Integration

**Decision**: Add a "Complaints" section to `ClientDetail.vue` by fetching complaints filtered by client UUID, following the same pattern as the existing contacts/leads/requests sections.

### 8. Navigation Placement

**Decision**: Add "Complaints" navigation item after "Requests" in MainMenu.vue, using `AlertCircleOutline` (mdi) icon.

**Rationale**: Complaints are closely related to requests in the CRM workflow. Placing them adjacent creates a logical grouping.

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `lib/Settings/pipelinq_register.json` | MODIFY | Add `complaint` schema definition |
| `src/store/store.js` | MODIFY | Register `complaint` object type from settings |
| `src/services/complaintStatus.js` | CREATE | Status transitions, labels, colors for complaints |
| `src/views/complaints/ComplaintList.vue` | CREATE | List view with CnIndexPage, filters, SLA indicators |
| `src/views/complaints/ComplaintDetail.vue` | CREATE | Detail view with status transitions, timeline, resolution |
| `src/views/complaints/ComplaintForm.vue` | CREATE | Create/edit form with validation and client linking |
| `src/views/complaints/ComplaintCreateDialog.vue` | CREATE | Quick-create dialog (from client detail or nav) |
| `src/views/widgets/ComplaintsOverviewWidget.vue` | CREATE | Dashboard widget showing open/overdue counts |
| `src/views/Dashboard.vue` | MODIFY | Add ComplaintsOverviewWidget to dashboard layout |
| `src/views/clients/ClientDetail.vue` | MODIFY | Add complaints section showing linked complaints |
| `src/router/index.js` | MODIFY | Add `/complaints` and `/complaints/:id` routes |
| `src/navigation/MainMenu.vue` | MODIFY | Add "Complaints" navigation item |
| `lib/Service/SettingsService.php` | MODIFY | Add `complaint_schema` and SLA config keys |
