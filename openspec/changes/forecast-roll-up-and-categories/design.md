# Design: forecast-roll-up-and-categories

## Architecture

### Data Layer

#### Extended Schema: `deal` (from `pipeline`)

One new property is added to the existing `deal` entity from the `pipeline` spec:

| Property | Type | Required | Description |
|---|---|---|---|
| `forecast_category` | string | No | Forecast confidence category: `commit`, `best_case`, `pipeline`, `closed_won`, `closed_lost`, or `omitted`. Default: `pipeline`. |
| `commit_justification` | string | No | Free-text justification for `commit` category if deal value > commit threshold. Visible to manager. |

State transitions:
- New deal → `forecast_category = "pipeline"`
- Rep can change to `commit`, `best_case`, `pipeline`, or `omitted` at any time (unless deal is closed)
- Deal reaches `closed_won` or `closed_lost` → `forecast_category` is automatically set; user cannot change it back (must reopen deal at stage level first)
- Reopening a closed deal → `forecast_category` resets to `pipeline`

**Schema.org mapping**: `forecast_category` is a pipelinq-specific confidence classification with no schema.org equivalent; stored as implementation detail, not exposed in Dutch API mapping layer.

OpenRegister built-in fields on `deal` (do NOT redefine): `id`, `uuid`, `uri`, `version`, `createdAt`, `updatedAt`, `owner`, `organization`, `register`, `schema`, `relations`, `files`, `auditTrail`, `notes`, `tasks`, `tags`, `status`, `locked`.

---

#### New Schema: `forecast_snapshot`

Captures the state of forecasts at a point in time. Generated automatically every Monday 06:00 and on-demand. Immutable (once created, never modified).

| Property | Type | Required | Description |
|---|---|---|---|
| `period_id` | string | Yes | UUID reference to the fiscal period (e.g., Q2 2026) |
| `as_of_date` | string | Yes | ISO 8601 date when the snapshot was taken |
| `owner_id` | string | Yes | Nextcloud user UID (for rep) or team/division/company identifier |
| `level` | string | Yes | Hierarchy level: `rep`, `team`, `division`, or `company` |
| `commit_amount` | number | Yes | Total commit forecast in the org's reporting currency |
| `best_case_amount` | number | Yes | Total best_case forecast |
| `pipeline_amount` | number | Yes | Total pipeline forecast |
| `closed_won_amount` | number | Yes | Total closed_won amount (cumulative for the period) |
| `quota_amount` | number | No | Quota for this owner/period (null if no quota set) |
| `deal_snapshot_ids` | array | No | UUID references to contributing `deal` objects (for audit trail) |
| `partial` | boolean | No | If true, some rep/team snapshots were missing when this level's snapshot was generated |
| `missing_reps` | array | No | List of rep names/UIDs that were missing if `partial = true` |

Computed properties (not stored):
- `projected_attainment` = closed_won + commit + 0.5 × best_case
- `gap_to_quota` = max(0, quota - projected_attainment)

**Index**: `(period_id, owner_id, level, as_of_date)` for fast queries by period and owner.

---

#### New Schema: `forecast_override`

Represents a manager-supplied number that replaces the calculated roll-up at a given level. Immutable audit trail.

| Property | Type | Required | Description |
|---|---|---|---|
| `period_id` | string | Yes | UUID reference to the fiscal period |
| `owner_id` | string | Yes | Nextcloud user UID (for rep) or team/division identifier of the PERSON OVERRIDING (the manager) |
| `override_owner_id` | string | Yes | Nextcloud user UID or team ID of the entity BEING OVERRIDDEN (the rep or subordinate team) |
| `level` | string | Yes | Level at which the override applies: `rep`, `team`, or `division` |
| `category` | string | Yes | Category overridden: `commit` or `best_case` |
| `override_amount` | number | Yes | The manager-supplied amount (in reporting currency) |
| `original_amount` | number | Yes | The calculated amount before override (for visual diff) |
| `reason` | string | Yes | Manager's justification for the override |
| `created_by` | string | Yes | Nextcloud user UID of the manager who created the override |
| `created_at` | string | Yes | ISO timestamp when override was created |

**Index**: `(period_id, override_owner_id, level, category)` for fast lookup when rendering roll-ups.

---

#### New Schema: `sales_quota`

Defines quota targets per owner and period.

| Property | Type | Required | Description |
|---|---|---|---|
| `owner_id` | string | Yes | Nextcloud user UID (for rep) or team/division identifier |
| `period_id` | string | Yes | UUID reference to fiscal period (e.g., Q2 2026) |
| `level` | string | Yes | Hierarchy level: `rep`, `team`, or `division` |
| `quota_amount` | number | Yes | Target amount in the org's reporting currency |
| `currency` | string | Yes | ISO 4217 currency code (usually org's reporting currency) |
| `effective_from` | string | No | ISO date when quota becomes active |
| `effective_to` | string | No | ISO date when quota expires |
| `set_by` | string | Yes | Nextcloud user UID of the person who set this quota |
| `created_at` | string | Yes | ISO timestamp |

**Note**: Quota hierarchy is advisory, not enforced. The sum of rep quotas should equal team quota, but this is a data quality check, not a constraint.

**Index**: `(owner_id, period_id, level)` for fast lookup during snapshot generation.

---

### Backend

#### `lib/Listener/DealCreatedListener.php`

Implements `OCP\EventDispatcher\IEventListener`. Registered in `lib/AppInfo/Application.php` for `ObjectCreatedEvent`, filtered to `deal` schema.

Responsibilities:
1. Receive `ObjectCreatedEvent` carrying the new `deal` object
2. Set `deal.forecast_category = "pipeline"` if not already set
3. Save the updated `deal` via `ObjectService::saveObject()`
4. Write audit log entry (via built-in `auditTrail` on the object)

---

#### `lib/Listener/DealUpdatedListener.php`

Implements `OCP\EventDispatcher\IEventListener`. Registered in `lib/AppInfo/Application.php` for `ObjectUpdatedEvent`, filtered to `deal` schema.

Responsibilities:
1. Receive `ObjectUpdatedEvent` with old and new `deal` objects
2. Check if `forecast_category` changed
3. **Transition validation**: If old category is `closed_won` or `closed_lost`, reject change with error message "Cannot change category of a closed deal. Reopen the deal first."
4. **Justification validation**: If new category is `commit` and `deal.value > commit_threshold` (from admin settings), prompt UI for justification; validate that `commit_justification` is non-empty before allowing save
5. Write audit log entry (category change, who, when, old→new value)

Note: This listener runs as a pre-save hook (if framework provides) or post-save with revert. Design assumes OpenRegister event model allows rejection.

---

#### `lib/Job/ForecastSnapshotJob.php`

Scheduled via OpenRegister `cron-trigger` extension for every Monday at 06:00 UTC (org-configurable timezone).

Responsibilities:
1. Get the currently-open fiscal period (or all open periods if org has multiple calendars)
2. For each open period:
   a. Fetch all active reps/teams/divisions/company
   b. For each rep: compute commit/best_case/pipeline from `deal` objects where `forecast_category` is set (exclude `omitted` and closed deals) and `owner == rep_id`; create `forecast_snapshot` with `level=rep`
   c. For each team: sum rep snapshots (with currency normalization); if any rep snapshot is missing, set `partial=true` and list missing reps; create `forecast_snapshot` with `level=team`
   d. For each division: sum team snapshots; create `forecast_snapshot` with `level=division`
   e. For company: sum division snapshots; create `forecast_snapshot` with `level=company`
3. Write all snapshots to OpenRegister
4. On error at any level, log the error and raise an alert to pipelinq admin (via `NotificationService`) WITHOUT blocking other levels
5. Return summary: "Generated 1 rep, 1 team, 1 division, 1 company snapshot for Q2 2026"

---

#### `lib/Service/ForecastService.php`

Core computation engine for roll-ups, overrides, and accuracy.

**Method: `computeRollUp(string $owner_id, string $period_id, string $level): array`**

Returns the effective forecast after applying overrides:

```php
{
  "commit": 500000,
  "original_commit": 400000,
  "commit_override_id": "<uuid>",
  "commit_override_reason": "Manager adjustment based on deal risk review",
  "best_case": 750000,
  "original_best_case": 750000,
  "pipeline": 1200000,
  "closed_won": 200000,
  "quota": 600000,
  "deal_snapshot_ids": ["<uuid>", ...]
}
```

Pseudocode:
1. Fetch snapshot for (owner, period, level)
2. Check for `forecast_override` where (override_owner_id == owner, period == period, level == level, category == 'commit'); if exists, replace commit_amount with override_amount and include override details
3. Same for best_case override
4. Fetch quota_amount for (owner, period, level)
5. Return combined object

---

**Method: `computeAccuracyScore(string $owner_id, string $period_id, string $level): ?float`**

Once period closes, compare week-1 commit snapshot to actual closed_won.

Formula: `accuracy = 1 - abs(commit_snapshot - closed_won_actual) / closed_won_actual`

Returns null if period not closed or no data available.

Color bands:
- > 0.90 → green
- 0.75-0.90 → amber
- < 0.75 → red

---

**Method: `computeTrailingQuartersAccuracy(string $owner_id): float`**

Average of accuracy scores for the last 4 closed quarters. Returns 0.0 if fewer than 4 quarters of data exist.

---

#### `lib/Service/QuotaService.php`

Lookup and validation for quotas.

**Method: `getQuota(string $owner_id, string $period_id, string $level): ?array`**

Returns the `sales_quota` object or null if not set.

**Method: `validateQuotaHierarchy(string $period_id): array`**

Returns advisory validation report:
```php
{
  "teams": [
    {"team_id": "...", "quota": 1000000, "rep_quotas_sum": 950000, "variance_percent": -5},
    ...
  ],
  "divisions": [...]
}
```

---

#### `lib/Controller/ForecastController.php`

REST API for snapshot export and override management.

**Endpoint: `GET /api/forecast/snapshots`**

Query params:
- `period_id` (required): UUID of fiscal period
- `level` (required): one of `rep`, `team`, `division`, `company`
- `owner_id` (optional): filter to specific owner
- `format` (optional): `json` (default) or `csv`

Requires permission `forecast:read` scoped to requester's owned hierarchy.

Returns:
```json
{
  "snapshots": [
    {
      "id": "...",
      "as_of_date": "2026-05-20",
      "commit": 500000,
      "best_case": 750000,
      "pipeline": 1200000,
      "closed_won": 200000,
      "quota": 600000,
      "calculation_audit": [
        {"rep_id": "...", "name": "John Doe", "commit": 250000, "override_reason": null},
        {"team_id": "...", "name": "Sales East", "commit": 500000, "override_reason": "Manager adjustment"}
      ]
    }
  ]
}
```

CSV format: rows per snapshot, columns for all numeric fields, one row for calculation_audit.

---

**Endpoint: `POST /api/forecast/overrides`**

Create a forecast override. Requires `forecast:override` permission.

Request body:
```json
{
  "period_id": "...",
  "override_owner_id": "...",
  "level": "rep|team|division",
  "category": "commit|best_case",
  "override_amount": 500000,
  "reason": "Manager review indicates lower confidence"
}
```

Returns the created `forecast_override` object.

---

### Frontend

#### Deal Detail View

- **Forecast Category Selector**: dropdown with options (commit, best_case, pipeline, omitted)
- **Lock Indicator**: if deal is closed, show "This deal is closed. Reopen to change category." (disabled selector)
- **Justification Modal**: if user selects `commit` and value > commit_threshold:
  - Modal title: "Justification required for large commitment"
  - Text input: "Why are you confident in this €50K+ deal? (e.g., decision-maker engaged, contract draft signed)"
  - Min length: 10 characters
  - On save: update deal and close modal
- **Audit Log Section**: "Category history" showing past changes (timestamp, old→new, who changed it)

#### Manager Forecast View

Layout: hierarchical drill-down (company → division → team → rep)

**Team Summary Panel**:
- Column headers: Rep Name | Commit | Override | Original | Manager Note | Best Case | Pipeline | Quota | Status
- Rep rows:
  - Commit field: shows calculated value with "override" badge if override exists
  - Click to expand override entry row (reason text field, save/cancel buttons)
  - Visual diff (red text if override ≠ original)
  - Best Case, Pipeline: read-only
  - Quota: read-only
  - Status badge: "on_track" (closed+commit >= 90% quota), "at_risk" (< 90% and < 30 days), "overdue" (period closed)
- Team total row: calculated sum of rep values, with override if team-level override exists

**Quota Progress Bar**:
- Stacked bar: solid green for closed_won, hatched blue for commit, light for remaining quota
- Percent labels: "65% on track"
- Warning banner (if at_risk): "This team is 15% below quota with 20 days to close. Action recommended."

#### Forecast Accuracy View

**Closed Period Table**:
- Columns: Rep/Team | Week 1 Commit | Final Closed Won | Accuracy % | Trend (4Q avg)
- Rows sortable by accuracy descending
- Color coding: green (>0.9), amber (0.75-0.9), red (<0.75)
- Click rep to see week-by-week trend chart

**Trend Chart** (for individual rep, on detail page):
- X-axis: snapshot dates (each Monday)
- Y-axis: forecast amount (EUR)
- 3 lines: commit (blue), best_case (orange), pipeline (grey)
- On hover: show exact amount and deal count
- Below: delta panel
  - "Deals moved into commit": list of deal names (click to detail)
  - "Deals moved out of commit": list of deal names
  - Repeat for best_case, pipeline

#### Admin Settings

New section: "Forecast Configuration"

- **Commit Threshold (EUR)**: default 50,000 (input field)
- **Forecast Generation Timezone**: dropdown (default UTC)
- **Accuracy Green Threshold**: 0.90 (slider 0.0-1.0, step 0.05)
- **Accuracy Amber Threshold**: 0.75 (slider)
- **At-Risk Warning**: Show if quota attainment < (%) AND days remaining < (N) (defaults: 90%, 30 days)

#### i18n

Dutch and English labels for all UI elements and error messages:

**Dutch**:
- `forecast.category.commit` = "Toezegging"
- `forecast.category.best_case` = "Best-case"
- `forecast.category.pipeline` = "Pipeline"
- `forecast.category.omitted` = "Uitgesloten"
- `forecast.error.closed_deal_locked` = "Dit is een afgesloten deal. U kunt de categorie alleen wijzigen na heropening."
- `forecast.justification.required` = "Dit is een grote toezegging. Vul alstublieft een reden in."
- `forecast.override.manager_adjustment` = "Manager aanpassing"
- `forecast.warning.at_risk` = "Dit team is beneden de quota. Actie aanbevolen."

**English**: (standard translations)

---

### Seed Data

**Deal 1**:
```json
{
  "title": "Implementatie ERP - Bakker BV",
  "value": 75000,
  "forecast_category": "commit",
  "commit_justification": "Bakker BV CFO signed contract draft. Close date April 30 locked. Decision-maker confirmed budget approved.",
  "expectedCloseDate": "2026-04-30",
  "assignee": "john.doe"
}
```

**Deal 2**:
```json
{
  "title": "Upgrade licenties - Van der Berg Groep",
  "value": 25000,
  "forecast_category": "best_case",
  "expectedCloseDate": "2026-05-15",
  "assignee": "jane.smith"
}
```

**Deal 3**:
```json
{
  "title": "Feasibility study - Gemeente Arnhem",
  "value": 12000,
  "forecast_category": "pipeline",
  "expectedCloseDate": "2026-06-30",
  "assignee": "john.doe"
}
```

**Forecast Snapshot 1** (Monday 2026-05-20, rep level):
```json
{
  "period_id": "Q2-2026",
  "as_of_date": "2026-05-20",
  "owner_id": "john.doe",
  "level": "rep",
  "commit_amount": 75000,
  "best_case_amount": 25000,
  "pipeline_amount": 12000,
  "closed_won_amount": 50000,
  "quota_amount": 150000,
  "deal_snapshot_ids": ["deal-uuid-1", "deal-uuid-2", "deal-uuid-3"]
}
```

**Forecast Override 1**:
```json
{
  "period_id": "Q2-2026",
  "owner_id": "manager.alice",
  "override_owner_id": "john.doe",
  "level": "rep",
  "category": "commit",
  "override_amount": 60000,
  "original_amount": 75000,
  "reason": "Bakker BV deal has execution risk. Reducing commit based on vendor survey results.",
  "created_by": "manager.alice",
  "created_at": "2026-05-20T09:15:00Z"
}
```

**Sales Quota 1**:
```json
{
  "owner_id": "john.doe",
  "period_id": "Q2-2026",
  "level": "rep",
  "quota_amount": 150000,
  "currency": "EUR",
  "effective_from": "2026-04-01",
  "effective_to": "2026-06-30",
  "set_by": "director.bob"
}
```

**Sales Quota 2**:
```json
{
  "owner_id": "sales-team-east",
  "period_id": "Q2-2026",
  "level": "team",
  "quota_amount": 450000,
  "currency": "EUR",
  "effective_from": "2026-04-01",
  "effective_to": "2026-06-30",
  "set_by": "director.bob"
}
```
