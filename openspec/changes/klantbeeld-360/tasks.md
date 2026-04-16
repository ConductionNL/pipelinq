# Tasks: Klantbeeld 360

## 0. Deduplication Check

- [ ] 0.1 Verify no overlap with existing OpenRegister services and shared components
  - **spec_ref**: `specs/klantbeeld-360/spec.md#Overview`
  - **action**: Search `openregister/lib/Service/` for existing analytics aggregation. Search
    `openspec/specs/` for analytics or cross-module reporting specs. Check
    `@conduction/nextcloud-vue` for chart and KPI components before building custom ones.
  - **finding** (pre-verified): No `AnalyticsService` exists in OpenRegister core. No existing
    cross-module analytics spec in pipelinq. `CnDashboardPage`, `CnStatsBlock`, `CnKpiGrid`,
    `CnChartWidget` cover all dashboard and KPI needs — no custom chart or layout components
    are needed. The new `AnalyticsService` is pipelinq-domain-specific (lead win rate, CRM
    schema filter) and cannot be placed in OpenRegister core.

---

## 1. Backend: Analytics Service and Controller

- [ ] 1.1 Create `lib/Service/AnalyticsService.php`
  - **spec_ref**: `specs/klantbeeld-360/spec.md#REQ-KB360-020`
  - **files**: `lib/Service/AnalyticsService.php`
  - **acceptance_criteria**:
    - GIVEN `getSummary('month')` is called
    - THEN it MUST return `openPipelineValue`, `openRequests`, `contactmomentenCount`,
      `activeLeads` for the trailing 30 days
    - AND it MUST call `ObjectService::findObjects($register, $schema, $params)` with 3
      positional args (never 1-arg shorthand)
    - AND all error paths MUST log via logger and throw — never return `$e->getMessage()` to caller
  - **notes**: Add `@spec openspec/changes/klantbeeld-360/tasks.md#task-1.1` PHPDoc tag per ADR-003

- [ ] 1.2 Create `lib/Controller/AnalyticsController.php`
  - **spec_ref**: `specs/klantbeeld-360/spec.md#REQ-KB360-020`, `REQ-KB360-022`
  - **files**: `lib/Controller/AnalyticsController.php`
  - **acceptance_criteria**:
    - GIVEN `GET /api/analytics/summary?period=month` is called by an authenticated user
    - THEN it MUST return HTTP 200 with the summary JSON object
    - GIVEN an invalid `period` parameter is provided
    - THEN it MUST return HTTP 400 with `{ "message": "Invalid period" }`
    - GIVEN OpenRegister is unavailable
    - THEN it MUST return HTTP 500 with `{ "message": "Analytics unavailable" }` (never stack trace)
    - AND `#[NoAdminRequired]` MUST be applied (all authenticated users may view)
  - **notes**: Controller MUST be thin (<10 lines per action). Business logic in AnalyticsService.

- [ ] 1.3 Add analytics route to `appinfo/routes.php`
  - **files**: `appinfo/routes.php`
  - **acceptance_criteria**:
    - `GET /api/analytics/summary` MUST be registered as `analytics#summary`
    - Route MUST be placed BEFORE any wildcard `{slug}` routes

- [ ] 1.4 Write PHPUnit tests for `AnalyticsService`
  - **spec_ref**: ADR-008 (≥3 test methods per service)
  - **files**: `tests/Unit/Service/AnalyticsServiceTest.php`
  - **acceptance_criteria**:
    - Test `getSummary('month')` with mock ObjectService returning known data → verify sums
    - Test `getSummary('week')` → verify period boundary calculation
    - Test `getSummary('quarter')` → verify quarterly boundary
    - Test error path: ObjectService throws → service propagates without leaking getMessage

---

## 2. Frontend: Analytics Dashboard

- [ ] 2.1 Create `src/views/analytics/AnalyticsDashboard.vue`
  - **spec_ref**: `specs/klantbeeld-360/spec.md#REQ-KB360-020`, `REQ-KB360-021`, `REQ-KB360-022`
  - **files**: `src/views/analytics/AnalyticsDashboard.vue`
  - **acceptance_criteria**:
    - GIVEN user navigates to `/analytics`
    - THEN `CnDashboardPage` renders with four `CnStatsBlock` KPI cards:
      Open Pipeline Value (EUR), Open Requests, Contactmomenten, Active Leads
    - AND an `NcSelect` time-period filter (week/month/quarter) is placed in the
      `header-actions` slot of the dashboard page
    - WHEN the period is changed, `GET /api/analytics/summary?period={period}` is called
    - AND on re-navigation to the route, data is re-fetched on `mounted()`
    - AND loading state is shown during fetch; error state shown on failure
    - AND EVERY `await` call MUST be in `try/catch` with user-facing error feedback
    - AND all strings use `this.t(appName, 'English key')` — no hardcoded text
  - **import checklist** (Vue 2 safety):
    - `CnDashboardPage`, `CnKpiGrid`, `CnStatsBlock` imported AND in `components: {}`
    - `NcSelect` imported from `@conduction/nextcloud-vue` (never `@nextcloud/vue` directly)
    - `axios` imported from `@nextcloud/axios` for API call

---

## 3. Frontend: Pipeline Analytics View

- [ ] 3.1 Create `src/views/pipeline/PipelineAnalyticsView.vue`
  - **spec_ref**: `specs/klantbeeld-360/spec.md#REQ-KB360-010`, `REQ-KB360-011`,
    `REQ-KB360-012`, `REQ-KB360-013`
  - **files**: `src/views/pipeline/PipelineAnalyticsView.vue`
  - **acceptance_criteria**:
    - GIVEN user navigates to `/pipeline-analytics`
    - THEN an `NcSelect` pipeline dropdown is rendered; default pipeline auto-selected
    - WHEN a pipeline is selected, `objectStore.fetchCollection('lead', { pipeline: uuid })`
      is called
    - THEN four `CnStatsBlock` KPI cards render: Total Pipeline Value, Win Rate (%), Avg Deal
      Size, Active Opportunities
    - AND win rate shows `—` when no closed leads exist (no division-by-zero)
    - AND a `CnChartWidget` horizontal bar chart shows lead counts per stage (ordered by stageOrder)
    - AND changing the pipeline dropdown refetches leads and updates all KPIs and chart
    - AND loading/error states are handled; EVERY `await` in `try/catch`
  - **computed properties**:
    - `openLeads`: filter leads where status === 'active'
    - `wonLeads`: filter leads where status === 'won'
    - `lostLeads`: filter leads where status === 'lost'
    - `totalValue`: sum of openLeads[].value
    - `winRate`: wonLeads.length / (wonLeads.length + lostLeads.length) or null
    - `avgDealSize`: totalValue / openLeads.length or null
    - `stageData`: array of { stage, count } sorted by stageOrder
  - **import checklist**:
    - `CnDetailPage`, `CnDetailCard`, `CnKpiGrid`, `CnStatsBlock`, `CnChartWidget`, `NcSelect`
      imported from `@conduction/nextcloud-vue` AND registered in `components: {}`

---

## 4. Frontend: Client 360° View (ClientDetail.vue Enhancements)

- [ ] 4.1 Add summary statistics card to `ClientDetail.vue`
  - **spec_ref**: `specs/klantbeeld-360/spec.md#REQ-KB360-001`
  - **files**: `src/views/clients/ClientDetail.vue`
  - **acceptance_criteria**:
    - GIVEN a client detail page loads
    - THEN a summary `CnDetailCard` with four `CnStatsBlock` metrics appears near the top:
      open leads (count + EUR value), won leads (count + EUR value), open requests (count),
      contactmomenten (count)
    - AND EUR values formatted as `€ X.XXX` (Dutch locale)
    - AND zero values display `0`, not blank

- [ ] 4.2 Add linked leads section to `ClientDetail.vue`
  - **spec_ref**: `specs/klantbeeld-360/spec.md#REQ-KB360-002`
  - **files**: `src/views/clients/ClientDetail.vue`
  - **acceptance_criteria**:
    - GIVEN a client with linked leads
    - THEN a "Leads" `CnDetailCard` section shows up to 10 leads sorted by `updatedAt` desc
    - AND each row shows: title, stage, EUR value, probability %, expected close date
    - AND clicking a row navigates to `/leads/{uuid}`
    - GIVEN no linked leads: empty state shown, no error

- [ ] 4.3 Add linked contactmomenten section to `ClientDetail.vue`
  - **spec_ref**: `specs/klantbeeld-360/spec.md#REQ-KB360-003`
  - **files**: `src/views/clients/ClientDetail.vue`
  - **acceptance_criteria**:
    - GIVEN a client with contactmomenten
    - THEN a "Contactmomenten" `CnDetailCard` section shows up to 10 entries sorted by
      `contactedAt` desc
    - AND each row shows: subject, channel, contactedAt (formatted), agent UID, outcome
    - GIVEN no contactmomenten: empty state shown

- [ ] 4.4 Add linked requests section to `ClientDetail.vue`
  - **spec_ref**: `specs/klantbeeld-360/spec.md#REQ-KB360-004`
  - **files**: `src/views/clients/ClientDetail.vue`
  - **acceptance_criteria**:
    - GIVEN a client with requests
    - THEN a "Requests" `CnDetailCard` section shows up to 5 requests sorted by `requestedAt` desc
    - AND each row shows: title, status, priority, requestedAt (formatted)

- [ ] 4.5 Enhance linked contacts section in `ClientDetail.vue`
  - **spec_ref**: `specs/klantbeeld-360/spec.md#REQ-KB360-005`
  - **files**: `src/views/clients/ClientDetail.vue`
  - **acceptance_criteria**:
    - GIVEN a client with contacts (where `contact.client` equals this client UUID)
    - THEN a "Contacts" `CnDetailCard` shows all contacts with: name, role, email, phone
    - AND clicking a contact row navigates to `/contacts/{uuid}`
    - GIVEN no contacts: empty state with "Add Contact" action button

- [ ] 4.6 Implement parallel loading with section-level states in `ClientDetail.vue`
  - **spec_ref**: `specs/klantbeeld-360/spec.md#REQ-KB360-006`
  - **files**: `src/views/clients/ClientDetail.vue`
  - **acceptance_criteria**:
    - GIVEN the client detail page opens
    - THEN all four relation fetches run in parallel via `Promise.all` (or `Promise.allSettled`)
    - AND each section shows its own loading indicator until its fetch resolves
    - AND a failure in one section MUST NOT prevent others from rendering
    - AND failed sections display an error message

---

## 5. Frontend: Contact–Organisation Linking (ContactDetail.vue)

- [ ] 5.1 Add Parent Organisation card to `ContactDetail.vue`
  - **spec_ref**: `specs/klantbeeld-360/spec.md#REQ-KB360-030`, `REQ-KB360-031`
  - **files**: `src/views/contacts/ContactDetail.vue`
  - **acceptance_criteria**:
    - GIVEN a contact with `contact.client` set
    - THEN a "Parent Organisation" `CnDetailCard` shows the client name and type
    - AND clicking the name navigates to `/clients/{client.uuid}`
    - GIVEN `contact.client` is null
    - THEN empty state + "Link to Organisation" button is shown (no error)

- [ ] 5.2 Implement organisation linking dialog in `ContactDetail.vue`
  - **spec_ref**: `specs/klantbeeld-360/spec.md#REQ-KB360-032`
  - **files**: `src/views/contacts/ContactDetail.vue`
  - **acceptance_criteria**:
    - GIVEN the "Link to Organisation" button is clicked
    - THEN a `CnFormDialog` with a searchable `NcSelect` client list MUST open
    - WHEN the user selects a client and confirms
    - THEN `contact.client` is set and `objectStore.saveObject('contact', data)` is called
    - AND on success the Parent Organisation card updates immediately
    - AND on failure a user-facing error notification is shown
    - AND the save call is wrapped in `try/catch` (never silently fails)
    - AND NEVER `window.confirm()` or `window.alert()` — use `CnFormDialog`

---

## 6. Frontend: Opportunity Tracking (LeadList.vue Enhancements)

- [ ] 6.1 Add expected close date warnings to `LeadList.vue`
  - **spec_ref**: `specs/klantbeeld-360/spec.md#REQ-KB360-014`
  - **files**: `src/views/leads/LeadList.vue`
  - **acceptance_criteria**:
    - GIVEN a lead with `expectedCloseDate` within the next 7 days
    - THEN the date cell shows a warning icon + the date (NOT color alone — WCAG AA)
    - GIVEN a lead with `expectedCloseDate` in the past
    - THEN the cell shows an overdue icon + the date with overdue color

- [ ] 6.2 Add probability badge to `LeadList.vue`
  - **spec_ref**: `specs/klantbeeld-360/spec.md#REQ-KB360-015`
  - **files**: `src/views/leads/LeadList.vue`
  - **acceptance_criteria**:
    - GIVEN a lead with `probability < 30`
    - THEN a "Low" probability badge MUST be shown (icon + label — not color alone)
    - GIVEN a lead with `probability >= 30`
    - THEN no badge is shown; probability displayed as plain percentage

---

## 7. Navigation and Routing

- [ ] 7.1 Add analytics routes to `src/router/index.js`
  - **files**: `src/router/index.js`
  - **acceptance_criteria**:
    - Route `{ path: '/analytics', name: 'Analytics', component: AnalyticsDashboard }` registered
    - Route `{ path: '/pipeline-analytics', name: 'PipelineAnalytics',
      component: PipelineAnalyticsView }` registered
    - Both routes use history mode with `generateUrl('/apps/pipelinq/')` base
    - Deep link URL format: path-based (`/apps/pipelinq/analytics`) NOT hash-based

- [ ] 7.2 Add Analytics nav item to `src/navigation/MainMenu.vue`
  - **files**: `src/navigation/MainMenu.vue`
  - **acceptance_criteria**:
    - An `NcAppNavigationItem` for "Analytics" linking to `{ name: 'Analytics' }` appears
    - Item uses an appropriate MDI icon via `CnIcon`
    - Label uses `this.t(appName, 'Analytics')` (not hardcoded)

---

## 8. Translations

- [ ] 8.1 Add all new translation keys to `l10n/en.json` and `l10n/nl.json`
  - **spec_ref**: ADR-007 (both files MUST have exactly the same keys, zero gaps)
  - **acceptance_criteria**:
    - Every string passed to `this.t(appName, 'key')` in new/modified files MUST have an entry
      in both `l10n/en.json` (identity-mapped: key === value) and `l10n/nl.json` (Dutch value)
    - Example keys: `Analytics`, `Pipeline Analytics`, `Parent Organisation`,
      `Link to Organisation`, `Open Pipeline Value`, `Win Rate`, `Active Opportunities`,
      `Average Deal Size`, `No leads linked`, `No contactmomenten`, `No requests linked`
    - `l10n/nl.json` Dutch values: `Analyses`, `Pijplijnanalyse`, `Moederorganisatie`,
      `Koppel aan organisatie`, `Open Pijplijnwaarde`, `Winstpercentage`,
      `Actieve kansen`, `Gemiddelde dealgrootte`, `Geen leads gekoppeld`,
      `Geen contactmomenten`, `Geen verzoeken gekoppeld`

---

## 9. Pre-commit Verification

- [ ] 9.1 SPDX headers on all new files
  - **action**: `grep -rL 'SPDX-License-Identifier' src/views/analytics/ src/views/pipeline/
    lib/Service/AnalyticsService.php lib/Controller/AnalyticsController.php`
  - → All new PHP files: `// SPDX-License-Identifier: EUPL-1.2` after `<?php`
  - → All new Vue files: `<!-- SPDX-License-Identifier: EUPL-1.2 -->` as first line

- [ ] 9.2 ObjectService call signatures
  - **action**: `grep -rn 'findObjects\|saveObject\|findObject' lib/ --include='*.php'`
  - → Every call MUST have 3 positional args: `($register, $schema, $paramsOrId)`
  - → Zero 1-arg calls allowed

- [ ] 9.3 Error responses check
  - **action**: `grep -rn 'getMessage()' lib/Controller/ --include='*.php'`
  - → Must return zero matches. Replace any with static error strings.

- [ ] 9.4 Vue import completeness
  - **action**: For every `<CnFoo>` or `<NcFoo>` in new templates, verify imported AND in
    `components: {}`. Vue 2 silently renders unknown elements.

- [ ] 9.5 No `@nextcloud/vue` direct imports
  - **action**: `grep -rn "from '@nextcloud/vue'" src/`
  - → Must return zero matches. Use `@conduction/nextcloud-vue`.

- [ ] 9.6 try/catch on all store calls
  - **action**: `grep -rn 'await.*[Ss]tore\.' src/views/analytics/ src/views/pipeline/
    src/views/clients/ClientDetail.vue src/views/contacts/ContactDetail.vue`
  - → Every `await store.X()` must be wrapped in `try/catch` with user-facing error feedback

- [ ] 9.7 No hardcoded strings
  - **action**: Scan new Vue files for string literals in templates that are not wrapped in `t()`

- [ ] 9.8 Translation key language
  - **action**: `grep -rn "t('pipelinq'," src/ --include='*.vue'`
  - → All keys MUST be English (e.g., `'Analytics'` not `'Analyses'`)

---

## 10. Smoke Tests (before PR)

- [ ] 10.1 API endpoint smoke test
  - `curl -u admin:password http://localhost/index.php/apps/pipelinq/api/analytics/summary?period=month`
  - → Verify HTTP 200 and JSON with all four keys
  - `curl -u admin:password http://localhost/index.php/apps/pipelinq/api/analytics/summary?period=invalid`
  - → Verify HTTP 400 with `{ "message": "Invalid period" }`

- [ ] 10.2 Analytics dashboard smoke test
  - Open `/analytics` in browser → verify 4 KPI cards load without errors
  - Switch time period → verify Contactmomenten count updates
  - Return to dashboard via nav → verify data re-fetched (check network tab)

- [ ] 10.3 Pipeline analytics smoke test
  - Open `/pipeline-analytics` → verify pipeline dropdown populated
  - Select a pipeline → verify KPI cards update and stage funnel chart renders
  - Verify win rate shows `—` when no won/lost leads exist

- [ ] 10.4 Client 360 smoke test
  - Open a client detail page with linked leads/contactmomenten/requests
  - Verify all 4 relation sections render with correct data
  - Open a fresh client with no links → verify empty states (no errors)

- [ ] 10.5 Contact–organisation linking smoke test
  - Open a contact without a client link → verify "Link to Organisation" button visible
  - Click → select a client → confirm → verify parent org card updates immediately
  - Open a contact with a client link → verify parent org card shows client name

- [ ] 10.6 WCAG AA spot check
  - Verify close-date warning on lead list uses icon + color (not color alone)
  - Verify probability badge uses label + color (not color alone)
  - Tab through analytics dashboard → verify all interactive elements reachable by keyboard
