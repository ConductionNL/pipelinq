# Design: terugbel-taakbeheer

## Architecture

### Data Model (OpenRegister Schema)

New `taak` schema in the pipelinq register. Properties match ADR-000 `task` entity exactly:

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| type | string | Yes | Task type — terugbelverzoek, opvolgtaak, or informatievraag (facetable) |
| subject | string | Yes | Task subject line (VNG gevraagdeHandeling / schema:name) |
| description | string | No | Detailed task description |
| status | string | No | Lifecycle status: open / in_behandeling / afgerond / verlopen (default: open, facetable) |
| priority | string | No | Priority level: hoog / normaal / laag (default: normaal, facetable) |
| deadline | string | No | Task deadline date and time (ISO 8601) |
| assigneeUserId | string | No | Nextcloud user UID of the assigned handler (facetable) |
| assigneeGroupId | string | No | Nextcloud group ID for team/department assignment (facetable) |
| clientId | string | No | UUID reference to the associated client |
| requestId | string | No | UUID reference to the associated request |
| contactMomentSummary | string | No | Summary text from the originating contact moment |
| callbackPhoneNumber | string | No | Override phone number for callback |
| preferredTimeSlot | string | No | Citizen's preferred callback time window (e.g., 'Dinsdag 14:00 - 16:00') |
| createdBy | string | No | Nextcloud user UID of the agent who created this task |
| completedAt | string | No | Timestamp when the task was completed |
| resultText | string | No | Completion summary text |
| attempts | array | No | Callback attempt log — each entry: timestamp, result, notes |

**VNG mapping**: Maps to `InterneTaak` — `subject` → `gevraagdeHandeling`, `description` → `toelichting`, `status` → `status`, `assigneeUserId` → `toegewezenAanMedewerker`, `assigneeGroupId` → `toegewezenAanOrganisatorischeEenheid`.

**Schema.org mapping**: Maps to `schema:Action` — `subject` → `schema:name`, `description` → `schema:description`, `deadline` → `schema:scheduledTime`.

### Backend

#### TaskEscalationJob (`lib/BackgroundJob/TaskEscalationJob.php`)

`ITimedJob` running every 15 minutes (interval: 900 seconds). Registered in `appinfo/info.xml` under `<background-jobs>`.

Checks for:
1. Tasks approaching deadline (within 4 hours) with status `open` or `in_behandeling` — sends escalation reminder notification to assignee via `NotificationService`
2. Tasks past deadline with status `open` — changes status to `verlopen`, sends escalation notification
3. Tasks more than 24 hours past deadline with status `in_behandeling` — changes status to `verlopen`, sends escalation notification to assignee and `createdBy`

Idempotency: tracks last reminder timestamp to avoid duplicate notifications.

#### TaskService (`lib/Service/TaskService.php`)

- `calculateDeadline(string $createdAt, int $businessHours): string` — Calculate deadline in business hours, respecting Mon-Fri 08:00-17:00. Skips weekends.
- `getDefaultDeadline(): string` — Returns next business day at 17:00.
- `validateTask(array $data): array` — Validates required fields (`type`, `subject`); at least one of `assigneeUserId` or `assigneeGroupId` must be set.
- `logAttempt(string $taskId, string $result, string $notes): void` — Appends entry to `attempts` array with timestamp.
- `claimTask(string $taskId, string $userId): array` — Sets `assigneeUserId`, clears `assigneeGroupId`, status → `in_behandeling`.

### Frontend

#### Routes (added to `src/router/index.js`)

- `/tasks` — TaskList
- `/tasks/new` — TaskForm (create mode, `id='new'`)
- `/tasks/:id` — TaskDetail

#### Views

**TaskList.vue** (`src/views/tasks/TaskList.vue`)

Uses `CnIndexPage` with `useListView('taak', { sidebarState, objectStore })`.
- Filterable list with `CnStatusBadge` for status and priority
- `CnFacetSidebar` with facets: type, status, assigneeUserId, assigneeGroupId, priority
- `CnActionsBar` with search (subject + clientId) and "Nieuwe taak" button
- Row click → `$router.push({ name: 'TaskDetail', params: { id } })`

**TaskDetail.vue** (`src/views/tasks/TaskDetail.vue`)

Uses `CnDetailPage` with `useDetailView` composable.
- Header actions: Claim / Complete / Heropenen / Verwijderen buttons (conditional on status)
- `preferredTimeSlot` displayed in a highlighted banner when set
- `callbackPhoneNumber` displayed prominently alongside client default phone
- `CnDetailCard` sections: Task Info, Linked Entities (client, request, contactmoment), Attempt Log
- Status history via `CnObjectSidebar` audit trail tab
- `CnObjectSidebar` with Files, Notes, Tasks, Audit tabs

**TaskForm.vue** (`src/views/tasks/TaskForm.vue`)

Uses `CnFormDialog` schema-driven generation or `CnTabbedFormDialog` for complex layout.
- Unified form for terugbelverzoek, opvolgtaak, informatievraag (type selector adapts visible fields)
- User/group assignment autocomplete via Nextcloud OCS API (`/ocs/v1.php/cloud/users`, `/ocs/v1.php/cloud/groups`) — visually distinguishes users (person icon) from groups (group icon)
- Priority selector with Dutch labels (Hoog / Normaal / Laag)
- Deadline field with business-hours calculation — defaults to `TaskService.getDefaultDeadline()`
- `preferredTimeSlot` field for callback time preference
- `callbackPhoneNumber` field (shown when type is `terugbelverzoek`)

#### Navigation

Add "Taken" entry to `MainMenu.vue` with task/checklist icon. Route: `/tasks`.

#### My Work Integration

Extend `MyWork.vue` to:
- Fetch tasks from `objectStore` alongside existing leads and requests (via `Promise.all`)
- Add "Taken" filter button alongside "Leads" and "Requests" in the filter-buttons pattern
- Include tasks in the temporal grouping (overdue → today → this week → later)
- Apply `getPriorityColor` to tasks using priority field
- Show task type badge (Terugbelverzoek / Opvolgtaak / Informatievraag) on each task row
- Update count display: "Leads (N) — Verzoeken (N) — Taken (N) — N items totaal"
- Task row click → `$router.push({ name: 'TaskDetail', params: { id } })`

## Reuse Analysis

| Existing capability | How it is reused |
|---------------------|------------------|
| `ObjectService` (OpenRegister) | All taak CRUD — no custom mapper or controller needed |
| `NotificationService` | Task assignment and escalation notifications |
| `CnIndexPage` + `useListView` | TaskList.vue — list with search, filter, pagination |
| `CnDetailPage` + `useDetailView` | TaskDetail.vue — detail view with header actions |
| `CnObjectSidebar` | Audit trail, notes, files tabs on task detail |
| `createObjectStore` with plugins | Pinia store for taak objects (auditTrails, relations, search) |
| `ITimedJob` (Nextcloud) | TaskEscalationJob — deadline monitoring every 15 min |
| `CnFacetSidebar` | Faceted filter by status, priority, assignee, type |
| `columnsFromSchema()` / `fieldsFromSchema()` | Auto-generate table columns and form fields from taak schema |
| MyWork.vue temporal grouping | Extended to include tasks alongside leads/requests |

**No overlap found with existing custom task/workflow systems** — the existing `Task & Workflow Management` section in ADR-001 refers to OpenRegister's internal `TasksController`, which manages OpenRegister's built-in tasks attached to objects (via `CnTasksCard`). The `taak` schema here is domain-specific KCC task management, distinct from OpenRegister's internal task tracking.

## Seed Data

3-5 realistic `taak` objects for dev/test, included in `pipelinq_register.json` under `components.objects[]`:

```json
{
  "@self": {
    "register": "pipelinq",
    "schema": "taak",
    "slug": "taak-terugbel-vergunning-2024-001"
  },
  "type": "terugbelverzoek",
  "subject": "Terugbellen over status vergunningaanvraag",
  "description": "Burger wil update over doorlooptijd voor dakkapel vergunning, dossiernummer 2024-VG-0047. Heeft eerder twee brieven ontvangen maar wacht al 6 weken.",
  "status": "open",
  "priority": "hoog",
  "deadline": "2026-03-21T17:00:00+01:00",
  "assigneeGroupId": "afdeling-vergunningen",
  "contactMomentSummary": "Burger belde in over vergunningsstatus. Vraag doorgezet naar afdeling vergunningen.",
  "callbackPhoneNumber": "+31 6 12345678",
  "preferredTimeSlot": "Dinsdag 14:00 - 16:00",
  "createdBy": "kcc-agent-1",
  "attempts": []
}
```

```json
{
  "@self": {
    "register": "pipelinq",
    "schema": "taak",
    "slug": "taak-opvolg-parkeervergunning-2024-002"
  },
  "type": "opvolgtaak",
  "subject": "Opvolging aanvraag parkeervergunning bewonerszone B",
  "description": "Bewoner Maria Jansen heeft aanvraag ingediend voor parkeervergunning bewonerszone B. Aanvraag staat al 3 weken open. Navraag doen bij afdeling.",
  "status": "in_behandeling",
  "priority": "normaal",
  "deadline": "2026-03-22T17:00:00+01:00",
  "assigneeUserId": "petra.bakker",
  "requestId": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
  "createdBy": "kcc-agent-2",
  "attempts": []
}
```

```json
{
  "@self": {
    "register": "pipelinq",
    "schema": "taak",
    "slug": "taak-informatievraag-erfpacht-2024-003"
  },
  "type": "informatievraag",
  "subject": "Opzoeken of erfpachtregeling van toepassing is",
  "description": "Burger vraagt of zijn perceel aan de Keizersgracht 45 onder gemeentelijke erfpacht valt. KCC kon dit niet direct beantwoorden, vraag doorgezet naar afdeling Vastgoed.",
  "status": "open",
  "priority": "normaal",
  "deadline": "2026-03-23T17:00:00+01:00",
  "assigneeGroupId": "afdeling-vastgoed",
  "contactMomentSummary": "Burger belt over erfpacht perceel Keizersgracht 45. KCC heeft geen toegang tot erfpachtregister.",
  "createdBy": "kcc-agent-1",
  "attempts": []
}
```

```json
{
  "@self": {
    "register": "pipelinq",
    "schema": "taak",
    "slug": "taak-terugbel-uitkering-2024-004"
  },
  "type": "terugbelverzoek",
  "subject": "Terugbellen over stopzetting bijstandsuitkering",
  "description": "Mevrouw De Jong heeft een brief ontvangen over stopzetting uitkering maar begrijpt de reden niet. Heeft spoed, belt opnieuw morgen als ze niets hoort.",
  "status": "afgerond",
  "priority": "hoog",
  "deadline": "2026-03-19T12:00:00+01:00",
  "assigneeUserId": "mark.de.groot",
  "contactMomentSummary": "Mevrouw belde over stopzettingsbrief bijstand. Zeer bezorgd. Doorverbonden maar niet bereikt, terugbelverzoek aangemaakt.",
  "callbackPhoneNumber": "+31 10 9876543",
  "createdBy": "kcc-agent-3",
  "completedAt": "2026-03-19T10:30:00+01:00",
  "resultText": "Mevrouw teruggebeld, uitgelegd dat uitkering stopgezet is vanwege nieuwe inkomsten uit parttime werk. Verwezen naar loket voor herbeoordeling.",
  "attempts": [
    { "timestamp": "2026-03-19T09:15:00+01:00", "result": "niet_bereikbaar", "notes": "Telefoon ging over, geen voicemail." },
    { "timestamp": "2026-03-19T10:30:00+01:00", "result": "bereikt", "notes": "Mevrouw telefonisch bereikt en geholpen." }
  ]
}
```

```json
{
  "@self": {
    "register": "pipelinq",
    "schema": "taak",
    "slug": "taak-opvolg-pothole-keizersgracht-2024-005"
  },
  "type": "opvolgtaak",
  "subject": "Melding wegdek: gat in wegdek Keizersgracht t.h.v. nr. 100",
  "description": "Anonieme beller meldde een groot gat in het wegdek ter hoogte van Keizersgracht 100. Gevaarlijk voor fietsers. Doorgezet naar afdeling Beheer Openbare Ruimte voor inspectie.",
  "status": "verlopen",
  "priority": "hoog",
  "deadline": "2026-03-15T17:00:00+01:00",
  "assigneeGroupId": "afdeling-beheer-openbare-ruimte",
  "createdBy": "kcc-agent-2",
  "attempts": []
}
```

## Files Changed

### New Files
- `lib/Service/TaskService.php`
- `lib/BackgroundJob/TaskEscalationJob.php`
- `src/views/tasks/TaskList.vue`
- `src/views/tasks/TaskDetail.vue`
- `src/views/tasks/TaskForm.vue`

### Modified Files
- `lib/Settings/pipelinq_register.json` — Add `taak` schema and seed data objects; update register schemas list
- `appinfo/info.xml` — Register `TaskEscalationJob` under `<background-jobs>`
- `src/router/index.js` — Add task routes (`/tasks`, `/tasks/new`, `/tasks/:id`)
- `src/navigation/MainMenu.vue` — Add "Taken" nav item
- `src/views/MyWork.vue` — Extend to include tasks alongside leads and requests
