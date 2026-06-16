---
status: draft
---

# Specification: project-task-hierarchy

## Purpose

Define the requirements for a 4-level work breakdown structure (WBS) within Pipelinq:
**client → project → phase → task → activity**

Each level carries an independent `billable` flag that inherits downward when not explicitly set. This enables professional services firms to track billable and non-billable effort at the granularity they need.

**Standards**: Schema.org (`schema:Project`, `schema:Action`, `schema:Event`), ISO 8601 (dates/durations)
**Primary feature tier**: MVP (with V1 enhancements noted per requirement)
**Demand evidence**: 23/26 competitors implement at least a 3-level hierarchy with a billable flag

---

## Data Model

See design.md for full schema definitions.

| Entity | Schema.org type | Required parents |
|--------|----------------|-----------------|
| project | schema:Project | client (optional) |
| projectPhase | schema:Event | project |
| projectTask | schema:Action | projectPhase |
| projectActivity | schema:Action + duration | projectTask |

---

## Requirements

### REQ-PTH-001: Project CRUD [MVP]

The system MUST support creating, reading, updating, and deleting project records. Each project MUST have a `name`. Projects are stored as OpenRegister objects in the `pipelinq` register using the `project` schema.

#### Scenario 1: Create a minimal project

- GIVEN a user with CRM access
- WHEN they create a new project with name "Website Herontwerp"
- THEN the system MUST create an OpenRegister object with `@type` set to `schema:Project`
- AND `billable` MUST default to `true`
- AND `status` MUST default to `open`
- AND the audit trail MUST record the creation with the creating user's identity

#### Scenario 2: Create a project linked to a client

- GIVEN an existing client "De Vries & Partners"
- WHEN the user creates a project named "Jaarrapport 2026" and links it to that client
- THEN the project MUST store a `client` reference to the De Vries & Partners object
- AND the project MUST appear in the client's detail view under a "Projecten" section

#### Scenario 3: Update project budget

- GIVEN a project "Digitalisering Dienstverlening" with budgetHours 400 and budgetAmount 56000
- WHEN the user updates budgetHours to 450 and saves
- THEN the project object MUST reflect the new budgetHours value
- AND the audit trail MUST record the change (400 → 450)

#### Scenario 4: Delete a project

- GIVEN a project "Afgerond project" with status "completed" and no open phases
- WHEN the user deletes the project after confirming the dialog
- THEN the project object MUST be removed from OpenRegister
- AND the project MUST no longer appear in the project list or client detail view

#### Scenario 5: Reject project without name

- GIVEN a user creating a new project
- WHEN they submit the form without a name
- THEN the system MUST reject the request with the validation error "Naam is verplicht"
- AND the project MUST NOT be created

---

### REQ-PTH-002: Phase Management [MVP]

The system MUST support creating, ordering, and managing phases within a project. Phases are the second level of the WBS.

#### Scenario 6: Add a phase to a project

- GIVEN a project "Digitalisering Dienstverlening"
- WHEN the user adds a phase named "Voorbereiding" with order 1
- THEN a `projectPhase` object MUST be created with `project` referencing the parent project
- AND the phase MUST appear in the project's WBS tree at position 1

#### Scenario 7: Reorder phases

- GIVEN a project with phases: Voorbereiding (order 1), Ontwerp (order 2), Ontwikkeling (order 3)
- WHEN the user moves "Ontwikkeling" to position 2
- THEN Ontwerp MUST become order 3 and Ontwikkeling order 2
- AND the WBS tree MUST reflect the new order immediately

#### Scenario 8: Phase inherits billable flag from project

- GIVEN a project with `billable: false`
- WHEN a new phase is added without explicitly setting its `billable` flag
- THEN the phase's resolved billable value MUST be `false` (inherited from project)
- AND the UI MUST display "(geërfd van project)" next to the billable indicator

#### Scenario 9: Phase overrides billable flag

- GIVEN a project with `billable: false`
- WHEN the user creates a phase and explicitly sets `billable: true`
- THEN the phase's resolved billable value MUST be `true` (override)
- AND the phase's `billable` property MUST store `true`, not `null`

---

### REQ-PTH-003: Task Management [MVP]

The system MUST support creating and managing tasks within a phase. Tasks are the third level of the WBS.

#### Scenario 10: Add a task to a phase

- GIVEN a phase "Ontwerp & Prototyping" within project "Digitalisering Dienstverlening"
- WHEN the user adds a task "Wireframes maken" with estimatedHours 40 and assignee "maria"
- THEN a `projectTask` object MUST be created with `phase` referencing the parent phase
- AND the task MUST also store the denormalised `project` reference
- AND the task MUST appear under the phase in the WBS tree

#### Scenario 11: Task inherits billable flag from phase

- GIVEN a phase with `billable: true` (inherited from project)
- WHEN a task is created without setting `billable`
- THEN the task's resolved billable value MUST be `true`
- AND the UI MUST show "(geërfd van fase)" next to the billable indicator

#### Scenario 12: Assign task to a Nextcloud user

- GIVEN a task "Requirementsanalyse"
- WHEN the user assigns it to Nextcloud user "jan" (display name "Jan de Vries")
- THEN the task MUST store `assignee: "jan"`
- AND the task row in the WBS tree MUST display Jan's avatar

#### Scenario 13: Update task status to completed

- GIVEN a task "Contentstrategie opstellen" with status "in_progress"
- WHEN the user changes the status to "completed"
- THEN the task MUST update `status: "completed"`
- AND the phase's progress bar MUST update to reflect the newly completed task (tasks completed count increases by 1)

---

### REQ-PTH-004: Time Entry (Activity) Registration [MVP]

The system MUST support registering time entries against tasks. Each entry records the date, duration, user, and whether the time is billable.

#### Scenario 14: Register a time entry

- GIVEN a task "Wireframes maken"
- WHEN the user creates an activity with:
  - date: 2026-03-10
  - durationMinutes: 240
  - description: "Eerste iteratie wireframes dashboard"
  - user: "maria"
- THEN a `projectActivity` object MUST be created referencing the parent task
- AND the activity MUST also store the denormalised `project` reference
- AND the entry MUST appear in the project's activity list

#### Scenario 15: Duration displayed in hours and minutes

- GIVEN a time entry with durationMinutes 90
- WHEN the entry is displayed in any view
- THEN the system MUST display "1u 30min" (or equivalent locale-appropriate format)
- AND the lead total for the task MUST update to include the new 1.5 hours

#### Scenario 16: Activity inherits billable from task

- GIVEN a task with `billable: true`
- WHEN a time entry is created without an explicit `billable` flag
- THEN the entry's resolved billable value MUST be `true`
- AND the entry MUST appear in the billable hours total for the project

#### Scenario 17: Override billable on a single activity

- GIVEN a project with `billable: true` and a task with `billable: true`
- WHEN the user creates a time entry and explicitly sets `billable: false`
- THEN the activity MUST store `billable: false`
- AND that entry's hours MUST NOT count towards billable hours
- AND the resolved value for other entries on the same task remains `true`

#### Scenario 18: View all time entries for a project

- GIVEN a project with 15 time entries spread across multiple tasks
- WHEN the user navigates to the project's activity list
- THEN the system MUST display all 15 entries with columns: date, user, task name, description, duration, billable
- AND a totals row MUST show: total hours, billable hours, non-billable hours
- AND the user MUST be able to filter by date range, user, and billable flag

---

### REQ-PTH-005: Billable Flag Inheritance [MVP]

The system MUST implement a cascading billable flag across all four WBS levels. A level's effective billable status is the first explicitly set value encountered walking up the hierarchy.

#### Scenario 19: Full inheritance chain (all null)

- GIVEN a project with `billable: true`, phase with `billable: null`, task with `billable: null`, activity with `billable: null`
- WHEN the system resolves the activity's billable status
- THEN the resolved value MUST be `true` (inherited from project)
- AND each intermediate level MUST also resolve to `true`

#### Scenario 20: Override at phase level breaks the chain

- GIVEN a project with `billable: true`, a phase with `billable: false`, tasks and activities with `billable: null`
- WHEN the system resolves the activity's billable status
- THEN the resolved value MUST be `false` (phase override takes precedence over project)

#### Scenario 21: Override at task level

- GIVEN a project `billable: false`, phase `billable: null`, task `billable: true`, activity `billable: null`
- WHEN the system resolves the activity's billable status
- THEN the resolved value MUST be `true` (task override wins over phase and project)

---

### REQ-PTH-006: Project List View [MVP]

The system MUST provide a list view of all projects with search, filter, and sort capabilities.

#### Scenario 22: Display project list with key columns

- GIVEN 10 projects exist across multiple clients
- WHEN the user navigates to the Projecten section
- THEN the system MUST display a table with columns: name, client, status, billable indicator, budget hours / logged hours, end date
- AND each row MUST link to the project detail view
- AND pagination MUST be supported (default page size: 25)

#### Scenario 23: Filter projects by status

- GIVEN projects with statuses: open (6), completed (3), on_hold (1)
- WHEN the user filters by status "open"
- THEN exactly 6 projects MUST be shown

#### Scenario 24: Filter projects by client

- GIVEN projects linked to "Gemeente Amsterdam" (3) and "De Vries & Partners" (2)
- WHEN the user filters by client "Gemeente Amsterdam"
- THEN exactly 3 projects MUST be shown

#### Scenario 25: Search projects by name

- GIVEN projects named "Digitalisering Dienstverlening" and "Website Herontwerp"
- WHEN the user searches for "digital"
- THEN "Digitalisering Dienstverlening" MUST appear in results
- AND "Website Herontwerp" MUST NOT appear

---

### REQ-PTH-007: Project Detail View [MVP]

The system MUST provide a detail view for each project showing all properties, the WBS tree, and a budget summary.

#### Scenario 26: View project detail — core information

- GIVEN project "Digitalisering Dienstverlening" with budgetHours 400, budgetAmount EUR 56,000, status "open", startDate "2026-02-01"
- WHEN the user navigates to the project detail view
- THEN the system MUST display: name, linked client (clickable), status badge, billable indicator, budget hours, budget amount, start and end dates

#### Scenario 27: View budget summary cards

- GIVEN a project with budgetHours 400 and 180 hours of logged activities
- WHEN the user views the project detail
- THEN the system MUST display: "Geplande uren: 400", "Gelogde uren: 180", "Resterende uren: 220"
- AND logged hours MUST be calculated as the sum of `durationMinutes / 60` across all activities in the project

#### Scenario 28: View WBS tree with phases and tasks

- GIVEN a project with 2 phases and 5 tasks distributed across them
- WHEN the user views the project detail WBS section
- THEN the system MUST display each phase as a collapsible row
- AND tasks MUST be displayed indented under their phase
- AND each phase row MUST show: name, status, billable indicator, tasks completed/total (e.g., "2/3")
- AND each task row MUST show: name, assignee avatar, estimated hours, logged hours, status chip

#### Scenario 29: Inline add phase

- GIVEN the project detail WBS section
- WHEN the user clicks "Fase toevoegen"
- THEN the system MUST open a `CnFormDialog` pre-populated with the parent project
- AND upon saving, the new phase MUST appear at the end of the phase list

#### Scenario 30: Inline add task to a phase

- GIVEN a phase row in the WBS tree
- WHEN the user clicks "Taak toevoegen" on that phase
- THEN the system MUST open a `CnFormDialog` pre-populated with the parent phase and project
- AND upon saving, the new task MUST appear under that phase

---

### REQ-PTH-008: Budget Tracking [MVP]

The system MUST compare planned budget (hours and EUR) against actual logged hours per project.

#### Scenario 31: Over-budget warning on project

- GIVEN a project with budgetHours 80 and 85 logged hours
- WHEN the user views the project detail
- THEN the system MUST display the logged hours in a warning colour (amber/red)
- AND the label MUST read "Gelogde uren: 85 / 80 (5 uur over budget)"

#### Scenario 32: Billable hours vs non-billable breakdown

- GIVEN a project with 120 logged hours: 100 billable and 20 non-billable
- WHEN the user views the project detail summary
- THEN the system MUST display separately: "Factureerbaar: 100u", "Niet-factureerbaar: 20u"
- AND the billable amount MUST be calculated as billable hours × project hourlyRate (when set)

---

### REQ-PTH-009: Navigation and Deep Linking [MVP]

Projects MUST be accessible from the main navigation and from the linked client's detail view.

#### Scenario 33: Access projects from main navigation

- GIVEN the Pipelinq app is open
- WHEN the user clicks "Projecten" in the navigation menu
- THEN the system MUST navigate to `/projects` and display the project list

#### Scenario 34: Project link from client detail view

- GIVEN a client "Gemeente Amsterdam" with 3 linked projects
- WHEN the user views the client detail
- THEN a "Projecten" section MUST be present showing the 3 linked projects
- AND clicking a project name MUST navigate to that project's detail view

---

### REQ-PTH-010: Error Scenarios [MVP]

The system MUST handle error conditions gracefully.

#### Scenario 35: Create activity when OpenRegister is unavailable

- GIVEN the OpenRegister API is unreachable
- WHEN the user submits a new time entry
- THEN the system MUST display an error message: "Kon tijdregistratie niet opslaan. Probeer het opnieuw."
- AND the form data MUST be preserved

#### Scenario 36: Create task without a parent phase

- GIVEN a user creating a new projectTask via the API
- WHEN the `phase` field is missing or invalid
- THEN the system MUST reject the request with validation error "Fase is verplicht"
- AND the task MUST NOT be created

---

### REQ-PTH-011: Project Status Lifecycle [V1]

The system SHOULD support a managed status lifecycle for projects with allowed transitions and audit recording.

#### Scenario 37: Transition project to completed

- GIVEN a project with status "open" and all tasks at status "completed"
- WHEN the user changes status to "completed"
- THEN the system MUST update the project status
- AND the audit trail MUST record the transition with timestamp and user
- AND the project MUST no longer appear in "Open Projecten" dashboard count

#### Scenario 38: Prevent completing project with open tasks

- GIVEN a project where 2 tasks still have status "open"
- WHEN the user attempts to set project status to "completed"
- THEN the system SHOULD warn: "Dit project heeft nog 2 openstaande taken."
- BUT the user MUST be able to proceed after acknowledging the warning

---

### REQ-PTH-012: CSV Export of Time Entries [V1]

The system SHOULD support exporting time entries for a project to CSV for invoicing or external reporting.

#### Scenario 39: Export project activities to CSV

- GIVEN a project activity list showing 30 entries
- WHEN the user clicks "Exporteer CSV"
- THEN the system MUST generate a CSV with columns: date, user, project, phase, task, description, durationMinutes, billable, hourlyRate
- AND the file MUST be downloaded to the user's browser
- AND only the currently filtered entries MUST be included in the export
