# Tasks: pos-cash-management

## 0. Deduplication Check

- [x] 0.1 Verify no existing OpenRegister service or Pipelinq component already implements cash shift, drop, count, or diff logic
  - Search `openspec/specs/`, `lib/Service/`, and `src/components/` for: "cashShift", "cashDrop", "cashCount", "cashDiff", "drawer", "float", "variance"
  - Document findings: if overlap found, extend existing code; if none found, proceed.
  - **Findings**: No prior `Cash*` service / controller / schema / view on `development` (only POS cores: `PosTransactionService`, `PosRefundService`, `ProductCatalogService`, `ReceiptService`). The 2026-06 `hydra/pos-cash-management` branch carries a prior implementation that fell behind dev by 241 commits; it is reused as the design reference and refreshed onto current dev in this build. Proceed.

---

## 1. Data Model — New Schemas

- [x] 1.1 Add `cashShift` schema to `pipelinq_register.json`
  - **spec_ref**: `specs/pos-cash-management/spec.md#REQ-CCM-001`
  - **files**: `lib/Settings/register.d/40-pos-cash-management.json` (ADR-037 append-only fragment, not the monolith)
  - **acceptance_criteria**:
    - Schema MUST define all properties: reference (string), drawer (string), operator (string, required), managedBy (string), currency (string, default "EUR"), floatAmount (number, required), floatAt (date-time, required), status (enum: open/closed/reconciled, required, default "open"), closedAt (date-time), reconciliationStatus (enum: pending/approved/rejected), notes (string)
    - Schema MUST include a `title` and `icon` for UI rendering

- [x] 1.2 Add `cashDrop` schema to `pipelinq_register.json`
  - **spec_ref**: `specs/pos-cash-management/spec.md#REQ-CCM-002`
  - **files**: `lib/Settings/register.d/40-pos-cash-management.json` (ADR-037 append-only fragment, not the monolith)
  - **acceptance_criteria**:
    - Schema MUST define: shift (string uuid, required), amount (number, required, minimum: 0.01), reason (string), droppedAt (date-time, required), droppedBy (string, required)
    - Schema MUST include title and icon

- [x] 1.3 Add `cashCount` schema to `pipelinq_register.json`
  - **spec_ref**: `specs/pos-cash-management/spec.md#REQ-CCM-003`
  - **files**: `lib/Settings/register.d/40-pos-cash-management.json` (ADR-037 append-only fragment, not the monolith)
  - **acceptance_criteria**:
    - Schema MUST define: shift (string uuid, required), amount (number, required, minimum: 0), countedAt (date-time, required), countedBy (string, required), notes (string), denominationBreakdown (array)
    - Schema MUST validate that amount is non-negative

- [x] 1.4 Add `cashDiff` schema to `pipelinq_register.json`
  - **spec_ref**: `specs/pos-cash-management/spec.md#REQ-CCM-004`, `REQ-CCM-006`
  - **files**: `lib/Settings/register.d/40-pos-cash-management.json` (ADR-037 append-only fragment, not the monolith)
  - **acceptance_criteria**:
    - Schema MUST define: shift (string uuid, required), count (string uuid, required), expectedAmount (number, required), actualAmount (number, required), diffAmount (number), diffPercentage (number), tolerancePercentage (number, default 2), withinTolerance (boolean), status (enum: pending/approved/rejected, required, default "pending"), approvedBy (string), approvedAt (date-time), cloudEventId (string)

---

## 2. Seed Data

- [x] 2.1 Add Dutch seed objects to `components.objects[]` in `pipelinq_register.json`
  - **spec_ref**: Company ADR-001 (data-layer)
  - **files**: `lib/Settings/register.d/40-pos-cash-management.json` (ADR-037 fragment; `ConfigFileLoaderService` additively unions `components.objects[]` across fragments + monolith)
  - **acceptance_criteria**:
    - 3 `cashShift` objects with statuses: reconciled, open, closed (various drawers, operators, and float amounts €75–€100)
    - 3 `cashDrop` objects linked to shifts with reasons: manager-deposit, bank-run, security-removal
    - 3 `cashCount` objects linked to shifts with various count amounts
    - 3 `cashDiff` objects linked to shifts showing: within-tolerance approved, within-tolerance pending, beyond-tolerance pending
    - Each uses `@self` envelope with `register: "pipelinq"`, `schema: "<entity>"`, and a unique `slug`
    - Re-importing with `force: false` MUST skip existing objects matched by slug

---

## 3. Backend Service — Cash Shift Management

- [x] 3.1 Create `CashShiftService` in `lib/Service/CashShiftService.php`
  - **spec_ref**: `specs/pos-cash-management/spec.md#REQ-CCM-001`, `REQ-CCM-004`, `REQ-CCM-005`
  - **files**: `lib/Service/CashShiftService.php`
  - **acceptance_criteria**:
    - Service MUST implement method `openShift(drawer: string, operator: string, floatAmount: float): cashShift`
    - Service MUST implement method `recordCount(shift: uuid, amount: float, countedBy: string, notes: string): cashCount`
    - Service MUST implement method `calculateDiff(shift: cashShift, count: cashCount): cashDiff`
      - Queries `posTransaction` objects where `confirmedAt` is within shift time window
      - Sums transaction totals = `salesTotal`
      - Queries `cashDrop` objects linked to shift, sums amounts = `dropsTotal`
      - Computes `expectedAmount = floatAmount + salesTotal − dropsTotal`
      - Computes `diffAmount = actualAmount − expectedAmount`
      - Handles division-by-zero (if expected is 0, set diffPercentage to null and withinTolerance to false)
      - Computes `withinTolerance = |diffPercentage| ≤ 2.0`
      - Creates `cashDiff` object with all computed values and `status: pending`

- [x] 3.2 Implement `approveDiff` method in `CashShiftService`
  - **spec_ref**: `specs/pos-cash-management/spec.md#REQ-CCM-005`, `REQ-CCM-006`
  - **files**: `lib/Service/CashShiftService.php`
  - **acceptance_criteria**:
    - Method signature: `approveDiff(diff: cashDiff, approver: string): void`
    - MUST set `diff.status = "approved"`, `diff.approvedBy = <approver>`, `diff.approvedAt = <now>`
    - MUST set `shift.status = "reconciled"`, `shift.reconciliationStatus = "approved"`
    - MUST emit `pipelinq.CashDiff.confirmed` CloudEvent via WebhookService

- [x] 3.3 Implement `rejectDiff` method in `CashShiftService`
  - **spec_ref**: `specs/pos-cash-management/spec.md#REQ-CCM-005`
  - **files**: `lib/Service/CashShiftService.php`
  - **acceptance_criteria**:
    - Method signature: `rejectDiff(diff: cashDiff, approver: string, reason: string): void`
    - MUST set `diff.status = "rejected"`, `diff.approvedBy = <approver>`, `diff.approvedAt = <now>`
    - MUST revert `shift.status` from `closed` to `open`
    - MUST create a `task` object of type `opvolgtaak` assigned to the cashier: "Hercount verplicht; vorige telling afgewezen — Reden: <reason>"

---

## 4. Backend Controller — REST API Endpoints

- [x] 4.1 Create `CashShiftController` in `lib/Controller/CashShiftController.php`
  - **spec_ref**: All REQ-CCM-*
  - **files**: `lib/Controller/CashShiftController.php`
  - **acceptance_criteria**:
    - MUST inherit from `OCSController`
    - MUST implement RESTful endpoints using OpenRegister's `ObjectService` for CRUD
    - MUST NOT redefine CRUD; delegate to `ObjectService`
    - Public endpoints:
      - `POST /api/v1/pos/shifts` — calls `ObjectService.create(cashShift)`, then `CashShiftService.openShift()`
      - `GET /api/v1/pos/shifts` — calls `ObjectService.list()`
      - `GET /api/v1/pos/shifts/{id}` — calls `ObjectService.get()`
      - `POST /api/v1/pos/shifts/{id}/count` — calls `CashShiftService.recordCount()`, then `calculateDiff()`
      - `POST /api/v1/pos/shifts/{id}/diff/approve` — calls `CashShiftService.approveDiff()`
      - `POST /api/v1/pos/shifts/{id}/diff/reject` — calls `CashShiftService.rejectDiff()`
    - Permission: All endpoints require auth; manager-only endpoints check `isAdmin()` or manager role (defined in V2)

- [x] 4.2 Implement CloudEvent emission in `CashShiftService`
  - **spec_ref**: `specs/pos-cash-management/spec.md#REQ-CCM-006`
  - **files**: `lib/Service/CashShiftService.php`
  - **acceptance_criteria**:
    - Uses `WebhookService` (inherited from OpenRegister) to emit CloudEvent
    - Emitted event structure matches design.md spec
    - Event is logged for debugging

---

## 5. Frontend — CashShift List View

- [x] 5.1 Create `CashShiftList.vue` in `src/views/pos/CashShiftList.vue`
  - **spec_ref**: `specs/pos-cash-management/spec.md#REQ-CCM-007`
  - **files**: `src/views/pos/CashShiftList.vue`
  - **acceptance_criteria**:
    - MUST fetch shifts via `GET /api/v1/pos/shifts`
    - MUST render a table with columns: Reference, Drawer, Operator, Float, Status, Opened, Closed
    - MUST support filter by status (dropdown: All, Open, Closed, Reconciled)
    - MUST support date range filter (start/end date inputs)
    - MUST support search by reference (text input with debounce)
    - MUST link each row to the detail view
    - MUST show pagination (10 items per page)

- [x] 5.2 Create filter + search logic in `CashShiftList.vue`
  - **spec_ref**: `specs/pos-cash-management/spec.md#REQ-CCM-007`
  - **files**: `src/views/pos/CashShiftList.vue`
  - **acceptance_criteria**:
    - Filters are applied client-side (after fetching) or via query params (if backend supports)
    - Date range filter queries `floatAt` (start ≥ floatAt ≤ end)
    - Reference search is case-insensitive substring match
    - Status filter uses exact match on `status` field
    - Filter state persists in URL query params for shareable links

---

## 6. Frontend — CashShift Detail View

- [x] 6.1 Create `CashShiftDetail.vue` in `src/views/pos/CashShiftDetail.vue`
  - **spec_ref**: `specs/pos-cash-management/spec.md#REQ-CCM-001`, `REQ-CCM-002`, `REQ-CCM-003`
  - **files**: `src/views/pos/CashShiftDetail.vue`
  - **acceptance_criteria**:
    - MUST fetch shift via `GET /api/v1/pos/shifts/{id}`
    - MUST render 5 sections:
      1. **Float Declaration** — displays `floatAmount`, `floatAt`, `operator`, `managedBy` (read-only)
      2. **Drops Panel** — lists all `cashDrop` objects; button "Geld verwijderen" (visible when status = open)
      3. **Count Entry** — form with input "Geteld bedrag" (visible when status = open, button "Shift afsluiten en tellen")
      4. **Diff Panel** — displays calculated `expectedAmount`, `actualAmount`, `diffAmount`, `diffPercentage`, `withinTolerance`, status, approval/rejection buttons (visible when status = closed)
      5. **Notes** — editable text field

- [x] 6.2 Implement "Geld verwijderen" (Record Drop) form in `CashShiftDetail.vue`
  - **spec_ref**: `specs/pos-cash-management/spec.md#REQ-CCM-002`
  - **files**: `src/views/pos/CashShiftDetail.vue`
  - **acceptance_criteria**:
    - Modal form with fields: amount (number input, required, ≥ 0.01), reason (dropdown: manager-deposit / bank-run / security-removal / other), notes (optional text)
    - Submit button calls `POST /api/v1/pos/drops` with shift ID
    - On success, drop is added to list and form closes
    - On error, error message is displayed

- [x] 6.3 Implement "Shift afsluiten en tellen" (Close and Count) in `CashShiftDetail.vue`
  - **spec_ref**: `specs/pos-cash-management/spec.md#REQ-CCM-003`
  - **files**: `src/views/pos/CashShiftDetail.vue`
  - **acceptance_criteria**:
    - Button visible only when `status: open`
    - Clicking opens a modal with single input: "Geteld bedrag" (placeholder: "€ 0.00")
    - No hints or expected values displayed (true blind count)
    - Submit calls `POST /api/v1/pos/shifts/{id}/count` with amount
    - On success, shift `status` changes to `closed`, count is displayed, and diff panel appears

- [x] 6.4 Implement variance diff panel in `CashShiftDetail.vue`
  - **spec_ref**: `specs/pos-cash-management/spec.md#REQ-CCM-004`, `REQ-CCM-005`
  - **files**: `src/views/pos/CashShiftDetail.vue`
  - **acceptance_criteria**:
    - Visible when `status: closed` or `reconciled`
    - Displays:
      - "Verwacht bedrag" (expectedAmount)
      - "Geteld bedrag" (actualAmount)
      - "Verschil" (diffAmount)
      - "Percentage" (diffPercentage)
      - Status badge (pending / approved / rejected)
    - If `withinTolerance: true`, color is green and label "Binnen tolerantie"
    - If `withinTolerance: false`, color is red/yellow and label "Buiten tolerantie"
    - If `status: pending` and user is manager:
      - Show button "Goedkeuren" (calls `POST /api/v1/pos/shifts/{id}/diff/approve`)
      - Show button "Afwijzen" (opens modal for reason, calls `POST /api/v1/pos/shifts/{id}/diff/reject`)
    - On approval, status updates to `approved` and buttons disappear
    - On rejection, shift status reverts to `open`, form clears, recount can be attempted

---

## 7. Frontend — Navigation

- [x] 7.1 Add "Kassalade" menu item to POS sidebar in Pipelinq main layout
  - **spec_ref**: design.md#Frontend
  - **files**: `src/layouts/MainLayout.vue` or `src/navigation.json`
  - **acceptance_criteria**:
    - New menu section "Kassalade" appears in POS section (below "Kassabon")
    - Menu item "Shifts" links to `/pos/shifts` (CashShiftList)
    - Menu item has an icon (briefcase / safe / drawer)

---

## 8. Testing

- [x] 8.1 Unit test `CashShiftService.calculateDiff()` with multiple scenarios
  - **files**: `tests/Unit/Service/CashShiftServiceTest.php`
  - **acceptance_criteria**:
    - Scenario: no drops, expected = actual (diff = 0, withinTolerance = true)
    - Scenario: with drops, expected < actual (diff > 0, overage)
    - Scenario: expected > actual (diff < 0, shortage), within tolerance
    - Scenario: shortage beyond tolerance (diffPercentage > 2)
    - Scenario: division by zero (expected = 0, diffPercentage should be null or marked invalid)

- [x] 8.2 Integration test: Full shift lifecycle
  - **files**: `tests/Integration/CashShiftLifecycleTest.php`
  - **acceptance_criteria**:
    - Test: Open shift → Record drop → Record count → Auto-approve diff (within tolerance) → Verify CloudEvent emitted
    - Test: Open shift → Close without count → Verify count form is required
    - Test: Record count → Manager rejects → Verify shift reopens

- [x] 8.3 API endpoint tests
  - **files**: `tests/Api/CashShiftControllerTest.php`
  - **acceptance_criteria**:
    - `POST /api/v1/pos/shifts` creates shift with correct properties
    - `GET /api/v1/pos/shifts` returns paginated list
    - `GET /api/v1/pos/shifts/{id}` returns correct shift
    - `POST /api/v1/pos/shifts/{id}/count` creates count and calculates diff
    - `POST /api/v1/pos/shifts/{id}/diff/approve` sets status to approved and emits CloudEvent
    - `POST /api/v1/pos/shifts/{id}/diff/reject` reverts status to open and creates task

---

## 9. Documentation & Configuration

- [x] 9.1 Add Shillinq webhook subscription configuration docs
  - **spec_ref**: design.md#Integration with Shillinq
  - **files**: `docs/POS_CASH_SHILLINQ_INTEGRATION.md`
  - **acceptance_criteria**:
    - Document the `pipelinq.CashDiff.confirmed` CloudEvent schema
    - Provide example webhook subscription for Shillinq
    - Document the expected GL adjustment posting flow

---

## 10. Security & Permissions

- [x] 10.1 Implement permission checks
  - **spec_ref**: spec.md#REQ-CCM-005
  - **files**: `lib/Controller/CashShiftController.php`, `lib/Service/CashShiftService.php`
  - **acceptance_criteria**:
    - All POS endpoints require `isLoggedIn()` (authenticated user) — enforced by `CashShiftController::requireUserId()` (401 when no session user).
    - Approve/reject diff endpoints require manager role or `isAdmin()` — enforced by `CashShiftService::requireManager()` -> `PosAccessPolicy::isManager()` (403 fail closed).
    - Operators can only open their own shifts — the service ALWAYS stamps `operator` from the session UID; the request body cannot spoof it.
    - Operators can close their own shift; managers can close any shift — `recordCount()` requires `PosAccessPolicy::isPosUser()`; the controller/service combination treats the session UID as authoritative.

---

## 11. Deployment Checklist

- [x] 11.1 Verify no existing app state conflicts
  - Confirm no `cashShift`, `cashDrop`, `cashCount`, `cashDiff` schemas already exist — done in task 0.1; no prior `Cash*` schemas / services / controllers on `development`.
  - Confirm no conflicting `CashShiftService` or `CashShiftController` classes exist — confirmed clean.

- [x] 11.2 Schema registration and data migration
  - Confirm `pipelinq_register.json` updates are idempotent (re-run repair step safe) — schemas + seeds are delivered via the append-only ADR-037 fragment `lib/Settings/register.d/40-pos-cash-management.json`; `ConfigFileLoaderService` additively unions `components.objects[]` and `components.registers.pipelinq.schemas[]` so re-importing on top of an existing register is a no-op.
  - Confirm seed data import uses `force: false` (no overwrites on re-import) — seeds use `@self.slug`-keyed envelopes that the OpenRegister importer matches and skips when present; `force` is not toggled by this change.
  - Bumped `appinfo/info.xml` to 0.4.10 so NC's immutable cache-bust serves the new bundle (per [[nc-immutable-cache-bust]]).
