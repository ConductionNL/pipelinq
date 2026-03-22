## ADDED Requirements

### Requirement: Queue Management Section [Enterprise]

The admin settings page SHALL include a "Queues" section for managing queues. Admins can create, edit, and delete queues, configure categories, set capacity limits, and assign agents to queues.

#### Scenario: View queue list in admin settings
- **WHEN** an admin navigates to the Pipelinq admin settings
- **THEN** a "Queues" section SHALL be displayed after the Pipelines section
- **THEN** all queues SHALL be listed with title, item count, agent count, and active status

#### Scenario: Create a queue from admin settings
- **WHEN** an admin clicks "Add queue" and enters title "Vergunningen", categories ["vergunningen"], maxCapacity 50
- **THEN** a new queue object SHALL be created in OpenRegister
- **THEN** the queue SHALL appear in the queue list

#### Scenario: Edit a queue
- **WHEN** an admin clicks "Edit" on queue "Vergunningen"
- **THEN** a form SHALL display with all queue fields editable (title, description, categories, maxCapacity, isActive, sortOrder)
- **THEN** saving SHALL persist changes to OpenRegister

#### Scenario: Delete a queue from admin settings
- **WHEN** an admin clicks "Delete" on queue "Oude Wachtrij"
- **THEN** a confirmation dialog SHALL appear warning about items in the queue
- **THEN** confirming SHALL delete the queue and unqueue all items

#### Scenario: Assign agents to a queue
- **WHEN** an admin opens the agent assignment panel for queue "Vergunningen"
- **THEN** a user picker SHALL display all Nextcloud users
- **THEN** the admin SHALL be able to add/remove agents from the queue's assignedAgents list

---

### Requirement: Skill Management Section [Enterprise]

The admin settings page SHALL include a "Skills" section for managing skill definitions and agent skill profiles.

#### Scenario: View skills list in admin settings
- **WHEN** an admin navigates to the Pipelinq admin settings
- **THEN** a "Skills" section SHALL be displayed after the Queues section
- **THEN** all skills SHALL be listed with title, category mappings, and agent count

#### Scenario: Create a skill
- **WHEN** an admin clicks "Add skill" and enters title "Vergunningen", categories ["vergunningen", "omgevingsrecht"]
- **THEN** a new skill object SHALL be created in OpenRegister
- **THEN** the skill SHALL appear in the skills list

#### Scenario: Edit a skill
- **WHEN** an admin clicks "Edit" on skill "Vergunningen"
- **THEN** a form SHALL display with title, description, categories, and isActive fields
- **THEN** saving SHALL persist changes

#### Scenario: Delete a skill
- **WHEN** an admin deletes skill "Vergunningen"
- **THEN** the skill SHALL be removed from OpenRegister
- **THEN** the skill SHALL be removed from all agent profiles that reference it

#### Scenario: Manage agent skill profiles
- **WHEN** an admin opens the "Agent Skills" panel
- **THEN** a list of Nextcloud users SHALL be displayed
- **THEN** for each user, the admin SHALL be able to assign/remove skills, set maxConcurrent, and toggle isAvailable
- **THEN** changes SHALL be persisted to the agent's skill profile object in OpenRegister

## MODIFIED Requirements

### Requirement: Nextcloud Admin Panel Registration [MVP]

The system MUST register a settings page in the Nextcloud admin panel under "Administration". Only users with Nextcloud admin privileges MUST be able to access this page.

#### Scenario: Admin user accesses settings
- GIVEN a user with Nextcloud admin privileges
- WHEN they navigate to Administration settings
- THEN a "Pipelinq" section MUST appear in the admin settings navigation
- AND clicking it MUST display the Pipelinq settings page

#### Scenario: Non-admin user cannot access settings
- GIVEN a regular (non-admin) Nextcloud user
- WHEN they attempt to access the Pipelinq admin settings URL directly
- THEN the system MUST deny access (HTTP 403 or redirect)
- AND the "Pipelinq" section MUST NOT appear in their settings navigation

#### Scenario: Settings page structure
- GIVEN an admin user on the Pipelinq settings page
- THEN the page MUST display the following sections in order:
  1. Register Status
  2. Pipelines (with stage management)
  3. Queues [Enterprise]
  4. Skills [Enterprise]
  5. Lead Sources [V1]
  6. Request Channels [V1]
