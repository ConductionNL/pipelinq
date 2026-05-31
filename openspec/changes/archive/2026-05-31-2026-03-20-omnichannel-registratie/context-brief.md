# Proposal: omnichannel-registratie

## Problem

Pipelinq has no contactmoment entity or registration form. KCC agents cannot register interactions from any channel (phone, email, counter, chat, social media). No channel-specific metadata, no timer for phone calls, and no unified inbox. 54% of tenders explicitly require this.

## Solution

Implement omnichannel contact registration with:
1. **Contactmoment schema** in OpenRegister aligned to VNG Klantinteracties
2. **Unified registration form** adapting fields based on channel selection
3. **Call timer component** for phone channel duration tracking
4. **Contact moment list** with search, filter, and CSV export
5. **Activity integration** for entity timelines

## Scope

- Contactmoment schema with channel-specific metadata
- Registration form with channel adaptation (phone, email, counter, chat, social, letter)
- Call timer for phone contacts
- Auto-linking to client by context
- Contact moment list with filters
- CSV export
- Activity timeline integration

## Out of scope

- Unified inbox (V1)
- Bulk registration (V1)
- CTI integration (Enterprise)
- Nextcloud Talk integration (Enterprise)



## Design

# Design: omnichannel-registratie

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
- `/contactmomenten` — ContactmomentList
- `/contactmomenten/new` — ContactmomentForm
- `/contactmomenten/:id` — ContactmomentDetail

#### Views

**ContactmomentList.vue** — Filterable list with channel icons, search, CSV export
**ContactmomentForm.vue** — Adaptive form based on channel selection, call timer
**ContactmomentDetail.vue** — Full detail view with linked entities

#### Components

**CallTimer.vue** — MM:SS timer with start/stop/reset controls, auto-fills duration

### Navigation
Add "Contact Moments" entry to MainMenu.vue.

## Files Changed

### New Files
- `src/views/contactmomenten/ContactmomentList.vue`
- `src/views/contactmomenten/ContactmomentForm.vue`
- `src/views/contactmomenten/ContactmomentDetail.vue`
- `src/components/CallTimer.vue`

### Modified Files
- `lib/Settings/pipelinq_register.json` — Add contactmoment schema
- `src/router/index.js` — Add contactmomenten routes
- `src/navigation/MainMenu.vue` — Add nav item



## Tasks

# Tasks: omnichannel-registratie

## 1. Data Model
- [x] 1.1 Add `contactmoment` schema to `pipelinq_register.json`
- [x] 1.2 Update register's schemas list

## 2. Frontend Views
- [x] 2.1 Create `src/views/contactmomenten/ContactmomentList.vue`
- [x] 2.2 Create `src/views/contactmomenten/ContactmomentForm.vue`
- [x] 2.3 Create `src/views/contactmomenten/ContactmomentDetail.vue`
- [x] 2.4 Create `src/components/CallTimer.vue`

## 3. Navigation and Routing
- [x] 3.1 Add contactmomenten routes to `src/router/index.js`
- [x] 3.2 Add Contact Moments entry to `src/navigation/MainMenu.vue`

## 4. Verification
- [ ] 4.1 Run `npm run build` and verify no errors