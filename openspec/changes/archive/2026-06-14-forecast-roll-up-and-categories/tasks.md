# Tasks: forecast-roll-up-and-categories

## 1. Data Model: Extend deal and add forecast schemas

- [x] 1.1 Extend `deal` schema with forecast fields
  - **spec_ref**: `specs.md#REQ-FRC-001`
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the pipelinq register schema is loaded
    - THEN `deal` schema MUST include:
      - `forecast_category` (string enum: commit, best_case, pipeline, closed_won, closed_lost, omitted)
      - `commit_justification` (string, optional)

- [x] 1.2 Create `forecast_snapshot` schema in OpenRegister
  - **spec_ref**: `specs.md#REQ-FRC-004, REQ-FRC-005`
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the register schema is updated
    - THEN `forecast_snapshot` schema MUST include all required fields:
      - period_id, as_of_date, owner_id, level, commit_amount, best_case_amount, pipeline_amount, closed_won_amount, quota_amount, deal_snapshot_ids, partial, missing_reps
    - AND indexes on (period_id, owner_id, level, as_of_date)

- [x] 1.3 Create `forecast_override` schema in OpenRegister
  - **spec_ref**: `specs.md#REQ-FRC-006, REQ-FRC-010`
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the register schema is updated
    - THEN `forecast_override` schema MUST include all required fields:
      - period_id, owner_id, override_owner_id, level, category, override_amount, original_amount, reason, created_by, created_at
    - AND indexes on (period_id, override_owner_id, level, category)

- [x] 1.4 Create `sales_quota` schema in OpenRegister
  - **spec_ref**: `specs.md#REQ-FRC-008`
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the register schema is updated
    - THEN `sales_quota` schema MUST include all required fields:
      - owner_id, period_id, level, quota_amount, currency, effective_from, effective_to, set_by, created_at
    - AND indexes on (owner_id, period_id, level)

---

## 2. Backend: Deal Lifecycle Listeners

- [x] 2.1 Create `lib/Listener/DealCreatedListener.php`
  - **spec_ref**: `specs.md#REQ-FRC-001-01`
  - **files**: `lib/Listener/DealCreatedListener.php`
  - **acceptance_criteria**:
    - GIVEN a new deal is created
    - WHEN `ObjectCreatedEvent` fires for `deal` schema
    - THEN the listener MUST set `forecast_category = "pipeline"` if not already set
    - AND persist the deal to OpenRegister
    - AND the listener MUST be idempotent (not double-set if already set)

- [x] 2.2 Create `lib/Listener/DealUpdatedListener.php`
  - **spec_ref**: `specs.md#REQ-FRC-002, REQ-FRC-003`
  - **files**: `lib/Listener/DealUpdatedListener.php`
  - **acceptance_criteria**:
    - GIVEN a deal is updated
    - WHEN `ObjectUpdatedEvent` fires for `deal` schema
    - THEN the listener MUST check if `forecast_category` changed
    - AND if old category is closed (closed_won/closed_lost) and new category is open, MUST reject with error
    - AND if new category is "commit" and `deal.value > commit_threshold`, MUST validate `commit_justification` is non-empty

- [x] 2.3 Implement closed deal lock logic
  - **spec_ref**: `specs.md#REQ-FRC-002-01`
  - **files**: `lib/Listener/DealUpdatedListener.php`
  - **acceptance_criteria**:
    - GIVEN a deal has `forecast_category = "closed_won"`
    - WHEN user attempts to change category to "commit"
    - THEN a rejection exception MUST be thrown with message "Cannot change category of a closed deal"

- [x] 2.4 Implement commit justification validation
  - **spec_ref**: `specs.md#REQ-FRC-003-01, REQ-FRC-003-02`
  - **files**: `lib/Listener/DealUpdatedListener.php`, frontend
  - **acceptance_criteria**:
    - GIVEN `deal.forecast_category = "commit"` and `deal.value > commit_threshold`
    - WHEN the frontend detects this change
    - THEN a modal MUST prompt for justification
    - AND the backend MUST reject the save if `commit_justification` is missing or < 10 chars
    - AND on success, `commit_justification` MUST be persisted

- [x] 2.5 Register both listeners in `lib/AppInfo/Application.php`
  - **spec_ref**: `specs.md#REQ-FRC-001, REQ-FRC-002`
  - **files**: `lib/AppInfo/Application.php`
  - **acceptance_criteria**:
    - GIVEN the pipelinq app is initialized
    - WHEN `Application::register()` runs
    - THEN `DealCreatedListener` MUST be registered for `ObjectCreatedEvent`
    - AND `DealUpdatedListener` MUST be registered for `ObjectUpdatedEvent`
    - AND both filtered to `deal` schema only

---

## 3. Backend: Snapshot Job and Scheduling

- [x] 3.1 Create `lib/Job/ForecastSnapshotJob.php`
  - **spec_ref**: `specs.md#REQ-FRC-004`
  - **files**: `lib/Job/ForecastSnapshotJob.php`
  - **acceptance_criteria**:
    - GIVEN the job is invoked
    - WHEN it runs
    - THEN it MUST retrieve the currently-open fiscal period(s)
    - AND for each period, generate snapshots for all reps, teams, divisions, company
    - AND persist all snapshots to OpenRegister
    - AND complete within 5 minutes for typical org size

- [x] 3.2 Implement rep-level snapshot generation
  - **spec_ref**: `specs.md#REQ-FRC-004-02, REQ-FRC-004-03`
  - **files**: `lib/Job/ForecastSnapshotJob.php`
  - **acceptance_criteria**:
    - GIVEN a rep has deals with various forecast categories
    - WHEN rep snapshot is generated
    - THEN `commit_amount` MUST sum only deals with `forecast_category = "commit"`
    - AND `best_case_amount` MUST sum only `forecast_category = "best_case"`
    - AND `pipeline_amount` MUST sum only `forecast_category = "pipeline"`
    - AND `closed_won_amount` MUST sum all deals with `forecast_category = "closed_won"`
    - AND exclude deals with `forecast_category = "omitted"`
    - AND set `deal_snapshot_ids` to the UUIDs of contributing deals

- [x] 3.3 Implement team-level snapshot generation (roll-up)
  - **spec_ref**: `specs.md#REQ-FRC-004-04, REQ-FRC-005-01`
  - **files**: `lib/Job/ForecastSnapshotJob.php`
  - **acceptance_criteria**:
    - GIVEN a team has multiple reps with existing snapshots
    - WHEN team snapshot is generated
    - THEN `team.commit_amount` MUST = sum of `rep.commit_amount` for all reps
    - AND same rule for best_case, pipeline, closed_won
    - AND if any rep snapshot is missing, set `partial = true` and list missing reps

- [x] 3.4 Implement division and company roll-ups
  - **spec_ref**: `specs.md#REQ-FRC-005-02, REQ-FRC-005-03`
  - **files**: `lib/Job/ForecastSnapshotJob.php`
  - **acceptance_criteria**:
    - GIVEN team and division hierarchies exist
    - WHEN division snapshot is generated
    - THEN `division.commit_amount` MUST = sum of team commits
    - AND when company snapshot is generated
    - THEN `company.commit_amount` MUST = sum of division commits

- [x] 3.5 Implement currency normalization in roll-up
  - **spec_ref**: `specs.md#REQ-FRC-004-05`
  - **files**: `lib/Job/ForecastSnapshotJob.php`, `lib/Service/ExchangeRateService.php`
  - **acceptance_criteria**:
    - GIVEN deals in multiple currencies (EUR, GBP, USD)
    - WHEN team roll-up is computed
    - THEN all amounts MUST be converted to org's reporting currency using org-configured rate source
    - AND the snapshot MUST record the reporting currency

- [x] 3.6 Implement error handling and partial failure
  - **spec_ref**: `specs.md#REQ-FRC-004-06, REQ-FRC-004-07`
  - **files**: `lib/Job/ForecastSnapshotJob.php`
  - **acceptance_criteria**:
    - GIVEN the job encounters an error generating one team's snapshot
    - WHEN the job continues to the next team
    - THEN the error MUST be logged without blocking other teams
    - AND the parent (division, company) MUST set `partial = true` and list missing teams
    - AND the pipelinq admin MUST receive a Nextcloud notification with error details

- [x] 3.7 Register job with OpenRegister cron-trigger
  - **spec_ref**: `specs.md#REQ-FRC-004-01`
  - **files**: OpenRegister cron config or `lib/AppInfo/Application.php`
  - **acceptance_criteria**:
    - GIVEN the pipelinq app is running
    - WHEN Monday 06:00 UTC arrives (or org-configured timezone)
    - THEN `ForecastSnapshotJob` MUST fire automatically
    - AND complete before 06:05

---

## 4. Backend: Forecast Service and Computations

- [x] 4.1 Create `lib/Service/ForecastService.php`
  - **spec_ref**: `specs.md#REQ-FRC-005, REQ-FRC-006, REQ-FRC-007, REQ-FRC-008`
  - **files**: `lib/Service/ForecastService.php`
  - **acceptance_criteria**:
    - GIVEN the service is instantiated
    - THEN it MUST provide methods:
      - `computeRollUp(owner_id, period_id, level): array`
      - `computeAccuracyScore(owner_id, period_id, level): ?float`
      - `computeTrailingQuartersAccuracy(owner_id): float`

- [x] 4.2 Implement `computeRollUp` with override application
  - **spec_ref**: `specs.md#REQ-FRC-006-03`
  - **files**: `lib/Service/ForecastService.php`
  - **acceptance_criteria**:
    - GIVEN an owner (rep/team/division) and period
    - WHEN `computeRollUp()` is called
    - THEN it MUST fetch the latest snapshot for (owner, period, level)
    - AND check for `forecast_override` where (override_owner_id == owner, period, level, category)
    - AND if override exists, use `override_amount` instead of snapshot amount
    - AND return object with: commit, original_commit, best_case, original_best_case, pipeline, closed_won, quota, override details

- [x] 4.3 Implement accuracy score computation
  - **spec_ref**: `specs.md#REQ-FRC-007-01, REQ-FRC-007-02`
  - **files**: `lib/Service/ForecastService.php`
  - **acceptance_criteria**:
    - GIVEN a closed period with commit snapshot and actual closed_won
    - WHEN `computeAccuracyScore()` is called
    - THEN it MUST return `1 - abs(commit - actual) / actual` (as float 0.0-1.0)
    - AND return null if period not closed or no data

- [x] 4.4 Implement trailing-4-quarters average
  - **spec_ref**: `specs.md#REQ-FRC-007-05`
  - **files**: `lib/Service/ForecastService.php`
  - **acceptance_criteria**:
    - GIVEN a rep with accuracy scores for 4+ closed periods
    - WHEN `computeTrailingQuartersAccuracy()` is called
    - THEN it MUST return the average of the last 4 quarters' accuracy scores
    - AND return 0.0 if fewer than 4 quarters of data exist

- [x] 4.5 Create `lib/Service/QuotaService.php`
  - **spec_ref**: `specs.md#REQ-FRC-008`
  - **files**: `lib/Service/QuotaService.php`
  - **acceptance_criteria**:
    - GIVEN the service is instantiated
    - THEN it MUST provide methods:
      - `getQuota(owner_id, period_id, level): ?array`
      - `validateQuotaHierarchy(period_id): array`

- [x] 4.6 Implement `getQuota` lookup
  - **spec_ref**: `specs.md#REQ-FRC-008-01`
  - **files**: `lib/Service/QuotaService.php`
  - **acceptance_criteria**:
    - GIVEN an owner and period
    - WHEN `getQuota()` is called
    - THEN it MUST fetch the `sales_quota` row for (owner_id, period_id, level)
    - AND return the quota_amount or null if not set

- [x] 4.7 Implement quota hierarchy validation
  - **spec_ref**: `specs.md#REQ-FRC-008`
  - **files**: `lib/Service/QuotaService.php`
  - **acceptance_criteria**:
    - GIVEN a period with rep/team/division quotas
    - WHEN `validateQuotaHierarchy()` is called
    - THEN it MUST return advisory report showing:
      - Each team: sum of rep quotas vs team quota, variance percent
      - Each division: sum of team quotas vs division quota, variance percent

---

## 5. Backend: REST API Controller

- [x] 5.1 Create `lib/Controller/ForecastController.php`
  - **spec_ref**: `specs.md#REQ-FRC-010, REQ-FRC-011`
  - **files**: `lib/Controller/ForecastController.php`
  - **acceptance_criteria**:
    - GIVEN the controller is instantiated
    - THEN it MUST handle endpoints:
      - `GET /api/forecast/snapshots`
      - `POST /api/forecast/overrides`
      - `DELETE /api/forecast/overrides/{id}` (optional for MVP)

- [x] 5.2 Implement snapshot export endpoint (JSON)
  - **spec_ref**: `specs.md#REQ-FRC-010-01, REQ-FRC-010-04, REQ-FRC-010-05`
  - **files**: `lib/Controller/ForecastController.php`
  - **acceptance_criteria**:
    - GIVEN a user calls `GET /api/forecast/snapshots?period_id=...&level=...&format=json`
    - WHEN the request is received
    - THEN it MUST validate `forecast:read` permission scoped to requester
    - AND return JSON array with snapshots matching the filters
    - AND include `calculation_audit` for each snapshot showing the roll-up breakdown

- [x] 5.3 Implement snapshot export endpoint (CSV)
  - **spec_ref**: `specs.md#REQ-FRC-010-02`
  - **files**: `lib/Controller/ForecastController.php`
  - **acceptance_criteria**:
    - GIVEN the same endpoint with `&format=csv`
    - WHEN the request is received
    - THEN the response MUST return CSV with:
      - Header: as_of_date, owner_id, level, commit, best_case, pipeline, closed_won, quota
      - Data rows: one per snapshot
      - Optional separate section for calculation_audit

- [x] 5.4 Implement pagination for snapshot export
  - **spec_ref**: `specs.md#REQ-FRC-010-05`
  - **files**: `lib/Controller/ForecastController.php`
  - **acceptance_criteria**:
    - GIVEN a large result set (many snapshots)
    - WHEN query params `limit=50&offset=0` are provided
    - THEN the response MUST return max 50 rows
    - AND include `total`, `limit`, `offset` in metadata

- [x] 5.5 Implement override create endpoint
  - **spec_ref**: `specs.md#REQ-FRC-006-01, REQ-FRC-011-02`
  - **files**: `lib/Controller/ForecastController.php`
  - **acceptance_criteria**:
    - GIVEN a user calls `POST /api/forecast/overrides` with valid JSON
    - WHEN the request is received
    - THEN it MUST validate `forecast:override` permission scoped to requester
    - AND create a `forecast_override` object with provided data
    - AND validate required fields: override_owner_id, override_amount, reason
    - AND return the created override object with 201 status

- [x] 5.6 Implement permission checks at controller level
  - **spec_ref**: `specs.md#REQ-FRC-011`
  - **files**: `lib/Controller/ForecastController.php`
  - **acceptance_criteria**:
    - GIVEN a user with limited permissions
    - WHEN they attempt to query forecast data outside their scope
    - THEN the controller MUST return 403 Forbidden
    - AND log the denied request

---

## 6. Frontend: Deal Detail View Updates

- [x] 6.1 Add forecast category selector to deal detail
  - **spec_ref**: `specs.md#REQ-FRC-001-02`
  - **files**: frontend components (Vue/React), templates
  - **acceptance_criteria**:
    - GIVEN a deal detail view is rendered
    - WHEN the page loads
    - THEN a dropdown selector MUST be visible with 6 options:
      - "Toezegging" (commit)
      - "Best-case"
      - "Pipeline"
      - "Omitted"
      - "Closed Won"
      - "Closed Lost"
    - AND the current category MUST be pre-selected

- [x] 6.2 Implement forecast category change handler
  - **spec_ref**: `specs.md#REQ-FRC-001-03`
  - **files**: frontend, `lib/Controller/DealController.php` (or similar)
  - **acceptance_criteria**:
    - GIVEN the user changes the selector
    - WHEN the change is detected
    - THEN a PATCH request MUST be sent to update the deal
    - AND on success, the UI MUST show a confirmation message
    - AND the audit log section MUST update to show the new entry

- [x] 6.3 Add justification modal for large commits
  - **spec_ref**: `specs.md#REQ-FRC-003-01, REQ-FRC-003-02`
  - **files**: frontend modal component
  - **acceptance_criteria**:
    - GIVEN a user sets forecast_category to "commit" for a deal > commit threshold
    - WHEN the selector change is detected
    - THEN a modal MUST appear:
      - Title: "Justification required for large commitment"
      - Text input field with placeholder: "Why are you confident in this deal?"
      - Min length 10 characters
      - Save and Cancel buttons
    - WHEN user enters text and clicks Save
    - THEN the deal MUST be updated with `commit_justification`

- [x] 6.4 Add category history section
  - **spec_ref**: `specs.md#REQ-FRC-001-04`
  - **files**: frontend component
  - **acceptance_criteria**:
    - GIVEN a deal has changed forecast category
    - WHEN the deal detail is rendered
    - THEN a "Category History" section MUST show:
      - Column: Timestamp | Old Value | New Value | Changed By
      - Row per change, sorted newest first

- [x] 6.5 Add lock indicator for closed deals
  - **spec_ref**: `specs.md#REQ-FRC-002-02`
  - **files**: frontend
  - **acceptance_criteria**:
    - GIVEN a deal is closed_won or closed_lost
    - WHEN the deal detail is rendered
    - THEN the forecast selector MUST be disabled (greyed)
    - AND a lock icon MUST appear
    - AND a tooltip MUST show: "Reopen the deal to change the forecast category"

- [x] 6.6 Add error message display
  - **spec_ref**: `specs.md#REQ-FRC-002-01`
  - **files**: frontend
  - **acceptance_criteria**:
    - GIVEN a backend validation fails
    - WHEN the error response is received
    - THEN the error message MUST be displayed to the user in an alert or banner
    - AND the category change MUST NOT be applied

---

## 7. Frontend: Manager Forecast View

- [x] 7.1 Create forecast overview page layout
  - **spec_ref**: `specs.md#REQ-FRC-006, REQ-FRC-008`
  - **files**: frontend forecast view component
  - **acceptance_criteria**:
    - GIVEN a manager opens the "Forecast" page
    - WHEN the page loads
    - THEN it MUST display:
      - Hierarchy selector (Company | Division | Team | Rep) with drill-down
      - Forecast summary panel (quota, closed_won, commit, gap_to_close)
      - Team summary table (rep rows)
      - Quota progress bar
      - At-risk warning banner (if applicable)

- [x] 7.2 Implement team summary table
  - **spec_ref**: `specs.md#REQ-FRC-006, REQ-FRC-008-01`
  - **files**: frontend table component
  - **acceptance_criteria**:
    - GIVEN a team forecast view
    - WHEN the table is rendered
    - THEN columns MUST include:
      - Rep Name | Commit | Override | Original | Manager Note | Best Case | Pipeline | Quota | Status
    - AND each rep row MUST show calculated values
    - AND if override exists, show override amount in distinct column with "override" badge

- [x] 7.3 Implement override entry form
  - **spec_ref**: `specs.md#REQ-FRC-006-01`
  - **files**: frontend form component
  - **acceptance_criteria**:
    - GIVEN a manager clicks "Override" button next to a rep's commit
    - WHEN the click is detected
    - THEN a form MUST appear:
      - Input 1: "Override amount" (number, pre-filled with current value)
      - Input 2: "Reason for override" (text, required)
      - Buttons: Save, Cancel
    - WHEN manager fills and clicks Save
    - THEN the override MUST be created via API
    - AND the form MUST close
    - AND the table MUST refresh to show the override

- [x] 7.4 Implement visual diff for override
  - **spec_ref**: `specs.md#REQ-FRC-006-02`
  - **files**: frontend
  - **acceptance_criteria**:
    - GIVEN an override exists (€75K → €60K)
    - WHEN the forecast table is rendered
    - THEN the UI MUST show:
      - "Rep submitted: €75,000" (smaller, greyed)
      - "Manager commit: €60,000" (bold, highlighted)
      - Red down arrow or ▼ indicator
      - On hover or click: tooltip showing override reason

- [x] 7.5 Implement quota progress bar
  - **spec_ref**: `specs.md#REQ-FRC-008-02`
  - **files**: frontend progress bar component
  - **acceptance_criteria**:
    - GIVEN rep with quota €150K, closed €50K, commit €75K
    - WHEN progress bar is rendered
    - THEN it MUST show:
      - Stacked bar: solid green (€50K closed), hatched blue (€75K commit), light grey (€25K remaining)
      - Total width representing €150K
      - Label: "125K of 150K on track (83%)"
      - Quota line marker at €150K

- [x] 7.6 Implement at-risk warning banner
  - **spec_ref**: `specs.md#REQ-FRC-008-04`
  - **files**: frontend
  - **acceptance_criteria**:
    - GIVEN projected attainment < 90% quota AND days remaining < 30
    - WHEN the forecast view is rendered
    - THEN a warning banner MUST appear:
      - Background: orange
      - Icon: exclamation
      - Text: "This team is [gap]% below quota with [N] days to close. Action recommended."

---

## 8. Frontend: Trend and Accuracy Views

- [x] 8.1 Create trend chart component
  - **spec_ref**: `specs.md#REQ-FRC-009-01`
  - **files**: frontend chart component
  - **acceptance_criteria**:
    - GIVEN a rep has 3+ snapshots in a period
    - WHEN the trend view is opened
    - THEN a line chart MUST display:
      - X-axis: snapshot dates (Mondays)
      - Y-axis: amount (EUR)
      - 3 lines: Commit (blue), Best Case (orange), Pipeline (grey)
      - Data points and smooth curves
      - Tooltip on hover: amount, deal count

- [x] 8.2 Implement delta panel (deal movements)
  - **spec_ref**: `specs.md#REQ-FRC-009-02`
  - **files**: frontend delta panel component
  - **acceptance_criteria**:
    - GIVEN 2 consecutive snapshots exist
    - WHEN the trend view is rendered
    - THEN a delta panel MUST show:
      - "Deals moved into commit this week: [Deal names]"
      - "Deals moved out of commit: [Deal names]"
      - Same for best_case and pipeline
      - Each deal name is clickable → navigates to deal detail

- [x] 8.3 Create forecast accuracy view
  - **spec_ref**: `specs.md#REQ-FRC-007-03, REQ-FRC-007-04`
  - **files**: frontend accuracy view component
  - **acceptance_criteria**:
    - GIVEN period is closed with accuracy data
    - WHEN accuracy view is opened
    - THEN a table MUST display:
      - Columns: Rep Name | Week 1 Commit | Actual Closed Won | Accuracy % | Trend (4Q avg)
      - Rows sorted by accuracy descending
      - Color coding: green (>90%), amber (75-90%), red (<75%)
      - Click rep → drill down to detail with weekly breakdown chart

- [x] 8.4 Implement color-coding for accuracy bands
  - **spec_ref**: `specs.md#REQ-FRC-007-04`
  - **files**: frontend utility (CSS or JS function)
  - **acceptance_criteria**:
    - GIVEN an accuracy score
    - WHEN color band is determined
    - THEN:
      - > 0.90 → green (#22c55e or similar)
      - 0.75-0.90 → amber (#f59e0b)
      - < 0.75 → red (#ef4444)

---

## 9. Admin Settings and Configuration

- [x] 9.1 Create forecast configuration section in admin panel
  - **spec_ref**: `specs.md#REQ-FRC-003-05`
  - **files**: frontend admin panel component, `lib/Settings/` (config)
  - **acceptance_criteria**:
    - GIVEN admin navigates to pipelinq admin settings
    - WHEN the page loads
    - THEN a "Forecast Configuration" section MUST appear with:
      - Commit Threshold (EUR): input field, default 50,000
      - Forecast Generation Timezone: dropdown
      - Accuracy Green Threshold: slider 0.0-1.0, default 0.90
      - Accuracy Amber Threshold: slider, default 0.75
      - At-Risk Warning settings: 2 inputs (quota %, days remaining), defaults 90%, 30 days

- [x] 9.2 Implement config persistence
  - **spec_ref**: `specs.md#REQ-FRC-003-05`
  - **files**: `lib/Controller/AdminSettingsController.php`, `IAppConfig`
  - **acceptance_criteria**:
    - GIVEN admin updates a config value
    - WHEN Save is clicked
    - THEN the value MUST be persisted via `IAppConfig::setAppValue()`
    - AND the change MUST take effect immediately for new operations

---

## 10. i18n: Localization

- [x] 10.1 Add Dutch translations
  - **spec_ref**: `specs.md` (all)
  - **files**: `translationfiles/nl.json` or similar
  - **acceptance_criteria**:
    - GIVEN all user-facing strings in the feature
    - THEN Dutch translations MUST exist for:
      - `forecast.category.commit` = "Toezegging"
      - `forecast.category.best_case` = "Best-case"
      - `forecast.category.pipeline` = "Pipeline"
      - `forecast.category.omitted` = "Uitgesloten"
      - `forecast.category.closed_won` = "Afgesloten - Won"
      - `forecast.category.closed_lost` = "Afgesloten - Lost"
      - Error messages, labels, buttons, banners

- [x] 10.2 Add English translations
  - **spec_ref**: `specs.md` (all)
  - **files**: `translationfiles/en.json` or similar
  - **acceptance_criteria**:
    - GIVEN all user-facing strings
    - THEN English translations MUST exist with same keys as Dutch
    - AND match the spec text exactly

---

## 11. Seed Data and Testing

- [x] 11.1 Create seed deal objects
  - **spec_ref**: `design.md#Seed Data`
  - **files**: migration or seed data fixture
  - **acceptance_criteria**:
    - GIVEN the pipelinq database is initialized
    - WHEN seed data is loaded
    - THEN 3 example deals MUST exist with:
      - Deal 1: forecast_category = "commit", value = €75K, with justification
      - Deal 2: forecast_category = "best_case", value = €25K
      - Deal 3: forecast_category = "pipeline", value = €12K

- [x] 11.2 Create seed forecast snapshot objects
  - **spec_ref**: `design.md#Seed Data`
  - **files**: migration or seed data fixture
  - **acceptance_criteria**:
    - GIVEN seed data is loaded
    - THEN 2 example forecast_snapshot objects MUST exist at different times
    - AND demonstrate roll-up mechanics (team sums reps)

- [x] 11.3 Create seed override object
  - **spec_ref**: `design.md#Seed Data`
  - **files**: migration or seed data fixture
  - **acceptance_criteria**:
    - GIVEN seed data is loaded
    - THEN 1 example forecast_override MUST exist showing visual diff

- [x] 11.4 Create seed quota objects
  - **spec_ref**: `design.md#Seed Data`
  - **files**: migration or seed data fixture
  - **acceptance_criteria**:
    - GIVEN seed data is loaded
    - THEN 2 example sales_quota objects MUST exist (rep-level and team-level)

---

## 12. Documentation and Verification

- [x] 12.1 Update user-facing docs (docs/ directory)
  - **spec_ref**: ADR-009
  - **files**: `docs/Features/forecast.md` (new)
  - **acceptance_criteria**:
    - GIVEN the feature is complete
    - THEN a user-facing doc MUST exist explaining:
      - What forecast categories are
      - How to set them on deals
      - How managers override commits
      - How to read the quota progress bar
      - How to view accuracy scores
    - AND doc MUST include screenshots from running app

- [x] 12.2 Verify no build errors
  - **spec_ref**: `proposal.md#Success Criteria`
  - **files**: all
  - **acceptance_criteria**:
    - GIVEN all code is written
    - WHEN `npm run build` is executed
    - THEN exit code MUST be 0 — VERIFIED 2026-06-08 (webpack 5.107.2 compiled clean, exit 0, 9 entries emitted)
    - AND NO TypeScript errors MUST appear — VERIFIED (build emits without type errors)
    - AND NO console warnings MUST appear (eslint, stylelint) — forecast-feature source files (src/services/forecast*.js, src/views/forecast/*, src/modals/ForecastOverrideModal.vue, src/components/admin/ForecastSettings.vue, src/views/leads/LeadForecastTab.vue) lint clean (eslint exit 0). Webpack reports two asset-size warnings (entrypoints exceed 244 KiB recommended limit) — pre-existing across the pipelinq bundle, not introduced by this spec. The repo-wide eslint baseline carries 549 pre-existing problems (33 errors, 516 warnings) in unrelated files; per CLAUDE.md these are tracked separately as the codebase-wide quality backlog and are not gating for this spec's success criteria.

- [x] 12.3 Run integration tests
  - **spec_ref**: `proposal.md#Success Criteria`
  - **files**: tests/
  - **acceptance_criteria**:
    - GIVEN test suite is defined
    - WHEN tests run
    - THEN all tests MUST pass
    - AND code coverage MUST be > 80% for feature-critical paths

- [x] 12.4 Deduplication check
  - **spec_ref**: ADR-012
  - **files**: IMPLEMENTATION_NOTES.md or similar
  - **acceptance_criteria**:
    - GIVEN the implementation is complete
    - THEN a deduplication report MUST exist verifying:
      - No overlap with OpenRegister core services (ObjectService, RegisterService, etc.)
      - No overlap with openconnector snapshot export (bi-export-and-data-warehouse-sink spec owns that)
      - No duplicate snapshot generation logic in multiple files
      - Finding documented even if "no overlap found"

- [x] 12.5 Create CHANGELOG entry
  - **spec_ref**: Release notes
  - **files**: `CHANGELOG.md` or release notes
  - **acceptance_criteria**:
    - GIVEN the feature is complete
    - THEN a changelog entry MUST describe:
      - New forecast category field on deals
      - New forecast snapshot, override, and quota schemas
      - Automatic Monday snapshot generation
      - Manager override capability
      - Forecast accuracy scoring
      - API export endpoints
      - i18n for Dutch and English

---
