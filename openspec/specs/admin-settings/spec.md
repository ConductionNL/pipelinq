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
  1. Pipelines (with stage management)
  2. Lead Sources [V1]
  3. Request Channels [V1]

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

## UI Layout Reference

The admin settings page follows the wireframe in DESIGN-REFERENCES.md section 3.7:

```
Administration > Pipelinq
==========================

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
