# Tasks: pipelinq-time-to-shillinq-wip

## 0. Deduplication Check

- [ ] 0.1 Verify that `time-approval-workflow` is merged and `TimeEntryApprovedEvent` exists. If not, block this change until `time-approval-workflow` is complete.
  - Check: `find lib/ -name "TimeEntryApprovedEvent.php"` or search the `time-approval-workflow` change artifacts
- [ ] 0.2 Verify that `time-entry-core` is merged and the `timeEntry` schema exists in `lib/Settings/pipelinq_register.json`. If not, block this change.
- [ ] 0.3 Search for any existing shillinq WIP integration:
  - `grep -r "shillinq\|wipSync\|wip_sync\|WipSync" lib/ src/`
  - If any WIP-related listener or service already exists, extend it rather than create new files. Document findings below.
- [ ] 0.4 Search for any existing `TimeApprovalListener` or approval event listener in `lib/Listener/`:
  - `ls lib/Listener/ 2>/dev/null`
  - If an existing listener handles time approval, add the WIP dispatch logic there instead of creating a new class.
- [ ] 0.5 Confirm `WebhookService` is available in the installed OpenRegister version. Check `lib/AppInfo/Application.php` or OpenRegister vendor for `WebhookService`.
- [ ] 0.6 Verify that `billable-categories-and-tags` is merged and `billingCategory` field exists on `timeEntry` (the WIP payload includes `billingCategoryId`).

  **Findings:** _(document here after running checks)_

---

## 1. Schema: extend `timeEntry` with WIP sync fields

- [ ] 1.1 Add `wipSyncStatus` and `wipSyncedAt` properties to the `timeEntry` schema in `lib/Settings/pipelinq_register.json`
  - **spec_ref**: `specs/pipelinq-time-to-shillinq-wip/spec.md#REQ-WIP-002`
  - **files**: `lib/Settings/pipelinq_register.json`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - `wipSyncStatus` added as optional string property (enum: `pending`, `synced`, `failed`)
    - `wipSyncedAt` added as optional string property (ISO 8601 timestamp)
    - Existing `timeEntry` seed objects from `time-entry-core` are NOT modified (both fields are optional)
    - Schema version is incremented in the register template
    - Re-importing with `force: false` MUST NOT create a duplicate schema (matched by slug)

- [ ] 1.2 Add 5 WIP-status seed `timeEntry` objects to `lib/Settings/pipelinq_register.json`
  - **spec_ref**: Company ADR-001 (data-layer) — seed data requirement
  - **files**: `lib/Settings/pipelinq_register.json`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Objects present: 2× `wipSyncStatus: synced`, 1× WBSO `synced`, 1× `pending`, 1× `failed`
    - Each uses `@self` envelope with `register: "pipelinq"`, `schema: "timeEntry"`, unique slug (e.g., `time-entry-wip-synced-1`)
    - All objects have `status: "approved"` and realistic Dutch `title` and `description` values
    - Re-importing with `force: false` MUST skip objects matched by slug
    - See `design.md` Seed Data section for exact field values

---

## 2. Backend: event listener

- [ ] 2.1 Create `lib/Listener/TimeApprovalListener.php`
  - **spec_ref**: `specs/pipelinq-time-to-shillinq-wip/spec.md#REQ-WIP-001`
  - **files**: `lib/Listener/TimeApprovalListener.php`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Implements `OCP\EventDispatcher\IEventListener`
    - Constructor receives `ShillinqWipService`, `ObjectService`, and `NotificationService` via DI
    - `handle()` method: calls `ShillinqWipService::shouldDispatch()` first; if false, returns immediately
    - If dispatch enabled: sets `wipSyncStatus = pending` via `ObjectService::saveObject()` before dispatch
    - Calls `ShillinqWipService::dispatchWipEvent()` and handles return value
    - On success: updates `wipSyncStatus = synced`, `wipSyncedAt = now()` via `ObjectService::saveObject()`
    - On failure: sets `wipSyncStatus = failed`; sends admin notification via `NotificationService`
    - MUST skip dispatch if `wipSyncStatus` is already `synced` (idempotency guard)

- [ ] 2.2 Register `TimeApprovalListener` in `lib/AppInfo/Application.php`
  - **spec_ref**: `specs/pipelinq-time-to-shillinq-wip/spec.md#REQ-WIP-001`
  - **files**: `lib/AppInfo/Application.php`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - `$dispatcher->addServiceListener(TimeEntryApprovedEvent::class, TimeApprovalListener::class)` is registered in `register()` or `boot()`
    - Class is autoloaded without additional config (PSR-4 `OCA\Pipelinq\Listener\` namespace)

---

## 3. Backend: WIP dispatch service

- [ ] 3.1 Create `lib/Service/ShillinqWipService.php`
  - **spec_ref**: `specs/pipelinq-time-to-shillinq-wip/spec.md#REQ-WIP-001`, `REQ-WIP-004`
  - **files**: `lib/Service/ShillinqWipService.php`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - `shouldDispatch(): bool` returns `true` only if `IAppConfig::getValueString('pipelinq', 'shillinq_wip_webhook_url', '')` is a non-empty valid URL
    - `dispatchWipEvent(array $timeEntry, string $approvedBy, string $approvedAt): bool` constructs the CloudEvents payload per `design.md` and calls `WebhookService::dispatchEvent($url, $payload)`
    - Payload `data` object contains all required fields: `timeEntryId`, `hours`, `billingCategoryId`, `clientId`, `leadId`, `approvedBy`, `approvedAt`
    - `hours` field MUST be cast to `float` (not string)
    - Returns `true` on successful HTTP delivery; `false` on any exception or non-2xx response

- [ ] 3.2 Create `POST /api/time-entries/{uuid}/wip-retry` controller action
  - **spec_ref**: `specs/pipelinq-time-to-shillinq-wip/spec.md#REQ-WIP-003`
  - **files**: Existing time entries controller (path from `time-entry-core`)
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Action is protected by Nextcloud CSRF (`#[NoAdminRequired]` only if manager role check is implemented; otherwise `#[AuthorizedAdminSetting]`)
    - Returns HTTP 401 for unauthenticated requests
    - Returns HTTP 404 if `timeEntry` UUID does not exist
    - On invocation: sets `wipSyncStatus = pending`, calls `ShillinqWipService::dispatchWipEvent()`
    - Returns JSON `{ "status": "synced" }` or `{ "status": "failed", "message": "..." }` based on outcome

---

## 4. Backend: admin settings

- [ ] 4.1 Add `shillinq_wip_webhook_url` to `lib/Settings/Admin.php`
  - **spec_ref**: `specs/pipelinq-time-to-shillinq-wip/spec.md#REQ-WIP-004`
  - **files**: `lib/Settings/Admin.php`, admin settings template
  - **tier**: P0-must
  - **acceptance_criteria**:
    - GET response includes `shillinq_wip_webhook_url` current value from `IAppConfig`
    - POST/PUT persists value via `IAppConfig::setValueString('pipelinq', 'shillinq_wip_webhook_url', $url)`
    - Empty string is accepted (disables dispatch)
    - Non-URL strings return HTTP 400 with validation error message

---

## 5. Frontend: time entry list view WIP badge

- [ ] 5.1 Add `wipSyncStatus` badge column to the time entry list view (from `time-entry-core`)
  - **spec_ref**: `specs/pipelinq-time-to-shillinq-wip/spec.md#REQ-WIP-005`
  - **files**: Time entry list component (path from `time-entry-core`)
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Column added to `CnDataTable` with header `t('pipelinq', 'WIP sync status')`
    - `synced` → green `<span>` badge with text `t('pipelinq', 'WIP synced')`
    - `pending` → yellow `<span>` badge with text `t('pipelinq', 'WIP pending')`
    - `failed` → red `<span>` badge with text `t('pipelinq', 'WIP sync failed')`
    - null → grey `<span>` with text `–`
    - No hardcoded Dutch or English strings; all badge text via `t()`

- [ ] 5.2 Add `wipSyncStatus` facet to `CnFacetSidebar` in the time entry list view
  - **spec_ref**: `specs/pipelinq-time-to-shillinq-wip/spec.md#REQ-WIP-005`
  - **files**: Time entry list component (path from `time-entry-core`)
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Facet field: `wipSyncStatus`, label: `t('pipelinq', 'WIP sync status')`
    - Options: `synced`, `pending`, `failed`, null (as "Niet gesynchroniseerd")
    - Selected facet persists in URL query params across navigation

---

## 6. Frontend: time entry detail view WIP section

- [ ] 6.1 Add "Shillinq WIP" sidebar section to the time entry detail view (from `time-entry-core`)
  - **spec_ref**: `specs/pipelinq-time-to-shillinq-wip/spec.md#REQ-WIP-003`, `REQ-WIP-005`
  - **files**: Time entry detail component (path from `time-entry-core`)
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Section header: `t('pipelinq', 'Shillinq WIP')`
    - Shows `wipSyncStatus` badge (same color scheme as list)
    - When `wipSyncStatus === 'synced'`: shows `wipSyncedAt` timestamp next to `t('pipelinq', 'WIP synced at')` label
    - When `wipSyncStatus === 'failed'`: shows "Opnieuw synchroniseren" `NcButton` (type: `error`)
    - When null: shows grey "–" placeholder only
    - "Opnieuw synchroniseren" button calls `POST /api/time-entries/{uuid}/wip-retry` and updates the displayed status on success/failure

---

## 7. Frontend: admin settings WIP webhook field

- [ ] 7.1 Add `shillinq_wip_webhook_url` field to the pipelinq admin settings Vue component
  - **spec_ref**: `specs/pipelinq-time-to-shillinq-wip/spec.md#REQ-WIP-004`
  - **files**: Admin settings Vue component (path from existing admin settings implementation)
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Field labeled `t('pipelinq', 'Shillinq WIP webhook URL')` under an "Integraties" section
    - Placeholder: `https://shillinq.example.com/api/wip/events`
    - Client-side URL validation on blur: shows inline error if not empty and not a valid HTTP(S) URL
    - Value is loaded from backend on settings page mount
    - Saved to backend on change/submit; success toast shown on save

---

## 8. i18n

- [ ] 8.1 Add 8 new translation keys to `l10n/en.json`
  - **spec_ref**: `specs/pipelinq-time-to-shillinq-wip/spec.md#REQ-WIP-005`
  - **files**: `l10n/en.json`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - All 8 keys from `design.md` i18n table are present
    - Keys are English sentence case per ADR-007
    - Keys: `WIP sync status`, `WIP synced`, `WIP pending`, `WIP sync failed`, `Retry WIP sync`, `Shillinq WIP webhook URL`, `WIP synced at`, `Not synced`

- [ ] 8.2 Add Dutch translations for all 8 keys to `l10n/nl.json`
  - **spec_ref**: `specs/pipelinq-time-to-shillinq-wip/spec.md#REQ-WIP-005`
  - **files**: `l10n/nl.json`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Dutch values match `design.md` i18n table exactly
    - Both locale files have the same set of 8 new keys (no gaps per ADR-007)

---

## 9. Verification

- [ ] 9.1 Run `npm run build` in the pipelinq app directory — MUST produce zero errors
- [ ] 9.2 Schema check: confirm `wipSyncStatus` and `wipSyncedAt` appear in the `timeEntry` schema in `pipelinq_register.json` with correct types
- [ ] 9.3 Seed data: navigate to the time entry list → confirm the 5 WIP-seed entries appear with correct `wipSyncStatus` badges (2 green, 1 green WBSO, 1 yellow, 1 red)
- [ ] 9.4 Approval trigger (manual test): approve a time entry via the `time-approval-workflow` UI → confirm `wipSyncStatus` transitions from null → `pending` → `synced` (or `failed` if no valid webhook URL is configured)
- [ ] 9.5 No webhook URL: with `shillinq_wip_webhook_url` unconfigured, approve a time entry → confirm `wipSyncStatus` remains null and no notification is sent
- [ ] 9.6 Retry button: find the seed entry with `wipSyncStatus: failed` → open detail view → confirm "Opnieuw synchroniseren" button is visible → click it → confirm status updates
- [ ] 9.7 Admin settings: open `/settings/admin/pipelinq` → confirm "Shillinq WIP-webhook-URL" field is present under "Integraties" → save a valid URL → confirm it persists after page reload
- [ ] 9.8 Facet filter: in the time entry list, select "WIP synchronisatie mislukt" in the facet sidebar → confirm only `wipSyncStatus: failed` entries are shown; confirm URL contains the filter param
- [ ] 9.9 Hardcoded string check: `grep -n "WIP gesynchroniseerd\|WIP mislukt\|WIP in behandeling\|Opnieuw synchroniseren" src/` → all occurrences MUST be inside `t()` calls
- [ ] 9.10 Translation key parity: `grep -c "WIP sync\|wipSync" l10n/en.json l10n/nl.json` → both files MUST have the same count
- [ ] 9.11 Idempotency: call `POST /api/time-entries/{uuid}/wip-retry` twice for the same `synced` entry → confirm the second call returns a non-error response without dispatching a duplicate event
- [ ] 9.12 Unauthenticated retry: call `POST /api/time-entries/{uuid}/wip-retry` without Nextcloud session cookie → confirm HTTP 401 is returned
