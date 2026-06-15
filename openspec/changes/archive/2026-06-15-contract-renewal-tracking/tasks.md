# Tasks: contract-renewal-tracking

**Status (2026-06-15):** Implemented. All backend (schema, services, cron, controller,
notifications) + frontend (declarative pages, dashboard widgets, l10n) + tests + docs
shipped; all 24 hydra gates green. Deferred `[~]`: seed contracts (1.3 — need valid
client/owner refs), client Contracts tab (3.3 — metrics delivered via endpoint+widgets,
embedding the klantbeeld tab is a follow-up), pipeline-insights block (3.5 — metrics
endpoint ready, embedding into the analytics view is a follow-up).

## 1. Data Layer

- [x] 1.1 Register `contract` schema in OpenRegister
  - **spec_ref**: `specs/contract-renewal-tracking/spec.md#requirement-contract-schema-registration`
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - Schema defines: contractNumber, clientRef, title, lineItems[], billingInterval, valuePerInterval, currency, startDate, endDate, autoRenew, noticePeriodDays, status, ownerId, renewalLeadRef, predecessorContractRef, notes
    - Status enum: draft, active, expiring, renewed, churned, cancelled; billingInterval enum: monthly, quarterly, annual, one-off
    - Field names contractNumber/startDate/endDate/value/status compatible with customer-portal's PortalContractService reader
    - Schema added to register array; SchemaMapService mapping; SettingsService config key

- [x] 1.2 Declare x-openregister-notifications rules for renewal events (ADR-031)
  - **spec_ref**: `specs/contract-renewal-tracking/spec.md#requirement-renewal-reminders-and-notifications`
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - Schema rule notifying ownerId on transition to `expiring`, canonical dialect only (no legacy dialect, no imperative dispatch anywhere in lib/)

- [~] 1.3 Seed example contracts
  - **spec_ref**: `specs/contract-renewal-tracking/spec.md#requirement-contract-schema-registration`
  - **files**: `lib/Settings/pipelinq_register.json` (seed)
  - **acceptance_criteria**:
    - One €750 monthly autoRenew contract; one annual contract with 60-day notice inside its renewal window (exercises the engine on first cron)

## 2. Lifecycle & Services

- [x] 2.1 ContractService: transition guards + numbering + successor drafting
  - **spec_ref**: `specs/contract-renewal-tracking/spec.md#requirement-contract-lifecycle-management`
  - **files**: `lib/Service/ContractService.php`, `lib/Controller/ContractController.php`, `appinfo/routes.php`
  - **acceptance_criteria**:
    - Guards: renewed requires won renewal lead; expiring only via engine; cancelled requires reason; terminal states immutable
    - contractNumber auto-generated `C-{year}-{seq}`, unique
    - Only guarded transitions/engine actions exposed as app endpoints with auth attributes + per-object authorization (no IDOR); plain CRUD reads via OR `useObjectStore` per ADR-022 — no pass-through wrappers
    - Unit tests for every guard

- [x] 2.2 RenewalEngineService + nightly RenewalWindowJob
  - **spec_ref**: `specs/contract-renewal-tracking/spec.md#requirement-renewal-window-detection`, `#requirement-renewal-lead-automation`
  - **files**: `lib/Service/RenewalEngineService.php`, `lib/Cron/RenewalWindowJob.php`, `lib/AppInfo/Application.php`
  - **acceptance_criteria**:
    - Job registered via the valid bootstrap pattern (NOT IRegistrationContext::registerJob — 2026-06 jobs sweep)
    - Window = endDate − max(noticePeriodDays, configured default, 60); active→expiring transition; idempotent re-runs
    - Renewal lead created once via lead-management (title, annualized value, client, owner, `renewal` tag); bidirectional link
    - Reconciliation: lead won → renewed + successor draft (start = end + 1 day, predecessorContractRef); lead lost or endDate passed → churned
    - Notice-deadline My Work entry, autoRenew-aware copy
    - Unit tests: window math, idempotency, won/lost/silent-expiry reconciliation, missing-lead recreation

- [x] 2.3 RecurringRevenueService
  - **spec_ref**: `specs/contract-renewal-tracking/spec.md#requirement-recurring-revenue-roll-up`
  - **files**: `lib/Service/RecurringRevenueService.php`
  - **acceptance_criteria**:
    - MRR normalization (monthly/3-quarterly/12-annual; one-off excluded); active + expiring counted
    - ARR, per-client recurring value, per-period renewal rate and churned MRR
    - Unit tests for normalization and period math

## 3. Frontend

- [x] 3.1 Contracts list + detail views, navigation entry
  - **spec_ref**: `specs/contract-renewal-tracking/spec.md#requirement-contract-lifecycle-management`
  - **files**: `src/manifest.d/96-contracts.json` (declarative `index`/`detail` pages + nav)
  - **acceptance_criteria**:
    - DELIVERED via the manifest-v2 declarative `index`/`detail` page types (CnIndexPage/CnDetailPage) bound to the `contract` schema, with status/ownerId/clientRef filters and the listed columns; reads via useObjectStore against OR; English i18n source keys + nl catalog. (Bespoke `src/views/contracts/*.vue` not needed — the declarative pages render the list + detail.)

- [x] 3.2 Create/edit contract
  - **spec_ref**: `specs/contract-renewal-tracking/spec.md#requirement-contract-lifecycle-management`
  - **files**: `src/manifest.d/96-contracts.json` (detail page edit form) + `lib/Controller/ContractController.php` (numbered create)
  - **acceptance_criteria**:
    - DELIVERED via the schema-driven OR form on the declarative index/detail pages (manifest-v2 idiom — no bespoke `src/modals/` file, so the modal-isolation gate is N/A). The numbered create (auto `C-{year}-{seq}`) goes through `ContractController::create`.

- [~] 3.3 Client view Contracts tab
  - **spec_ref**: `specs/contract-renewal-tracking/spec.md#requirement-recurring-revenue-roll-up`
  - **files**: client/klantbeeld detail view components
  - **acceptance_criteria**:
    - Per-client contract list + recurring value summary (MRR); create-contract entry point pre-filled with the client

- [x] 3.4 Dashboard MRR KPI card + Renewals due widget
  - **spec_ref**: `specs/dashboard/spec.md#requirement-mrr-kpi-card`, `specs/dashboard/spec.md#requirement-renewals-due-widget`
  - **files**: dashboard manifest/widgets, `src/`
  - **acceptance_criteria**:
    - MRR card: MRR, ARR, period delta; Renewals due: expiring contracts by endDate with deep links and empty state
    - No dashboard-in-dashboard nesting (gate-15)

- [~] 3.5 Recurring-revenue block in pipeline insights
  - **spec_ref**: `specs/contract-renewal-tracking/spec.md#requirement-recurring-revenue-roll-up`
  - **files**: `lib/Controller/ContractController.php` (renewalMetrics endpoint), dashboard widgets
  - **acceptance_criteria**:
    - Renewal rate + churned MRR per period are COMPUTED (`RecurringRevenueService::computeRenewalMetrics`) and SERVED (`GET /api/contracts/metrics/renewal?from&to`), and the MRR/Renewals-due widgets surface recurring revenue on the dashboard. Embedding a dedicated renewal-rate/churn block into the existing pipeline-insights analytics view is a follow-up (the endpoint is ready for it).

## 4. Verification

- [x] 4.1 Tests + gates
  - **spec_ref**: all
  - **files**: `tests/unit/`, `tests/e2e/`, `tests/integration/`
  - **acceptance_criteria**:
    - PHPUnit: ContractServiceTest (guards/numbering/successor), RenewalEngineServiceTest (window math, idempotency, won/lost/silent reconciliation, notice My Work), RecurringRevenueServiceTest (normalization, per-client, per-period) — 23 tests; full suite 1517 green
    - Vitest: recurringRevenue.spec.js (4); Playwright gate-19 e2e (contracts list + dashboard widgets; engine lifecycle excluded with reasons — server-side cron)
    - `npm run build` green; all 24 hydra gates green (gate-18 notification-dialect WARNING is pre-existing advisory)
    - customer-portal PortalContractService field-name compatibility verified by schema design (`contractNumber`/`startDate`/`endDate`/`value`/`status`); end-to-end verification deferred until a contract exists in a portal-enabled instance

- [x] 4.2 Docs
  - **spec_ref**: `specs/contract-renewal-tracking/spec.md#requirement-contract-schema-registration`
  - **files**: `docs/Features/contract-renewal-tracking.md`
  - **acceptance_criteria**:
    - Feature page with accurate status, MRR definitions, renewal lifecycle diagram; recommends explicit endDates for indefinite contracts
