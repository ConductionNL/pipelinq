# Design: omnichannel-registratie

## Architecture Overview

Frontend-only feature. All data operations use the OpenRegister API via the `objectStore` pattern. The `contactmoment` schema is registered in `pipelinq_register.json` (OpenAPI 3.0 format) and loaded via `ConfigurationService::importFromApp()`.

**Standards**: Schema.org `CommunicateAction`, VNG Klantinteracties `Contactmoment` (mapping layer, not stored fields). Per ADR-001, internal properties use schema.org names; VNG mapping is derived.

---

## Data Model (OpenRegister Schema)

Schema slug: `contactmoment` | `@type`: `schema:CommunicateAction`

Registered in `lib/Settings/pipelinq_register.json` under the `pipelinq` register.

| Property | Type | Required | Facetable | Description | Schema.org | VNG Mapping |
|----------|------|----------|-----------|-------------|------------|-------------|
| `subject` | string | Yes | No | Subject of the interaction | `schema:about` | Contactmoment.onderwerp |
| `summary` | string | No | No | Summary notes | `schema:description` | Contactmoment.tekst |
| `channel` | string (enum) | Yes | Yes | Communication channel | `schema:instrument` | Contactmoment.kanaal |
| `outcome` | string (enum) | No | Yes | Result of the interaction | `schema:result` | Contactmoment.resultaat |
| `client` | string (uuid) | No | No | Reference to client object | `schema:recipient` | KlantContactmoment → Klant |
| `contact` | string (uuid) | No | No | Reference to contact person | `schema:participant` | — |
| `request` | string (uuid) | No | No | Reference to request | `schema:object` | ObjectContactmoment → Verzoek |
| `agent` | string | No | Yes | Nextcloud user UID (auto-set) | `schema:agent` | Contactmoment.medewerker |
| `contactedAt` | string (datetime) | No | No | Datetime of interaction (auto-set) | `schema:startTime` | Contactmoment.registratiedatum |
| `duration` | string | No | No | ISO 8601 duration (e.g. `PT6M12S`) | `schema:duration` | Contactmoment.gespreksduur |
| `channelMetadata` | object | No | No | Channel-specific metadata | — | — |
| `notes` | string | No | No | Internal agent notes | `schema:text` | Contactmoment.notitie |

**Channel enum values**: `telefoon`, `email`, `balie`, `chat`, `social`, `brief`

**Outcome enum values**: `afgehandeld`, `doorverbonden`, `terugbelverzoek`, `vervolgactie`

### channelMetadata structure per channel

| Channel | Keys | Description |
|---------|------|-------------|
| `telefoon` | `richting` (inkomend/uitgaand), `telefoonnummer` | Call direction and caller number |
| `email` | `richting` (inkomend/uitgaand), `threadId` | Email thread reference |
| `balie` | `locatie`, `afspraakId` | Counter desk name, appointment ID |
| `chat` | `platform`, `sessionId` | Chat platform (website/whatsapp), session ref |
| `social` | `platform`, `username`, `postId` | Social network, account handle, post ref |
| `brief` | `referentie` | Letter reference number |

---

## Seed Data

Five example `contactmoment` objects with Dutch values:

### 1. Telefoongesprek — bouwvergunningaanvraag
```json
{
  "@type": "schema:CommunicateAction",
  "subject": "Vraag over bouwvergunningaanvraag",
  "channel": "telefoon",
  "outcome": "afgehandeld",
  "agent": "kcc.medewerker1",
  "contactedAt": "2026-03-15T09:23:00Z",
  "duration": "PT6M12S",
  "summary": "Burger vraagt naar status bouwvergunningaanvraag. Doorverwezen naar afdeling VTH.",
  "channelMetadata": { "richting": "inkomend", "telefoonnummer": "+31612345678" }
}
```

### 2. E-mail — reactie op klacht parkeerbeleid
```json
{
  "@type": "schema:CommunicateAction",
  "subject": "Reactie op klacht parkeerbeleid",
  "channel": "email",
  "outcome": "vervolgactie",
  "agent": "kcc.medewerker2",
  "contactedAt": "2026-03-16T14:05:00Z",
  "summary": "E-mail verzonden met toelichting nieuw parkeerbeleid. Vervolgafspraak ingepland.",
  "channelMetadata": { "richting": "uitgaand", "threadId": "msg-20260316-abc123" }
}
```

### 3. Baliegesprek — paspoortaanvraag
```json
{
  "@type": "schema:CommunicateAction",
  "subject": "Aanvraag paspoortverlenging",
  "channel": "balie",
  "outcome": "afgehandeld",
  "agent": "balie.medewerker1",
  "contactedAt": "2026-03-17T11:00:00Z",
  "duration": "PT15M00S",
  "summary": "Burger ingecheckt voor paspoortaanvraag. Documenten gecontroleerd en ingediend.",
  "channelMetadata": { "locatie": "Stadhuis balie 3", "afspraakId": "afsp-20260317-112" }
}
```

### 4. Chat — subsidieaanvraag zonnepanelen
```json
{
  "@type": "schema:CommunicateAction",
  "subject": "Informatie over subsidieaanvraag zonnepanelen",
  "channel": "chat",
  "outcome": "doorverbonden",
  "agent": "kcc.medewerker1",
  "contactedAt": "2026-03-18T13:30:00Z",
  "duration": "PT8M45S",
  "summary": "Burger vraagt naar subsidiemogelijkheden voor zonnepanelen. Doorverwezen naar energieloket.",
  "channelMetadata": { "platform": "website", "sessionId": "chat-20260318-xyz789" }
}
```

### 5. Social media — klacht wegwerkzaamheden
```json
{
  "@type": "schema:CommunicateAction",
  "subject": "Twitter-reactie over wegwerkzaamheden Kalverstraat",
  "channel": "social",
  "outcome": "afgehandeld",
  "agent": "communicatie.medewerker1",
  "contactedAt": "2026-03-19T10:15:00Z",
  "summary": "Reactie geplaatst op klacht over verkeershinder bij wegwerkzaamheden.",
  "channelMetadata": { "platform": "twitter", "username": "@jdvries_ams", "postId": "tw-20260319-456" }
}
```

---

## Key Design Decisions

### 1. Channel-Adaptive Form

**Decision**: `ContactmomentForm.vue` uses a single form with `v-if` blocks per channel. Switching channel clears `channelMetadata` and shows/hides relevant fields.

**Rationale**: A single adaptive form is simpler than separate forms per channel. Clearing metadata on channel change prevents stale data from previous channel selections.

### 2. Call Timer as Standalone Component

**Decision**: `CallTimer.vue` is a standalone component that emits `@duration-updated` with an ISO 8601 string. `ContactmomentForm.vue` listens and writes to the `duration` field.

**Rationale**: Decoupled timer component can be reused in other forms (e.g. balie interactions). Emitting ISO 8601 directly keeps the form data clean.

### 3. CSV Export via OpenRegister

**Decision**: Use OpenRegister's built-in CSV export (`CnMassExportDialog`) — no custom export endpoint.

**Rationale**: OpenRegister already provides CSV/JSON/XML export for any schema. Reusing it avoids duplicate implementation per ADR-011 (no rebuilding existing capabilities).

### 4. Auto-populate agent and contactedAt

**Decision**: `agent` is set to `OC.currentUser` and `contactedAt` to `new Date().toISOString()` in the form's `created()` hook.

**Rationale**: Both fields should default to current user and current time. The agent can be overridden for retrospective logging.

---

## Frontend

### Routes

```
/contactmomenten          → ContactmomentList
/contactmomenten/new      → ContactmomentForm (create)
/contactmomenten/:id      → ContactmomentDetail
```

### Views

**`ContactmomentList.vue`** (`src/views/contactmomenten/ContactmomentList.vue`)
- Uses `CnIndexPage` + `useListView` composable
- Table columns: subject, channel (with icon), client name, agent, contactedAt, outcome
- Filters: channel (multi-select), agent, date range (contactedAt from/to), full-text search
- Row click navigates to `ContactmomentDetail`
- Add button navigates to `ContactmomentForm`
- Mass export via `CnMassExportDialog` (CSV)

**`ContactmomentForm.vue`** (`src/views/contactmomenten/ContactmomentForm.vue`)
- Channel selector (NcSelect) shown first — controls which other fields are displayed
- Always visible: `subject` (required), `channel` (required), `client` (search), `request` (search), `outcome`, `summary`, `notes`
- Telefoon channel: shows `CallTimer` component + `channelMetadata.richting` (inkomend/uitgaand) + `channelMetadata.telefoonnummer`
- Email channel: shows `channelMetadata.richting` + `channelMetadata.threadId`
- Balie channel: shows `channelMetadata.locatie` + `channelMetadata.afspraakId`
- Chat/Social channel: shows `channelMetadata.platform` + `channelMetadata.sessionId` / `channelMetadata.username`
- Brief channel: shows `channelMetadata.referentie`
- `agent` and `contactedAt` pre-filled (can be overridden)
- Can be opened with pre-filled `clientId` query param for context-aware logging

**`ContactmomentDetail.vue`** (`src/views/contactmomenten/ContactmomentDetail.vue`)
- Uses `CnDetailPage` + `CnDetailCard` sections
- Sections: Interaction Details, Channel Metadata (rendered as key/value), Linked Entities
- Linked entities section: client → link to `ClientDetail`, request → link to `RequestDetail`
- Header actions: Edit (opens `ContactmomentForm` in edit mode) + Delete (`CnDeleteDialog`)
- Sidebar: `CnObjectSidebar` (Notes/Audit tabs)

### Components

**`CallTimer.vue`** (`src/components/CallTimer.vue`)
- Displays MM:SS elapsed time
- Buttons: Start / Stop / Reset
- On Stop: emits `@duration-updated` with ISO 8601 duration string (e.g. `PT6M12S`)
- Uses `setInterval` (1s tick) managed in `mounted` / `beforeDestroy`

### Navigation

Add "Contactmomenten" entry to `src/navigation/MainMenu.vue` with phone/chat icon, linking to `/contactmomenten`.

---

## Files Changed

### New Files
- `src/views/contactmomenten/ContactmomentList.vue`
- `src/views/contactmomenten/ContactmomentForm.vue`
- `src/views/contactmomenten/ContactmomentDetail.vue`
- `src/components/CallTimer.vue`

### Modified Files
- `lib/Settings/pipelinq_register.json` — Add `contactmoment` schema with all properties; add to register schemas list
- `src/router/index.js` — Add three named routes: `ContactmomentList`, `ContactmomentNew`, `ContactmomentDetail`
- `src/navigation/MainMenu.vue` — Add "Contactmomenten" nav item
