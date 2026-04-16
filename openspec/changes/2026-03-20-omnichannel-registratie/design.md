# Design: omnichannel-registratie

**Status:** pr-created

## Architecture

### Data Model (OpenRegister Schema)

New `contactmoment` schema:
- `subject` (string, required, max 255 chars) — Subject/topic of interaction
- `summary` (string) — Summary or notes of the interaction
- `channel` (string, required, enum, facetable) — Communication channel: `telefoon`, `email`, `balie`, `chat`, `social`, `brief`
- `outcome` (string, enum, facetable) — Result: `afgehandeld`, `doorverbonden`, `terugbelverzoek`, `vervolgactie`
- `client` (string, uuid) — Reference to client
- `request` (string, uuid) — Reference to request
- `agent` (string, facetable) — Nextcloud user UID of handler
- `contactedAt` (datetime) — Date/time of interaction
- `duration` (string, ISO 8601) — Interaction duration (phone calls)
- `channelMetadata` (object) — Channel-specific metadata with per-channel sub-schemas:
  - `telefoon`: `{direction: "inbound"|"outbound", extension?: string}`
  - `email`: `{threadId?: string, cc?: string[], bcc?: string[]}`
  - `balie`: `{location?: string, waitTime?: integer}`
  - `chat`: `{sessionId?: string, platform?: string}`
  - `social`: `{handle?: string, platform: "facebook"|"twitter"|"linkedin"}`
  - `brief`: `{referenceNumber?: string, sendDate?: date}`
  - Max properties per channel: 5, max string length per value: 255
- `notes` (string) — Additional internal notes

**Security note:** CSV export (ContactmomentenList.vue) must escape cell values beginning with formula-trigger characters (`=`, `+`, `-`, `@`) by prefixing with a single quote or tab character to prevent CSV injection attacks.

### Backend

#### REST API Endpoints

All endpoints require authentication via Nextcloud user session.

- **DELETE** `/api/v1/contactmomenten/{id}` — Delete a contactmoment
  - Returns `200 OK { success: true }` on success
  - Returns `401` if not authenticated
  - Returns `404` if contactmoment not found
  - Returns `403` if user is neither the creating agent nor a Nextcloud admin
  - Returns `500` on internal error

#### Architecture: Controller → Service → ObjectService

1. **ContactmomentController** (lib/Controller/ContactmomentController.php)
   - Handles HTTP request routing and authentication checks
   - Delegates business logic to ContactmomentService
   - Enforces user context from IUserSession
   - Returns JSONResponse with appropriate HTTP status codes

2. **ContactmomentService** (lib/Service/ContactmomentService.php)
   - Retrieves ObjectService from OpenRegister (DI container)
   - Checks authorization: only the creating agent or a Nextcloud admin may delete
   - Calls ObjectService to perform actual CRUD operations
   - Throws DoesNotExistException if object not found
   - Throws NotPermittedException if user lacks permission

3. **OpenRegister ObjectService** (external dependency)
   - Performs persistence operations against the configured register and schema
   - Manages object lifecycle, timestamps, and indexing

#### Authorization Model

- **List/View:** All authenticated users may list and view contactmomenten
- **Create:** All authenticated users may create contactmomenten
- **Update:** Only the creating agent may edit a contactmoment
- **Delete:** Only the creating agent or a Nextcloud admin may delete a contactmoment (enforced by ContactmomentService)

### Frontend

#### Routes
- `/contactmomenten` — ContactmomentenList
- `/contactmomenten/new` — ContactmomentForm via quick-log dialog
- `/contactmomenten/:id` — ContactmomentDetail

#### Views

**ContactmomentenList.vue** — Uses `CnIndexPage` wrapper from `@conduction/nextcloud-vue`. Filterable list with channel icons, search, and quick-log add dialog. Displays subject, channel, outcome, and contact date columns.

**ContactmomentForm.vue** — Custom form (no wrapper component). Adaptive layout based on channel selection. Includes CallTimer component for phone channel duration tracking. Uses `NcTextField` (Nextcloud Vue) for text input and channel button selector.

**ContactmomentDetail.vue** — Uses `CnDetailPage` wrapper and `CnDetailCard` components from `@conduction/nextcloud-vue`. Full detail view with contact information, linked client/request, outcome, and channel metadata. Edit button toggles back to ContactmomentForm for in-place editing. Delete button (admin/owner only) calls DELETE endpoint via ContactmomentController.

#### Components

**CallTimer.vue** — Reusable MM:SS timer with start/stop/reset controls. Displays elapsed time, auto-fills duration field on stop. Phone channel only.

### Standards Compliance

- **SPDX Licence Headers:** All new `.vue` and `.php` files must include the EUPL-1.2 SPDX header:
  ```
  // SPDX-License-Identifier: EUPL-1.2
  // Copyright (C) 2026 Conduction B.V.
  ```
- **Internationalization (i18n):** All user-visible strings must use the `t('pipelinq', 'string')` helper function for translation key externalisation. No hardcoded UI text.

### Navigation
Add "Contact Moments" entry to MainMenu.vue with appropriate icon.

## Files Changed

### New Files
- `src/views/contactmomenten/ContactmomentenList.vue` — Uses `CnIndexPage`
- `src/views/contactmomenten/ContactmomentForm.vue` — Custom form with CallTimer
- `src/views/contactmomenten/ContactmomentDetail.vue` — Uses `CnDetailPage`, `CnDetailCard`
- `src/components/CallTimer.vue` — Timer component
- `lib/Controller/ContactmomentController.php` — REST API delete endpoint (auth-required)
- `lib/Service/ContactmomentService.php` — Business logic & permission checks

### Modified Files
- `lib/Settings/pipelinq_register.json` — Add contactmoment schema with channelMetadata per-channel sub-schemas
- `src/router/index.js` — Add contactmomenten routes
- `src/navigation/MainMenu.vue` — Add nav item

### Test Files
- `tests/Unit/Controller/ContactmomentControllerTest.php` — REST API endpoint tests
- `tests/Unit/Service/ContactmomentServiceTest.php` — Service layer permission tests
