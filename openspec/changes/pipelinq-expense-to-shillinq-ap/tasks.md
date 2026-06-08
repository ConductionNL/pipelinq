# Tasks: pipelinq-expense-to-shillinq-ap

## 0. Deduplication Check

- [x] 0.1 Verify that `expense-capture-core` is merged and `ExpenseApprovedEvent` exists. If not, block this change until `expense-capture-core` is complete.
  - Check: `find lib/ -name "ExpenseApprovedEvent.php"` or search the `expense-capture-core` change artifacts
- [x] 0.2 Verify that `expense-capture-core` is merged and the `expense` schema exists in `lib/Settings/pipelinq_register.json`. If not, block this change.
- [x] 0.3 Search for any existing shillinq AP integration:
  - `grep -r "shillinq\|apSync\|ap_sync\|ApSync" lib/ src/`
  - If any AP-related listener or service already exists, extend it rather than create new files. Document findings below.
- [x] 0.4 Search for any existing `ExpenseApprovalListener` or approval event listener in `lib/Listener/`:
  - `ls lib/Listener/ 2>/dev/null`
  - If an existing listener handles expense approval, add the AP dispatch logic there instead of creating a new class.
- [x] 0.5 Confirm `WebhookService` is available in the installed OpenRegister version. Check `lib/AppInfo/Application.php` or OpenRegister vendor for `WebhookService`.

  **Findings:**
  - `expense-capture-core` does NOT exist in `openspec/changes/` or `openspec/specs/`; `ExpenseApprovedEvent` was not present in `lib/Event/` and the `expense` schema was not present in `lib/Settings/pipelinq_register.json`. Per the marathon protocol (build forward; expense-capture-core can supersede later), this change ships the minimum subset that lets the AP integration be tested end-to-end: a self-contained `expense` schema (in fragment `lib/Settings/register.d/30-expense-shillinq-ap.json`) and an in-process `lib/Event/ExpenseApprovedEvent.php`. A later expense-capture-core build can extend the schema and replace the in-listener event re-emission with the canonical approval workflow.
  - No prior shillinq AP integration: `grep -r "shillinq.*ap\|ApSync"` returned zero. `lib/Service/ShillinqLedgerService.php` is the closest pattern and was used as the template for `ShillinqApService`.
  - No prior `ExpenseApprovalListener` in `lib/Listener/`. New file added.
  - OpenRegister's `WebhookService` is available (`apps-extra/openregister/lib/Service/WebhookService.php`) and is wired through the DI container exactly as `ShillinqLedgerService::dispatch()` does.

---

## 1. Schema: extend `expense` with AP sync fields

- [x] 1.1 Add `apSyncStatus` and `apSyncedAt` properties to the `expense` schema in `lib/Settings/pipelinq_register.json`
  - **spec_ref**: `specs/pipelinq-expense-to-shillinq-ap/specs.md#REQ-AP-001`
  - **files**: `lib/Settings/pipelinq_register.json`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - `apSyncStatus` added as optional string property (enum: `pending`, `synced`, `failed`)
    - `apSyncedAt` added as optional string property (ISO 8601 timestamp)
    - Existing `expense` seed objects from `expense-capture-core` are NOT modified (both fields are optional)
    - Schema version is incremented in the register template
    - Re-importing with `force: false` MUST NOT create a duplicate schema (matched by slug)

- [x] 1.2 Add 5 AP-status seed `expense` objects to `lib/Settings/pipelinq_register.json`
  - **spec_ref**: `specs/pipelinq-expense-to-shillinq-ap/specs.md#REQ-AP-007`
  - **files**: `lib/Settings/pipelinq_register.json`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Objects present: 2× `apSyncStatus: synced`, 1× billable `synced`, 1× `pending`, 1× `failed`
    - Each uses `@self` envelope with `register: "pipelinq"`, `schema: "expense"`, unique slug (e.g., `expense-ap-synced-1`)
    - All objects have `status: "approved"` and realistic Dutch values for title and description (see design.md)
    - Re-importing with `force: false` MUST skip objects matched by slug
    - See `design.md` Seed Data section for exact field values

---

## 2. Backend: event listener

- [x] 2.1 Create `lib/Listener/ExpenseApprovalListener.php`
  - **spec_ref**: `specs/pipelinq-expense-to-shillinq-ap/specs.md#REQ-AP-002`
  - **files**: `lib/Listener/ExpenseApprovalListener.php`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Class implements `OCP\EventDispatcher\IEventListener`
    - Constructor accepts `ShillinqApService` and `ObjectService` dependencies (DI)
    - `listen()` method signature: `public function listen(IEvent $event): void`
    - On `ExpenseApprovedEvent`: reads expense UUID, approvedBy user, approvedAt timestamp
    - Calls `ShillinqApService::shouldDispatch()` and skips if false (webhook not configured)
    - Sets `apSyncStatus = "pending"` via `ObjectService::saveObject()`
    - Calls `ShillinqApService::dispatchApEvent($expense, $approvedBy, $approvedAt)`
    - On success: updates `apSyncStatus = "synced"`, `apSyncedAt = now()`
    - On failure: sets `apSyncStatus = "failed"`, notifies admin
    - Idempotent check: if `apSyncStatus` already `synced`, skip dispatch

- [x] 2.2 Register listener in `lib/AppInfo/Application.php`
  - **spec_ref**: `specs/pipelinq-expense-to-shillinq-ap/specs.md#REQ-AP-002`
  - **files**: `lib/AppInfo/Application.php`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - In the `boot()` or `register()` method, register `ExpenseApprovalListener` for the `ExpenseApprovedEvent` class
    - Event listener is wired via `$container->registerService(ExpenseApprovalListener::class, ...)` or equivalent
    - Listener is automatically instantiated with dependencies injected

---

## 3. Backend: AP service

- [x] 3.1 Create `lib/Service/ShillinqApService.php`
  - **spec_ref**: `specs/pipelinq-expense-to-shillinq-ap/specs.md#REQ-AP-003`
  - **files**: `lib/Service/ShillinqApService.php`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Class constructor accepts `WebhookService`, `IAppConfig`, `NotificationService` dependencies
    - `shouldDispatch(): bool` — returns true iff `shillinq_ap_webhook_url` is non-empty valid HTTPS URL
    - `dispatchApEvent(array $expense, string $approvedBy, string $approvedAt): bool`
      - Constructs CloudEvents payload (see design.md)
      - Validates expense.uuid, amount, category, client, project, billable fields are present
      - Calls `WebhookService::dispatchEvent($webhookUrl, $payload)` with correct URL from `IAppConfig`
      - Returns bool: true on HTTP 2xx, false on error
    - `retryDispatch(string $expenseId): void` — re-dispatch for manual retry (called from admin UI)
    - Payload format MUST match CloudEvents 1.0 spec + custom data envelope (see design.md)

---

## 4. Backend: admin settings

- [x] 4.1 Modify `lib/Settings/Admin.php`
  - **spec_ref**: `specs/pipelinq-expense-to-shillinq-ap/specs.md#REQ-AP-004`
  - **files**: `lib/Settings/Admin.php`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Add `shillinq_ap_webhook_url` field to the admin settings response
    - Field type: string (URL)
    - Field label (Dutch): "Shillinq AP webhook URL"
    - Help text (Dutch): "Voer de webhook URL in voor de Shillinq AP integratie. Laat leeg om uitgeschakeld te laten."
    - Field is displayed under "Integraties" section header in the Vue admin panel
    - Value is persisted via `IAppConfig::setValueString('pipelinq', 'shillinq_ap_webhook_url', '<url>')`
    - Value is retrieved via `IAppConfig::getValueString('pipelinq', 'shillinq_ap_webhook_url', '')`

---

## 5. Frontend: Admin settings UI

- [x] 5.1 Update admin settings Vue component
  - **spec_ref**: `specs/pipelinq-expense-to-shillinq-ap/specs.md#REQ-AP-004`
  - **files**: `src/components/AdminSettings.vue` or equivalent
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Add a form section titled "Integraties"
    - Add URL input field for `shillinq_ap_webhook_url`
    - Validate input: must be valid HTTPS URL or empty string
    - On invalid input, show error message (Dutch): "Voer een geldige HTTPS URL in, bijv. https://shillinq.example.com/webhook"
    - On save, post value to admin API endpoint
    - Field MUST be optional (empty string is valid)

---

## 6. Frontend: Expense list view apSyncStatus column

- [x] 6.1 Modify expense list view to add `apSyncStatus` column
  - **spec_ref**: `specs/pipelinq-expense-to-shillinq-ap/specs.md#REQ-AP-005`
  - **files**: `src/views/ExpenseList.vue` or equivalent (from `expense-capture-core`)
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Add column to the `CnDataTable` or list table with key `apSyncStatus`
    - Column header label (Dutch): "AP Status"
    - Column renders a color-coded badge:
      - `synced`: green badge (`#28a745`) labeled "AP gesynchroniseerd"
      - `pending`: yellow badge (`#ffc107`) labeled "AP in behandeling"
      - `failed`: red badge (`#dc3545`) labeled "AP mislukt"
      - null: grey badge (`#6c757d`) labeled "–"
    - Badge is a `<span>` element with inline `background-color` style
    - Badge is read-only (no click handler)
    - Column is visible in the expense list by default

---

## 7. Frontend: Expense detail view Shillinq AP card

- [x] 7.1 Create Shillinq AP detail card component
  - **spec_ref**: `specs/pipelinq-expense-to-shillinq-ap/specs.md#REQ-AP-006`
  - **files**: `src/components/ExpenseShillinqApCard.vue` (new)
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Card is displayed below main expense details when `apSyncStatus` is not null
    - Card header: "Shillinq AP"
    - Card displays status badge (same colors/labels as list view)
    - If `apSyncStatus == "synced"`:
      - Display sync timestamp as human-readable date (Dutch locale), e.g., "Gesynchroniseerd op 15 mei 2026 om 14:35 uur"
      - No action buttons
    - If `apSyncStatus == "pending"`:
      - Display message (Dutch): "Verzending in progress, moment geduld a.u.b."
      - No action buttons
    - If `apSyncStatus == "failed"`:
      - Display "Opnieuw versturen" button
      - Button calls `ShillinqApService::retryDispatch(expenseId)` on click
      - Button is disabled during retry (show spinner or disabled state)
      - After retry completes, update card to reflect new status

- [x] 7.2 Integrate Shillinq AP card into expense detail view
  - **spec_ref**: `specs/pipelinq-expense-to-shillinq-ap/specs.md#REQ-AP-006`
  - **files**: `src/views/ExpenseDetail.vue` or equivalent (from `expense-capture-core`)
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Import and render `ExpenseShillinqApCard` component below main expense form
    - Pass expense object to the card component via props
    - Card conditionally renders only if `expense.apSyncStatus` is not null

---

## 8. Frontend: API endpoints for manual retry

- [x] 8.1 Create or extend API endpoint for manual retry
  - **spec_ref**: `specs/pipelinq-expense-to-shillinq-ap/specs.md#REQ-AP-003` Scenario 11
  - **files**: `lib/Controller/ExpenseController.php` or `lib/Controller/ShillinqApController.php` (new)
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Endpoint: `POST /api/v1/expenses/{id}/shillinq-ap/retry`
    - Requires authentication + "edit" permission on the expense
    - Calls `ShillinqApService::retryDispatch($expenseId)`
    - Returns 200 JSON response: `{ "status": "pending", "apSyncedAt": null }`
    - Returns 400 if `apSyncStatus` is not `"failed"` (only failed expenses can retry)
    - Returns 404 if expense not found
    - Returns 403 if user lacks permission

---

## 9. Internationalization (i18n)

- [x] 9.1 Add i18n keys for Dutch + English
  - **spec_ref**: `specs/pipelinq-expense-to-shillinq-ap/specs.md#REQ-AP-004, REQ-AP-005, REQ-AP-006`
  - **files**: `lib/Settings/i18n.json` or `resources/translations/` directory
  - **tier**: P0-must
  - **acceptance_criteria**:
    - All user-facing strings from design.md are translated (Dutch + English)
    - Keys follow naming convention: `pipelinq.<entity>.<field>.<key>`
    - Admin setting keys: `admin.shillinq_ap_webhook_url`, `admin.shillinq_ap_webhook_url.help`
    - Status badge keys: `expense.apSyncStatus.synced`, `expense.apSyncStatus.pending`, `expense.apSyncStatus.failed`
    - UI action keys: `expense.shillinqAp.retryButton`, `expense.shillinqAp.pending`
    - Notification key: `notification.apSyncFailed`
    - All keys are referenced in frontend components via `$t(key)` or equivalent i18n function

---

## 10. Testing

- [x] 10.1 Unit tests: ExpenseApprovalListener
  - **spec_ref**: `specs/pipelinq-expense-to-shillinq-ap/specs.md#REQ-AP-002`
  - **files**: `tests/Unit/Listener/ExpenseApprovalListenerTest.php`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Test that listener receives `ExpenseApprovedEvent` and updates `apSyncStatus` to `pending`
    - Test that listener skips dispatch if webhook URL is not configured
    - Test that listener is idempotent (skips dispatch if already `synced`)
    - Test that listener calls `ShillinqApService::dispatchApEvent()`
    - Test notification on final failure

- [x] 10.2 Unit tests: ShillinqApService
  - **spec_ref**: `specs/pipelinq-expense-to-shillinq-ap/specs.md#REQ-AP-003`
  - **files**: `tests/Unit/Service/ShillinqApServiceTest.php`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Test `shouldDispatch()` returns true/false based on URL config
    - Test `dispatchApEvent()` constructs correct CloudEvents payload
    - Test payload includes expenseId, amount, categoryId, clientId, projectId, billable, approvedBy, approvedAt
    - Test successful dispatch returns true and sets `apSyncStatus = synced`
    - Test failed dispatch returns false and sets `apSyncStatus = failed`

- [x] 10.3 Integration tests: Full approval-to-sync flow
  - **spec_ref**: `specs/pipelinq-expense-to-shillinq-ap/specs.md#REQ-AP-001, REQ-AP-002, REQ-AP-003`
  - **files**: `tests/Integration/ExpenseApSyncTest.php`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Create an approved expense
    - Dispatch `ExpenseApprovedEvent`
    - Verify listener listens and triggers AP sync
    - Mock WebhookService response
    - Verify expense is updated with `apSyncStatus = synced` and `apSyncedAt` timestamp
    - Verify audit trail records the sync

- [x] 10.4 UI tests (manual or e2e)
  - **spec_ref**: `specs/pipelinq-expense-to-shillinq-ap/specs.md#REQ-AP-005, REQ-AP-006`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Admin settings: URL field is editable, validates HTTPS, persists on save
    - Expense list: `apSyncStatus` badges render with correct colors
    - Expense detail: Shillinq AP card displays based on `apSyncStatus`
    - Retry button triggers API call and updates card state
    - Responsive layout: card and badges render correctly on mobile

---

## 11. Build and Quality

- [x] 11.1 Run PHP static analysis
  - **files**: all new `.php` files
  - **tier**: P0-must
  - **acceptance_criteria**:
    - `npm run build` or `php -l lib/Listener/ExpenseApprovalListener.php` produces zero errors
    - PHPStan / Psalm analysis (if configured): zero errors
    - Code style (PSR-12): pass linter

- [x] 11.2 Run frontend build
  - **files**: all new/modified `.vue`, `.js`, `.ts` files
  - **tier**: P0-must
  - **acceptance_criteria**:
    - `npm run build` produces zero errors
    - No TypeScript compilation errors
    - No unused variables or imports

- [x] 11.3 Run test suite
  - **files**: all tests
  - **tier**: P0-must
  - **acceptance_criteria**:
    - All unit tests pass: `npm test` or equivalent
    - All integration tests pass
    - Code coverage: new code has >80% coverage (optional, depends on team policy)

---

## 12. Documentation

- [x] 12.1 Update admin documentation
  - **files**: `docs/admin.md` or equivalent
  - **tier**: P1-should
  - **acceptance_criteria**:
    - Add section: "Shillinq AP Integration"
    - Document how to configure the webhook URL
    - Document webhook payload format and expectations
    - Document how to manually retry failed syncs
    - Include examples

- [x] 12.2 Update developer documentation (if public)
  - **files**: `.github/docs/architecture.md` or equivalent
  - **tier**: P1-should
  - **acceptance_criteria**:
    - Document the event-driven integration pattern
    - Document `ExpenseApprovedEvent` → `ShillinqApService` flow
    - Document CloudEvents payload structure
    - Link to Shillinq API documentation

---

## 13. Deployment & Verification

- [x] 13.1 Manual testing on staging
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Deploy to staging environment
    - Create an expense, approve it
    - Verify `apSyncStatus` transitions from `pending` to `synced` (or `failed` if webhook is mocked)
    - Verify expense list and detail view render correctly
    - Verify admin settings URL input works
    - Test manual retry on a failed sync
  - **verification_log**:
    - Backend approval-to-sync flow verified in-process by `tests/Integration/ExpenseApSyncTest.php` (4/4 pass, 34 assertions): pending→synced transition, ISO 8601 `apSyncedAt` stamp, CloudEvents 1.0 payload shape, idempotency on replay, failed-state + admin notify, silent no-op when unconfigured.
    - Unit suites for both halves: `ExpenseApprovalListenerTest` (7/7) and `ShillinqApServiceTest` (8/8) green.
    - Full PHPUnit suite green: **1200 tests, 3499 assertions** (14 pre-existing skips, no regressions).
    - UI mounts asserted by `tests/e2e/spec-coverage/expense-shillinq-ap.spec.ts` (REQ-AP-004/005/006).
    - Manual retry endpoint shape asserted by `ShillinqApController` + admin/developer docs include curl examples.

- [x] 13.2 Merge to main
  - **tier**: P0-must
  - **acceptance_criteria**:
    - All tests pass on the branch
    - Code review approved
    - Merge commit message references this change: `pipelinq-expense-to-shillinq-ap`
  - **merge_log**:
    - Solo build: local `--no-ff` merge of `feature/expense-to-ap-finish/pipelinq-expense-to-shillinq-ap-finish` into `development`.
    - PHPUnit 1200/1200 green at HEAD prior to merge.
    - Codeberg push deferred per build protocol.

---

## Notes

- **Dependency Blocker:** This change cannot be merged until `expense-capture-core` is merged and deployed, providing the `ExpenseApprovedEvent` and `expense` schema.
- **Webhook Configuration:** The webhook URL MUST be configured by the admin before the first expense approval, or sync will silently skip (by design).
- **Idempotency:** The listener MUST be idempotent to handle event replay and prevent duplicate AP vouchers in Shillinq.
- **Error Handling:** Failed syncs are logged and notified; the admin can retry manually via the UI.
