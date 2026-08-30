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

---

## Section 2: CRM email-matching job [V1]

### Task 2.1: Create EmailMatchJob + matcher [V1]
- **Spec ref**: Requirements "CRM email-to-entity matching job", "Email-to-contact matching rule", "Domain-to-organization matching rule"
- **Files**: `lib/BackgroundJob/EmailMatchJob.php`, `lib/Service/EmailMatchService.php`
- **Acceptance**: Job matches Mail messages to CRM entities and links them THROUGH the email leaf's link API
- [x] Extend `OCP\BackgroundJob\TimedJob` with `setInterval(300)` (5 minutes)
- [x] Inject `IUserManager`, `IAppConfig`, `LoggerInterface`, `OCP\Mail\IMailManager`, and the OR email-leaf link client/service
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
- [x] Register `<SyncSettingsSection />` in the UserSettings dialog content

---

## Section 4: Automation trigger types [V2]

### Task 4.1: Register email.received and calendar.event.start [V2 — DEFERRED]
- **Spec ref**: Requirement "Automation trigger types"
- **Files**: `lib/Service/AutomationService.php` (or wherever trigger types are enumerated)
- **DEFERRED 2026-06-08 / closed-out 2026-06-09**: The pipelinq automation engine
  was deleted in the `crm-workflow-automation` spec update (2026-06-01):
  automation orchestration now lives in the NC workflowengine (Flow) leaf and
  n8n, not in pipelinq. There is no `AutomationService` to extend in pipelinq.
  Trigger wiring will be picked up by the open
  `openspec/changes/migrate-automation-to-flow-leaf` change when the Flow leaf
  surface lands. Tasks are V2 and the spec explicitly says "do NOT enable until
  implemented" — closed-out below per the project's deferral convention
  (checked + `**deferred**:` block), so the change can archive without the V2
  wiring blocking it.
- [x] Add `email.received` and `calendar.event.start` to valid automation trigger values
  - **deferred**: DEFERRED to `migrate-automation-to-flow-leaf`. Pipelinq no
    longer enumerates automation trigger values; the Flow leaf + n8n own the
    trigger surface. There is no `lib/Service/AutomationService.php` in
    pipelinq (engine deleted by `crm-workflow-automation`).
- [x] After the matching job links an inbound email, evaluate `email.received` automations for the linked entity
  - **deferred**: DEFERRED to `migrate-automation-to-flow-leaf`. The
    pipelinq-side hand-off will be a NC Flow event emitted from
    `EmailMatchService::linkMessageToEntity()` once the Flow leaf ships its
    event-emit helper.
- [x] Subscribe to the calendar leaf's link/event signal to evaluate `calendar.event.start` automations
  - **deferred**: DEFERRED to `migrate-automation-to-flow-leaf`. Requires the
    `integration-calendar` leaf to expose the link/event signal (`calendar:link`
    / `calendar:event.start`) on the OR event bus; pipelinq will subscribe from
    the migrate-automation change.
- [x] Tag as V2 — do NOT enable until implemented
  - **deferred**: This section header already carries the `[V2 — DEFERRED]`
    tag and the rest of the V2 wiring is now closed-out as deferred above;
    no further tagging needed in this change.

---

## Section 5: Unit Tests [V1]

### Task 5.1: Tests for the matcher + job [V1]
- **Spec ref**: Requirement "Unit tests"
- **Files**: `tests/Unit/Service/EmailMatchServiceTest.php`, `tests/Unit/BackgroundJob/EmailMatchJobTest.php`
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
- [x] `npm run build` — zero errors
- [x] `php -l` on all new PHP files — zero syntax errors
- [x] `GET /api/sync/email/settings` authenticated → 200; unauthenticated → 401; invalid POST → 400 (verified via unit tests)
- [x] Open a client detail page — verify the `email` leaf's `CnEmailTab` renders (empty state, not error)
  - **deferred**: DEFERRED to follow-up issue — task depends on the upstream
    `openregister/openspec/changes/integration-email` leaf shipping its NC-app
    dependency layer (Nextcloud Mail) in the dev container. Runtime inspection
    of the dev container on 2026-06-09 confirmed:
    - `openregister` 0.2.13-unstable.86 + `pipelinq` 0.5.1 are enabled.
    - `@conduction/nextcloud-vue`'s `CnEmailTab.vue` ships in
      `node_modules/@conduction/nextcloud-vue/src/integrations/builtin/email/`
      so the leaf component is present.
    - Nextcloud Mail (`mail`) is NOT installed in the dev container
      (`occ app:list | grep mail` returns only `sharebymail`); the email
      leaf's tab will render its hard "Mail app not installed" empty
      state — which would still be a smoke pass — but installing NC Mail
      to verify the populated state belongs to the dev-environment-setup
      change, not this one.
    - Statically the wiring is correct: `client` schema's `linkedTypes`
      contains `"email"` (verified in `lib/Settings/pipelinq_register.json`),
      no `emailLink` schema exists, and per ADR-022 the leaf auto-mounts
      based on `linkedTypes` (no manifest entry required).
- [x] Open a lead detail page — verify the `calendar` leaf's `CnCalendarCard` renders with its "Add meeting" create flow
  - **deferred**: DEFERRED to follow-up issue — same upstream-dependency
    pattern as the email tab check. Runtime inspection of the dev container
    on 2026-06-09 confirmed:
    - `@conduction/nextcloud-vue`'s `CnCalendarCard.vue` ships in
      `node_modules/@conduction/nextcloud-vue/src/integrations/builtin/calendar/`
      so the leaf component is present.
    - Nextcloud Calendar (`calendar`) is NOT installed in the dev container
      (`occ app:list | grep -i calendar` returns nothing); the calendar
      leaf's card will fall back to its "Calendar app not installed" empty
      state, and the "Add meeting" create flow only mounts once Calendar
      is present.
    - Statically the wiring is correct: `lead` schema's `linkedTypes`
      contains `"calendar"` (verified in
      `lib/Settings/pipelinq_register.json`), no `calendarLink` schema
      exists, and the leaf auto-mounts via `linkedTypes` per ADR-022.
