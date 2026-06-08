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
- [ ] Document leaf-consumption decision + ADR-022 citation in PR description before merging

---

## Section 1: Enable leaves on CRM detail pages [V1]

### Task 1.1: Add `email` + `calendar` to schema linkedTypes [V1]
- **Spec ref**: Requirement "Enable email + calendar leaves on CRM detail pages"
- **Files**: `lib/Settings/pipelinq_register.json`, app manifest (ADR-024)
- **Acceptance**: The leaf tabs/cards render on client, contact, lead, request detail pages
- [ ] Add `email` and `calendar` to the `linkedTypes` of the `client`, `contact`, `lead`, `request` schemas
- [ ] Confirm the app manifest declares the `email` + `calendar` leaf widget placements on those detail pages
- [ ] Verify the register file contains NO `emailLink` or `calendarLink` schema definition (remove if present)

---

## Section 2: CRM email-matching job [V1]

### Task 2.1: Create EmailMatchJob + matcher [V1]
- **Spec ref**: Requirements "CRM email-to-entity matching job", "Email-to-contact matching rule", "Domain-to-organization matching rule"
- **Files**: `lib/BackgroundJob/EmailMatchJob.php`, `lib/Service/EmailMatchService.php`
- **Acceptance**: Job matches Mail messages to CRM entities and links them THROUGH the email leaf's link API
- [ ] Extend `OCP\BackgroundJob\TimedJob` with `setInterval(300)` (5 minutes)
- [ ] Inject `IUserManager`, `IAppConfig`, `LoggerInterface`, `OCP\Mail\IMailManager`, and the OR email-leaf link client/service
- [ ] Implement `matchEmailToEntities(string $address): array` — query `contact`/`client` schemas by email field
- [ ] Implement `matchDomainToOrganization(string $domain): ?array` — corporate-domain fallback
- [ ] Implement `isPublicDomain(string $domain): bool` — gmail/outlook/hotmail/yahoo/live/icloud
- [ ] On match, call the email leaf's link API to attach the message to the matched OR object (do NOT write a pipelinq link table)
- [ ] Skip messages the leaf has already linked (no duplicate links)
- [ ] Per-user errors caught + logged; job continues for remaining users
- [ ] Register in `appinfo/info.xml` under `<background-jobs>`
- [ ] Add `@spec` PHPDoc to class and all public methods

---

## Section 3: Per-user matching settings [V1]

### Task 3.1: Create matching-settings controller [V1]
- **Spec ref**: Requirements "Per-user matching-job settings", "Matching-job status display"
- **Files**: `lib/Controller/EmailSyncController.php`, `appinfo/routes.php`
- [ ] `GET /api/sync/email/settings` → current user's matching config from `IAppConfig`
- [ ] `POST /api/sync/email/settings` → validate + save (account, enabled, excludedAddresses)
- [ ] `POST /api/sync/email/trigger` → run the matching job for the current user, return count linked
- [ ] `GET /api/sync/email/status` → last run timestamp, links-created count, last error
- [ ] Derive user identity from `IUserSession` — never trust frontend-sent user ID (ADR-005)
- [ ] Error responses use static messages, never `$e->getMessage()` (ADR-015)
- [ ] Controller methods thin (<10 lines) — delegate to service (ADR-003)
- [ ] Add `@spec` PHPDoc

### Task 3.2: Create SyncSettingsSection.vue [V1]
- **Spec ref**: Requirement "Per-user matching-job settings"
- **Files**: `src/components/sync/SyncSettingsSection.vue`, `src/views/UserSettings.vue`
- **Note**: Rendered inside `NcAppSettingsDialog`, NOT a routed page (ADR-004)
- [ ] SPDX header first line
- [ ] On `created()`: `GET /api/sync/email/settings`
- [ ] Fields: mail account selector (`NcSelect` with `inputLabel`), enabled toggle (`NcCheckboxRadioSwitch`), excluded addresses
- [ ] Status display: last run time, links-created count, error indicator
- [ ] "Sync now" → `POST /api/sync/email/trigger`; Save → `POST /api/sync/email/settings`
- [ ] All strings via `t('pipelinq', ...)`; import only from `@conduction/nextcloud-vue`
- [ ] Register `<SyncSettingsSection />` in the UserSettings dialog content

---

## Section 4: Automation trigger types [V2]

### Task 4.1: Register email.received and calendar.event.start [V2]
- **Spec ref**: Requirement "Automation trigger types"
- **Files**: `lib/Service/AutomationService.php` (or wherever trigger types are enumerated)
- [ ] Add `email.received` and `calendar.event.start` to valid automation trigger values
- [ ] After the matching job links an inbound email, evaluate `email.received` automations for the linked entity
- [ ] Subscribe to the calendar leaf's link/event signal to evaluate `calendar.event.start` automations
- [ ] Tag as V2 — do NOT enable until implemented

---

## Section 5: Unit Tests [V1]

### Task 5.1: Tests for the matcher + job [V1]
- **Spec ref**: Requirement "Unit tests"
- **Files**: `tests/Unit/Service/EmailMatchServiceTest.php`, `tests/Unit/BackgroundJob/EmailMatchJobTest.php`
- [ ] Test exact-address match returns the correct entity
- [ ] Test unknown address returns empty and creates no link
- [ ] Test `isPublicDomain()` true for gmail.com, false for corporate domain
- [ ] Test the job calls the email leaf link API on match (mock the leaf client)
- [ ] Test the job continues when one user's run throws
- [ ] Mock OR services — no real DB in unit tests

### Task 5.2: Tests for the settings controller [V1]
- **Spec ref**: Requirement "Unit tests"
- **Files**: `tests/Unit/Controller/EmailSyncControllerTest.php`
- [ ] 200 success, 401 unauthenticated, 400 invalid input for each endpoint

---

## Section 6: Translations [V1]

### Task 6.1: Add sync-settings UI strings [V1]
- **Spec ref**: Requirement "Translation coverage"
- **Files**: `l10n/en.json`, `l10n/nl.json`
- [ ] Add every `SyncSettingsSection` key to `en.json` (key == English value) and `nl.json` (Dutch)
- [ ] Verify key parity (zero gaps)

---

## Section 7: Pre-commit Verification [V1]

### Task 7.1: Run pre-commit checklist [V1]
- **Spec ref**: ADR-015, ADR-022
- [ ] SPDX headers on all new PHP + Vue files → zero missing
- [ ] Register file contains NO `emailLink`/`calendarLink` schema and NO pipelinq timeline component (ADR-022 gate)
- [ ] Error responses: `grep -rn 'getMessage()' lib/Controller/EmailSyncController.php` → zero results
- [ ] Auth: `IUserSession::getUser()` in all controller methods
- [ ] No raw `fetch(` in `src/components/sync/`; no `from '@nextcloud/vue'` imports

### Task 7.2: Build and smoke test [V1]
- [ ] `npm run build` — zero errors
- [ ] `php -l` on all new PHP files — zero syntax errors
- [ ] `GET /api/sync/email/settings` authenticated → 200; unauthenticated → 401; invalid POST → 400
- [ ] Open a client detail page — verify the `email` leaf's `CnEmailTab` renders (empty state, not error)
- [ ] Open a lead detail page — verify the `calendar` leaf's `CnCalendarCard` renders with its "Add meeting" create flow
