# Tasks: contract-renewal-tracking

## 1. Data Layer

- [ ] 1.1 Register `contract` schema in OpenRegister
  - **spec_ref**: `specs/contract-renewal-tracking/spec.md#requirement-contract-schema-registration`
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - Schema defines: contractNumber, clientRef, title, lineItems[], billingInterval, valuePerInterval, currency, startDate, endDate, autoRenew, noticePeriodDays, status, ownerId, renewalLeadRef, predecessorContractRef, notes
    - Status enum: draft, active, expiring, renewed, churned, cancelled; billingInterval enum: monthly, quarterly, annual, one-off
    - Field names contractNumber/startDate/endDate/value/status compatible with customer-portal's PortalContractService reader
    - Schema added to register array; SchemaMapService mapping; SettingsService config key

- [ ] 1.2 Declare x-openregister-notifications rules for renewal events (ADR-031)
  - **spec_ref**: `specs/contract-renewal-tracking/spec.md#requirement-renewal-reminders-and-notifications`
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - Schema rule notifying ownerId on transition to `expiring`, canonical dialect only (no legacy dialect, no imperative dispatch anywhere in lib/)

- [ ] 1.3 Seed example contracts
  - **spec_ref**: `specs/contract-renewal-tracking/spec.md#requirement-contract-schema-registration`
  - **files**: `lib/Settings/pipelinq_register.json` (seed)
  - **acceptance_criteria**:
    - One €750 monthly autoRenew contract; one annual contract with 60-day notice inside its renewal window (exercises the engine on first cron)

## 2. Lifecycle & Services

- [ ] 2.1 ContractService: transition guards + numbering + successor drafting
  - **spec_ref**: `specs/contract-renewal-tracking/spec.md#requirement-contract-lifecycle-management`
  - **files**: `lib/Service/ContractService.php`, `lib/Controller/ContractController.php`, `appinfo/routes.php`
  - **acceptance_criteria**:
    - Guards: renewed requires won renewal lead; expiring only via engine; cancelled requires reason; terminal states immutable
    - contractNumber auto-generated `C-{year}-{seq}`, unique
    - Only guarded transitions/engine actions exposed as app endpoints with auth attributes + per-object authorization (no IDOR); plain CRUD reads via OR `useObjectStore` per ADR-022 — no pass-through wrappers
    - Unit tests for every guard

- [ ] 2.2 RenewalEngineService + nightly RenewalWindowJob
  - **spec_ref**: `specs/contract-renewal-tracking/spec.md#requirement-renewal-window-detection`, `#requirement-renewal-lead-automation`
  - **files**: `lib/Service/RenewalEngineService.php`, `lib/Cron/RenewalWindowJob.php`, `lib/AppInfo/Application.php`
  - **acceptance_criteria**:
    - Job registered via the valid bootstrap pattern (NOT IRegistrationContext::registerJob — 2026-06 jobs sweep)
    - Window = endDate − max(noticePeriodDays, configured default, 60); active→expiring transition; idempotent re-runs
    - Renewal lead created once via lead-management (title, annualized value, client, owner, `renewal` tag); bidirectional link
    - Reconciliation: lead won → renewed + successor draft (start = end + 1 day, predecessorContractRef); lead lost or endDate passed → churned
    - Notice-deadline My Work entry, autoRenew-aware copy
    - Unit tests: window math, idempotency, won/lost/silent-expiry reconciliation, missing-lead recreation

- [ ] 2.3 RecurringRevenueService
  - **spec_ref**: `specs/contract-renewal-tracking/spec.md#requirement-recurring-revenue-roll-up`
  - **files**: `lib/Service/RecurringRevenueService.php`
  - **acceptance_criteria**:
    - MRR normalization (monthly/3-quarterly/12-annual; one-off excluded); active + expiring counted
    - ARR, per-client recurring value, per-period renewal rate and churned MRR
    - Unit tests for normalization and period math

## 3. Frontend

- [ ] 3.1 Contracts list + detail views, navigation entry
  - **spec_ref**: `specs/contract-renewal-tracking/spec.md#requirement-contract-lifecycle-management`
  - **files**: `src/views/contracts/ContractsList.vue`, `src/views/contracts/ContractDetail.vue`, router, navigation
  - **acceptance_criteria**:
    - List: status/owner/client filters, renewal-window highlight; detail: lifecycle, line items, renewal chain (predecessor/renewal lead links), linked client
    - Reads via useObjectStore against OR; English i18n source keys + nl catalog

- [ ] 3.2 Create/edit contract modal
  - **spec_ref**: `specs/contract-renewal-tracking/spec.md#requirement-contract-lifecycle-management`
  - **files**: `src/modals/ContractFormModal.vue`
  - **acceptance_criteria**:
    - Own file under `src/modals/` (modal-isolation gate); NcSelect with `inputLabel`; line items pick products from the existing catalog; date pickers for start/end

- [ ] 3.3 Client view Contracts tab
  - **spec_ref**: `specs/contract-renewal-tracking/spec.md#requirement-recurring-revenue-roll-up`
  - **files**: client/klantbeeld detail view components
  - **acceptance_criteria**:
    - Per-client contract list + recurring value summary (MRR); create-contract entry point pre-filled with the client

- [ ] 3.4 Dashboard MRR KPI card + Renewals due widget
  - **spec_ref**: `specs/dashboard/spec.md#requirement-mrr-kpi-card`, `specs/dashboard/spec.md#requirement-renewals-due-widget`
  - **files**: dashboard manifest/widgets, `src/`
  - **acceptance_criteria**:
    - MRR card: MRR, ARR, period delta; Renewals due: expiring contracts by endDate with deep links and empty state
    - No dashboard-in-dashboard nesting (gate-15)

- [ ] 3.5 Recurring-revenue block in pipeline insights
  - **spec_ref**: `specs/contract-renewal-tracking/spec.md#requirement-recurring-revenue-roll-up`
  - **files**: pipeline insights view
  - **acceptance_criteria**:
    - Renewal rate + churned MRR per period alongside existing deal metrics

## 4. Verification

- [ ] 4.1 Tests + gates
  - **spec_ref**: all
  - **files**: `tests/unit/`, `tests/e2e/`, `tests/integration/`
  - **acceptance_criteria**:
    - PHPUnit: ContractService guards, RenewalEngineService, RecurringRevenueService
    - Playwright UI coverage: contracts list/detail/modal, client tab, dashboard widgets, insights block; API/contract assertions in Newman collections (not Playwright)
    - `composer check:strict` green; hydra gates pass (incl. route-auth, no-admin-idor, redundant-controller, notification-dialect)
    - Verify customer-portal PortalContractService reads the seeded contracts without mapping

- [ ] 4.2 Docs
  - **spec_ref**: `specs/contract-renewal-tracking/spec.md#requirement-contract-schema-registration`
  - **files**: `docs/Features/contract-renewal-tracking.md`
  - **acceptance_criteria**:
    - Feature page with accurate status, MRR definitions, renewal lifecycle diagram; recommends explicit endDates for indefinite contracts
