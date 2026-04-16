# Tasks: email-calendar-sync

## Section 0: Deduplication Check

### Task 0.1: Verify no overlap with existing services [MVP]
- **Spec ref**: ADR-012
- **Files**: Search `openspec/specs/`, `openregister/lib/Service/`, existing Pipelinq services
- **Findings**:
  - `ObjectService` — reused for all emailLink/calendarLink CRUD (no custom CRUD built)
  - `createObjectStore` — reused for Pinia stores (no hand-rolled stores)
  - No prior email or calendar sync service exists in Pipelinq
  - No overlap found with OpenRegister's `ObjectService`, `RegisterService`, `SchemaService`, or `ConfigurationService`
  - `CnStatusBadge` from `@conduction/nextcloud-vue` reused for calendar event status badges
- [ ] Document deduplication check findings in PR description before merging

---

## Section 1: Seed Data [V1]

### Task 1.1: Add emailLink seed objects to pipelinq_register.json [V1]
- **Spec ref**: REQ-ECS-001
- **Files**: `lib/Settings/pipelinq_register.json`
- **Acceptance**: 5 emailLink seed objects with realistic Dutch values, varied entityTypes (lead, client, contact, request), both directions, unique slugs
- [ ] Add 5 emailLink objects under `components.objects[]` with `@self` envelope (`register: pipelinq`, `schema: email-link`, unique slug)
- [ ] Each object has `messageId`, `subject`, `sender`, `recipients`, `date`, `linkedEntityType`, `linkedEntityId`, `direction`, `syncSource`
- [ ] Verify slugs are unique and match `email-link-001` through `email-link-005` pattern

### Task 1.2: Add calendarLink seed objects to pipelinq_register.json [V1]
- **Spec ref**: REQ-ECS-002
- **Files**: `lib/Settings/pipelinq_register.json`
- **Acceptance**: 4 calendarLink seed objects with realistic Dutch event names, varied statuses and entity types, unique slugs
- [ ] Add 4 calendarLink objects under `components.objects[]` with `@self` envelope (`register: pipelinq`, `schema: calendar-link`, unique slug)
- [ ] Each object has `eventUid`, `title`, `startDate`, `endDate`, `attendees`, `linkedEntityType`, `linkedEntityId`, `status`, `createdFrom`
- [ ] Include both `status: completed` and `status: scheduled` entries for realistic test scenarios

---

## Section 2: Backend Services [V1]

### Task 2.1: Create EmailSyncService [V1]
- **Spec ref**: REQ-ECS-003, REQ-ECS-004, REQ-ECS-005
- **Files**: `lib/Service/EmailSyncService.php`
- **Acceptance**: Service matches emails to CRM entities, avoids duplicates, skips public domains
- [ ] Implement `syncUserEmails(string $userId): int` — fetch messages via `OCP\Mail\IMailManager`, call matching, create emailLink objects, return count
- [ ] Implement `matchEmailToEntities(string $address): array` — query `ObjectService::findObjects` on `contact` and `client` schemas filtering by email field
- [ ] Implement `matchDomainToOrganization(string $domain): ?array` — extract domain, check against client organization emails
- [ ] Implement `isPublicDomain(string $domain): bool` — return true for gmail.com, outlook.com, hotmail.com, yahoo.com, live.com, icloud.com
- [ ] Implement `isDuplicate(string $messageId): bool` — query emailLink objects with `messageId` filter, return true if found
- [ ] Use `ObjectService::saveObject($register, $schema, $data)` (3 positional args per ADR-015)
- [ ] Add `@spec openspec/changes/email-calendar-sync/tasks.md#task-2.1` PHPDoc to class and all public methods

### Task 2.2: Create CalendarSyncService [V1]
- **Spec ref**: REQ-ECS-008, REQ-ECS-009, REQ-ECS-010
- **Files**: `lib/Service/CalendarSyncService.php`
- **Acceptance**: Service syncs calendar events, matches attendees to CRM entities, creates follow-up events
- [ ] Implement `syncUserCalendar(string $userId): int` — query calendar events via `OCP\Calendar\ICalendarQuery`, match attendees, create calendarLink objects
- [ ] Implement `matchAttendeesToEntities(array $attendeeEmails): array` — for each email, call `EmailSyncService::matchEmailToEntities()`
- [ ] Implement `createFollowUpEvent(string $entityType, string $entityId, array $eventData, string $userId): array` — create event via `ICalendarManager`, save calendarLink with `createdFrom: pipelinq`
- [ ] Implement `isDuplicate(string $eventUid): bool` — query calendarLink objects with `eventUid` filter
- [ ] Add `@spec` PHPDoc to class and all public methods

### Task 2.3: Create EmailSyncJob [V1]
- **Spec ref**: REQ-ECS-003, REQ-ECS-008
- **Files**: `lib/BackgroundJob/EmailSyncJob.php`
- **Acceptance**: Job runs every 5 minutes via ITimedJob, calls both sync services for all users with sync enabled
- [ ] Extend `OCP\BackgroundJob\TimedJob` with `setInterval(300)` (5 minutes)
- [ ] Inject `EmailSyncService`, `CalendarSyncService`, `IUserManager`, `IAppConfig`, `LoggerInterface`
- [ ] In `run()`: iterate users, check sync enabled in app config, call `syncUserEmails()` and `syncUserCalendar()`, log counts and any errors
- [ ] Per-user errors MUST be caught and logged — job continues for remaining users (does not abort)
- [ ] Register in `appinfo/info.xml` under `<background-jobs>`
- [ ] Add `@spec` PHPDoc

### Task 2.4: Create EmailSyncController [V1]
- **Spec ref**: REQ-ECS-011, REQ-ECS-012
- **Files**: `lib/Controller/EmailSyncController.php`, `appinfo/routes.php`
- **Acceptance**: Settings endpoints save/load per-user config; trigger endpoint starts manual sync; status returns last run info
- [ ] Implement `GET /api/sync/email/settings` → return current user's sync config from `IAppConfig`
- [ ] Implement `POST /api/sync/email/settings` → validate and save sync config (account, enabled, excludedAddresses) to `IAppConfig`
- [ ] Implement `POST /api/sync/email/trigger` → call `EmailSyncService::syncUserEmails()` + `CalendarSyncService::syncUserCalendar()` for current user, return count
- [ ] Implement `GET /api/sync/email/status` → return last sync timestamp, email count, calendar count, last error (if any)
- [ ] All endpoints derive user identity from `IUserSession` — NEVER trust frontend-sent user ID (ADR-005)
- [ ] Error responses use static messages, never `$e->getMessage()` in JSONResponse (ADR-015)
- [ ] Controller methods MUST be thin (<10 lines) — delegate logic to services (ADR-003)
- [ ] Add routes to `appinfo/routes.php` (specific routes before any wildcard routes)
- [ ] Add `@spec` PHPDoc

---

## Section 3: Unit Tests [V1]

### Task 3.1: Unit tests for EmailSyncService [V1]
- **Spec ref**: REQ-ECS-014
- **Files**: `tests/Unit/Service/EmailSyncServiceTest.php`
- **Acceptance**: ≥3 test methods; covers matching, public domain detection, and deduplication
- [ ] Test `matchEmailToEntities()` returns correct entity when contact email matches
- [ ] Test `matchEmailToEntities()` returns empty array for unknown address
- [ ] Test `isPublicDomain()` returns true for gmail.com, false for corporate domain
- [ ] Test `isDuplicate()` returns true when messageId already exists
- [ ] Test `isDuplicate()` returns false when messageId is new
- [ ] Mock `ObjectService` — do NOT use real DB in unit tests

### Task 3.2: Unit tests for CalendarSyncService [V1]
- **Spec ref**: REQ-ECS-014
- **Files**: `tests/Unit/Service/CalendarSyncServiceTest.php`
- **Acceptance**: ≥3 test methods covering attendee matching and duplicate detection
- [ ] Test `matchAttendeesToEntities()` returns entities for known attendee emails
- [ ] Test `isDuplicate()` correctly identifies existing calendarLink by eventUid
- [ ] Test `createFollowUpEvent()` saves calendarLink with `createdFrom: pipelinq`

### Task 3.3: Unit tests for EmailSyncJob [V1]
- **Spec ref**: REQ-ECS-003
- **Files**: `tests/Unit/BackgroundJob/EmailSyncJobTest.php`
- **Acceptance**: ≥2 test methods covering normal run and per-user error handling
- [ ] Test job calls `syncUserEmails()` for each user with sync enabled
- [ ] Test job continues processing remaining users when one user's sync throws an exception

### Task 3.4: Unit tests for EmailSyncController [V1]
- **Spec ref**: REQ-ECS-011, REQ-ECS-014
- **Files**: `tests/Unit/Controller/EmailSyncControllerTest.php`
- **Acceptance**: ≥3 test methods per endpoint covering success, unauthorized, and validation errors
- [ ] Test `GET /api/sync/email/settings` returns 200 with settings for authenticated user
- [ ] Test `POST /api/sync/email/settings` returns 200 and saves config
- [ ] Test `POST /api/sync/email/trigger` returns 200 with counts
- [ ] Test unauthenticated request returns 401

---

## Section 4: Frontend Components [V1]

### Task 4.1: Create EmailTimelineCard.vue [V1]
- **Spec ref**: REQ-ECS-006, REQ-ECS-007
- **Files**: `src/components/sync/EmailTimelineCard.vue`
- **Acceptance**: Component shows linked emails per entity, supports exclude action, shows empty state
- [ ] Add `<!-- SPDX-License-Identifier: EUPL-1.2 -->` as first line
- [ ] Props: `entityType` (String, required), `entityId` (String, required)
- [ ] On `created()`, fetch emailLink objects filtered by `linkedEntityType` and `linkedEntityId` using `emailLink` object store
- [ ] Display list: direction icon (inbound=arrow-down, outbound=arrow-up), subject, sender/recipient, date formatted via Nextcloud locale
- [ ] "Open in Mail" button: link to Nextcloud Mail (`generateUrl('/apps/mail')`)
- [ ] "Exclude" button: calls `ObjectService.saveObject` to set `excluded: true`, removes from list on success
- [ ] Empty state: `CnEmptyState` with translated message "No emails linked yet"
- [ ] EVERY `await` on store actions MUST be wrapped in `try/catch` with `this.showError()` (ADR-015)
- [ ] ALL strings via `this.t('pipelinq', 'key')` — no hardcoded strings (ADR-007)
- [ ] Import only from `@conduction/nextcloud-vue` — NEVER from `@nextcloud/vue` (ADR-004)
- [ ] All template components MUST be imported AND registered in `components: {}` (ADR-015)
- [ ] `<style scoped>` using only `var(--color-*)` CSS variables (ADR-010)

### Task 4.2: Create CalendarEventCard.vue [V1]
- **Spec ref**: REQ-ECS-009, REQ-ECS-010
- **Files**: `src/components/sync/CalendarEventCard.vue`
- **Acceptance**: Component shows linked calendar events, supports opening follow-up dialog
- [ ] Add SPDX header
- [ ] Props: `entityType` (String, required), `entityId` (String, required)
- [ ] Fetch calendarLink objects for entity on `created()`
- [ ] Display events: title, formatted start/end datetime, attendees (comma-separated), `CnStatusBadge` for status
- [ ] "Schedule follow-up" button opens `FollowUpEventDialog`
- [ ] On dialog confirm, call `POST /api/sync/calendar/events` and refresh list
- [ ] EVERY await wrapped in try/catch with user-facing error (ADR-004)
- [ ] All strings translated (ADR-007)
- [ ] `<style scoped>` with CSS variables only

### Task 4.3: Create FollowUpEventDialog.vue [V1]
- **Spec ref**: REQ-ECS-010
- **Files**: `src/components/sync/FollowUpEventDialog.vue`
- **Acceptance**: Dialog pre-fills attendees from linked contacts, creates calendar event and calendarLink on submit
- [ ] Add SPDX header
- [ ] Use `CnFormDialog` pattern (not `window.confirm()` or custom dialog — ADR-004)
- [ ] Props: `entityType`, `entityId`, `prefillAttendees` (Array)
- [ ] Fields: title, startDate (datetime-local), endDate (datetime-local), notes, attendees (pre-filled from `prefillAttendees`)
- [ ] On submit: `POST /api/sync/email/trigger` with event data and entity reference (update route in controller per design)
- [ ] Emit `event-created` on success so parent refreshes calendarLink list
- [ ] All strings translated

### Task 4.4: Create SyncSettingsSection.vue [V1]
- **Spec ref**: REQ-ECS-011, REQ-ECS-012
- **Files**: `src/components/sync/SyncSettingsSection.vue`
- **Acceptance**: Settings section in UserSettings modal, loads/saves sync config, shows status
- [ ] Add SPDX header
- [ ] Use `CnSettingsCard` or `CnSettingsSection` wrapper
- [ ] On `created()`: call `GET /api/sync/email/settings` to load current config
- [ ] Fields: mail account selector (`NcSelect`, options from settings store), sync enabled toggle (`NcCheckboxRadioSwitch`), excluded addresses (text input)
- [ ] Status display: last sync time, email count, calendar count, error indicator
- [ ] "Sync now" button: calls `POST /api/sync/email/trigger`, shows spinner, displays result count
- [ ] Save button: calls `POST /api/sync/email/settings`, shows success toast
- [ ] All strings translated (en + nl)

---

## Section 5: Wire Components into Detail Views [V1]

### Task 5.1: Add email and calendar cards to ClientDetail.vue [V1]
- **Spec ref**: REQ-ECS-006, REQ-ECS-009
- **Files**: `src/views/clients/ClientDetail.vue`
- **Acceptance**: Client detail shows EmailTimelineCard and CalendarEventCard sections
- [ ] Import `EmailTimelineCard` and `CalendarEventCard`, register in `components: {}`
- [ ] Add `<EmailTimelineCard :entity-type="'client'" :entity-id="clientId" />` in a `CnDetailCard` section below existing sections
- [ ] Add `<CalendarEventCard :entity-type="'client'" :entity-id="clientId" />` below email timeline

### Task 5.2: Add EmailTimelineCard to ContactDetail.vue [V1]
- **Spec ref**: REQ-ECS-006
- **Files**: `src/views/contacts/ContactDetail.vue`
- [ ] Import and register `EmailTimelineCard`
- [ ] Add `<EmailTimelineCard :entity-type="'contact'" :entity-id="contactId" />` section

### Task 5.3: Add email and calendar cards to LeadDetail.vue [V1]
- **Spec ref**: REQ-ECS-006, REQ-ECS-009, REQ-ECS-010
- **Files**: `src/views/leads/LeadDetail.vue`
- [ ] Import and register `EmailTimelineCard` and `CalendarEventCard`
- [ ] Add `<EmailTimelineCard :entity-type="'lead'" :entity-id="leadId" />` section
- [ ] Add `<CalendarEventCard :entity-type="'lead'" :entity-id="leadId" />` section

### Task 5.4: Add email and calendar cards to RequestDetail.vue [V1]
- **Spec ref**: REQ-ECS-006, REQ-ECS-009
- **Files**: `src/views/requests/RequestDetail.vue`
- [ ] Import and register `EmailTimelineCard` and `CalendarEventCard`
- [ ] Add `<EmailTimelineCard :entity-type="'request'" :entity-id="requestId" />` section
- [ ] Add `<CalendarEventCard :entity-type="'request'" :entity-id="requestId" />` section

### Task 5.5: Add SyncSettingsSection to UserSettings.vue [V1]
- **Spec ref**: REQ-ECS-011
- **Files**: `src/views/UserSettings.vue`
- **Note**: User settings is rendered inside `NcAppSettingsDialog` — NOT a routed page (ADR-004)
- [ ] Import and register `SyncSettingsSection`
- [ ] Add `<SyncSettingsSection />` inside the settings dialog content

---

## Section 6: Automation Trigger Types [V2]

### Task 6.1: Register email.received and calendar.event.start trigger types [V2]
- **Spec ref**: REQ-ECS-013
- **Files**: `lib/Service/AutomationService.php` (or wherever trigger types are enumerated)
- **Acceptance**: Automation form shows new trigger options; automation engine dispatches on emailLink/calendarLink creation
- [ ] Add `email.received` and `calendar.event.start` to the list of valid automation trigger values
- [ ] In `EmailSyncService::syncUserEmails()`: after creating an emailLink, call automation evaluation for the linked entity
- [ ] In `CalendarSyncService::syncUserCalendar()`: after creating a calendarLink, evaluate `calendar.event.start` automations for the linked entity
- [ ] Tag as V2 — do NOT enable in info.xml or register until implemented

---

## Section 7: Translations [V1]

### Task 7.1: Add sync UI strings to translation files [V1]
- **Spec ref**: REQ-ECS-015
- **Files**: `l10n/en.json`, `l10n/nl.json`
- **Acceptance**: Both files have identical key sets; no hardcoded strings in sync components
- [ ] Add all string keys from `EmailTimelineCard`, `CalendarEventCard`, `FollowUpEventDialog`, `SyncSettingsSection` to `l10n/en.json` (key == English value)
- [ ] Add Dutch translations for every key in `l10n/nl.json`
- [ ] Verify key parity: both files MUST contain exactly the same keys (zero gaps)
- [ ] Run pre-commit translation check: `grep -rn "'" src/components/sync/ --include='*.vue' | grep -v "this\.t\|import\|//\|console"` — must return zero matches

---

## Section 8: Pre-commit Verification [V1]

### Task 8.1: Run pre-commit checklist [V1]
- **Spec ref**: ADR-015
- [ ] SPDX headers: `grep -rL 'SPDX-License-Identifier' lib/Service/EmailSync* lib/Service/CalendarSync* lib/BackgroundJob/EmailSync* lib/Controller/EmailSync* --include='*.php'` → zero results
- [ ] SPDX headers: `grep -rL 'SPDX-License-Identifier' src/components/sync/ --include='*.vue'` → zero results
- [ ] ObjectService calls: verify all `saveObject`/`findObjects`/`findObject` calls use 3 positional args
- [ ] Error responses: `grep -rn 'getMessage()' lib/Controller/EmailSyncController.php` → zero results
- [ ] Auth checks: verify `IUserSession::getUser()` is called in all controller methods
- [ ] Store registration: verify `email-link` and `calendar-link` are registered in `src/store/store.js` (kebab-case, once each)
- [ ] Translations: `npm run lint` — no missing i18n keys
- [ ] No raw fetch: `grep -rn 'fetch(' src/components/sync/ --include='*.vue'` → zero results
- [ ] No direct @nextcloud/vue imports: `grep -rn "from '@nextcloud/vue'" src/` → zero results
- [ ] Component imports: for every `<NcFoo>` / `<CnFoo>` in sync templates, verify import AND `components: {}` entry

### Task 8.2: Build and smoke test [V1]
- [ ] `npm run build` — zero errors
- [ ] `php -l` on all new PHP files — zero syntax errors
- [ ] `curl GET /api/sync/email/settings` with valid session — verify 200 response shape
- [ ] `curl GET /api/sync/email/settings` unauthenticated — verify 401
- [ ] `curl POST /api/sync/email/settings` with invalid payload — verify 400
- [ ] Open client detail page — verify EmailTimelineCard renders (or shows empty state, not error)
- [ ] Open lead detail page — verify CalendarEventCard renders with "Schedule follow-up" button
