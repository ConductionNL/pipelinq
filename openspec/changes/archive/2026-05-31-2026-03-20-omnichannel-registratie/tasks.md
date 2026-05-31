# Tasks: omnichannel-registratie

## 1. Data Model

- [x] 1.1 Add `contactmoment` schema to `lib/Settings/pipelinq_register.json`
  - **spec_ref**: `specs/omnichannel-registratie/spec.md#REQ-OMN-003`
  - **files**: `pipelinq/lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the pipelinq app is installed
    - THEN the `pipelinq` register MUST contain a `contactmoment` schema with `@type: schema:CommunicateAction`
    - AND properties MUST include: `subject` (required), `channel` (required, enum), `outcome` (enum), `client` (uuid), `contact` (uuid), `request` (uuid), `agent`, `contactedAt`, `duration`, `channelMetadata` (object), `summary`, `notes`
    - AND `channel` and `outcome` MUST be marked as facetable

- [x] 1.2 Add `contactmoment` to register schemas list in `pipelinq_register.json`
  - **files**: `pipelinq/lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the schema is defined
    - THEN the schema slug MUST appear in the register's `schemas` array

## 2. CallTimer Component

- [x] 2.1 Create `src/components/CallTimer.vue` — MM:SS elapsed timer with start/stop/reset
  - **spec_ref**: `specs/omnichannel-registratie/spec.md#REQ-OMN-002`
  - **files**: `pipelinq/src/components/CallTimer.vue`
  - **acceptance_criteria**:
    - GIVEN the component is rendered
    - WHEN a user clicks Start
    - THEN the display MUST count up in MM:SS format, updating every second
    - WHEN a user clicks Stop
    - THEN the timer MUST pause AND emit `@duration-updated` with ISO 8601 string (e.g. `PT6M12S`)
    - WHEN a user clicks Reset
    - THEN the display MUST return to `00:00` AND emit `@duration-updated` with empty string

## 3. Frontend Views

- [x] 3.1 Create `src/views/contactmomenten/ContactmomentList.vue`
  - **spec_ref**: `specs/omnichannel-registratie/spec.md#REQ-OMN-004`, `REQ-OMN-005`
  - **files**: `pipelinq/src/views/contactmomenten/ContactmomentList.vue`
  - **acceptance_criteria**:
    - GIVEN contactmomenten exist in OpenRegister
    - WHEN a user navigates to `/contactmomenten`
    - THEN a table MUST display columns: subject, channel (with icon), client, agent, contactedAt, outcome
    - AND filter controls MUST be present for: channel (multi-select), agent, date range (contactedAt from/to), full-text search
    - AND a mass export button MUST open `CnMassExportDialog` with CSV format

- [x] 3.2 Create `src/views/contactmomenten/ContactmomentForm.vue` — channel-adaptive registration form
  - **spec_ref**: `specs/omnichannel-registratie/spec.md#REQ-OMN-001`, `REQ-OMN-007`, `REQ-OMN-008`
  - **files**: `pipelinq/src/views/contactmomenten/ContactmomentForm.vue`
  - **acceptance_criteria**:
    - GIVEN a user opens the form
    - THEN `agent` MUST be pre-filled with the current Nextcloud user UID
    - AND `contactedAt` MUST be pre-filled with the current timestamp
    - WHEN channel is `telefoon`
    - THEN `CallTimer`, `channelMetadata.richting`, and `channelMetadata.telefoonnummer` fields MUST appear
    - WHEN channel is `email`
    - THEN `channelMetadata.richting` and `channelMetadata.threadId` MUST appear (no CallTimer)
    - WHEN channel is `balie`
    - THEN `channelMetadata.locatie` and `channelMetadata.afspraakId` MUST appear
    - WHEN channel changes
    - THEN `channelMetadata` MUST be reset to `{}`
    - WHEN opened with `?clientId=<uuid>`
    - THEN the `client` field MUST be pre-filled with the referenced client

- [x] 3.3 Create `src/views/contactmomenten/ContactmomentDetail.vue`
  - **spec_ref**: `specs/omnichannel-registratie/spec.md#REQ-OMN-006`
  - **files**: `pipelinq/src/views/contactmomenten/ContactmomentDetail.vue`
  - **acceptance_criteria**:
    - GIVEN a contactmoment with channelMetadata
    - WHEN a user opens the detail view
    - THEN a "Kanaalgegevens" section MUST render each channelMetadata key as a labelled row
    - AND if `client` is set, the client name MUST be rendered as a link to `/clients/:id`
    - AND if `request` is set, the request title MUST be rendered as a link to `/requests/:id`
    - AND header MUST include Edit and Delete buttons

## 4. Navigation and Routing

- [x] 4.1 Add contactmomenten routes to `src/router/index.js`
  - **files**: `pipelinq/src/router/index.js`
  - **acceptance_criteria**:
    - GIVEN the router is initialised
    - THEN three named routes MUST be registered: `ContactmomentList` (`/contactmomenten`), `ContactmomentNew` (`/contactmomenten/new`), `ContactmomentDetail` (`/contactmomenten/:id`)

- [x] 4.2 Add "Contactmomenten" nav item to `src/navigation/MainMenu.vue`
  - **files**: `pipelinq/src/navigation/MainMenu.vue`
  - **acceptance_criteria**:
    - GIVEN a user opens Pipelinq
    - THEN the sidebar MUST contain a "Contactmomenten" navigation item with a phone/chat icon
    - AND clicking it MUST navigate to `/contactmomenten`

## 5. Verification

- [x] 5.1 Run `npm run build` and verify no compilation errors
- [x] 5.2 Manually verify channel switching clears channelMetadata and shows correct fields
- [x] 5.3 Manually verify CallTimer emits correct ISO 8601 duration on Stop
