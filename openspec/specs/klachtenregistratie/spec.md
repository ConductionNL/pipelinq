---
status: done
---

# Klachtenregistratie (Complaint Registration) — Delta Spec

## Purpose

@e2e exclude backend delta spec — complaint schema and SLA logic are OR-object CRUD and PHP service; covered by PHPUnit

Add complaint registration and tracking to Pipelinq, enabling KCC agents and CRM users to register, categorize, follow up on, and resolve customer complaints. Complaints are linked to contacts and organizations with SLA-based deadline tracking and full audit trail.

**Main spec ref**: [client-management/spec.md](../../../../specs/client-management/spec.md)
**Feature tier**: V1
**Schema.org type**: `schema:ComplainAction`
**VNG mapping**: Klacht (no formal ZGW standard yet; modeled after Verzoek pattern)

---
## Requirements
### Requirement: Complaint Schema in Register

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

### Requirement: Complaint Registration Form

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

### Requirement: Complaint List View

Complaints SHALL be listed on the Tickets page under the view Klachten, with the ticket list's columns, filters, sort and bulk actions; its empty state offers Nieuw verzoek. Board: pipelinq/PqTickets (view Klachten).

#### Scenario: The Klachten view lists only complaints
- WHEN a user opens Tickets and chooses the view Klachten
- THEN only tickets of kind Klacht are listed, newest first, with status, assignee and deadline

### Requirement: Complaint Detail View

A complaint SHALL open as a ticket of kind Klacht on the ticket page, with the ticket's status transitions (in behandeling, afgerond, afgewezen) and its history. Board: pipelinq/PqTicket.

#### Scenario: A complaint's status is changed on the ticket page
- GIVEN an open complaint
- WHEN the handler sets it to In behandeling, Afgerond or Afgewezen
- THEN the ticket shows the new status and the history records who changed it and when

### Requirement: Complaint Audit Trail

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

### Requirement: Complaint Dashboard Widget

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

### Requirement: Complaints on Client Detail

A complaint SHALL appear on the client page as a ticket of kind Klacht in the Tickets card, with its status and deadline; the card's "Nieuw verzoek" action registers a complaint by choosing the kind Klacht. There SHALL be no separate Complaints section. Boards: pipelinq/PqKlant (Tickets card), pipelinq/PqNieuwVerzoek.

#### Scenario: A client's complaints are listed as tickets
- GIVEN a client with one open complaint
- WHEN a user opens the client page
- THEN the Tickets card lists the complaint with the kind Klacht, its status and its deadline
- AND Nieuw verzoek with kind Klacht registers another one

### Requirement: SLA Configuration

The admin settings MUST allow configuring SLA response times per complaint category.

#### Scenario: Configure SLA per category

- GIVEN the admin settings page
- WHEN the admin sets SLA for category "service" to 48 hours
- THEN new service complaints MUST have `slaDeadline` set to creation time + 48 hours

#### Scenario: Default SLA for unconfigured categories

- GIVEN no SLA is configured for category "other"
- WHEN a complaint with category "other" is created
- THEN no `slaDeadline` MUST be set (no deadline tracking)

---

### Requirement: Backend SLA Deadline Service

A PHP service MUST calculate SLA deadlines and provide SLA configuration helpers for backend use.

#### Scenario: Calculate deadline from category config

- GIVEN category "service" has 48 SLA hours configured
- WHEN `calculateDeadline('service')` is called
- THEN a DateTimeImmutable 48 hours from now MUST be returned

#### Scenario: No deadline for unconfigured category

- GIVEN category "other" has no SLA hours configured
- WHEN `calculateDeadline('other')` is called
- THEN null MUST be returned

#### Scenario: Overdue detection

- GIVEN an open complaint with slaDeadline in the past
- WHEN `isOverdue()` is called
- THEN true MUST be returned
- AND for resolved/rejected complaints, false MUST be returned regardless of deadline

---

### Requirement: Background Job for SLA Monitoring

A timed background job MUST periodically check for overdue complaints and log warnings. The check
MUST use the backend complaint SLA service's overdue detection
(`ComplaintSlaService::isOverdue()`) against each open complaint's category-derived `slaDeadline`.
The monitoring MAY be folded into the app's existing timed SLA sweep job rather than a separate
standalone job, provided overdue open complaints are surfaced as warnings on every run. The check
MUST be read-only with respect to the complaint object — it logs/surfaces the overdue state and
MUST NOT mutate the complaint's deadline or status.

**Feature tier**: V1

#### Scenario: Job warns on overdue open complaints

- GIVEN register and complaint_schema are configured
- AND an open complaint (status `new` or `in_progress`) has a `slaDeadline` in the past
- WHEN the timed SLA sweep job runs
- THEN it MUST log a warning identifying the overdue complaint (uuid, category, status, deadline)
- AND it MUST NOT log a warning for complaints whose status is `resolved` or `rejected`

#### Scenario: Job skips when not configured

- GIVEN register or complaint_schema is empty
- WHEN the timed SLA sweep job runs
- THEN it MUST skip complaint processing without raising an error

### Requirement: Complaint SLA computation — documented operations

The complaint SLA deadline computation implemented in this app MUST provide the operations enumerated in this change's tasks.md (for example `calculateDeadline`, `getSlaHoursForCategory`, `isOverdue`). Each listed method realises an observable part of complaint SLA deadline computation and MUST behave as implemented in the current codebase.

**Feature tier**: V1

#### Scenario: Documented operations are available

- GIVEN the backend service/controller is loaded
- WHEN a caller invokes one of the documented operations for complaint SLA deadline computation
- THEN the operation MUST execute and return a result consistent with the current implementation

---

### Requirement: Complaint SLA computation — results derived from current CRM state

Operations for complaint SLA deadline computation MUST read their inputs from the relevant CRM entities/configuration and compute results from that live state (no hard-coded or stubbed responses). Derivations such as formatting, aggregation, filtering and validation MUST reflect the data present at call time.

**Feature tier**: V1

#### Scenario: Results reflect live state

- GIVEN CRM data backing complaint SLA deadline computation
- WHEN a documented operation runs
- THEN its output MUST be derived from that data
- AND it MUST change when the underlying data changes

---

### Requirement: Complaint SLA computation — defensive handling of absent or invalid input

Operations for complaint SLA deadline computation MUST tolerate missing, empty, or malformed input without throwing unhandled errors — returning empty or default results, or surfacing a validation outcome as implemented, rather than crashing the surrounding flow.

**Feature tier**: V1

#### Scenario: Missing input does not crash the flow

- GIVEN an operation for complaint SLA deadline computation is called with absent or invalid input
- WHEN it executes
- THEN it MUST return a safe default or a validation result
- AND it MUST NOT raise an unhandled exception

### Requirement: Complaint UI — documented operations

The complaint registration screens implemented in this app MUST provide the operations enumerated in this change's tasks.md (for example `objectStore`, `onSave`, `applyStatusChange`, `assigneeOption`, `buildStatusHistory`, `complaintData`). Each listed method realises an observable part of complaint registration screens and MUST behave as implemented in the current codebase.

**Feature tier**: V1

#### Scenario: Documented operations are available

- GIVEN the frontend component/store is loaded
- WHEN a caller invokes one of the documented operations for complaint registration screens
- THEN the operation MUST execute and return a result consistent with the current implementation

---

### Requirement: Complaint UI — results derived from current CRM state

Operations for complaint registration screens MUST read their inputs from the relevant CRM entities/configuration and compute results from that live state (no hard-coded or stubbed responses). Derivations such as formatting, aggregation, filtering and validation MUST reflect the data present at call time.

**Feature tier**: V1

#### Scenario: Results reflect live state

- GIVEN CRM data backing complaint registration screens
- WHEN a documented operation runs
- THEN its output MUST be derived from that data
- AND it MUST change when the underlying data changes

---

### Requirement: Complaint UI — defensive handling of absent or invalid input

Operations for complaint registration screens MUST tolerate missing, empty, or malformed input without throwing unhandled errors — returning empty or default results, or surfacing a validation outcome as implemented, rather than crashing the surrounding flow.

**Feature tier**: V1

#### Scenario: Missing input does not crash the flow

- GIVEN an operation for complaint registration screens is called with absent or invalid input
- WHEN it executes
- THEN it MUST return a safe default or a validation result
- AND it MUST NOT raise an unhandled exception

