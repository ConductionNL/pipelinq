# Proposal: forecast-roll-up-and-categories

## Problem

Sales leadership in mid-market and enterprise organizations cannot run a business on a raw pipeline number alone. Not every deal in the funnel is equally likely to close, and a single €5M deal in early-stage discovery carries less credibility than five €1M deals in contract review. Without a mechanism to categorize deals by confidence, managers cannot produce reliable weekly forecasts or track accuracy over time.

Current gaps:

1. **No deal confidence classification** — All deals flow into a single "pipeline" aggregate, mixing high-confidence deals with long-shots. Managers lack a standard way to distinguish "commit" deals (the rep stakes their quarter on them) from "best_case" (likely with luck) or "pipeline" (real but uncertain).

2. **No snapshot mechanism for trend analysis** — Forecast numbers change week to week, but there is no historical record of what was forecast when. This makes it impossible to compute forecast accuracy, spot trends, or answer "did we hit what we committed to?"

3. **No override capability for management** — A sales manager has no way to override a rep's submit without destroying the underlying data. If the rep commits to €500K but the manager believes it is €400K, today there is no audit trail or visual diff showing the discrepancy.

4. **No quota attainment rendering** — Sales directors cannot see quota-vs-forecast-vs-closed side-by-side, nor can they flag at-risk teams with less than 30 days left in the quarter.

## Solution

Add three capabilities to pipelinq:

1. **Forecast category field** — Each deal gets a `forecast_category` selector (`commit`, `best_case`, `pipeline`, `closed_won`, `closed_lost`, `omitted`) with workflow rules that lock closed deals and require justification for large commits. The default for a new deal is `pipeline`.

2. **Weekly snapshot mechanism** — Every Monday at 06:00, an automated job generates a `forecast_snapshot` for every rep, team, division, and the whole company, freezing the commit/best_case/pipeline amounts and the contributing deals at that moment. On-demand snapshots are also available. Snapshots are immutable, enabling historical trend analysis and accuracy scoring once a period closes.

3. **Hierarchical roll-up with manager override** — Team snapshots sum rep snapshots; division snapshots sum team snapshots; company snapshots sum division snapshots (with currency normalization). At any level, a manager can create a `forecast_override` that replaces the calculated roll-up, with the original visible as "rep submitted: €X" and override as "manager commit: €Y" for transparency. Overrides cascade upward (if a manager overrides a rep's commit, the team recalc uses the override).

4. **Quota tracking and accuracy scoring** — Each rep/team/division has a quarterly `sales_quota`. The forecast view displays quota, closed_won (solid progress bar), commit (hatched), and gap-to-close. For closed periods, `forecast_accuracy_score` (1 - abs(commit - actual) / actual) per rep is computed and colour-coded. A trailing-four-quarters average tracks rep "commitment reliability."

## Scope

### Data Schema
- Extend `deal` with `forecast_category` enum field
- New `forecast_snapshot` schema: period_id, as_of_date, owner_id, level (rep|team|division|company), commit_amount, best_case_amount, pipeline_amount, closed_won_amount, quota_amount, deal_snapshot_ids[], partial flag, missing_reps list
- New `forecast_override` schema: period_id, owner_id, level, category, override_amount, original_amount, reason, created_by, created_at
- New `sales_quota` schema: owner_id, period_id, level, quota_amount, currency, effective_from, effective_to, set_by

### Backend
- `lib/Listener/DealCreatedListener.php` — sets forecast_category default to `pipeline` on new deal
- `lib/Listener/DealUpdatedListener.php` — enforces category transition rules (closed deals lock) and validates commit threshold
- `lib/Job/ForecastSnapshotJob.php` — scheduled for Monday 06:00, generates snapshots for all levels
- `lib/Service/ForecastService.php` — computes roll-ups, applies overrides, calculates accuracy scores
- `lib/Service/QuotaService.php` — quota lookup and attainment calculations
- `lib/Controller/ForecastController.php` — snapshot API export (JSON, CSV), override management
- Extended `lib/Settings/pipelinq_register.json` with new schemas and deal field
- Admin settings: commit threshold (EUR), forecast generation timezone, accuracy thresholds (green/amber/red bands)

### Frontend
- Deal detail view: forecast category selector + validation messages + justification modal for large commits
- Manager forecast view: rep submissions with override entry field, team roll-up, division roll-up, company aggregate
- Quota view: quota + closed_won + commit + projected attainment progress bar + at-risk warning banner
- Trend chart: commit/best_case/pipeline over time + delta panel (deals moved in/out per category week-over-week)
- Accuracy view: rep/team/division accuracy scores (colour-coded) + trailing-four-quarters average
- i18n: Dutch + English for all UI labels and error messages

### Cross-App Integration
- OpenRegister: snapshots, overrides, and quotas are OR schemas in the pipelinq register
- Cron job: uses OR's `cron-trigger` extension for scheduled snapshot generation
- launchpad: reads snapshots via `/api/forecast/snapshots` for sales leadership dashboard widget
- openconnector: snapshot export for Tableau/Power BI BI pipelines (handled by separate `bi-export-and-data-warehouse-sink` spec)

### Seed Data
- 3 example `deal` objects demonstrating forecast categories (commit, best_case, pipeline, omitted)
- 2 example `forecast_snapshot` objects at different points in time showing roll-up mechanics
- 1 example `forecast_override` with reason and visual diff
- 2 example `sales_quota` objects (rep-level and team-level)

**Depends on:** `pipeline`, `pipeline-insights`, OpenRegister `cron-trigger` extension

## Out of Scope

- Replacing the deal stage workflow (owned by `pipeline` spec)
- Reverse sync (shillinq → pipelinq) or budget reallocation triggered from external systems
- Forecast variance analysis or budget burn tracking (separate spec)
- Deal probability weighting (always treats each category as 1.0x commit, 0.6x best_case for projection)
- Real-time forecast dashboard widget (separate change)
- Bulk retroactive snapshot generation for historical periods
- Mobile app UI for forecast overrides (browser-first)
- Integration with Excel/Google Sheets for direct quota entry (API export only)

## Success Criteria

- A rep can set `forecast_category` on any deal via a selector in the deal detail view
- When a deal reaches `closed_won` or `closed_lost`, the category locks and cannot be changed without reopening the deal
- A rep committing to a deal > €50K (org-configurable) is prompted for a free-text justification, which is stored and visible to their manager
- Every Monday at 06:00 UTC (or org-configured timezone), a snapshot is auto-generated for every rep, team, division, and company for the currently-open fiscal quarter
- Team commit = sum of rep commit values (with currency normalization); same rule applies team → division → company
- A sales manager can override a rep's commit with a reason; the original number remains visible in a "rep submitted" field
- A VP can override division numbers; overrides cascade upward (manager override feeds into division roll-up)
- Once a fiscal period closes, the system computes `forecast_accuracy_score` for each rep/team/division comparing the week-1 commit snapshot to actual closed_won
- The forecast view shows quota, closed_won (solid bar), commit (hatched bar), and "gap to close" with an at-risk warning if (closed + commit + 0.5×best_case) < 90% quota and < 30 days remain
- A line chart plots commit/best_case/pipeline over time; a delta panel shows which deals moved categories week-over-week
- An authorised user can export snapshots via `GET /api/forecast/snapshots?period_id=X&level=division&format=csv`
- `npm run build` produces zero errors after all changes

## Standards

- **Currency normalization**: ISO 4217 codes; ECB daily rates (configurable at org level)
- **Audit log**: All forecast overrides and category changes follow OpenRegister `audit_log` schema (ADR-009)
- **Fiscal period**: Uses org-configured fiscal calendar (Q1-Q4 custom start); falls back to calendar quarters
- **Permission model**: `forecast:read`, `forecast:override`, `forecast:quota:set` are granular, role-scoped permissions (ADR-012)
- **Schema mapping**: Contact/organization data uses schema.org; forecast categories are pipelinq-specific enums
