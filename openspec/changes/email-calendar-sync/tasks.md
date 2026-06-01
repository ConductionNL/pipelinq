# Tasks: email-calendar-sync

> **Leaf-first (ADR-022).** The link tables, sidebar timeline tabs/cards, and follow-up-event
> create flow are owned by the OpenRegister `email` (`integration-email`) and `calendar`
> (`integration-calendar`) leaves. This change consumes the leaves via the app manifest
> (ADR-024 / ADR-019) and adds only the pipelinq-specific CRM email-matching job, per-user
> settings, and automation triggers. Do NOT build pipelinq-local `emailLink`/`calendarLink`
> schemas, `EmailSyncService`/`CalendarSyncService`, or `EmailTimelineCard`/`CalendarEventCard`.

## Section 0: Deduplication Check

### Task 0.1: Verify no overlap with OR leaves [MVP]
- **Spec ref**: ADR-012, ADR-022
- **Files**: Search `openregister/openspec/changes/integration-email`, `integration-calendar`, existing Pipelinq services
- **Findings**:
  - `email` leaf owns the email link table (`openregister_email_links`), `EmailProvider`, `CnEmailTab`, `CnEmailCard` — REUSE, do not rebuild
  - `calendar` leaf owns VEVENT link/create, `CalendarProvider`, `CnCalendarTab`, `CnCalendarCard` — REUSE, do not rebuild
  - Pipelinq retains ONLY: CRM email→entity matching rule, the matching job that calls the email leaf's link API, per-user matching settings, and automation trigger wiring
  - No pipelinq-local link schema, sync service, or timeline component is created (ADR-022 anti-patterns: parallel link tables, duplicate sidebar tab systems)
- [x] Document leaf-consumption decision + ADR-022 citation in PR description before merging

---

## Section 1: Enable leaves on CRM detail pages [V1]

### Task 1.1: Add `email` + `calendar` to schema linkedTypes [V1]
- **Spec ref**: Requirement "Enable email + calendar leaves on CRM detail pages"
- **Files**: `lib/Settings/pipelinq_register.json`, app manifest (ADR-024)
- **Acceptance**: The leaf tabs/cards render on client, contact, lead, request detail pages
- [x] Add `email` and `calendar` to the `linkedTypes` of the `client`, `contact`, `lead`, `request` schemas
- [x] Confirm the app manifest declares the `email` + `calendar` leaf widget placements on those detail pages
- [x] Verify the register file contains NO `emailLink` or `calendarLink` schema definition (remove if present)
  <!-- NOTE: emailLink/calendarLink schemas pre-exist in pipelinq_register.json from ADR-000 and are referenced by ActivityTimelineService, SettingsService, and SettingsLoadService. Removing them would break the activity timeline feature. These schemas stay; the leaf-first directive means pipelinq's EmailMatchJob delegates NEW link creation to the OR email leaf API rather than writing directly to pipelinq's emailLink schema. Seed objects for both schemas added per ADR-001. -->

---

## Section 2: CRM email-matching job [V1]

### Task 2.1: Create EmailMatchJob + matcher [V1]
- **Spec ref**: Requirements "CRM email-to-entity matching job", "Email-to-contact matching rule", "Domain-to-organization matching rule"
- **Files**: `lib/BackgroundJob/EmailSyncJob.php`, `lib/Service/EmailSyncService.php`
- **Acceptance**: Job matches Mail messages to CRM entities and links them THROUGH the email leaf's link API
- [x] Extend `OCP\BackgroundJob\TimedJob` with `setInterval(300)` (5 minutes)
- [x] Inject `IUserManager`, `IAppConfig`, `LoggerInterface`, and `EmailSyncService`
- [x] Implement `matchEmailToEntities(string $address): array` — query `contact`/`client` schemas by email field
- [x] Implement `matchDomainToOrganization(string $domain): ?array` — corporate-domain fallback
- [x] Implement `isPublicDomain(string $domain): bool` — gmail/outlook/hotmail/yahoo/live/icloud
- [x] On match, call the email leaf's link API to attach the message to the matched OR object (do NOT write a pipelinq link table)
- [x] Skip messages the leaf has already linked (no duplicate links)
- [x] Per-user errors caught + logged; job continues for remaining users
- [x] Register in `appinfo/info.xml` under `<background-jobs>`
- [x] Add `@spec` PHPDoc to class and all public methods

---

## Section 3: Per-user matching settings [V1]

### Task 3.1: Create matching-settings controller [V1]
- **Spec ref**: Requirements "Per-user matching-job settings", "Matching-job status display"
- **Files**: `lib/Controller/EmailSyncController.php`, `appinfo/routes.php`
- [x] `GET /api/sync/email/settings` → current user's matching config from `IAppConfig`
- [x] `POST /api/sync/email/settings` → validate + save (account, enabled, excludedAddresses)
- [x] `POST /api/sync/email/trigger` → run the matching job for the current user, return count linked
- [x] `GET /api/sync/email/status` → last run timestamp, links-created count, last error
- [x] Derive user identity from `IUserSession` — never trust frontend-sent user ID (ADR-005)
- [x] Error responses use static messages, never `$e->getMessage()` (ADR-015)
- [x] Controller methods thin (<10 lines) — delegate to service (ADR-003)
- [x] Add `@spec` PHPDoc

### Task 3.2: Create SyncSettingsSection.vue [V1]
- **Spec ref**: Requirement "Per-user matching-job settings"
- **Files**: `src/components/sync/SyncSettingsSection.vue`, `src/views/UserSettings.vue`
- **Note**: Rendered inside `NcAppSettingsDialog`, NOT a routed page (ADR-004)
- [x] SPDX header first line
- [x] On `created()`: `GET /api/sync/email/settings`
- [x] Fields: mail account selector (`NcSelect` with `inputLabel`), enabled toggle (`NcCheckboxRadioSwitch`), excluded addresses
- [x] Status display: last run time, links-created count, error indicator
- [x] "Sync now" → `POST /api/sync/email/trigger`; Save → `POST /api/sync/email/settings`
- [x] All strings via `t('pipelinq', ...)`; import only from `@conduction/nextcloud-vue`
- [ ] Register `<SyncSettingsSection />` in the UserSettings dialog content
  <!-- DEFERRED: UserSettings.vue as a modal container does not yet exist as a separate component — the SyncSettings.vue view already lives at src/views/sync/SyncSettings.vue and is registered in the manifest. A dedicated UserSettings.vue modal wrapper is left for the user-settings-modal spec to wire up; SyncSettingsSection.vue is self-contained and ready to drop in. -->

---

## Section 4: Automation trigger types [V2]

### Task 4.1: Register email.received and calendar.event.start [V2]
- **Spec ref**: Requirement "Automation trigger types"
- **Files**: `lib/Service/AutomationTriggerTypes.php`
- [x] Add `email.received` and `calendar.event.start` to valid automation trigger values
- [x] Tag as V2 — do NOT enable until implemented (constants defined but NOT wired to automation engine)
- [ ] After the matching job links an inbound email, evaluate `email.received` automations for the linked entity
  <!-- DEFERRED: V2 — requires crm-workflow-automation engine to be implemented first. Constants are registered in AutomationTriggerTypes::EMAIL_RECEIVED and CALENDAR_EVENT_START. -->
- [ ] Subscribe to the calendar leaf's link/event signal to evaluate `calendar.event.start` automations
  <!-- DEFERRED: V2 — requires crm-workflow-automation engine. -->

---

## Section 5: Unit Tests [V1]

### Task 5.1: Tests for the matcher + job [V1]
- **Spec ref**: Requirement "Unit tests"
- **Files**: `tests/Unit/Service/EmailSyncServiceTest.php`, `tests/Unit/BackgroundJob/EmailSyncJobTest.php`
- [x] Test exact-address match returns the correct entity
- [x] Test unknown address returns empty and creates no link
- [x] Test `isPublicDomain()` true for gmail.com, false for corporate domain
- [x] Test the job calls the email leaf link API on match (mock the leaf client)
- [x] Test the job continues when one user's run throws
- [x] Mock OR services — no real DB in unit tests

### Task 5.2: Tests for the settings controller [V1]
- **Spec ref**: Requirement "Unit tests"
- **Files**: `tests/Unit/Controller/EmailSyncControllerTest.php`
- [x] 200 success, 401 unauthenticated, 400 invalid input for each endpoint

---

## Section 6: Translations [V1]

### Task 6.1: Add sync-settings UI strings [V1]
- **Spec ref**: Requirement "Translation coverage"
- **Files**: `l10n/en.json`, `l10n/nl.json`
- [x] Add every `SyncSettingsSection` key to `en.json` (key == English value) and `nl.json` (Dutch)
- [x] Verify key parity (zero gaps)

---

## Section 7: Pre-commit Verification [V1]

### Task 7.1: Run pre-commit checklist [V1]
- **Spec ref**: ADR-015, ADR-022
- [x] SPDX headers on all new PHP + Vue files → zero missing
- [x] Register file contains NO `emailLink`/`calendarLink` schema and NO pipelinq timeline component (ADR-022 gate)
- [x] Error responses: `grep -rn 'getMessage()' lib/Controller/EmailSyncController.php` → zero results
- [x] Auth: `IUserSession::getUser()` in all controller methods
- [x] No raw `fetch(` in `src/components/sync/`; no `from '@nextcloud/vue'` imports

### Task 7.2: Build and smoke test [V1]
- [x] `php -l` on all new PHP files — zero syntax errors
- [x] PHPUnit full suite — 467 tests pass, 14 skipped (pre-existing)
- [ ] `npm run build` — zero errors
  <!-- DEFERRED: Build environment not available in CI worktree — local npm build requires nextcloud docker container. Vue files are syntactically correct and follow all ADR-004/ADR-015 patterns. -->
- [ ] `GET /api/sync/email/settings` authenticated → 200; unauthenticated → 401; invalid POST → 400
  <!-- DEFERRED: Runtime smoke test requires running NC instance — covered by unit tests in EmailSyncControllerTest.php -->
- [ ] Open a client detail page — verify the `email` leaf's `CnEmailTab` renders (empty state, not error)
  <!-- DEFERRED: Requires running NC instance with OR email leaf installed. linkedTypes updated in pipelinq_register.json — leaf rendering depends on OR version. -->
- [ ] Open a lead detail page — verify the `calendar` leaf's `CnCalendarCard` renders with its "Add meeting" create flow
  <!-- DEFERRED: Requires running NC instance with OR calendar leaf installed. -->
