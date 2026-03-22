# Queue Management Specification

## Purpose

Queue management provides priority-ordered work queues for organizing requests. Queues enable workload distribution across teams, ensuring urgent items are handled first and work is routed to the right agents. Queues are independent from pipelines: a request can be in a queue (waiting for pickup) AND on a pipeline (tracking progress).

**Standards**: Schema.org (`ItemList`)
**Feature tier**: Enterprise

---

## Data Model

### Queue Entity

| Property | Type | Schema.org | Required | Default |
|----------|------|------------|----------|---------|
| `title` | string | `schema:name` | Yes | -- |
| `description` | string | `schema:description` | No | -- |
| `categories` | array of string | `schema:category` | No | [] |
| `isActive` | boolean | -- | No | true |
| `maxCapacity` | integer | -- | No | null (unlimited) |
| `sortOrder` | integer | `schema:position` | No | 0 |
| `assignedAgents` | array of string | -- | No | [] |

---

## Requirements

### Requirement: Queue Entity CRUD [Enterprise]

The system SHALL support creating, reading, updating, and deleting queue entities. A queue is a named container for organizing work items (requests and leads) with priority-based ordering. Each queue SHALL be stored as an OpenRegister object with `@type` set to `schema:ItemList`.

#### Scenario: Create a queue with minimal fields
- **WHEN** an admin creates a queue with title "Algemene Zaken"
- **THEN** the system SHALL create an OpenRegister object with `@type` set to `schema:ItemList`
- **THEN** the queue SHALL have `isActive` set to `true` by default
- **THEN** the queue SHALL have `sortOrder` set to `0` by default

#### Scenario: Create a queue with all fields
- **WHEN** an admin creates a queue with title "Vergunningen", description "Alle vergunningsaanvragen", categories ["vergunningen", "omgevingsrecht"], and maxCapacity 50
- **THEN** the system SHALL store all provided fields on the OpenRegister object
- **THEN** the categories SHALL be used for automatic routing suggestions

#### Scenario: Update a queue
- **WHEN** an admin updates the title of queue "Algemene Zaken" to "Algemene Dienstverlening"
- **THEN** the system SHALL persist the change
- **THEN** existing items in the queue SHALL remain assigned to it

#### Scenario: Delete a queue
- **WHEN** an admin deletes queue "Oude Wachtrij" that contains 3 items
- **THEN** the system SHALL remove the queue object
- **THEN** all items in the queue SHALL have their `queue` reference cleared (unqueued)

#### Scenario: Validation - title is required
- **WHEN** an admin submits a queue without a title
- **THEN** the system SHALL reject the creation with a validation error indicating title is required

---

### Requirement: Queue Data Model [Enterprise]

The system SHALL define a `queue` schema in the OpenRegister pipelinq register with the properties listed in the Data Model section above.

#### Scenario: Schema registered in OpenRegister
- **WHEN** the Pipelinq repair step runs
- **THEN** a `queue` schema SHALL be registered in the pipelinq register
- **THEN** the schema SHALL have `@type` set to `schema:ItemList`
- **THEN** the `title` field SHALL be required

---

### Requirement: Queue Item Membership [Enterprise]

Requests and leads SHALL be assignable to exactly one queue at a time. The queue reference is stored on the item (request or lead) via its `queue` field.

#### Scenario: Add a request to a queue
- **WHEN** an agent adds request "Aanvraag parkeervergunning" to queue "Vergunningen"
- **THEN** the request's `queue` field SHALL be set to the queue's UUID
- **THEN** the request SHALL appear in the queue's item list

#### Scenario: Move a request between queues
- **WHEN** an agent moves a request from queue "Algemene Zaken" to queue "Vergunningen"
- **THEN** the request's `queue` field SHALL be updated to the new queue's UUID
- **THEN** the request SHALL no longer appear in "Algemene Zaken"
- **THEN** the request SHALL appear in "Vergunningen"

#### Scenario: Remove a request from a queue
- **WHEN** an agent removes a request from its queue
- **THEN** the request's `queue` field SHALL be cleared (null)
- **THEN** the request SHALL no longer appear in any queue's item list

#### Scenario: Queue capacity limit
- **WHEN** queue "Vergunningen" has maxCapacity 50 and already contains 50 items
- **THEN** the system SHALL prevent adding more items to that queue
- **THEN** the system SHALL display a warning "Queue is at capacity (50/50)"

---

### Requirement: Priority-Based Queue Ordering [Enterprise]

Items within a queue SHALL be automatically ordered by priority (urgent > high > normal > low), then by age (oldest `requestedAt` or `dateCreated` first within the same priority level). This ensures the most critical and longest-waiting items are served first.

#### Scenario: Items sorted by priority then age
- **WHEN** a queue contains: Request A (normal, created 2 days ago), Request B (urgent, created 1 hour ago), Request C (normal, created 5 days ago)
- **THEN** the display order SHALL be: B (urgent), C (normal, oldest), A (normal, newer)

#### Scenario: New urgent item appears at top
- **WHEN** a new request with priority "urgent" is added to a queue containing only "normal" priority items
- **THEN** the urgent request SHALL appear at the top of the queue

#### Scenario: Priority change re-orders queue
- **WHEN** a request's priority is changed from "normal" to "high"
- **THEN** the request's position in the queue SHALL be recalculated
- **THEN** it SHALL appear above all "normal" and "low" priority items

---

### Requirement: Queue List View [Enterprise]

The system SHALL provide a queue list view showing all queues with their current depth, oldest item age, and assigned agent count.

#### Scenario: View all queues
- **WHEN** an agent navigates to the queue list view
- **THEN** the system SHALL display a list of all active queues
- **THEN** each queue SHALL show: title, item count (depth), oldest item waiting time, number of assigned agents

#### Scenario: Queue depth indicator
- **WHEN** queue "Vergunningen" contains 12 items
- **THEN** the queue card SHALL display "12 items" as the depth
- **THEN** if maxCapacity is 50, it SHALL also show "12/50"

#### Scenario: Empty queue display
- **WHEN** queue "Klachten" has 0 items
- **THEN** the queue SHALL display "Empty" or "0 items"
- **THEN** the queue SHALL still be visible in the list (not hidden)

#### Scenario: Inactive queue visual treatment
- **WHEN** a queue has `isActive` set to `false`
- **THEN** the queue SHALL be visually muted (reduced opacity)
- **THEN** the queue SHALL display an "Inactive" badge

---

### Requirement: Queue Detail View [Enterprise]

The system SHALL provide a queue detail view showing all items in the queue with priority-based ordering, along with queue metadata and agent assignments.

#### Scenario: View queue items
- **WHEN** an agent navigates to queue "Vergunningen" detail view
- **THEN** the system SHALL display all items sorted by priority then age
- **THEN** each item SHALL show: entity type badge (REQ/LEAD), title, priority badge, age ("waiting 3 days"), assignee (if any), category

#### Scenario: Pick next item from queue
- **WHEN** an agent clicks "Pick next" on a queue
- **THEN** the system SHALL assign the top-priority item (first in queue order) to the current user
- **THEN** the item's `assignee` field SHALL be set to the current user's UID
- **THEN** a success message SHALL confirm the assignment

#### Scenario: Bulk assign from queue
- **WHEN** an agent selects multiple items and clicks "Assign to me"
- **THEN** all selected items SHALL have their `assignee` set to the current user

---

### Requirement: Queue Navigation [Enterprise]

The system SHALL add a "Queues" entry to the Pipelinq main navigation menu.

#### Scenario: Queue menu item
- **WHEN** a user views the Pipelinq navigation
- **THEN** a "Queues" menu item SHALL appear (icon: Tray)
- **THEN** clicking it SHALL navigate to the queue list view

#### Scenario: Queue route registration
- **WHEN** the router is initialized
- **THEN** routes SHALL be registered for `/queues` (list) and `/queues/:id` (detail)

---

### Requirement: Default Queues [Enterprise]

The system SHALL create default queues during the repair step to provide an out-of-box experience.

#### Scenario: Default queues created on install
- **WHEN** the Pipelinq repair step runs and no queues exist
- **THEN** the system SHALL create the following default queues:
  - "Algemeen" (General) with categories []
  - "Vergunningen" (Permits) with categories ["vergunningen"]
  - "Klachten" (Complaints) with categories ["klachten"]

#### Scenario: Default queues not duplicated
- **WHEN** the repair step runs and queues already exist
- **THEN** the system SHALL NOT create duplicate queues

---

### Current Implementation Status

**Implemented:**
- **Queue Entity CRUD:** Fully implemented. Queue schema defined in `lib/Settings/pipelinq_register.json` with `@type: schema:ItemList`. Properties include title, description, categories, isActive, maxCapacity, sortOrder, assignedAgents. CRUD via OpenRegister API through `src/store/modules/queues.js` Pinia store.
- **Queue Data Model:** Schema registered in pipelinq register. Repair step imports schema via `ConfigurationService::importFromApp()`.
- **Queue Item Membership:** Request schema includes `queue` field (UUID reference). Queue assignment, move, and removal implemented in `RequestDetail.vue` via dropdown. Queue column added to `RequestList.vue`.
- **Priority-Based Queue Ordering:** Implemented in `src/services/queueUtils.js` via `prioritySortComparator()`. Used in `QueueDetail.vue` and `MyWork.vue` queue tab.
- **Queue List View:** Implemented in `src/views/queues/QueueList.vue`. Shows title, item count, agent count, categories, active status. Supports create dialog.
- **Queue Detail View:** Implemented in `src/views/queues/QueueDetail.vue`. Shows items sorted by priority then age with entity badge, priority badge, waiting time, assignee. "Pick next" and bulk assign implemented.
- **Queue Navigation:** "Queues" item added to `src/navigation/MainMenu.vue` with InboxMultiple icon. Routes registered at `/queues` and `/queues/:id`.
- **Default Queues:** Created via `DefaultQueueService::createDefaultQueues()` called from repair step. Creates "Algemeen", "Vergunningen", "Klachten" if none exist.
