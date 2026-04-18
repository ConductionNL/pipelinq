# Design: omnichannel-registratie

**Status:** pr-created

## Architecture

### Data Model (OpenRegister Schema)

New `contactmoment` schema:
- `timestamp` (datetime, required) — When the contact occurred
- `agent` (string, required, facetable) — Nextcloud user UID
- `client` (string, format: uuid) — Client reference
- `contact` (string, format: uuid) — Contact person reference
- `zaak` (string, format: uuid) — Case reference
- `request` (string, format: uuid) — Request reference
- `kanaal` (string, required, facetable) — Channel type
- `onderwerp` (string, required) — Subject/topic
- `toelichting` (string) — Detailed notes
- `resultaat` (string, facetable) — Outcome
- `metadata` (object) — Channel-specific metadata
- `initiatiefnemer` (string, enum: klant/medewerker) — Who initiated
- `registratiedatum` (datetime) — Auto-set creation timestamp

### Frontend

#### Routes
- `/contactmomenten` — ContactmomentenList
- `/contactmomenten/new` — ContactmomentForm
- `/contactmomenten/:id` — ContactmomentDetail

#### Views

**ContactmomentenList.vue** — Filterable list with channel icons, search, CSV export
**ContactmomentForm.vue** — Adaptive form based on channel selection, call timer
**ContactmomentDetail.vue** — Full detail view with linked entities

#### Components

**CallTimer.vue** — MM:SS timer with start/stop/reset controls, auto-fills duration

### Navigation
Add "Contact Moments" entry to MainMenu.vue.

## Files Changed

### New Files
- `src/views/contactmomenten/ContactmomentenList.vue`
- `src/views/contactmomenten/ContactmomentForm.vue`
- `src/views/contactmomenten/ContactmomentDetail.vue`
- `src/components/CallTimer.vue`

### Modified Files
- `lib/Settings/pipelinq_register.json` — Add contactmoment schema
- `src/router/index.js` — Add contactmomenten routes
- `src/navigation/MainMenu.vue` — Add nav item
