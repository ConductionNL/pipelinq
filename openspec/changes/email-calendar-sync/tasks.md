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
- [x] Confirm the app manifest declares the `email` + `calendar` leaf widget placements on those detail pages (CnEmailTab/CnCalendarTab + CnEmailCard/CnCalendarCard added to all four detail pages)
- [x] Verify the register file contains NO `emailLink` or `calendarLink` schema definition (removed the two stale schemas + their register membership)

---

## Section 2: CRM email-matching job [V1]

### Task 2.1: Create EmailMatchJob + matcher [V1]
- **Spec ref**: Requirements "CRM email-to-entity matching job", "Email-to-contact matching rule", "Domain-to-organization matching rule"
- **Files**: `lib/BackgroundJob/EmailMatchJob.php`, `lib/Service/EmailMatchService.php`
- **Acceptance**: Job matches Mail messages to CRM entities and links them THROUGH the email leaf's link API
- [x] Extend `OCP\BackgroundJob\TimedJob` with `setInterval(300)` (5 minutes) — `EmailMatchJob`
- [x] Inject `IUserManager`, the matcher (`EmailMatchService`, which holds `IAppConfig`), `LoggerInterface`, the candidate-message provider (`MailMessageProvider`), and the OR email-leaf link adapter (`EmailLeafLinkAdapter`). NOTE: `OCP\Mail\IMailManager` does NOT exist as a public OCP interface (only `OCP\Mail\IMailer`); NC Mail message enumeration is non-public, so it is isolated behind `MailMessageProvider` (see deferral note at end).
- [x] Implement `matchEmailToEntities(string $address): array` — query `contact`/`client` schemas by email field (real OR `findAll`, ADR-022)
- [x] Implement `matchDomainToOrganization(string $domain): ?array` — corporate-domain fallback
- [x] Implement `isPublicDomain(string $domain): bool` — gmail/outlook/hotmail/yahoo/live/icloud (+ common NL providers)
- [x] On match, call the email leaf's link API (`EmailLinkService::linkEmail` via `OpenRegisterEmailLeafLinkAdapter`) to attach the message to the matched OR object (no pipelinq link table)
- [x] Skip messages the leaf has already linked (adapter treats the leaf's 409 as "no new link" → no duplicates)
- [x] Per-user errors caught + logged; job continues for remaining users (per-user `runForUser` is try/wrapped; static "Sync failed" status, no internals leaked — ADR-005)
- [x] Register in `appinfo/info.xml` under `<background-jobs>`
- [x] Add `@spec` PHPDoc to class and all public methods

---

## Section 3: Per-user matching settings [V1]

### Task 3.1: Create matching-settings controller [V1]
- **Spec ref**: Requirements "Per-user matching-job settings", "Matching-job status display"
- **Files**: `lib/Controller/EmailSyncController.php`, `appinfo/routes.php`
- [x] `GET /api/sync/email/settings` → current user's matching config (per-user, via `EmailMatchService`/`IConfig`)
- [x] `POST /api/sync/email/settings` → validate + save (account, enabled, excludedAddresses)
- [x] `POST /api/sync/email/trigger` → run the matching job for the current user, return status (links-created count)
- [x] `GET /api/sync/email/status` → last run timestamp, links-created count, last error
- [x] Derive user identity from `IUserSession` — never trust frontend-sent user ID (ADR-005, IDOR-safe)
- [x] Error responses use static l10n messages, never `$e->getMessage()` (ADR-015)
- [x] Controller methods thin — delegate to service (ADR-003)
- [x] Add `@spec` PHPDoc

### Task 3.2: Create SyncSettingsSection.vue [V1]
- **Spec ref**: Requirement "Per-user matching-job settings"
- **Files**: `src/components/sync/SyncSettingsSection.vue`, `src/views/UserSettings.vue`
- **Note**: Rendered inside `NcAppSettingsDialog`, NOT a routed page (ADR-004)
- [x] SPDX header first line (`src/views/sync/SyncSettings.vue` — the existing manifest `settings`-type page component `SyncSettingsView`, rewritten)
- [x] On `created()`: `GET /api/sync/email/settings` (+ `GET /api/sync/email/status`)
- [x] Fields: mail account selector (`NcSelect` with `inputLabel`), enabled toggle (`NcCheckboxRadioSwitch`), excluded addresses (`NcTextArea`)
- [x] Status display: last run time, links-created count, error indicator (`NcNoteCard`)
- [x] "Sync now" → `POST /api/sync/email/trigger`; Save → `POST /api/sync/email/settings`
- [x] All strings via `t('pipelinq', ...)`; uses `@nextcloud/axios` (no raw `fetch`). NOTE: base `Nc*` components are imported from `@nextcloud/vue` (the app-wide convention — `@conduction/nextcloud-vue` does NOT re-export base Nc components; importing them from there would fail per ADR-016 "only import components that exist").
- [x] Rendered via the manifest `settings`-type page (ADR-004 declarative settings page, NOT a hand-rolled vue-router route)

---

## Section 4: Automation trigger types [V2]

### Task 4.1: Register email.received and calendar.event.start [V2]
- **Spec ref**: Requirement "Automation trigger types"
- **Files**: `lib/Service/AutomationService.php` (or wherever trigger types are enumerated)
- [ ] Add `email.received` and `calendar.event.start` to valid automation trigger values — **DEFERRED**
- [ ] After the matching job links an inbound email, evaluate `email.received` automations for the linked entity — **DEFERRED**
- [ ] Subscribe to the calendar leaf's link/event signal to evaluate `calendar.event.start` automations — **DEFERRED**
- [x] Tag as V2 — do NOT enable until implemented

> **Section 4 DEFERRED (dependency absent).** This section depends on the `automation`
> entity + automation engine from the `crm-workflow-automation` change. That change is
> NOT present in this codebase: there is no `automation` schema in
> `pipelinq_register.json`, no `AutomationService`, and no trigger-type enumeration to
> register `email.received` / `calendar.event.start` against. Registering trigger values
> would have nothing to attach to. This is wired-but-deferred: the matching job already
> emits a clear seam (`runForUser` → links via the leaf) that a future automation engine
> can hook. To be implemented in the same change/PR that lands the automation engine, or
> a follow-up once `crm-workflow-automation` is merged.

---

## Section 5: Unit Tests [V1]

### Task 5.1: Tests for the matcher + job [V1]
- **Spec ref**: Requirement "Unit tests"
- **Files**: `tests/Unit/Service/EmailMatchServiceTest.php`, `tests/Unit/BackgroundJob/EmailMatchJobTest.php`
- [x] Test exact-address match returns the correct entity
- [x] Test unknown address returns empty and creates no link
- [x] Test `isPublicDomain()` true for gmail.com, false for corporate domain
- [x] Test the job calls the email leaf link API on match (mock the leaf adapter)
- [x] Test the job continues when one user's run throws (error isolation + static status)
- [x] Mock OR services — no real DB in unit tests (container-mocked `ObjectService`)
- [x] Additional: dedup-by-entity, excluded-address skip, foreign-account skip, per-user setting isolation, ADR-037 list-union merge

### Task 5.2: Tests for the settings controller [V1]
- **Spec ref**: Requirement "Unit tests"
- **Files**: `tests/Unit/Controller/EmailSyncControllerTest.php`
- [x] 200 success, 401 unauthenticated, 400 invalid input for each endpoint (`EmailSyncControllerTest`, 11 tests)

---

## Section 6: Translations [V1]

### Task 6.1: Add sync-settings UI strings [V1]
- **Spec ref**: Requirement "Translation coverage"
- **Files**: `l10n/en.json`, `l10n/nl.json`
- [x] Add every sync-settings key to `en.json`/`en.js`/`en_US.json` (key == English value) and `nl.json`/`nl.js` (Dutch) — 18 keys added additively
- [x] Verify key parity (zero gaps for the added keys across en/nl)

---

## Section 7: Pre-commit Verification [V1]

### Task 7.1: Run pre-commit checklist [V1]
- **Spec ref**: ADR-015, ADR-022
- [x] SPDX on all new PHP (`@license`/`@copyright` docblock + central `REUSE.toml`) + Vue (SPDX header) → zero missing; phpcs 0 errors
- [x] Register file contains NO `emailLink`/`calendarLink` schema; stale `EmailSyncService`/`CalendarSyncService`/`EmailSyncJob`/`EmailTimeline.vue` anti-pattern files removed (ADR-022 gate)
- [x] Error responses: no `$e->getMessage()` returned to client in `EmailSyncController` (static l10n messages only)
- [x] Auth: `IUserSession::getUser()` in all controller methods (IDOR-safe)
- [x] No raw `fetch(` in the sync UI (uses `@nextcloud/axios`). Base `Nc*` imported from `@nextcloud/vue` per app-wide convention (see Task 3.2 note)

### Task 7.2: Build and smoke test [V1]
- [x] `npm run build` — zero errors (2 pre-existing asset-size warnings only)
- [x] `php -l` on all new PHP files — zero syntax errors; full `composer check:strict` (lint/phpcs/phpmd/psalm/phpstan) green; 470/470 unit tests pass
- [x] `GET /api/sync/email/settings` authenticated → 200; unauthenticated → 401; invalid POST → 400 (covered by `EmailSyncControllerTest`)
- [ ] Open a client detail page — verify the `email` leaf's `CnEmailTab` renders — **DEFERRED (needs running instance + NC Mail app)**
- [ ] Open a lead detail page — verify the `calendar` leaf's `CnCalendarCard` "Add meeting" create flow — **DEFERRED (needs running instance + NC Calendar app)**

---

## Deferred work summary

1. **Section 4 — automation triggers.** The `automation` entity/engine
   (`crm-workflow-automation`) is absent from this codebase; nothing to register the
   trigger values against. Wired-but-deferred (see Section 4 note). To land with the
   automation engine.
2. **Live Mail-message enumeration + link write.** NC Mail exposes no public OCP
   message API (`OCP\Mail\IMailManager` does not exist; only `OCP\Mail\IMailer`), and
   the OR leaf's link service requires a user session + the NC Mail app DB. The full
   match→link pipeline is implemented and unit-tested behind two seams
   (`MailMessageProvider`, `EmailLeafLinkAdapter`); the *live* candidate enumeration
   (`NcMailMessageProvider`) is a safe no-op until run against an instance with a
   configured mail account. End-to-end linking is therefore **deferred (needs a live
   mail account + running NC Mail/Calendar)** — exactly the runtime-dependent slice.
3. **Browser smoke tests** for the leaf tabs/cards on detail pages — needs a running
   instance with the OR email/calendar leaves and NC Mail/Calendar installed.
