# Design: project-task-hierarchy

## Architecture

### Data Model (OpenRegister Schemas)

All four schemas are added to `lib/Settings/pipelinq_register.json` under the existing `pipelinq` register.

---

#### `project`

Maps to `schema:Project` (sub-type of `schema:CreativeWork`). Top-level node in the WBS.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Project name (schema:name) |
| client | string (uuid) | No | Reference to client object |
| description | string | No | Project scope description |
| status | string | No | open \| on_hold \| completed \| cancelled |
| billable | boolean | No | Whether work on this project is billable; default true |
| budgetHours | number | No | Planned effort in hours |
| budgetAmount | number | No | Planned spend in EUR |
| hourlyRate | number | No | Default billing rate in EUR/hour |
| startDate | string (date) | No | Planned start date |
| endDate | string (date) | No | Planned end date |
| color | string | No | Hex colour tag for visual distinction |

---

#### `projectPhase`

An ordered milestone within a project. Maps to `schema:Event` (time-bounded work package).

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Phase name (schema:name) |
| project | string (uuid) | Yes | Reference to parent project |
| description | string | No | Phase objective |
| order | integer | No | Display order within the project |
| status | string | No | open \| in_progress \| completed \| cancelled |
| billable | boolean | No | Inherits from project when null |
| budgetHours | number | No | Phase-level hours budget |
| startDate | string (date) | No | Phase start date |
| endDate | string (date) | No | Phase end date |

---

#### `projectTask`

A deliverable within a phase. Maps to `schema:Action`.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Task name (schema:name) |
| phase | string (uuid) | Yes | Reference to parent phase |
| project | string (uuid) | No | Denormalised project reference for faster queries |
| description | string | No | Task description |
| order | integer | No | Display order within the phase |
| status | string | No | open \| in_progress \| completed \| cancelled |
| billable | boolean | No | Inherits from phase when null |
| estimatedHours | number | No | Planned effort for this task |
| assignee | string | No | Nextcloud user UID (schema:agent) |
| deadline | string (date) | No | Task completion deadline |

---

#### `projectActivity`

A time entry against a task. Maps to `schema:Action` with `duration`.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| task | string (uuid) | Yes | Reference to parent projectTask |
| project | string (uuid) | No | Denormalised project reference |
| description | string | No | Work done during this entry |
| date | string (date) | Yes | Date the work was performed |
| durationMinutes | integer | Yes | Duration in minutes |
| billable | boolean | No | Inherits from task when null; can be overridden per entry |
| user | string | Yes | Nextcloud user UID who performed the work |
| hourlyRate | number | No | Rate override in EUR/hour (inherits from project when null) |

---

### Billable Flag Inheritance

When a level's `billable` property is `null`/unset, the system reads the parent's resolved value:

```
projectActivity.billable ?? projectTask.billable ?? projectPhase.billable ?? project.billable ?? true
```

The resolved value is computed in the frontend (no backend calculation needed) and displayed with a "(inherited)" label in the UI.

---

### Frontend

#### Routes

```
/projects                       → ProjectList
/projects/:id                   → ProjectDetail
/projects/:id/activities        → ProjectActivityList (time entries for project)
```

Phase and task management are embedded within ProjectDetail — no separate routes.

#### Views

**`src/views/projects/ProjectList.vue`**
- Table: project name, client name, status badge, billable indicator, budget hours / logged hours, end date
- Filters: status, client, billable flag
- Search: by project name
- Add project button → new project detail (id='new')

**`src/views/projects/ProjectDetail.vue`**
- Header: project name, client link, status, colour swatch
- Summary cards: budgetHours vs loggedHours, budgetAmount vs billedAmount, open tasks count
- WBS tree: collapsible phase list with inline task list per phase
  - Each phase row: name, status badge, billable indicator, progress bar (tasks completed/total)
  - Each task row (indented): name, assignee avatar, estimated hours, logged hours, status chip
  - Inline "Add phase" / "Add task" buttons
- Sidebar: `CnObjectSidebar` with Files, Notes, Tags, Audit tabs
- Edit/Delete header actions

**`src/views/projects/ProjectActivityList.vue`**
- Table: date, user, task name, description, duration, billable badge
- Filters: date range, user, task, billable flag
- Total row: sum of billable hours and non-billable hours

#### Components

**`src/components/ProjectWbsTree.vue`**
- Recursive tree rendering phases and their tasks
- Collapse/expand per phase
- Inline status change chips
- Passes phase/task context to parent for add/edit actions

#### Navigation

Add "Projecten" entry to `src/navigation/MainMenu.vue` with a briefcase icon, linking to `/projects`.

---

### Stores

Four object stores created via `createObjectStore`:

```js
objectStore.registerObjectType('project', 'project', 'pipelinq')
objectStore.registerObjectType('projectPhase', 'projectPhase', 'pipelinq')
objectStore.registerObjectType('projectTask', 'projectTask', 'pipelinq')
objectStore.registerObjectType('projectActivity', 'projectActivity', 'pipelinq')
```

---

## Seed Data

Added to `lib/Settings/pipelinq_register.json` under `components.objects[]`.

### project objects

```json
{
  "@self": { "register": "pipelinq", "schema": "project", "slug": "project-digitalisering-amsterdam" },
  "name": "Digitalisering Dienstverlening",
  "client": "@ref:client-gemeente-amsterdam",
  "description": "Modernisering van de publieke dienstverlening via digitale kanalen",
  "status": "open",
  "billable": true,
  "budgetHours": 400,
  "budgetAmount": 56000.00,
  "hourlyRate": 140.00,
  "startDate": "2026-02-01",
  "endDate": "2026-08-31",
  "color": "#4A90D9"
}
```

```json
{
  "@self": { "register": "pipelinq", "schema": "project", "slug": "project-website-devries" },
  "name": "Website Herontwerp",
  "client": "@ref:client-devries-partners",
  "description": "Redesign van de corporate website inclusief CMS-migratie",
  "status": "in_progress",
  "billable": true,
  "budgetHours": 160,
  "budgetAmount": 19200.00,
  "hourlyRate": 120.00,
  "startDate": "2026-03-15",
  "endDate": "2026-06-30",
  "color": "#F5A623"
}
```

```json
{
  "@self": { "register": "pipelinq", "schema": "project", "slug": "project-hr-techbedrijf" },
  "name": "HR Systeem Implementatie",
  "client": "@ref:client-techbedrijf-bv",
  "description": "Implementatie en migratie naar nieuw HR-platform",
  "status": "open",
  "billable": false,
  "budgetHours": 80,
  "budgetAmount": 0,
  "hourlyRate": 0,
  "startDate": "2026-05-01",
  "endDate": "2026-07-31",
  "color": "#7ED321"
}
```

### projectPhase objects

```json
{
  "@self": { "register": "pipelinq", "schema": "projectPhase", "slug": "phase-digitalisering-voorbereiding" },
  "name": "Voorbereiding",
  "project": "@ref:project-digitalisering-amsterdam",
  "description": "Requirementsanalyse en stakeholderinterviews",
  "order": 1,
  "status": "completed",
  "billable": true,
  "budgetHours": 80,
  "startDate": "2026-02-01",
  "endDate": "2026-02-28"
}
```

```json
{
  "@self": { "register": "pipelinq", "schema": "projectPhase", "slug": "phase-digitalisering-ontwerp" },
  "name": "Ontwerp & Prototyping",
  "project": "@ref:project-digitalisering-amsterdam",
  "description": "UX-ontwerp, wireframes en klantvalidatie",
  "order": 2,
  "status": "in_progress",
  "billable": true,
  "budgetHours": 120,
  "startDate": "2026-03-01",
  "endDate": "2026-04-30"
}
```

```json
{
  "@self": { "register": "pipelinq", "schema": "projectPhase", "slug": "phase-website-discovery" },
  "name": "Discovery",
  "project": "@ref:project-website-devries",
  "description": "Intake, concurrentieanalyse en contentstrategie",
  "order": 1,
  "status": "completed",
  "billable": true,
  "budgetHours": 24,
  "startDate": "2026-03-15",
  "endDate": "2026-03-31"
}
```

### projectTask objects

```json
{
  "@self": { "register": "pipelinq", "schema": "projectTask", "slug": "task-requirementsanalyse" },
  "name": "Requirementsanalyse",
  "phase": "@ref:phase-digitalisering-voorbereiding",
  "project": "@ref:project-digitalisering-amsterdam",
  "description": "Documenteren van functionele en niet-functionele eisen via interviews",
  "order": 1,
  "status": "completed",
  "billable": true,
  "estimatedHours": 24,
  "assignee": "jan",
  "deadline": "2026-02-14"
}
```

```json
{
  "@self": { "register": "pipelinq", "schema": "projectTask", "slug": "task-wireframes" },
  "name": "Wireframes maken",
  "phase": "@ref:phase-digitalisering-ontwerp",
  "project": "@ref:project-digitalisering-amsterdam",
  "description": "Lage en hoge resolutie wireframes voor alle kernpagina's",
  "order": 1,
  "status": "in_progress",
  "billable": true,
  "estimatedHours": 40,
  "assignee": "maria",
  "deadline": "2026-03-31"
}
```

```json
{
  "@self": { "register": "pipelinq", "schema": "projectTask", "slug": "task-contentstrategie" },
  "name": "Contentstrategie opstellen",
  "phase": "@ref:phase-website-discovery",
  "project": "@ref:project-website-devries",
  "description": "Sitemap, inhoudstypen en SEO-aanbevelingen",
  "order": 2,
  "status": "completed",
  "billable": true,
  "estimatedHours": 8,
  "assignee": "pieter",
  "deadline": "2026-03-28"
}
```

### projectActivity objects

```json
{
  "@self": { "register": "pipelinq", "schema": "projectActivity", "slug": "activity-req-werksessie-1" },
  "task": "@ref:task-requirementsanalyse",
  "project": "@ref:project-digitalisering-amsterdam",
  "description": "Werksessie met projectteam gemeente — eerste ronde stakeholderinterviews",
  "date": "2026-02-05",
  "durationMinutes": 180,
  "billable": true,
  "user": "jan",
  "hourlyRate": 140.00
}
```

```json
{
  "@self": { "register": "pipelinq", "schema": "projectActivity", "slug": "activity-wireframes-iteratie-1" },
  "task": "@ref:task-wireframes",
  "project": "@ref:project-digitalisering-amsterdam",
  "description": "Eerste iteratie wireframes dashboard en zoekfunctionaliteit",
  "date": "2026-03-10",
  "durationMinutes": 240,
  "billable": true,
  "user": "maria",
  "hourlyRate": 140.00
}
```

```json
{
  "@self": { "register": "pipelinq", "schema": "projectActivity", "slug": "activity-contentstrategie-1" },
  "task": "@ref:task-contentstrategie",
  "project": "@ref:project-website-devries",
  "description": "Sitemap en inhoudsmatrix uitgewerkt, SEO-trefwoorden geïnventariseerd",
  "date": "2026-03-22",
  "durationMinutes": 120,
  "billable": true,
  "user": "pieter",
  "hourlyRate": 120.00
}
```

---

## Reuse Analysis

| Capability | OpenRegister / @conduction/nextcloud-vue provider | Custom code needed? |
|---|---|---|
| Project CRUD (create/read/update/delete) | `ObjectService` + `CnFormDialog` | No |
| Project list with search/filter/pagination | `CnIndexPage` + `useListView` | No |
| Project detail with sidebar tabs (files, notes, audit) | `CnDetailPage` + `CnObjectSidebar` | No |
| Phase & task inline editing | `CnFormDialog` (schema-driven) | No |
| Budget summary KPI cards | `CnStatsBlock` | No |
| Time entry table | `CnDataTable` inside `CnDetailCard` | No |
| Billable flag inheritance | N/A — UI-only computed property | **Yes** — `resolvedBillable()` helper in Vue component |
| WBS collapsible tree | N/A — no existing tree component | **Yes** — `ProjectWbsTree.vue` custom component |
| Denormalised `project` field on task/activity | N/A — set on save | **Yes** — set `project` UUID when creating task/activity |

No duplication with existing OpenRegister services or Pipelinq-specific service classes detected. The WBS tree rendering is the only genuinely custom UI component required.

---

## Files Changed

### New Files
- `src/views/projects/ProjectList.vue`
- `src/views/projects/ProjectDetail.vue`
- `src/views/projects/ProjectActivityList.vue`
- `src/components/ProjectWbsTree.vue`

### Modified Files
- `lib/Settings/pipelinq_register.json` — add project, projectPhase, projectTask, projectActivity schemas and seed data
- `src/router/index.js` — add /projects routes
- `src/navigation/MainMenu.vue` — add Projecten nav item
- `src/store/store.js` — register four new object stores
