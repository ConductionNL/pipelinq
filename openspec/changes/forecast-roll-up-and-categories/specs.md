---
status: draft
---

# Specs: forecast-roll-up-and-categories

**Feature tier**: MVP
**Spec refs**: `openspec/changes/forecast-roll-up-and-categories/design.md`
**Standards**: OpenRegister CRUD API, ADR-001 (international-first), ADR-012 (deduplication), ISO 4217 (currency), RFC 3339 (timestamps)

---

## REQ-FRC-001: Forecast category field on deal

The `deal` entity gains a `forecast_category` field with six allowed values. The field has a default value and enforces read-only state once a deal is closed.

**Feature tier**: MVP
**Spec ref**: `openspec/changes/forecast-roll-up-and-categories/design.md#Extended Schema: deal`
**Files**: `lib/Settings/pipelinq_register.json`, `lib/Listener/DealCreatedListener.php`

### Scenario REQ-FRC-001-01: New deal defaults to pipeline category

- GIVEN a user creates a new deal "ABC Corp License Renewal"
- WHEN the deal is saved to OpenRegister for the first time
- THEN `DealCreatedListener` MUST fire
- AND `deal.forecast_category` MUST be set to `"pipeline"`
- AND the deal MUST be persisted with this value

### Scenario REQ-FRC-001-02: Selector visible in deal detail view

- GIVEN a deal exists in the pipeline
- WHEN the rep opens the deal detail view
- THEN a forecast category selector MUST be visible with six options:
  - "Toezegging" (commit)
  - "Best-case"
  - "Pipeline"
  - "Omitted"
  - "Closed Won"
  - "Closed Lost"
- AND the current value MUST be selected
- AND the selector MUST be a dropdown or button group

### Scenario REQ-FRC-001-03: Category change persists to OpenRegister

- GIVEN a deal is open with forecast_category = "pipeline"
- WHEN the rep changes the selector to "commit"
- THEN the update MUST be persisted to OpenRegister immediately
- AND an audit log entry MUST be created with: old value "pipeline", new value "commit", who changed it, timestamp

### Scenario REQ-FRC-001-04: Audit log visible on deal

- GIVEN a deal has had its forecast_category changed at least once
- WHEN the rep views the deal detail
- THEN a "Category History" section MUST show:
  - Timestamp of each change
  - Old and new values
  - Who made the change

---

## REQ-FRC-002: Category transition rules for closed deals

Once a deal is marked `closed_won` or `closed_lost`, the forecast_category is locked and cannot be changed except by reopening the deal.

**Feature tier**: MVP
**Spec ref**: `openspec/changes/forecast-roll-up-and-categories/design.md#DealUpdatedListener`
**Files**: `lib/Listener/DealUpdatedListener.php`

### Scenario REQ-FRC-002-01: Reject category change on closed deal

- GIVEN a deal has forecast_category = "closed_won"
- WHEN any user attempts to change the category to "commit" (or any other open category)
- THEN the system MUST reject the change with error message:
  - Dutch: "Dit is een afgesloten deal. U kunt de categorie alleen wijzigen na heropening."
  - English: "This deal is closed. Reopen the deal first."
- AND no change MUST be persisted

### Scenario REQ-FRC-002-02: Closed deal selector is disabled

- GIVEN a deal is closed_won or closed_lost
- WHEN the deal detail view is rendered
- THEN the forecast category selector MUST be disabled (greyed out)
- AND a lock icon MUST appear next to it
- AND a tooltip MUST explain: "Reopen the deal to change the forecast category"

### Scenario REQ-FRC-002-03: Reopening deal resets category

- GIVEN a closed_won deal with forecast_category = "closed_won"
- WHEN the deal is reopened (stage changed back to an open stage)
- THEN forecast_category MUST be reset to "pipeline"
- AND an audit log entry MUST record this reset

---

## REQ-FRC-003: Commit category requires confidence justification

For deals with `forecast_category = "commit"` and value exceeding the org-configured commit threshold (default €50K), a free-text justification must be provided and stored.

**Feature tier**: MVP
**Spec ref**: `openspec/changes/forecast-roll-up-and-categories/design.md#DealUpdatedListener`
**Files**: `lib/Listener/DealUpdatedListener.php`, frontend deal detail view

### Scenario REQ-FRC-003-01: Justification prompted for large commit

- GIVEN a rep is setting a deal's forecast_category to "commit"
- AND the deal value is €65,000
- AND the org-configured commit threshold is €50,000 (default)
- WHEN the rep saves the category change
- THEN a modal dialog MUST appear:
  - Title: "Justification required for large commitment"
  - Text: "Why are you confident in this €65K+ deal? (e.g., decision-maker engaged, contract draft signed)"
  - Input field: required, minimum 10 characters
- AND the save MUST NOT complete until the field is filled

### Scenario REQ-FRC-003-02: Justification stored on deal

- GIVEN a rep fills in the justification modal with "Bakker BV CFO signed contract draft. Close date April 30 locked."
- WHEN the modal closes
- THEN `deal.commit_justification` MUST be set to this text
- AND the deal MUST be persisted

### Scenario REQ-FRC-003-03: Justification visible to manager

- GIVEN a deal has `forecast_category = "commit"` and a `commit_justification`
- WHEN the manager reviews the rep's forecast
- THEN the justification MUST appear in the deal detail or in a "rep notes" column in the forecast view

### Scenario REQ-FRC-003-04: Justification not required below threshold

- GIVEN a deal has value €45,000 and commit threshold is €50,000
- WHEN the rep sets forecast_category to "commit"
- THEN NO justification modal MUST appear
- AND the change MUST be saved immediately

### Scenario REQ-FRC-003-05: Admin can configure threshold

- GIVEN the pipelinq admin settings page is open
- WHEN the admin navigates to "Forecast Configuration"
- THEN a field "Commit Threshold (EUR)" MUST be visible
- AND the admin can change it from default 50,000 to e.g., 75,000
- AND the change MUST be saved to app config

---

## REQ-FRC-004: Weekly snapshot generation

Every Monday at 06:00 UTC (org-configurable), an automated job generates immutable forecast snapshots for every active rep, team, division, and the whole company for the currently-open fiscal period.

**Feature tier**: MVP
**Spec ref**: `openspec/changes/forecast-roll-up-and-categories/design.md#ForecastSnapshotJob`
**Files**: `lib/Job/ForecastSnapshotJob.php`, OpenRegister cron-trigger config

### Scenario REQ-FRC-004-01: Snapshot generated at scheduled time

- GIVEN it is Monday 2026-05-20 06:00 UTC
- WHEN the scheduled job fires
- THEN `ForecastSnapshotJob` MUST run
- AND it MUST complete within 5 minutes for typical org size (< 500 deals)

### Scenario REQ-FRC-004-02: Rep-level snapshot captures deal state

- GIVEN rep "john.doe" has 3 deals:
  - Deal A: forecast_category = "commit", value = €75K
  - Deal B: forecast_category = "best_case", value = €25K
  - Deal C: forecast_category = "pipeline", value = €12K
- WHEN the snapshot job runs
- THEN a `forecast_snapshot` MUST be created with:
  - `owner_id = "john.doe"`
  - `level = "rep"`
  - `period_id = "Q2-2026"` (currently open period)
  - `as_of_date = "2026-05-20"`
  - `commit_amount = 75000`
  - `best_case_amount = 25000`
  - `pipeline_amount = 12000`
  - `closed_won_amount = <sum of all closed_won deals>`
  - `deal_snapshot_ids = [deal-A-uuid, deal-B-uuid, deal-C-uuid]`

### Scenario REQ-FRC-004-03: Closed deals excluded, omitted deals excluded

- GIVEN a rep has 4 deals:
  - Deal A: forecast_category = "commit", value = €50K
  - Deal B: forecast_category = "closed_won", value = €30K (closed deal)
  - Deal C: forecast_category = "omitted", value = €20K (deliberately excluded)
  - Deal D: forecast_category = "pipeline", value = €10K
- WHEN the snapshot job runs
- THEN snapshot MUST include:
  - Deal A (commit): counted toward commit_amount
  - Deal B (closed_won): counted toward closed_won_amount but NOT toward commit/best_case/pipeline
  - Deal C (omitted): NOT counted in any category
  - Deal D (pipeline): counted toward pipeline_amount

### Scenario REQ-FRC-004-04: Team snapshot sums rep snapshots

- GIVEN team "Sales East" has 2 reps:
  - Rep 1 commit_amount = €100K
  - Rep 2 commit_amount = €150K
- WHEN the snapshot job creates the team snapshot
- THEN snapshot.commit_amount MUST = 250,000 (sum of reps)

### Scenario REQ-FRC-004-05: Currency normalization in roll-up

- GIVEN rep 1 has deals in EUR, rep 2 has deals in GBP
- WHEN the team snapshot is generated
- THEN all amounts MUST be converted to the org's reporting currency (default EUR) using org-configured exchange rate source (default ECB)
- AND the `currency` field in the snapshot MUST reflect the reporting currency

### Scenario REQ-FRC-004-06: Partial flag and missing reps list

- GIVEN team "Sales East" has 3 reps (A, B, C)
- AND rep C's snapshot failed to generate (e.g., data error)
- WHEN the team snapshot is created
- THEN snapshot MUST have:
  - `partial = true`
  - `missing_reps = ["rep.c", "Rep C"]`
- AND the team snapshot MUST still be created (not blocked by the missing rep)

### Scenario REQ-FRC-004-07: Admin notified on job failure

- GIVEN the snapshot job encounters an error for a specific level
- WHEN the job continues and completes
- THEN the pipelinq admin MUST receive a Nextcloud notification:
  - Title: "Forecast Snapshot Job — Partial Failure"
  - Body: "Team snapshot for Sales East failed: [error details]. Rep snapshots were created successfully."

### Scenario REQ-FRC-004-08: On-demand snapshot on request

- GIVEN a user is in the forecast view
- WHEN the user clicks a "Generate snapshot now" button
- THEN `ForecastSnapshotJob` MUST run immediately for the current period
- AND new snapshots MUST be created
- AND the view MUST refresh to show the new data

---

## REQ-FRC-005: Hierarchical roll-up

Team snapshots sum rep snapshots; division snapshots sum team snapshots; company snapshots sum division snapshots. The same rule applies for all forecast categories.

**Feature tier**: MVP
**Spec ref**: `openspec/changes/forecast-roll-up-and-categories/design.md#ForecastSnapshotJob, ForecastService`
**Files**: `lib/Job/ForecastSnapshotJob.php`, `lib/Service/ForecastService.php`

### Scenario REQ-FRC-005-01: Team commit equals sum of rep commits

- GIVEN team "Sales East" has 2 reps:
  - Rep A: commit = €200K, best_case = €300K, pipeline = €400K, closed_won = €50K
  - Rep B: commit = €150K, best_case = €250K, pipeline = €350K, closed_won = €100K
- WHEN the team snapshot is generated
- THEN:
  - team.commit_amount = 350K (200 + 150)
  - team.best_case_amount = 550K (300 + 250)
  - team.pipeline_amount = 750K (400 + 350)
  - team.closed_won_amount = 150K (50 + 100)

### Scenario REQ-FRC-005-02: Division roll-up same rule

- GIVEN division "North" has 2 teams:
  - Team 1: commit = €400K
  - Team 2: commit = €350K
- WHEN the division snapshot is generated
- THEN division.commit_amount = 750K (400 + 350)

### Scenario REQ-FRC-005-03: Company roll-up same rule

- GIVEN company has 2 divisions:
  - Division A: commit = €750K
  - Division B: commit = €600K
- WHEN the company snapshot is generated
- THEN company.commit_amount = 1,350K (750 + 600)

### Scenario REQ-FRC-005-04: Missing lower-level snapshot marks partial

- GIVEN division "North" has 2 teams (Team A, Team B)
- AND Team B's snapshot failed to generate
- WHEN the division snapshot is created
- THEN division snapshot MUST have `partial = true` and `missing_reps = ["Team B"]`
- AND the division snapshot MUST use only Team A's data in the roll-up

---

## REQ-FRC-006: Manager override at any level

A sales manager can override the calculated commit or best_case amount at any level (rep, team, division) with a reason. The original amount remains visible. Overrides cascade upward.

**Feature tier**: MVP
**Spec ref**: `openspec/changes/forecast-roll-up-and-categories/design.md#ForecastOverride, computeRollUp`
**Files**: `lib/Controller/ForecastController.php`, `lib/Service/ForecastService.php`, frontend forecast view

### Scenario REQ-FRC-006-01: Manager creates override for rep

- GIVEN a rep "john.doe" has a commit amount of €75K
- AND the manager "alice.manager" is reviewing the forecast
- WHEN the manager clicks an "Override" button next to john.doe's commit
- THEN a form MUST appear:
  - Input 1: "Override amount" (default: current amount)
  - Input 2: "Reason for override" (required, free text)
  - Buttons: Save, Cancel
- WHEN the manager enters €60K and reason "Bakker deal execution risk — reducing commit"
- AND clicks Save
- THEN a `forecast_override` MUST be created with:
  - `override_owner_id = "john.doe"`
  - `override_amount = 60000`
  - `original_amount = 75000`
  - `reason = "Bakker deal execution risk — reducing commit"`
  - `created_by = "alice.manager"`

### Scenario REQ-FRC-006-02: Original amount visible alongside override

- GIVEN an override exists for rep john.doe's commit (€75K → €60K)
- WHEN the manager views the forecast
- THEN the UI MUST show:
  - "Rep submitted: €75,000" (greyed out or smaller text)
  - "Manager commit: €60,000" (highlighted)
  - "Manager note: Bakker deal execution risk — reducing commit" (in sidebar or tooltip)
- AND a visual diff MUST indicate the discrepancy (e.g., red down arrow)

### Scenario REQ-FRC-006-03: Override cascades to team roll-up

- GIVEN:
  - Rep A commit (no override) = €200K
  - Rep B commit (with override) = €150K (original €175K)
- WHEN the team snapshot uses these values for roll-up
- THEN team.commit_amount MUST = 350K (200 + 150 override value, not original)

### Scenario REQ-FRC-006-04: VP can override division commit

- GIVEN division "North" has a team commit of €750K
- AND the VP "bob.vp" is reviewing division forecasts
- WHEN the VP creates an override for division "North" to €700K with reason "Macro headwinds"
- THEN the override MUST be created with `level = "division"`
- AND subsequent company roll-up MUST use €700K, not €750K

### Scenario REQ-FRC-006-05: Override does not modify underlying data

- GIVEN an override changes rep commit from €75K to €60K
- WHEN the override is viewed
- THEN the underlying deals on the rep's account MUST still show total commit = €75K
- AND ONLY the snapshot/roll-up computation MUST apply the override

### Scenario REQ-FRC-006-06: Delete override reverts to calculated amount

- GIVEN an override exists changing commit from €75K to €60K
- WHEN the manager deletes the override
- THEN subsequent roll-ups MUST use €75K again
- AND an audit log MUST record the deletion

---

## REQ-FRC-007: Forecast accuracy scoring

Once a fiscal period closes, the system computes a `forecast_accuracy_score` for each rep/team/division by comparing the commit snapshot from week 1 of the period to the actual closed_won amount.

**Feature tier**: MVP
**Spec ref**: `openspec/changes/forecast-roll-up-and-categories/design.md#computeAccuracyScore`
**Files**: `lib/Service/ForecastService.php`, frontend accuracy view

### Scenario REQ-FRC-007-01: Accuracy computed for closed period

- GIVEN period "Q2 2026" has closed (as_of_date >= 2026-06-30 + 1 day)
- AND week-1 snapshot (2026-04-01) shows rep john.doe commit = €100K
- AND actual closed_won for Q2 = €95K
- WHEN accuracy is computed
- THEN accuracy_score MUST = 1 - abs(100 - 95) / 95 = 1 - 5/95 = 0.947 (rounded)
- AND color band MUST be green (> 0.90)

### Scenario REQ-FRC-007-02: Low accuracy scored red

- GIVEN week-1 commit = €100K, actual closed_won = €60K
- WHEN accuracy is computed
- THEN score = 1 - abs(100 - 60) / 60 = 1 - 40/60 = 0.333
- AND color band MUST be red (< 0.75)

### Scenario REQ-FRC-007-03: Accuracy view displays scores

- GIVEN period Q2 2026 has closed
- WHEN the user opens the "Forecast Accuracy" view
- THEN a table MUST show:
  - Column: Rep Name | Week 1 Commit | Actual Closed Won | Accuracy | Trend (4Q avg)
  - Row for john.doe: | €100K | €95K | 94.7% ✓ (green) | 92% ↓
  - Sort by accuracy desc
- AND clicking a rep MUST show week-by-week trend chart

### Scenario REQ-FRC-007-04: Team accuracy is average of rep accuracies

- GIVEN team "Sales East" has 2 reps:
  - Rep A accuracy: 0.95
  - Rep B accuracy: 0.85
- WHEN team accuracy is computed
- THEN team_accuracy = (0.95 + 0.85) / 2 = 0.90
- AND color band MUST be green

### Scenario REQ-FRC-007-05: Trailing four quarters average

- GIVEN a rep has accuracy scores for last 4 closed periods:
  - Q1 2026: 0.92
  - Q4 2025: 0.88
  - Q3 2025: 0.85
  - Q2 2025: 0.90
- WHEN trailing-4Q average is computed
- THEN average = (0.92 + 0.88 + 0.85 + 0.90) / 4 = 0.8875 (rounded 89%)
- AND displayed in "Trend (4Q avg)" column as "89% ↑" (with direction indicator)

---

## REQ-FRC-008: Quota attainment display

The forecast view displays quota, closed_won, commit, gap-to-close, and a stacked progress bar. An at-risk warning appears if projected attainment falls below 90% of quota with less than 30 days remaining.

**Feature tier**: MVP
**Spec ref**: `openspec/changes/forecast-roll-up-and-categories/design.md#Manager Forecast View, QuotaService`
**Files**: `lib/Service/QuotaService.php`, frontend forecast view

### Scenario REQ-FRC-008-01: Quota, closed_won, commit visible

- GIVEN rep john.doe has:
  - Quota for Q2 2026: €150K
  - Closed won (as of 2026-05-20): €50K
  - Commit (from snapshot): €75K
  - Best case: €25K
- WHEN the forecast view is rendered
- THEN the top of the page MUST show:
  - "Quota: €150,000"
  - "Closed Won: €50,000 (33%)"
  - "Committed: €75,000"
  - "Gap to close: €25,000 (17%)"

### Scenario REQ-FRC-008-02: Progress bar stacked visualization

- GIVEN the above amounts
- WHEN the progress bar is rendered
- THEN it MUST show:
  - Solid green fill for €50K (closed_won)
  - Hatched blue fill for €75K (commit)
  - Light grey for remaining €25K to quota
  - Total width representing €150K quota line
  - Label: "125K of 150K on track (83%)"

### Scenario REQ-FRC-008-03: Projected attainment calculation

- GIVEN:
  - Closed won: €50K
  - Commit: €75K
  - Best case: €25K
  - Quota: €150K
- WHEN projected attainment is calculated
- THEN projected = 50 + 75 + 0.5×25 = 50 + 75 + 12.5 = €137.5K
- AND attainment percent = 137.5 / 150 = 91.7% (above 90% threshold, no warning)

### Scenario REQ-FRC-008-04: At-risk warning appears

- GIVEN:
  - Quota: €150K
  - Projected attainment: €130K (86.7%, below 90%)
  - Days remaining in quarter: 20 (below 30)
- WHEN the forecast view is rendered
- THEN a warning banner MUST appear:
  - Background: orange or red
  - Text: "This team is 13% below quota with 20 days to close. Action recommended."
  - Icon: exclamation mark

### Scenario REQ-FRC-008-05: No warning if quota exceeded

- GIVEN projected attainment = €160K (above quota)
- WHEN the forecast view is rendered
- THEN NO warning MUST appear (even if days remaining < 30)

### Scenario REQ-FRC-008-06: No warning if days remaining >= 30

- GIVEN projected attainment = €130K (below 90%)
- AND days remaining = 35 (above 30-day threshold)
- WHEN the forecast view is rendered
- THEN NO warning MUST appear

### Scenario REQ-FRC-008-07: Team/division quota same rules apply

- GIVEN a team has team-level quota and team-level commit
- WHEN the team forecast view is rendered
- THEN the same quota attainment logic MUST apply at team level

---

## REQ-FRC-009: Snapshot comparison and trend

When at least two snapshots exist for a given owner within a period, a line chart shows commit/best_case/pipeline over time, and a delta panel shows which deals moved in or out of each category.

**Feature tier**: MVP
**Spec ref**: `openspec/changes/forecast-roll-up-and-categories/design.md#Frontend - Trend Chart`
**Files**: frontend trend view, `lib/Service/ForecastService.php`

### Scenario REQ-FRC-009-01: Trend chart rendered

- GIVEN rep john.doe has snapshots for:
  - 2026-05-06: commit = €50K, best_case = €25K, pipeline = €100K
  - 2026-05-13: commit = €75K, best_case = €30K, pipeline = €90K
  - 2026-05-20: commit = €75K, best_case = €25K, pipeline = €120K
- WHEN the trend view is opened for john.doe in Q2 2026
- THEN a line chart MUST display:
  - X-axis: snapshot dates (May 6, May 13, May 20)
  - Y-axis: amount (EUR)
  - 3 lines: Commit (blue), Best Case (orange), Pipeline (grey)
  - Data points connected with smooth curves
  - On hover: tooltip showing exact amount and deal count

### Scenario REQ-FRC-009-02: Delta panel shows deal movements

- GIVEN:
  - Snapshot 2026-05-13 vs 2026-05-20
  - Deal A: commit → best_case (moved out of commit, into best_case)
  - Deal B: pipeline → commit (moved into commit)
  - Deal C: best_case → omitted (moved out of best_case)
- WHEN the delta panel is rendered
- THEN it MUST show:
  - "Deals moved into commit (this week): Deal B"
  - "Deals moved out of commit (this week): Deal A"
  - "Deals moved into best_case: Deal A"
  - "Deals moved out of best_case: Deal C"
  - "New deals added: none"
  - Each deal name is clickable → navigates to deal detail

### Scenario REQ-FRC-009-03: Delta calculated week-over-week

- GIVEN snapshots exist for every Monday in a 4-week period
- WHEN the trend view is rendered
- THEN delta MUST be computed for each consecutive week pair
- AND tabs or a dropdown MUST allow comparing "Week of May 20 vs May 13" vs "Week of May 13 vs May 6", etc.

---

## REQ-FRC-010: API export for board reporting

An authorized user can export snapshots and apply overrides via a REST API. The export supports JSON and CSV formats and includes a calculation audit trail.

**Feature tier**: MVP
**Spec ref**: `openspec/changes/forecast-roll-up-and-categories/design.md#ForecastController`
**Files**: `lib/Controller/ForecastController.php`

### Scenario REQ-FRC-010-01: JSON export endpoint

- GIVEN the user is authorized with `forecast:read` permission
- WHEN they call `GET /api/forecast/snapshots?period_id=Q2-2026&level=division`
- THEN the response MUST return:
  ```json
  {
    "snapshots": [
      {
        "id": "snap-uuid-1",
        "owner_id": "division-north",
        "as_of_date": "2026-05-20",
        "level": "division",
        "commit": 750000,
        "best_case": 1200000,
        "pipeline": 2000000,
        "closed_won": 300000,
        "quota": 800000,
        "calculation_audit": [
          {
            "source_id": "team-east",
            "source_name": "Sales East",
            "commit": 350000,
            "override_amount": null,
            "override_reason": null
          },
          {
            "source_id": "team-west",
            "source_name": "Sales West",
            "commit": 400000,
            "override_amount": 380000,
            "override_reason": "Market conditions"
          }
        ]
      }
    ]
  }
  ```

### Scenario REQ-FRC-010-02: CSV export format

- GIVEN the user calls the same endpoint with `&format=csv`
- WHEN the response is generated
- THEN CSV MUST contain:
  - Header row: as_of_date, owner_id, level, commit, best_case, pipeline, closed_won, quota
  - Data rows: one per snapshot
  - Separate CSV tab or section for calculation_audit rows

### Scenario REQ-FRC-010-03: Permission scoped to requester hierarchy

- GIVEN a rep john.doe calls `GET /api/forecast/snapshots?period_id=Q2-2026&level=company`
- WHEN the request is received
- THEN if john.doe lacks `forecast:read` permission at the company level
- THEN the API MUST return 403 Forbidden
- AND allow john.doe to query only their own snapshot or their team's (if they have `forecast:read` at team level)

### Scenario REQ-FRC-010-04: Query by owner_id filter

- GIVEN the user calls `GET /api/forecast/snapshots?period_id=Q2-2026&level=rep&owner_id=john.doe`
- THEN the API MUST return snapshots for john.doe only
- AND omit other reps

### Scenario REQ-FRC-010-05: Multiple snapshots per owner per period

- GIVEN period Q2 2026 has 13 Monday snapshots
- WHEN the user calls the endpoint for all reps
- THEN the response MUST include all snapshots (13 × number of reps)
- AND support pagination with `limit` and `offset` query params

---

## REQ-FRC-011: Permissions and access control

Forecast-related actions require specific permissions, scoped to the requester's hierarchy.

**Feature tier**: MVP
**Spec ref**: `openspec/changes/forecast-roll-up-and-categories/design.md#Backend`
**Files**: `lib/Controller/ForecastController.php`, OpenRegister permission config

### Scenario REQ-FRC-011-01: forecast:read permission

- GIVEN a user has `forecast:read` scoped to their team
- WHEN they open the forecast view
- THEN they can see snapshots for their team and reps below them
- AND they cannot see other teams' data
- AND the API enforces this at each request

### Scenario REQ-FRC-011-02: forecast:override permission

- GIVEN a user has `forecast:override` scoped to team level
- WHEN they create an override for a rep
- THEN the override MUST be created successfully
- AND if they attempt to override a division (above their scope), they MUST receive 403 Forbidden

### Scenario REQ-FRC-011-03: forecast:quota:set permission

- GIVEN a user has `forecast:quota:set` scoped to division level
- WHEN they create or edit a quota
- THEN the quota MUST be created/updated
- AND if they attempt to set a company-level quota, they MUST receive 403 Forbidden

---
