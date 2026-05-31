# Delta Spec: omnichannel-registratie

## Changes to specs/contactmomenten/spec.md

This delta adds omnichannel-specific requirements for channel-adaptive form behaviour, call timer, channel metadata capture, CSV export, and activity timeline integration. Base CRUD, list, detail, quick-log form, and Pinia store requirements are already covered by `specs/contactmomenten/spec.md`.

**Feature tier**: MVP (channel adaptation, call timer, list, detail), V1 (activity timeline integration)

---

### REQ-OMN-001: Channel-Adaptive Registration Form

The registration form MUST adapt its visible fields and `channelMetadata` keys based on the selected `channel` value. Switching channel MUST clear all previously entered `channelMetadata`.

**Feature tier**: MVP

#### Scenario: Form adapts fields for telefoon channel

- **GIVEN** a user opens the contactmoment registration form
- **WHEN** the user selects channel `telefoon`
- **THEN** the form MUST display a `CallTimer` component
- AND the form MUST show `channelMetadata.richting` (enum: inkomend/uitgaand)
- AND the form MUST show `channelMetadata.telefoonnummer` (optional text)
- AND fields specific to other channels (e.g. threadId, locatie) MUST NOT be visible

#### Scenario: Form adapts fields for email channel

- **GIVEN** a user opens the contactmoment registration form
- **WHEN** the user selects channel `email`
- **THEN** the form MUST show `channelMetadata.richting` (enum: inkomend/uitgaand)
- AND the form MUST show `channelMetadata.threadId` (optional text)
- AND the `CallTimer` component MUST NOT be visible

#### Scenario: Form adapts fields for balie channel

- **GIVEN** a user opens the contactmoment registration form
- **WHEN** the user selects channel `balie`
- **THEN** the form MUST show `channelMetadata.locatie` (text, counter desk name)
- AND the form MUST show `channelMetadata.afspraakId` (optional text, appointment reference)

#### Scenario: Form adapts fields for chat channel

- **GIVEN** a user opens the contactmoment registration form
- **WHEN** the user selects channel `chat`
- **THEN** the form MUST show `channelMetadata.platform` (enum: website/whatsapp/other)
- AND the form MUST show `channelMetadata.sessionId` (optional text)

#### Scenario: Form adapts fields for social channel

- **GIVEN** a user opens the contactmoment registration form
- **WHEN** the user selects channel `social`
- **THEN** the form MUST show `channelMetadata.platform` (text, e.g. twitter/facebook/linkedin)
- AND the form MUST show `channelMetadata.username` (optional text)
- AND the form MUST show `channelMetadata.postId` (optional text)

#### Scenario: Switching channel clears channelMetadata

- **GIVEN** a user has entered channel `telefoon` with `channelMetadata.telefoonnummer` set to "+31612345678"
- **WHEN** the user changes channel to `email`
- **THEN** `channelMetadata` MUST be reset to `{}`
- AND the telefoonnummer value MUST NOT be visible or retained

---

### REQ-OMN-002: Call Timer Component

The system MUST provide a `CallTimer` component that measures elapsed call duration and emits an ISO 8601 duration string. It MUST only appear when channel is `telefoon`.

**Feature tier**: MVP

#### Scenario: Timer starts and displays elapsed time

- **GIVEN** a user has selected channel `telefoon` on the registration form
- **WHEN** the user clicks the Start button on `CallTimer`
- **THEN** the timer MUST begin counting up in MM:SS format
- AND the display MUST update every second

#### Scenario: Timer stops and auto-fills duration field

- **GIVEN** the call timer is running and shows `00:06:12`
- **WHEN** the user clicks the Stop button
- **THEN** the timer MUST stop
- AND the `duration` field MUST be automatically populated with `PT6M12S`

#### Scenario: Timer can be reset

- **GIVEN** the call timer has been stopped
- **WHEN** the user clicks the Reset button
- **THEN** the timer display MUST return to `00:00`
- AND the `duration` field MUST be cleared

#### Scenario: Timer does not appear on non-phone channels

- **GIVEN** a user has selected channel `email` on the registration form
- **THEN** the `CallTimer` component MUST NOT be rendered

---

### REQ-OMN-003: Channel-Specific Metadata Stored on Contactmoment

The system MUST store the `channelMetadata` object with channel-appropriate keys on the contactmoment. The metadata MUST be retrievable as-is via the OpenRegister API.

**Feature tier**: MVP

#### Scenario: channelMetadata stored for telefoon

- **GIVEN** a user submits a contactmoment with channel `telefoon`, `channelMetadata.richting: "inkomend"`, and `channelMetadata.telefoonnummer: "+31612345678"`
- **WHEN** the contactmoment is created
- **THEN** the stored object MUST contain `channelMetadata: { "richting": "inkomend", "telefoonnummer": "+31612345678" }`

#### Scenario: channelMetadata stored for balie

- **GIVEN** a user submits a contactmoment with channel `balie`, `channelMetadata.locatie: "Stadhuis balie 3"`, and `channelMetadata.afspraakId: "afsp-20260317-112"`
- **WHEN** the contactmoment is created
- **THEN** the stored object MUST contain `channelMetadata: { "locatie": "Stadhuis balie 3", "afspraakId": "afsp-20260317-112" }`

#### Scenario: channelMetadata is empty object when not provided

- **GIVEN** a user submits a contactmoment with channel `brief` and no channelMetadata fields filled in
- **WHEN** the contactmoment is created
- **THEN** `channelMetadata` MUST be stored as `{}`

---

### REQ-OMN-004: Contactmomenten List View with Channel Icons and Filters

The list view MUST display channel icons alongside contactmomenten and support filtering by channel, agent, and date range.

**Feature tier**: MVP

#### Scenario: List displays channel icon per row

- **GIVEN** the contactmomenten list contains entries with channels `telefoon`, `email`, and `balie`
- **WHEN** a user navigates to `/contactmomenten`
- **THEN** each row MUST display a recognisable icon for its channel
- AND the icon MUST differ per channel type (phone icon for telefoon, envelope for email, counter/building for balie)

#### Scenario: Filter by channel

- **GIVEN** the contactmomenten list is displayed
- **WHEN** a user selects filter channel `telefoon`
- **THEN** only contactmomenten with `channel: "telefoon"` MUST be shown
- AND the filter MUST support selecting multiple channels simultaneously

#### Scenario: Filter by date range

- **GIVEN** the contactmomenten list is displayed
- **WHEN** a user selects date range `2026-03-01` to `2026-03-31`
- **THEN** only contactmomenten with `contactedAt` within that range MUST be shown

#### Scenario: Filter by agent

- **GIVEN** the contactmomenten list is displayed
- **WHEN** a user selects filter agent `kcc.medewerker1`
- **THEN** only contactmomenten where `agent = "kcc.medewerker1"` MUST be shown

---

### REQ-OMN-005: CSV Export of Contactmomenten

The system MUST allow exporting the contactmomenten list as CSV using OpenRegister's built-in export.

**Feature tier**: MVP

#### Scenario: CSV export from list view

- **GIVEN** a user is viewing the contactmomenten list
- **WHEN** the user triggers the export action
- **THEN** the `CnMassExportDialog` MUST open with CSV as the default format
- AND the exported file MUST include all selected or all visible contactmomenten
- AND columns MUST include: subject, channel, client, agent, contactedAt, outcome, duration

---

### REQ-OMN-006: Contactmoment Detail View Shows Channel Metadata

The detail view MUST render `channelMetadata` as a human-readable key/value section, and MUST show linked entities as clickable deep links.

**Feature tier**: MVP

#### Scenario: channelMetadata displayed in detail view

- **GIVEN** a contactmoment has `channel: "telefoon"` and `channelMetadata: { "richting": "inkomend", "telefoonnummer": "+31612345678" }`
- **WHEN** a user opens the contactmoment detail view
- **THEN** the detail view MUST show a "Kanaalgegevens" section with each key rendered as a labelled row
- AND `richting: inkomend` and `telefoonnummer: +31612345678` MUST be visible

#### Scenario: Linked client shown as deep link

- **GIVEN** a contactmoment has a `client` reference to client UUID "abc-123" with name "Jan de Vries"
- **WHEN** a user views the contactmoment detail
- **THEN** the linked client MUST be shown as "Jan de Vries" rendered as a clickable link
- AND clicking it MUST navigate to `/clients/abc-123`

#### Scenario: Linked request shown as deep link

- **GIVEN** a contactmoment has a `request` reference to request UUID "def-456" with title "Bouwvergunningaanvraag"
- **WHEN** a user views the contactmoment detail
- **THEN** the linked request MUST be shown as "Bouwvergunningaanvraag" rendered as a clickable link
- AND clicking it MUST navigate to `/requests/def-456`

---

### REQ-OMN-007: Auto-populate Agent and Timestamp on Form Open

The registration form MUST auto-populate `agent` and `contactedAt` when opened.

**Feature tier**: MVP

#### Scenario: Form pre-fills agent with current user

- **GIVEN** a KCC agent "kcc.medewerker1" opens the contactmoment registration form
- **WHEN** the form is initialised
- **THEN** the `agent` field MUST be pre-filled with the current Nextcloud user UID `"kcc.medewerker1"`
- AND the agent field MUST remain editable for retrospective logging

#### Scenario: Form pre-fills contactedAt with current timestamp

- **GIVEN** a user opens the contactmoment registration form at 10:23 on 2026-03-15
- **WHEN** the form is initialised
- **THEN** the `contactedAt` field MUST be pre-filled with `"2026-03-15T10:23:00Z"` (UTC)
- AND the timestamp field MUST remain editable

---

### REQ-OMN-008: Pre-fill Form from Context (Client or Request)

The registration form MUST accept a `clientId` query parameter and pre-fill the client field when provided.

**Feature tier**: MVP

#### Scenario: Form pre-fills client when opened from client context

- **GIVEN** a user clicks "Log contactmoment" on the detail view of client "Jan de Vries" (UUID "abc-123")
- **WHEN** `ContactmomentForm` is opened with query param `?clientId=abc-123`
- **THEN** the `client` field MUST be pre-filled with "Jan de Vries" (UUID "abc-123")
- AND the user MUST be able to change or clear the pre-filled client

#### Scenario: Form pre-fills request when opened from request context

- **GIVEN** a user clicks "Log contactmoment" on request "Bouwvergunningaanvraag" (UUID "def-456") linked to client "Gemeente Utrecht"
- **WHEN** `ContactmomentForm` is opened with `?requestId=def-456&clientId=ghi-789`
- **THEN** both `request` and `client` fields MUST be pre-filled
