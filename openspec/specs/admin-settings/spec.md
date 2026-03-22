# Admin Settings Specification

## Purpose

The admin settings page provides a Nextcloud admin panel for configuring Pipelinq. Administrators can manage pipelines and their stages, set a default pipeline, and configure lead source and request channel values. Only Nextcloud admin users can access this page. The design follows the wireframe in DESIGN-REFERENCES.md section 3.7.

**Feature tier**: MVP (admin page, pipeline CRUD, stage CRUD, default pipeline), V1 (lead source config, request channel config)

---

## Requirements

### REQ-AS-010: Nextcloud Admin Panel Registration [MVP]

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
  5. Agent Profiles [Enterprise]
  6. Lead Sources [V1]
  7. Request Channels [V1]

---

### REQ-AS-012: Register Status Display [MVP]

The admin settings page MUST display the current register configuration status so administrators can verify the OpenRegister integration is working.

#### Scenario: Register is configured

- GIVEN the repair step has run and the register exists
- WHEN the admin opens the settings page
- THEN it MUST show:
  - Register name: "pipelinq"
  - Register ID (from IAppConfig)
  - Status indicator: "Connected" (green)
  - List of 5 schemas with their names and IDs

#### Scenario: Register is not configured

- GIVEN OpenRegister is not installed or the repair step hasn't run
- WHEN the admin opens the settings page
- THEN it MUST show:
  - Status indicator: "Not configured" (orange/warning)
  - Message: "OpenRegister is required. Install and enable it, then click Re-import."

---

### REQ-AS-015: Re-import Configuration Action [MVP]

The admin settings page MUST provide a button to re-run the register configuration import, allowing administrators to recover from failed imports or apply updated schemas.

#### Scenario: Re-import succeeds

- GIVEN the admin clicks "Re-import configuration"
- WHEN the backend processes the request via `POST /api/settings/reimport`
- THEN it MUST call `SettingsService::loadSettings(force: true)` which delegates to `ConfigurationService::importFromApp()`
- AND the page MUST refresh to show updated schema list
- AND a success notification MUST be displayed

#### Scenario: Re-import fails

- GIVEN OpenRegister is not available
- WHEN the admin clicks "Re-import configuration"
- THEN an error notification MUST be displayed
- AND the error message MUST indicate what went wrong

---

### REQ-AS-020: Pipeline Management [MVP]

The admin settings MUST provide full CRUD operations for pipelines. Pipelines are stored as OpenRegister objects with schema `pipeline`.

#### Scenario: List all pipelines
- GIVEN the system has 2 pipelines: "Sales Pipeline" (default, 7 stages) and "Service Pipeline" (5 stages)
- WHEN the admin views the Pipelines section
- THEN the system MUST display both pipelines
- AND each pipeline MUST show: title, default indicator (star icon for the default), stage count, entity types, and a compact stage flow (e.g., "New -> Contacted -> Qualified -> ... -> Won -> Lost")
- AND each pipeline MUST have an "Edit" action button

#### Scenario: Create a new pipeline
- GIVEN the admin clicks "+ Add Pipeline"
- WHEN they enter title "Enterprise Sales", select entity types ["lead"], and save
- THEN a new pipeline MUST be created in OpenRegister
- AND the pipeline MUST appear in the pipeline list
- AND the admin MUST be immediately redirected to the stage management for this pipeline (so they can add stages)

#### Scenario: Create pipeline -- title required
- GIVEN the admin is creating a new pipeline
- WHEN they submit without entering a title
- THEN the system MUST display a validation error: "Pipeline title is required"
- AND the pipeline MUST NOT be created

#### Scenario: Edit pipeline title and entity types
- GIVEN an existing pipeline "Sales Pipeline"
- WHEN the admin changes the title to "B2B Sales Pipeline" and saves
- THEN the pipeline title MUST be updated
- AND all leads/requests referencing this pipeline MUST continue to work (pipeline reference is by ID, not by title)

#### Scenario: Delete a pipeline
- GIVEN a pipeline "Old Pipeline" that is NOT the default pipeline
- WHEN the admin clicks "Delete" and confirms the deletion
- THEN the pipeline and all its stages MUST be removed from OpenRegister
- AND the system MUST display a warning before deletion: "X leads and Y requests are on this pipeline. They will be removed from the pipeline but not deleted."
- AND leads/requests that were on this pipeline MUST have their `pipeline` and `stage` references cleared (set to null)

#### Scenario: Delete pipeline -- confirmation required
- GIVEN a pipeline with 5 leads on it
- WHEN the admin clicks "Delete"
- THEN the system MUST show a confirmation dialog with the count of affected items
- AND deletion MUST NOT proceed until the admin confirms

#### Scenario: Delete default pipeline -- prevented
- GIVEN the "Sales Pipeline" is marked as default
- WHEN the admin attempts to delete it
- THEN the system MUST prevent deletion
- AND the system MUST display an error: "Cannot delete the default pipeline. Set another pipeline as default first."

---

### REQ-AS-030: Stage Management [MVP]

The admin settings MUST provide CRUD operations for stages within each pipeline. Stages are stored as OpenRegister objects with schema `stage`.

#### Scenario: List stages for a pipeline
- GIVEN the admin is editing "Sales Pipeline"
- WHEN the stage management section is displayed
- THEN the system MUST list all stages in order: New (0), Contacted (1), Qualified (2), Proposal (3), Negotiation (4), Won (5), Lost (6)
- AND each stage MUST show: title, order number, probability (if set), isClosed flag, isWon flag, and color (if set)
- AND stages MUST be displayed in ascending order by their `order` field

#### Scenario: Add a new stage
- GIVEN the admin is editing "Sales Pipeline"
- WHEN they click "+ Add Stage" and enter title "Demo", probability 50, order 3
- THEN a new stage MUST be created in OpenRegister with `pipeline` referencing "Sales Pipeline"
- AND if the order conflicts with existing stages, the system MUST automatically re-order subsequent stages (shift them up by 1)

#### Scenario: Add stage -- title required
- GIVEN the admin is adding a stage
- WHEN they submit without a title
- THEN the system MUST display a validation error: "Stage title is required"

#### Scenario: Edit a stage
- GIVEN the stage "Contacted" with probability 20
- WHEN the admin changes the title to "First Contact" and probability to 25
- THEN the stage MUST be updated in OpenRegister
- AND leads in this stage MUST continue to reference it correctly (reference is by ID)

#### Scenario: Reorder stages via drag-and-drop
- GIVEN stages in order: New (0), Contacted (1), Qualified (2)
- WHEN the admin drags "Qualified" between "New" and "Contacted"
- THEN the order MUST update to: New (0), Qualified (1), Contacted (2)
- AND the `order` field of all affected stages MUST be updated in OpenRegister
- AND leads on these stages MUST retain their stage assignment (only the stage order changes, not the stage-to-lead relationship)

#### Scenario: Delete a stage
- GIVEN a stage "Demo" with 0 leads/requests currently in it
- WHEN the admin deletes the stage
- THEN the stage MUST be removed from OpenRegister
- AND subsequent stages MUST be re-ordered to fill the gap

#### Scenario: Delete a stage with items on it
- GIVEN a stage "Qualified" with 3 leads and 1 request
- WHEN the admin deletes the stage
- THEN the system MUST display a warning: "4 items are currently in this stage. They will be moved to the previous stage."
- AND upon confirmation, the 4 items MUST be moved to the stage with the next lower order number
- AND if no previous stage exists (order 0 is being deleted), items MUST be moved to the next stage

#### Scenario: Stage validation -- unique order within pipeline
- GIVEN stages with orders 0, 1, 2, 3 in a pipeline
- WHEN the admin manually sets a stage order to a value that already exists
- THEN the system MUST automatically adjust other stage orders to maintain uniqueness
- AND no two stages in the same pipeline MUST have the same `order` value

#### Scenario: Stage validation -- at least one non-closed stage
- GIVEN a pipeline with stages: "New" (isClosed=false) and "Done" (isClosed=true)
- WHEN the admin attempts to delete "New" (the only non-closed stage)
- THEN the system MUST prevent deletion
- AND the system MUST display an error: "A pipeline must have at least one non-closed stage"

#### Scenario: Stage validation -- at least one non-closed stage (edit)
- GIVEN a pipeline with stages: "Active" (isClosed=false) and "Done" (isClosed=true)
- WHEN the admin attempts to set "Active" to isClosed=true
- THEN the system MUST prevent the change
- AND the system MUST display an error: "A pipeline must have at least one non-closed stage"

---

### REQ-AS-040: Default Pipeline Selection [MVP]

The admin settings MUST allow selecting one pipeline as the default. The default pipeline is used when creating new leads or requests that are not explicitly assigned to a pipeline.

#### Scenario: Set default pipeline
- GIVEN pipelines "Sales Pipeline" and "Service Pipeline" exist, with "Sales Pipeline" as default
- WHEN the admin marks "Service Pipeline" as default
- THEN the "Service Pipeline" `isDefault` field MUST be set to `true`
- AND the "Sales Pipeline" `isDefault` field MUST be set to `false`
- AND only one pipeline MUST have `isDefault = true` at any time

#### Scenario: Default pipeline indicator
- GIVEN "Sales Pipeline" is the default
- WHEN the admin views the pipeline list
- THEN "Sales Pipeline" MUST display a visual indicator (e.g., star icon, "(default)" label)
- AND other pipelines MUST NOT display this indicator

#### Scenario: New lead uses default pipeline
- GIVEN "Sales Pipeline" is the default pipeline
- WHEN a user creates a new lead without specifying a pipeline
- THEN the lead SHOULD be placed on "Sales Pipeline" at the first non-closed stage (lowest order)

#### Scenario: First pipeline auto-becomes default
- GIVEN no pipelines exist
- WHEN the admin creates the first pipeline
- THEN it MUST automatically be marked as default

#### Scenario: Cannot unset default without replacement
- GIVEN "Sales Pipeline" is the only pipeline and is default
- WHEN the admin attempts to unmark it as default
- THEN the system MUST prevent this
- AND the system MUST display: "At least one pipeline must be set as default"

---

### REQ-AS-050: Lead Source Configuration [V1]

The admin settings SHOULD allow customizing the list of available lead source values. Lead sources are displayed as a dropdown when creating or editing leads.

#### Scenario: Default lead sources
- GIVEN a fresh Pipelinq installation
- THEN the following lead sources MUST be available by default: `website`, `email`, `phone`, `referral`, `partner`, `campaign`, `social_media`, `event`, `other`

#### Scenario: List lead sources
- GIVEN the admin views the Lead Sources section
- THEN all configured sources MUST be displayed
- AND each source MUST show its label
- AND each unused source MUST have a "Remove" action

#### Scenario: Add a custom source
- GIVEN the admin clicks "+ Add Source"
- WHEN they enter "Trade Show" and save
- THEN "Trade Show" MUST be added to the source list
- AND "Trade Show" MUST appear as an option in the lead creation/edit form's source dropdown

#### Scenario: Add duplicate source -- prevented
- GIVEN "website" already exists as a lead source
- WHEN the admin attempts to add "website" again
- THEN the system MUST prevent the addition
- AND the system MUST display: "This source already exists"

#### Scenario: Remove an unused source
- GIVEN lead source "social_media" exists and no leads use it
- WHEN the admin removes "social_media"
- THEN it MUST no longer appear in the source list or creation form dropdown
- AND existing leads MUST NOT be affected (no leads reference it)

#### Scenario: Remove a source used by existing leads
- GIVEN lead source "website" is used by 5 existing leads
- WHEN the admin attempts to remove "website"
- THEN the system MUST display a warning: "5 leads currently use this source. They will retain their source value, but it will no longer be available for new leads."
- AND upon confirmation, the source MUST be removed from the configuration
- AND the 5 existing leads MUST retain `source: "website"` (the value is NOT cleared from existing records)

#### Scenario: Source label editing
- GIVEN lead source "social_media" exists
- WHEN the admin renames it to "Social Media"
- THEN the display label MUST update
- AND existing leads with `source: "social_media"` MUST continue to display correctly

---

### REQ-AS-060: Request Channel Configuration [V1]

The admin settings SHOULD allow customizing the list of available request channel values. Channels are displayed as a dropdown when creating or editing requests.

#### Scenario: Default request channels
- GIVEN a fresh Pipelinq installation
- THEN the following channels MUST be available by default: `phone`, `email`, `website`, `counter`, `post`

#### Scenario: List request channels
- GIVEN the admin views the Request Channels section
- THEN all configured channels MUST be displayed
- AND each channel MUST show its label
- AND each unused channel MUST have a "Remove" action

#### Scenario: Add a custom channel
- GIVEN the admin clicks "+ Add Channel"
- WHEN they enter "Service Desk" and save
- THEN "Service Desk" MUST be added to the channel list
- AND "Service Desk" MUST appear as an option in the request creation/edit form's channel dropdown

#### Scenario: Add duplicate channel -- prevented
- GIVEN "email" already exists as a channel
- WHEN the admin attempts to add "email" again
- THEN the system MUST prevent the addition
- AND the system MUST display: "This channel already exists"

#### Scenario: Remove an unused channel
- GIVEN channel "post" exists and no requests use it
- WHEN the admin removes "post"
- THEN it MUST no longer appear in the channel list or creation form dropdown

#### Scenario: Remove a channel used by existing requests
- GIVEN channel "phone" is used by 8 existing requests
- WHEN the admin attempts to remove "phone"
- THEN the system MUST display a warning: "8 requests currently use this channel. They will retain their channel value, but it will no longer be available for new requests."
- AND upon confirmation, the channel MUST be removed from the configuration
- AND the 8 existing requests MUST retain `channel: "phone"`

---

### REQ-AS-070: Default Pipelines on Installation [MVP]

When Pipelinq is installed for the first time, the system MUST create default pipelines and stages via the repair step / configuration import.

#### Scenario: Default Sales Pipeline created
- GIVEN Pipelinq is freshly installed
- WHEN the repair step runs
- THEN a "Sales Pipeline" MUST be created with `entityTypes: ["lead"]` and `isDefault: true`
- AND it MUST have stages in this order:
  | Order | Title | Probability | isClosed | isWon |
  |-------|-------|-------------|----------|-------|
  | 0 | New | 10 | false | false |
  | 1 | Contacted | 20 | false | false |
  | 2 | Qualified | 40 | false | false |
  | 3 | Proposal | 60 | false | false |
  | 4 | Negotiation | 80 | false | false |
  | 5 | Won | 100 | true | true |
  | 6 | Lost | 0 | true | false |

#### Scenario: Default Service Pipeline created
- GIVEN Pipelinq is freshly installed
- WHEN the repair step runs
- THEN a "Service Pipeline" MUST be created with `entityTypes: ["request"]` and `isDefault: false`
- AND it MUST have stages in this order:
  | Order | Title | Probability | isClosed | isWon |
  |-------|-------|-------------|----------|-------|
  | 0 | New | -- | false | false |
  | 1 | In Progress | -- | false | false |
  | 2 | Completed | -- | true | true |
  | 3 | Rejected | -- | true | false |
  | 4 | Converted to Case | -- | true | false |

#### Scenario: Repair step is idempotent
- GIVEN the default pipelines already exist
- WHEN the repair step runs again (e.g., during app update)
- THEN the system MUST NOT create duplicate pipelines
- AND existing pipelines and stages MUST NOT be modified
- AND any admin customizations MUST be preserved

---

### REQ-AS-080: Settings Persistence [MVP]

All admin settings MUST be persisted and survive app updates and server restarts.

#### Scenario: Pipeline settings persist across restarts
- GIVEN the admin has created a custom pipeline "Enterprise Sales" with 5 stages
- WHEN the Nextcloud server restarts
- THEN the pipeline and its stages MUST still exist and be functional

#### Scenario: Source/channel settings persist
- GIVEN the admin has added custom lead sources and request channels
- WHEN the app is updated to a new version
- THEN all custom sources and channels MUST be preserved

#### Scenario: Settings stored in OpenRegister
- GIVEN pipeline and stage configurations
- THEN pipelines MUST be stored as OpenRegister objects with schema `pipeline`
- AND stages MUST be stored as OpenRegister objects with schema `stage`
- AND lead sources and request channels MAY be stored in Nextcloud IAppConfig or as OpenRegister configuration objects

---

### REQ-AS-090: Queue Management Section [Enterprise]

The admin settings page SHALL include a "Queues" section for managing queues. Admins can create, edit, and delete queues, configure categories, set capacity limits, and assign agents to queues.

#### Scenario: View queue list in admin settings
- GIVEN an admin navigates to the Pipelinq admin settings
- THEN a "Queues" section SHALL be displayed after the Pipelines section
- THEN all queues SHALL be listed with title, item count, agent count, and active status

#### Scenario: Create a queue from admin settings
- WHEN an admin clicks "Add queue" and enters title "Vergunningen", categories ["vergunningen"], maxCapacity 50
- THEN a new queue object SHALL be created in OpenRegister
- THEN the queue SHALL appear in the queue list

#### Scenario: Edit a queue
- WHEN an admin clicks "Edit" on queue "Vergunningen"
- THEN a form SHALL display with all queue fields editable (title, description, categories, maxCapacity, isActive, sortOrder)
- THEN saving SHALL persist changes to OpenRegister

#### Scenario: Delete a queue from admin settings
- WHEN an admin clicks "Delete" on queue "Oude Wachtrij"
- THEN a confirmation dialog SHALL appear warning about items in the queue
- THEN confirming SHALL delete the queue and unqueue all items

#### Scenario: Assign agents to a queue
- WHEN an admin opens the agent assignment panel for queue "Vergunningen"
- THEN a user picker SHALL display all Nextcloud users
- THEN the admin SHALL be able to add/remove agents from the queue's assignedAgents list

---

### REQ-AS-100: Skill Management Section [Enterprise]

The admin settings page SHALL include a "Skills" section for managing skill definitions and agent skill profiles.

#### Scenario: View skills list in admin settings
- GIVEN an admin navigates to the Pipelinq admin settings
- THEN a "Skills" section SHALL be displayed after the Queues section
- THEN all skills SHALL be listed with title, category mappings, and agent count

#### Scenario: Create a skill
- WHEN an admin clicks "Add skill" and enters title "Vergunningen", categories ["vergunningen", "omgevingsrecht"]
- THEN a new skill object SHALL be created in OpenRegister
- THEN the skill SHALL appear in the skills list

#### Scenario: Edit a skill
- WHEN an admin clicks "Edit" on skill "Vergunningen"
- THEN a form SHALL display with title, description, categories, and isActive fields
- THEN saving SHALL persist changes

#### Scenario: Delete a skill
- WHEN an admin deletes skill "Vergunningen"
- THEN the skill SHALL be removed from OpenRegister
- THEN the skill SHALL be removed from all agent profiles that reference it

#### Scenario: Manage agent skill profiles
- WHEN an admin opens the "Agent Skills" panel
- THEN a list of Nextcloud users SHALL be displayed
- THEN for each user, the admin SHALL be able to assign/remove skills, set maxConcurrent, and toggle isAvailable
- THEN changes SHALL be persisted to the agent's skill profile object in OpenRegister

---

## UI Layout Reference

The admin settings page follows the wireframe in DESIGN-REFERENCES.md section 3.7:

```
Administration > Pipelinq

PIPELINES                                       [+ Add Pipeline]
-------------------------------------------------------------
| * Sales Pipeline (default)    7 stages  [Edit] [Delete]    |
|   Entities: Leads                                           |
|   New -> Contacted -> Qualified -> Proposal ->              |
|   Negotiation -> Won -> Lost                                |
-------------------------------------------------------------
|   Service Pipeline            5 stages  [Edit] [Delete]    |
|   Entities: Requests                                        |
|   New -> In Progress -> Completed -> Rejected ->            |
|   Converted to Case                                         |
-------------------------------------------------------------

LEAD SOURCES [V1]                                [+ Add Source]
-------------------------------------------------------------
| website [x] | email [x] | phone [x] | referral [x] |      |
| partner [x] | campaign [x] | social_media [x] |            |
| event [x] | other [x]                                      |
-------------------------------------------------------------

REQUEST CHANNELS [V1]                           [+ Add Channel]
-------------------------------------------------------------
| phone [x] | email [x] | website [x] | counter [x] |       |
| post [x]                                                    |
-------------------------------------------------------------
```

- The settings page MUST use Nextcloud's standard admin settings layout and components
- Pipeline edit view MUST show a draggable stage list for reordering
- Source/channel items MUST use chip/tag components with inline remove buttons
- All form inputs MUST have accessible labels (WCAG AA)
- Destructive actions (delete pipeline, remove source) MUST require confirmation

---

### Current Implementation Status

**Substantially implemented.** Most MVP requirements are complete, V1 features (lead sources, request channels) are also implemented.

Implemented:
- `lib/Settings/AdminSettings.php` -- registers the Pipelinq admin settings section (`ISettings` implementation, section ID `pipelinq`, priority 10). Returns `TemplateResponse` with config JSON and app version.
- `lib/Sections/SettingsSection.php` -- registers the "Pipelinq" section in Nextcloud admin navigation.
- `lib/Controller/SettingsController.php` -- `GET /api/settings` (read, `@NoAdminRequired`), `POST /api/settings` (update, admin-only), `POST /api/settings/reimport` (re-import, admin-only). Also `GET/PUT /api/user/settings` for per-user notification preferences.
- `lib/Service/SettingsService.php` -- manages config keys (`register`, `client_schema`, `contact_schema`, `lead_schema`, `request_schema`, `pipeline_schema`, `product_schema`, `productCategory_schema`, `leadProduct_schema`). Delegates to `SettingsLoadService` for import and `DefaultPipelineService` for pipeline creation.
- `lib/Repair/InitializeSettings.php` -- repair step that imports register/schemas via `ConfigurationService`, creates default pipelines, and ensures default lead sources and request channels via `SystemTagService`.
- `lib/Service/DefaultPipelineService.php` -- creates "Sales Pipeline" and "Service Pipeline" with stages (idempotent -- checks if "Sales Pipeline" already exists).
- `lib/Service/PipelineStageData.php` -- defines default stage data for Sales Pipeline (7 stages: New through Won/Lost) and Service Pipeline (5 stages: New through Converted to Case).
- `src/views/settings/Settings.vue` -- full admin settings page with: version info, register configuration mapping (via `CnRegisterMapping`), pipeline management, product categories, lead source tags, request channel tags, prospect settings, and re-import button.
- `src/views/settings/PipelineManager.vue` -- pipeline CRUD with: list view showing title/default indicator/stage count/entity types/stage preview, add/edit/delete actions, default pipeline protection (cannot delete default), affected items count on delete confirmation, auto-set first pipeline as default, prevent unsetting default without replacement.
- `src/views/settings/PipelineForm.vue` -- pipeline edit form with stage management.
- `src/views/settings/TagManager.vue` -- reusable tag/chip manager for lead sources and request channels with add/remove/rename and usage checking.
- `lib/Controller/LeadSourceController.php` -- CRUD for lead sources (`GET/POST /api/settings/lead-sources`, `PUT/DELETE /api/settings/lead-sources/{id}`).
- `lib/Controller/RequestChannelController.php` -- CRUD for request channels (`GET/POST /api/settings/request-channels`, `PUT/DELETE /api/settings/request-channels/{id}`).
- `lib/Service/SystemTagService.php` + `lib/Service/SystemTagCrudService.php` -- manages lead sources and request channels as system tags.
- `src/store/modules/settings.js`, `leadSources.js`, `requestChannels.js` -- Pinia stores for settings, lead sources, and request channels.

NOT implemented:
- Register status display (REQ-AS-012) -- the settings page does not show a "Connected" / "Not configured" status indicator with schema list. It shows the register mapping form but no explicit status indicator.
- Stage drag-and-drop reorder -- the spec requires drag-and-drop reordering of stages, which may not be fully implemented in `PipelineForm.vue`.
- Stage validation for "at least one non-closed stage" -- not enforced in the UI or backend.
- Stage deletion with item migration (moving items to previous stage) -- not implemented; items stay on deleted stage.
- Duplicate source/channel prevention -- may not be validated on the frontend or backend.
- Source/channel removal warning with usage count -- the `TagManager` component has a `usageCheck` prop, but the warning behavior needs verification.

### Standards & References
- Nextcloud Admin Settings API (`OCP\Settings\ISettings`, `OCP\Settings\IIconSection`)
- Nextcloud IAppConfig for persisting application settings
- OpenRegister `ConfigurationService::importFromApp()` for register/schema import
- WCAG AA for accessible form labels and keyboard navigation

### Specificity Assessment
- The spec is highly specific and implementable as-is. Scenarios cover edge cases well (delete default prevention, unique order enforcement, idempotent repair step).
- **Gap**: The stage management UI details (drag-and-drop library, re-order API call) are not specified.
- **Gap**: No specification of how the repair step handles schema version upgrades (e.g., adding new properties to existing schemas).
- **Minor ambiguity**: The spec mentions stages are stored as "OpenRegister objects with schema `stage`", but the actual implementation stores stages as a JSON array within the pipeline object (`pipeline.stages[]`), not as separate objects. This is a significant architectural difference from what the spec describes.
=======
# Admin Settings Specification

## Purpose

The admin settings page provides a Nextcloud admin panel for configuring Pipelinq. Administrators can manage pipelines and their stages, set a default pipeline, configure lead source and request channel values, manage product categories, and configure prospect discovery (ICP) settings. Only Nextcloud admin users can access the admin settings page; regular users access per-user notification preferences via an in-app settings dialog. The design follows the wireframe in DESIGN-REFERENCES.md section 3.7.

**Feature tier**: MVP (admin page, version info, register mapping, pipeline CRUD, stage CRUD, default pipeline, re-import), V1 (lead source config, request channel config, product categories, prospect discovery ICP)

---

## Requirements

### REQ-AS-010: Nextcloud Admin Panel Registration [MVP]

The system MUST register a settings page in the Nextcloud admin panel under "Administration". Only users with Nextcloud admin privileges MUST be able to access this page. The implementation uses `OCP\Settings\ISettings` (`AdminSettings.php`) and `OCP\Settings\IIconSection` (`SettingsSection.php`) to register the "Pipelinq" section with priority 10.

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
  1. Version Information (app name, version, re-import button, support links)
  2. Register Configuration (register and schema mapping via `CnRegisterMapping`)
  3. Pipelines (pipeline CRUD with stage management)
  4. Product Categories
  5. Lead Sources [V1]
  6. Request Channels [V1]
  7. Prospect Discovery [V1]
- AND sections 3-7 MUST only render when the register is configured (`config.register` is non-empty)

#### Scenario: Non-admin user can read settings via API
- GIVEN a regular (non-admin) Nextcloud user
- WHEN they call `GET /api/settings`
- THEN the system MUST return the current config (register IDs, schema IDs) because the endpoint is annotated `@NoAdminRequired`
- AND the response MUST include `isAdmin: false` to indicate the user cannot modify settings
- AND the response MUST include `openRegisters: true/false` indicating whether OpenRegister is installed

---

## ADDED Requirements

### Requirement: REQ-AS-011: Version Information Display [MVP]

The admin settings page MUST display version information about the Pipelinq installation so administrators can verify which version is running and access support.

#### Scenario: Version info card renders
- GIVEN the admin opens the Pipelinq admin settings page
- THEN the page MUST display a `CnVersionInfoCard` component showing:
  - App name: "Pipelinq"
  - App version: read from `document.getElementById('pipelinq-settings').dataset.version` (set by `AdminSettings.php` via TemplateResponse)
  - A "Re-import configuration" button in the actions slot
  - A support footer with links to `support@conduction.nl` and `sales@conduction.nl` for SLA inquiries

#### Scenario: Version passed from backend
- GIVEN `AdminSettings::getForm()` is called
- THEN the TemplateResponse MUST include the app version via `$this->appManager->getAppVersion(Application::APP_ID)`
- AND the version MUST be available to the Vue component as a data attribute on the `#pipelinq-settings` element

---

### Requirement: REQ-AS-012: Register Configuration Mapping [MVP]

The admin settings page MUST display a register configuration mapping interface that allows administrators to map Pipelinq object types to OpenRegister registers and schemas. This uses the shared `CnRegisterMapping` component from `@conduction/nextcloud-vue`.

#### Scenario: Register mapping groups displayed
- GIVEN the admin opens the settings page
- THEN the `CnRegisterMapping` component MUST display one group called "Pipelinq Objects"
- AND the group MUST list 8 object types with their slugs and labels:
  | Slug | Label | Description |
  |------|-------|-------------|
  | client | Client | Companies and organisations |
  | contact | Contact | Contact persons |
  | lead | Lead | Sales leads |
  | request | Request | Customer requests |
  | pipeline | Pipeline | Pipeline stages |
  | product | Product | Products and services |
  | productCategory | Product Category | Product categories |
  | leadProduct | Lead Product | Product line items on leads |
- AND the register config key MUST be `register`

#### Scenario: Save register mapping
- GIVEN the admin modifies the register or schema assignments in the mapping UI
- WHEN they click Save
- THEN the component MUST emit a `save` event with the updated configuration
- AND the Settings.vue parent MUST call `settingsStore.saveSettings(configuration)` which posts to `POST /api/settings`
- AND the backend MUST persist each config key via `IAppConfig::setValueString()` for keys: `register`, `client_schema`, `contact_schema`, `lead_schema`, `request_schema`, `pipeline_schema`, `product_schema`, `productCategory_schema`, `leadProduct_schema`
- AND a success notification "Configuration saved" MUST be displayed

#### Scenario: Register not configured hides dependent sections
- GIVEN the register mapping has not been configured (config.register is empty)
- WHEN the admin views the settings page
- THEN the Pipeline Manager, Product Category Manager, Lead Sources, Request Channels, and Prospect Settings sections MUST NOT be rendered
- AND the `isConfigured` computed property MUST return `false`

---

### Requirement: REQ-AS-013: Re-import Configuration Action [MVP]

The admin settings page MUST provide a button to re-run the register configuration import, allowing administrators to recover from failed imports or apply updated schemas.

#### Scenario: Re-import button in version card
- GIVEN the admin views the settings page
- THEN a "Re-import configuration" button MUST be visible in the Version Information card actions slot
- AND the button MUST show a Refresh icon when idle
- AND the button MUST show a loading spinner and text "Importing..." when the re-import is in progress
- AND the button MUST be disabled during the re-import

#### Scenario: Re-import succeeds
- GIVEN the admin clicks "Re-import configuration"
- WHEN the frontend POSTs to `/apps/pipelinq/api/settings/reimport`
- THEN the backend MUST call `SettingsService::loadSettings(force: true)` which delegates to `SettingsLoadService`
- AND `SettingsLoadService` MUST call `ConfigurationService::importFromApp()` to re-import from `lib/Settings/pipelinq_register.json`
- AND the response MUST include `success: true`, the updated `config` object, and a `result` with register and schema counts
- AND the frontend MUST update the local config state with the returned config
- AND a success NcNoteCard MUST display "Configuration re-imported successfully"

#### Scenario: Re-import fails
- GIVEN OpenRegister is not available or the import throws an exception
- WHEN the admin clicks "Re-import configuration"
- THEN the backend MUST return HTTP 500 with `success: false` and an error message
- AND a red error NcNoteCard MUST display the error message

---

### Requirement: REQ-AS-020: Pipeline Management [MVP]

The admin settings MUST provide full CRUD operations for pipelines. Pipelines are stored as OpenRegister objects with schema `pipeline`. Stages are stored as a JSON array within each pipeline object (`pipeline.stages[]`), not as separate OpenRegister objects.

#### Scenario: List all pipelines
- GIVEN the system has 2 pipelines: "Sales Pipeline" (default, 7 stages) and "Service Pipeline" (5 stages)
- WHEN the admin views the Pipelines section
- THEN the `PipelineManager` component MUST fetch pipelines via `objectStore.fetchCollection('pipeline', { _limit: 100 })`
- AND each pipeline card MUST show: title, default indicator (star icon for the default), schema label (from `propertyMappings[].schemaSlug` or legacy `entityType`), stage count (e.g. "7 stages"), and a compact stage flow (e.g., "New -> Contacted -> ... -> Won -> Lost" truncated to first 2 and last 2 if more than 5 stages)
- AND each pipeline MUST have Edit (pencil icon) and Delete (trash icon) action buttons

#### Scenario: Create a new pipeline
- GIVEN the admin clicks "Add pipeline"
- WHEN the PipelineForm overlay opens and they enter title "Enterprise Sales", configure property mappings, add stages, and click Create
- THEN a new pipeline MUST be created via `objectStore.saveObject('pipeline', pipelineData)`
- AND the pipeline list MUST refresh via `objectStore.fetchCollection('pipeline', { _limit: 100 })`

#### Scenario: Create pipeline -- title required
- GIVEN the admin is creating a new pipeline
- WHEN they attempt to save without entering a title
- THEN the PipelineForm MUST display a validation error: "Pipeline title is required" via the `errors.title` computed property
- AND the Create/Save button MUST be disabled (via `isValid` computed)
- AND the pipeline MUST NOT be created

#### Scenario: Create pipeline -- at least one stage required
- GIVEN the admin is creating a new pipeline with no stages added
- WHEN they attempt to save
- THEN the Save/Create button MUST be disabled because `isValid` requires `form.stages.length > 0`
- AND the stages section MUST show "No stages yet. Add at least one stage."

#### Scenario: Edit pipeline title and properties
- GIVEN an existing pipeline "Sales Pipeline"
- WHEN the admin clicks the Edit button, changes the title to "B2B Sales Pipeline", and saves
- THEN the pipeline MUST be updated via `objectStore.saveObject('pipeline', pipelineData)`
- AND the pipeline list MUST refresh to show the new title

#### Scenario: Delete a pipeline
- GIVEN a pipeline "Old Pipeline" that is NOT the default pipeline
- WHEN the admin clicks the Delete button
- THEN the system MUST count affected items by querying OpenRegister for leads and requests with `pipeline=<id>`
- AND a confirmation dialog (NcDialog) MUST appear with "Are you sure you want to delete "{title}"?"
- AND if affected items > 0, a red warning MUST show: "{count} leads/requests are on this pipeline. They will be removed from the pipeline but not deleted."
- AND if the pipeline has stages, an additional warning MUST show: "This pipeline has {count} stages. All stage configuration will be lost."
- AND upon confirmation, the pipeline MUST be deleted via `objectStore.deleteObject('pipeline', id)` and the list MUST refresh

#### Scenario: Delete default pipeline -- prevented
- GIVEN the "Sales Pipeline" is marked as default
- WHEN the admin attempts to delete it
- THEN the system MUST prevent deletion immediately (before showing the dialog)
- AND the system MUST display an error via `showError()`: "Cannot delete the default pipeline. Set another pipeline as default first."

---

### Requirement: REQ-AS-030: Stage Management within Pipelines [MVP]

The admin settings MUST provide CRUD operations for stages within each pipeline via the `PipelineForm` component. Stages are stored as a JSON array on the pipeline object, each with: `name`, `order`, `probability`, `isClosed`, `isWon`, and `color`.

#### Scenario: List stages for a pipeline
- GIVEN the admin is editing "Sales Pipeline" in the PipelineForm
- THEN the form MUST list all stages sorted by their `order` field (via `sortedStages` computed)
- AND each stage row MUST show: drag handle, up/down reorder buttons, order number, name field, probability field (number input), color picker, isClosed switch, isWon switch (disabled unless isClosed is true), and a delete button

#### Scenario: Add a new stage
- GIVEN the admin is editing a pipeline
- WHEN they click "Add stage"
- THEN a new stage MUST be appended with `order` set to `maxOrder + 1`, empty name, null probability, `isClosed: false`, `isWon: false`, and no color

#### Scenario: Add stage -- name required
- GIVEN the admin has added a stage with an empty name
- WHEN they attempt to save the pipeline
- THEN the `stageErrors` computed MUST produce `name: "Stage name is required"` for that stage
- AND the Save button MUST be disabled (via `isValid`)

#### Scenario: Reorder stages via drag-and-drop
- GIVEN stages in order: New (0), Contacted (1), Qualified (2)
- WHEN the admin drags "Qualified" between "New" and "Contacted" using the drag handle
- THEN `vuedraggable` MUST trigger the `@end` event which calls `recomputeOrders()`
- AND the `order` field of all stages MUST be recalculated to sequential integers (0, 1, 2, ...)

#### Scenario: Reorder stages via up/down buttons
- GIVEN stages in order: New (0), Contacted (1), Qualified (2)
- WHEN the admin clicks the "up" button on "Qualified"
- THEN the `moveStage(stage, -1)` method MUST swap the `order` values of "Qualified" and "Contacted"
- AND the stage list MUST re-sort to: New (0), Qualified (1), Contacted (2)

#### Scenario: Delete a stage
- GIVEN a pipeline with stages: New (0), Contacted (1), Qualified (2)
- WHEN the admin deletes "Contacted"
- THEN the stage MUST be removed from the `form.stages` array
- AND `recomputeOrders()` MUST re-number remaining stages to: New (0), Qualified (1)

#### Scenario: Stage validation -- at least one non-closed stage
- GIVEN a pipeline with stages: "Active" (isClosed=false) and "Done" (isClosed=true)
- WHEN the admin sets "Active" to isClosed=true
- THEN the `errors.stages` computed MUST produce: "Pipeline must have at least one non-closed stage"
- AND the Save button MUST be disabled

#### Scenario: Stage validation -- isWon requires isClosed
- GIVEN a stage with `isClosed=false`
- WHEN the admin attempts to set `isWon=true`
- THEN the isWon switch MUST be disabled (`:disabled="!stage.isClosed"`)
- AND the `stageErrors` for this stage MUST include: "A Won stage must also be marked as Closed"

#### Scenario: Stage color picker
- GIVEN the admin is editing a stage
- THEN each stage row MUST include a color input (`type="color"`) defaulting to `#6b7280`
- AND the chosen color MUST be saved with the pipeline and used for visual display in the pipeline board

---

### Requirement: REQ-AS-035: Pipeline Property Mappings [MVP]

The PipelineForm MUST allow administrators to configure property mappings that define which schemas participate in the pipeline and how objects are placed into columns.

#### Scenario: Add a property mapping
- GIVEN the admin is editing a pipeline
- WHEN they click "Add mapping"
- THEN a new mapping row MUST appear with fields: Schema slug (text, placeholder "e.g. lead, request"), Column property (text, defaulting to "stage"), and Totals property (text, optional, placeholder "e.g. value")

#### Scenario: Configure multiple schema mappings
- GIVEN a pipeline with mappings for "lead" (column: "stage", totals: "value") and "request" (column: "stage", totals: null)
- WHEN the pipeline is saved
- THEN the `propertyMappings` array MUST be serialized as part of the pipeline object
- AND the pipeline card in the list view MUST display schema slugs from the mappings as the entity type badge

#### Scenario: Remove a property mapping
- GIVEN a pipeline with 2 property mappings
- WHEN the admin clicks the delete button on one mapping
- THEN the mapping MUST be removed from the `propertyMappings` array

---

### Requirement: REQ-AS-040: Default Pipeline Selection [MVP]

The admin settings MUST allow selecting one pipeline as the default. The default pipeline is used when creating new leads or requests that are not explicitly assigned to a pipeline.

#### Scenario: Set default pipeline
- GIVEN pipelines "Sales Pipeline" (default) and "Service Pipeline" exist
- WHEN the admin edits "Service Pipeline" and sets `isDefault=true`
- THEN `PipelineManager.onSave()` MUST iterate all other pipelines that have `isDefault=true` and save them with `isDefault: false` via `objectStore.saveObject()`
- AND only one pipeline MUST have `isDefault = true` at any time

#### Scenario: Default pipeline indicator
- GIVEN "Sales Pipeline" is the default
- WHEN the admin views the pipeline list
- THEN "Sales Pipeline" MUST display a yellow star icon (`<Star>` with class `default-star`, color `var(--color-warning)`)
- AND other pipelines MUST NOT display this indicator

#### Scenario: First pipeline auto-becomes default
- GIVEN no pipelines exist (or only one which is being created)
- WHEN the admin creates the first pipeline
- THEN `PipelineManager.onSave()` MUST automatically set `isDefault: true` on the new pipeline

#### Scenario: Cannot unset default without replacement
- GIVEN "Sales Pipeline" is the only default pipeline
- WHEN the admin edits it and unchecks the "Default pipeline" switch
- THEN `PipelineManager.onSave()` MUST detect no other defaults exist
- AND MUST re-set `isDefault: true` on this pipeline
- AND MUST display an error via `showError()`: "At least one pipeline must be set as default"

---

### Requirement: REQ-AS-045: Pipeline View Association [MVP]

The PipelineForm MUST allow associating a pipeline with a saved view to define which schemas are displayed in the pipeline board.

#### Scenario: Select a view for a pipeline
- GIVEN the admin is editing a pipeline
- THEN a "View" dropdown (NcSelect) MUST be displayed, populated from `getViews()` (via `viewService.js`)
- AND the dropdown MUST be clearable (optional association)
- AND selecting a view MUST set `form.viewId` on the pipeline

#### Scenario: Totals label configuration
- GIVEN the admin is editing a pipeline
- THEN a "Totals label" text field MUST be displayed with placeholder "e.g. EUR, hours, items"
- AND the help text MUST explain: "Label shown next to column totals. Leave empty to hide totals."

---

### Requirement: REQ-AS-050: Lead Source Configuration [V1]

The admin settings MUST allow customizing the list of available lead source values. Lead sources are managed as system tags (via `SystemTagService`) and displayed using the reusable `TagManager` component.

#### Scenario: Default lead sources
- GIVEN a fresh Pipelinq installation
- WHEN the repair step (`InitializeSettings`) runs
- THEN `SystemTagService::ensureDefaults()` MUST create the following lead sources with objectType `pipelinq_lead_source`: `website`, `email`, `phone`, `referral`, `partner`, `campaign`, `social_media`, `event`, `other`

#### Scenario: List lead sources
- GIVEN the admin views the Lead Sources section
- THEN the `TagManager` component MUST render with title "Lead Sources" and add label "+ Add Source"
- AND tags MUST be fetched via `leadSourcesStore.fetchSources()` on mount
- AND each source MUST display as a chip/pill with inline remove button (x)

#### Scenario: Add a custom source
- GIVEN the admin clicks "+ Add Source"
- WHEN the inline input appears and they type "Trade Show" and press Enter
- THEN `leadSourcesStore.addSource('Trade Show')` MUST be called
- AND the new source MUST appear as a chip in the list

#### Scenario: Remove a source with usage check
- GIVEN lead source "website" exists
- WHEN the admin clicks the remove button (x) on "website"
- THEN the `usageCheck` function MUST query OpenRegister for leads with `source=website` via `countObjectsWithField('lead', 'source', 'website')`
- AND if the count > 0, a confirm dialog MUST show: "{count} items currently use "website". They will retain their value, but it will no longer be available for new items."
- AND upon confirmation, `leadSourcesStore.removeSource(id)` MUST be called

#### Scenario: Rename a source via double-click
- GIVEN lead source "social_media" exists
- WHEN the admin double-clicks on the chip label
- THEN the chip MUST switch to edit mode with an inline text input pre-filled with "social_media"
- AND pressing Enter MUST call `leadSourcesStore.renameSource(id, newName)`
- AND pressing Escape MUST cancel the edit

---

### Requirement: REQ-AS-060: Request Channel Configuration [V1]

The admin settings MUST allow customizing the list of available request channel values, using the same `TagManager` component as lead sources.

#### Scenario: Default request channels
- GIVEN a fresh Pipelinq installation
- WHEN the repair step runs
- THEN `SystemTagService::ensureDefaults()` MUST create the following channels with objectType `pipelinq_request_channel`: `phone`, `email`, `website`, `counter`, `post`

#### Scenario: List request channels
- GIVEN the admin views the Request Channels section
- THEN the `TagManager` component MUST render with title "Request Channels" and add label "+ Add Channel"
- AND tags MUST be fetched via `requestChannelsStore.fetchChannels()` on mount

#### Scenario: Add a custom channel
- GIVEN the admin clicks "+ Add Channel"
- WHEN they enter "Service Desk" and press Enter
- THEN `requestChannelsStore.addChannel('Service Desk')` MUST be called

#### Scenario: Remove a channel with usage check
- GIVEN channel "phone" is used by existing requests
- WHEN the admin clicks the remove button
- THEN the usage check MUST query `countObjectsWithField('request', 'channel', 'phone')`
- AND the confirm dialog MUST display the usage count before proceeding

---

### Requirement: REQ-AS-065: Prospect Discovery Settings [V1]

The admin settings MUST include an Ideal Customer Profile (ICP) configuration section for prospect discovery, rendered via the `ProspectSettings` component.

#### Scenario: ICP form fields
- GIVEN the admin views the Prospect Discovery section
- THEN the form MUST display the following fields:
  | Field | Type | Description |
  |-------|------|-------------|
  | SBI Codes | Text (comma-separated) | Dutch Standard Industrial Classification codes |
  | Min Employees | Number | Minimum employee count filter |
  | Max Employees | Number | Maximum employee count filter |
  | Provinces | Multi-select | Dutch provinces (12 options: Drenthe through Zuid-Holland) |
  | Legal Forms | Multi-select | Dutch legal forms (BV, NV, VOF, Eenmanszaak, Stichting, Vereniging, CV, Maatschap) |
  | Exclude Inactive | Checkbox | Exclude inactive companies (default: true) |
  | Keywords | Text (comma-separated) | Keywords for OpenCorporates search |
  | KVK API Key | Password field | API key for KVK integration |
  | OpenCorporates | Checkbox | Enable OpenCorporates as supplementary data source |

#### Scenario: Load existing ICP settings
- GIVEN ICP settings have been previously saved
- WHEN the ProspectSettings component mounts
- THEN it MUST fetch settings from `GET /apps/pipelinq/api/prospects/settings`
- AND populate the form with the returned values
- AND the KVK API Key MUST display as `***configured***` if previously set (never expose the raw key)

#### Scenario: Save ICP settings
- GIVEN the admin fills in the ICP form and clicks "Save ICP Settings"
- THEN the form MUST PUT to `/apps/pipelinq/api/prospects/settings` with the payload
- AND SBI codes and keywords MUST be parsed from comma-separated strings to arrays
- AND if the KVK API key shows `***configured***`, it MUST be omitted from the payload (do not overwrite with the mask)
- AND a success NcNoteCard MUST display "ICP settings saved successfully"

---

### Requirement: REQ-AS-070: Default Pipelines on Installation [MVP]

When Pipelinq is installed for the first time, the system MUST create default pipelines and stages via the repair step / configuration import.

#### Scenario: Default Sales Pipeline created
- GIVEN Pipelinq is freshly installed
- WHEN the repair step runs (`InitializeSettings::run()`)
- THEN `SettingsService::createDefaultPipelines()` MUST delegate to `DefaultPipelineService::createDefaultPipelines()`
- AND a "Sales Pipeline" MUST be created with `isDefault: true`
- AND it MUST have stages defined by `PipelineStageData` in this order:
  | Order | Title | Probability | isClosed | isWon |
  |-------|-------|-------------|----------|-------|
  | 0 | New | 10 | false | false |
  | 1 | Contacted | 20 | false | false |
  | 2 | Qualified | 40 | false | false |
  | 3 | Proposal | 60 | false | false |
  | 4 | Negotiation | 80 | false | false |
  | 5 | Won | 100 | true | true |
  | 6 | Lost | 0 | true | false |

#### Scenario: Default Service Pipeline created
- GIVEN Pipelinq is freshly installed
- WHEN the repair step runs
- THEN a "Service Pipeline" MUST be created with `isDefault: false`
- AND it MUST have stages in this order:
  | Order | Title | Probability | isClosed | isWon |
  |-------|-------|-------------|----------|-------|
  | 0 | New | -- | false | false |
  | 1 | In Progress | -- | false | false |
  | 2 | Completed | -- | true | true |
  | 3 | Rejected | -- | true | false |
  | 4 | Converted to Case | -- | true | false |

#### Scenario: Repair step is idempotent
- GIVEN the default pipelines already exist
- WHEN the repair step runs again (e.g., during app update)
- THEN `DefaultPipelineService` MUST check if "Sales Pipeline" already exists
- AND MUST NOT create duplicate pipelines
- AND existing pipelines and stages MUST NOT be modified

#### Scenario: Repair step handles missing OpenRegister
- GIVEN OpenRegister is not installed
- WHEN the repair step runs
- THEN `InitializeSettings::run()` MUST output a warning: "OpenRegister app is not installed -- skipping configuration import"
- AND MUST advance the progress counter and finish without error

---

### Requirement: REQ-AS-075: User Notification Preferences [MVP]

Each user MUST be able to configure their notification preferences via a per-user settings dialog (`UserSettings.vue`), separate from the admin settings.

#### Scenario: User settings dialog content
- GIVEN a user opens the Pipelinq settings dialog (NcAppSettingsDialog)
- THEN the Notifications section MUST display three toggle switches:
  | Setting Key | Label | Default |
  |------------|-------|---------|
  | notify_assignments | Lead & request assignments | true |
  | notify_stage_status | Pipeline stage & status changes | true |
  | notify_notes | Notes & comments | true |
- AND each toggle MUST show a descriptive hint below it

#### Scenario: Toggle a notification preference
- GIVEN the user toggles "Lead & request assignments" off
- THEN the frontend MUST PUT to `/apps/pipelinq/api/user/settings` with `{ notify_assignments: false }`
- AND the backend MUST persist the value via `IConfig::setUserValue()` for that user
- AND the toggle MUST show a loading state while saving

#### Scenario: User settings persist per user
- GIVEN user A has `notify_assignments: false` and user B has the default `notify_assignments: true`
- WHEN each user fetches their settings via `GET /apps/pipelinq/api/user/settings`
- THEN user A MUST receive `notify_assignments: false`
- AND user B MUST receive `notify_assignments: true`

---

### Requirement: REQ-AS-080: Settings Persistence [MVP]

All admin settings MUST be persisted via `OCP\IAppConfig` and survive app updates and server restarts.

#### Scenario: Config keys persisted via IAppConfig
- GIVEN the admin saves settings
- THEN the following config keys MUST be persisted via `IAppConfig::setValueString()` under app ID `pipelinq`:
  `register`, `client_schema`, `contact_schema`, `lead_schema`, `request_schema`, `pipeline_schema`, `product_schema`, `productCategory_schema`, `leadProduct_schema`

#### Scenario: Pipeline settings persist as OpenRegister objects
- GIVEN the admin has created a custom pipeline "Enterprise Sales" with 5 stages
- WHEN the Nextcloud server restarts
- THEN the pipeline and its stages MUST still exist in OpenRegister and be functional

#### Scenario: Source/channel settings persist as system tags
- GIVEN the admin has added custom lead sources and request channels
- WHEN the app is updated to a new version
- THEN all custom sources and channels MUST be preserved (stored via `SystemTagService`)
- AND the repair step MUST only ensure defaults exist without overwriting customs

#### Scenario: User settings persist via IConfig
- GIVEN a user has modified notification preferences
- WHEN the server restarts
- THEN user preferences MUST be preserved via `IConfig::getUserValue()` / `IConfig::setUserValue()`

---

### Requirement: REQ-AS-085: Internationalization (i18n) [MVP]

All admin settings UI text MUST support Dutch (nl) and English (en) translations via the Nextcloud `t()` and `n()` translation functions.

#### Scenario: All UI strings use t() function
- GIVEN the admin settings components: Settings.vue, PipelineManager.vue, PipelineForm.vue, TagManager.vue, ProspectSettings.vue, UserSettings.vue
- THEN every user-visible string MUST be wrapped in `t('pipelinq', '...')` or `n('pipelinq', '...')` for pluralization
- AND the backend MUST use `IL10N::t()` for translatable response messages (e.g., "Configuration re-imported successfully")

#### Scenario: Pluralization for stage count
- GIVEN a pipeline with 1 stage
- THEN the display MUST show "1 stage" (singular)
- AND for 5 stages it MUST show "5 stages" (plural)
- AND this MUST use `n('pipelinq', '%n stage', '%n stages', count)`

---

### Requirement: REQ-AS-090: Accessible Form Controls [MVP]

The admin settings page MUST comply with WCAG AA accessibility standards for all interactive elements.

#### Scenario: Form inputs have labels
- GIVEN the admin settings page and pipeline form
- THEN all NcTextField components MUST have a `label` prop set
- AND all NcSelect components MUST have accessible labels
- AND all NcCheckboxRadioSwitch components MUST have visible text labels
- AND all icon-only buttons MUST have `title` attributes for screen readers

#### Scenario: Keyboard navigation
- GIVEN the admin is using keyboard navigation
- THEN all interactive elements (buttons, inputs, switches, drag handles) MUST be focusable
- AND the TagManager inline inputs MUST support Enter to save and Escape to cancel
- AND the pipeline form MUST be dismissible (Cancel button)

#### Scenario: Color contrast
- GIVEN the admin settings page uses CSS custom properties
- THEN all text MUST use Nextcloud theme variables (`var(--color-main-text)`, `var(--color-text-maxcontrast)`) to ensure sufficient contrast
- AND destructive actions MUST use `var(--color-error)` for visual distinction

---

## UI Layout Reference

The admin settings page follows the wireframe in DESIGN-REFERENCES.md section 3.7:

```
Administration > Pipelinq
==========================

PIPELINQ SETTINGS
Configure your Pipelinq installation        [Documentation link]

VERSION INFORMATION
Pipelinq v1.x.x                             [Re-import configuration]
Support: support@conduction.nl
SLA: sales@conduction.nl

REGISTER CONFIGURATION
Map Pipelinq object types to OpenRegister registers and schemas
[CnRegisterMapping component with 8 type mappings]       [Save]

PIPELINES                                       [+ Add pipeline]
-------------------------------------------------------------
| * Sales Pipeline (default)    7 stages  [Edit] [Delete]    |
|   Leads                                                     |
|   New -> Contacted -> ... -> Won -> Lost                    |
-------------------------------------------------------------
|   Service Pipeline            5 stages  [Edit] [Delete]    |
|   Requests                                                  |
|   New -> In Progress -> ... -> Rejected -> Conv. to Case    |
-------------------------------------------------------------

PRODUCT CATEGORIES
[ProductCategoryManager component]

LEAD SOURCES [V1]                                [+ Add Source]
-------------------------------------------------------------
| website [x] | email [x] | phone [x] | referral [x] |      |
| partner [x] | campaign [x] | social_media [x] |            |
| event [x] | other [x]                                      |
-------------------------------------------------------------

REQUEST CHANNELS [V1]                           [+ Add Channel]
-------------------------------------------------------------
| phone [x] | email [x] | website [x] | counter [x] |       |
| post [x]                                                    |
-------------------------------------------------------------

PROSPECT DISCOVERY [V1]
SBI Codes: [____________]    Min Employees: [___]
Keywords: [_____________]    Max Employees: [___]
Provinces: [multi-select]    Legal Forms: [multi-select]
[x] Exclude inactive         KVK API Key: [********]
[ ] Enable OpenCorporates                  [Save ICP Settings]
```

- The settings page MUST use Nextcloud's standard admin settings layout and NcSettingsSection
- Pipeline edit view MUST use an overlay form with draggable stage list (vuedraggable)
- Source/channel items MUST use chip/tag components with inline remove buttons
- All form inputs MUST have accessible labels (WCAG AA)
- Destructive actions (delete pipeline, remove source/channel) MUST require confirmation

---

### Current Implementation Status

**Substantially implemented.** Most MVP and V1 requirements are complete.

Implemented:
- `lib/Settings/AdminSettings.php` -- registers the Pipelinq admin settings section (`ISettings` implementation, section ID `pipelinq`, priority 10). Returns `TemplateResponse` with config JSON and app version.
- `lib/Sections/SettingsSection.php` -- registers the "Pipelinq" section in Nextcloud admin navigation.
- `lib/Controller/SettingsController.php` -- `GET /api/settings` (read, `@NoAdminRequired`), `POST /api/settings` (update, admin-only), `POST /api/settings/reimport` (re-import, admin-only). Also `GET/PUT /api/user/settings` for per-user notification preferences.
- `lib/Service/SettingsService.php` -- manages 9 config keys via IAppConfig. Delegates to `SettingsLoadService` for import and `DefaultPipelineService` for pipeline creation. Also manages user settings via `IConfig`.
- `lib/Repair/InitializeSettings.php` -- repair step with 4 progress steps: check OpenRegister, load config, create default pipelines, ensure default lead sources and request channels via `SystemTagService`.
- `lib/Service/DefaultPipelineService.php` -- creates "Sales Pipeline" (7 stages) and "Service Pipeline" (5 stages), idempotent.
- `lib/Service/PipelineStageData.php` -- defines default stage data for both pipelines.
- `src/views/settings/Settings.vue` -- full admin settings page with 7 sections: version info (`CnVersionInfoCard`), register mapping (`CnRegisterMapping`), pipelines (`PipelineManager`), product categories (`ProductCategoryManager`), lead sources (`TagManager`), request channels (`TagManager`), prospect discovery (`ProspectSettings`). Conditionally renders sections 3-7 only when register is configured.
- `src/views/settings/PipelineManager.vue` -- pipeline CRUD: list view with title/default star/schema label/stage count/stage preview, add/edit/delete. Default pipeline protection (cannot delete default), affected items count via OpenRegister queries, first-pipeline auto-default, prevent unsetting default without replacement.
- `src/views/settings/PipelineForm.vue` -- pipeline edit overlay with: title (required), description, view selector, default toggle, totals label, property mappings (schema slug / column property / totals property), stage management with drag-and-drop (vuedraggable) and up/down buttons, stage fields (name required, probability, color picker, isClosed/isWon switches), validation (title required, at least one stage, at least one non-closed stage, isWon requires isClosed).
- `src/views/settings/TagManager.vue` -- reusable tag/chip manager with add (inline input), remove (with usage check and confirm), rename (double-click to edit inline), keyboard shortcuts (Enter to save, Escape to cancel).
- `src/views/settings/ProspectSettings.vue` -- ICP configuration form with 9 fields, fetches/saves via `/api/prospects/settings`, masks KVK API key.
- `src/views/settings/UserSettings.vue` -- per-user notification preferences dialog with 3 toggles (assignments, stage/status changes, notes), saves per-toggle via PUT.
- `lib/Controller/LeadSourceController.php` / `lib/Controller/RequestChannelController.php` -- CRUD endpoints for lead sources and request channels.
- `lib/Service/SystemTagService.php` + `lib/Service/SystemTagCrudService.php` -- manages lead sources and request channels as system tags.
- `src/store/modules/settings.js`, `leadSources.js`, `requestChannels.js` -- Pinia stores for all settings data.

Gaps / partially implemented:
- Duplicate source/channel prevention -- not validated on frontend or backend (TagManager does not check for duplicates before calling the add event).
- Stage deletion does not migrate items to previous stage -- items remain on the deleted stage reference. The spec originally required migration but the implementation simply removes the stage.
- Register status indicator (connected/disconnected with green/orange badge) -- not implemented; the register mapping component shows the mapping form but no explicit status indicator.

### Standards & References
- Nextcloud Admin Settings API (`OCP\Settings\ISettings`, `OCP\Settings\IIconSection`)
- Nextcloud IAppConfig for persisting application config keys
- Nextcloud IConfig for persisting per-user preferences
- OpenRegister `ConfigurationService::importFromApp()` for register/schema import
- `@conduction/nextcloud-vue` for shared components (`CnRegisterMapping`, `CnVersionInfoCard`)
- `vuedraggable` for stage drag-and-drop reordering
- WCAG AA for accessible form labels and keyboard navigation

### Specificity Assessment
- The spec is highly specific and implementable. Scenarios cover edge cases well (delete default prevention, unique order enforcement, idempotent repair step, usage-checked removal).
- All 7 sections of the settings page are fully specified with implementation references.
- Property mappings and view association (pipeline-to-view) are now specified.
- User notification preferences are specified separately from admin settings.
- **Architectural note**: Stages are stored as a JSON array within the pipeline object (`pipeline.stages[]`), not as separate OpenRegister objects. This is correct per the implementation.
