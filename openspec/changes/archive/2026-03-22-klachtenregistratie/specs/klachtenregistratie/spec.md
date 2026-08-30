# Klachtenregistratie (Complaint Registration) — Delta Spec

## Purpose
Add complaint registration and tracking to Pipelinq, enabling KCC agents and CRM users to register, categorize, follow up on, and resolve customer complaints. Complaints are linked to contacts and organizations with SLA-based deadline tracking and full audit trail.

**Main spec ref**: [client-management/spec.md](../../../../specs/client-management/spec.md)
**Feature tier**: V1
**Schema.org type**: `schema:ComplainAction`
**VNG mapping**: Klacht (no formal ZGW standard yet; modeled after Verzoek pattern)

---

## Requirements

### REQ-KL-001: Complaint Schema in Register

The system MUST define a `complaint` schema in the Pipelinq register configuration with all required fields for complaint registration.

#### Scenario: Schema includes all required properties

- GIVEN the Pipelinq register configuration
- WHEN the complaint schema is loaded
- THEN it MUST include:
  - `title` (string, required, max 255 chars)
  - `description` (string, detailed complaint text)
  - `category` (enum: service, product, communication, billing, other; required; facetable)
  - `priority` (enum: low, normal, high, urgent; default: normal; facetable)
  - `status` (enum: new, in_progress, resolved, rejected; default: new; facetable)
  - `channel` (enum: phone, email, web, counter, letter, other; facetable)
  - `client` (uuid, reference to client)
  - `contact` (uuid, reference to contact person)
  - `assignedTo` (string, Nextcloud user ID of assigned agent)
  - `slaDeadline` (date-time, calculated from category SLA config)
  - `resolvedAt` (date-time, set when status moves to resolved/rejected)
  - `resolution` (string, explanation of resolution)

#### Scenario: Store initialization registers complaint type

- GIVEN the app settings include a `complaint_schema` config key
- WHEN `initializeStores()` runs
- THEN the object store MUST register `complaint` as a known type
- AND CRUD operations MUST work via `objectStore.saveObject('complaint', data)`

---

### REQ-KL-002: Complaint Registration Form

The system MUST provide a complaint form for creating and editing complaints with validation.

#### Scenario: Create complaint with required fields

- GIVEN a KCC agent opens the new complaint form
- WHEN they fill in title, category, and description
- THEN the form MUST validate:
  - `title` is required and max 255 characters
  - `category` is required (select from enum)
  - `description` is required (textarea)
  - `priority` defaults to "normal" but can be changed
  - `channel` is optional (select from enum)
- AND validation errors MUST appear inline next to the relevant field
- AND the save button MUST be disabled while required fields are empty

#### Scenario: Link complaint to existing client

- GIVEN the complaint form is open
- WHEN the agent searches for a client in the client selector
- THEN matching clients MUST appear as suggestions
- AND selecting a client MUST set the `client` UUID reference
- AND optionally, the agent can select a contact person belonging to that client

#### Scenario: Edit existing complaint

- GIVEN an existing complaint "Onjuiste factuur" with status "new"
- WHEN the agent opens the edit form
- THEN all existing field values MUST be pre-populated
- AND the agent can modify any field and save

---

### REQ-KL-003: Complaint List View

The complaint list MUST support search, filtering by status/category/priority, sorting, and pagination.

#### Scenario: Filter by status

- GIVEN 8 new complaints, 3 in-progress, and 2 resolved complaints
- WHEN the user selects "new" from the status filter
- THEN only the 8 new complaints MUST be shown
- AND the filter MUST be clearable

#### Scenario: Filter by category

- GIVEN complaints across multiple categories
- WHEN the user selects "service" from the category filter
- THEN only service complaints MUST be shown

#### Scenario: Search by title or description

- GIVEN complaints "Onjuiste factuur" and "Levertijd te lang"
- WHEN the user types "factuur" in the search box
- THEN "Onjuiste factuur" MUST appear in results
- AND search MUST be debounced (300ms)

#### Scenario: Sort by date or priority

- GIVEN the complaint list
- WHEN the user clicks the date column header
- THEN complaints MUST sort by creation date descending (newest first)
- AND clicking again MUST reverse to ascending

#### Scenario: Visual SLA overdue indicator

- GIVEN a complaint with `slaDeadline` in the past and status not resolved/rejected
- WHEN the user views the complaint list
- THEN the complaint row MUST display a visual overdue indicator (red badge or icon)

#### Scenario: Empty state

- GIVEN no complaints exist
- WHEN the user views the complaint list
- THEN an empty state message MUST display
- AND a "Register first complaint" button MUST be visible

#### Scenario: Pagination

- GIVEN 45 complaints with page size 20
- THEN page navigation MUST show current page, total pages, and total count
- AND prev/next buttons MUST be functional

---

### REQ-KL-004: Complaint Detail View

The complaint detail view MUST show all complaint information, linked entities, status timeline, and resolution fields.

#### Scenario: Display complaint details

- GIVEN a complaint "Onjuiste factuur" linked to client "Acme B.V."
- WHEN the agent views the complaint detail
- THEN all fields MUST be displayed: title, description, category, priority, status, channel, SLA deadline
- AND the linked client name MUST be clickable (navigates to client detail)
- AND the linked contact name MUST be clickable if set
- AND the assigned agent MUST be shown

#### Scenario: SLA deadline visual indicator

- GIVEN a complaint with SLA deadline in 2 hours
- WHEN the agent views the detail
- THEN the SLA deadline MUST show with an "approaching" warning indicator (orange)
- AND if the deadline is past, it MUST show as "overdue" (red)
- AND if resolved before deadline, it MUST show as "met" (green)

#### Scenario: Status transition buttons

- GIVEN a complaint with status "new"
- WHEN the agent views the detail
- THEN buttons for valid transitions MUST be shown:
  - "new" -> "In behandeling nemen" (to in_progress)
- AND from "in_progress":
  - "Afhandelen" (to resolved — requires resolution text)
  - "Afwijzen" (to rejected — requires resolution text)
- AND from "resolved"/"rejected": no further transitions

#### Scenario: Resolution requires explanation

- GIVEN a complaint in status "in_progress"
- WHEN the agent clicks "Afhandelen"
- THEN a dialog MUST appear requiring a resolution text
- AND the resolution MUST be saved with the status change
- AND `resolvedAt` MUST be set to the current timestamp

---

### REQ-KL-005: Complaint Audit Trail

The system MUST maintain a full audit trail of all complaint status changes visible on the complaint detail.

#### Scenario: Status change creates audit entry

- GIVEN a complaint transitions from "new" to "in_progress"
- THEN the audit trail MUST record:
  - Previous status
  - New status
  - Timestamp
  - User who made the change
- AND the trail MUST be visible as a timeline on the complaint detail

#### Scenario: Timeline shows chronological history

- GIVEN a complaint with 3 status changes
- WHEN the agent views the timeline
- THEN all changes MUST be shown in chronological order (newest first)
- AND each entry MUST show the status change, actor, and time

---

### REQ-KL-006: Complaint Dashboard Widget

The dashboard MUST include a complaints widget showing key metrics.

#### Scenario: Widget shows complaint counts

- GIVEN 5 open complaints (3 new, 2 in_progress) and 2 overdue
- WHEN the user views the dashboard
- THEN the complaints widget MUST show:
  - Total open complaints count
  - Overdue complaints count (with warning styling)
  - Breakdown by status (new / in_progress)
- AND clicking the widget MUST navigate to the complaint list

---

### REQ-KL-007: Complaints on Client Detail

Complaints linked to a client MUST be visible on the client detail view.

#### Scenario: Show complaint history on client detail

- GIVEN client "Acme B.V." with 3 complaints (1 new, 1 in_progress, 1 resolved)
- WHEN the agent views the client detail
- THEN a "Complaints" section MUST show all 3 complaints
- AND each complaint MUST display title, status, and date
- AND clicking a complaint MUST navigate to the complaint detail
- AND an "Add complaint" button MUST be visible

---

### REQ-KL-008: SLA Configuration

The admin settings MUST allow configuring SLA response times per complaint category.

#### Scenario: Configure SLA per category

- GIVEN the admin settings page
- WHEN the admin sets SLA for category "service" to 48 hours
- THEN new service complaints MUST have `slaDeadline` set to creation time + 48 hours

#### Scenario: Default SLA for unconfigured categories

- GIVEN no SLA is configured for category "other"
- WHEN a complaint with category "other" is created
- THEN no `slaDeadline` MUST be set (no deadline tracking)
