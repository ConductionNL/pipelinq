---
status: draft
app: pipelinq
spec: forecast-roll-up-and-categories
depends_on:
  - pipeline
  - pipeline-insights
---

# Forecast Roll-Up and Categories

## Purpose

Sales leadership cannot run a business on a raw pipeline number — every deal in the funnel is not equally likely to close, and a single $5M deal in early-stage discovery is worth less than five $1M deals in contract review. The standard fix, in every serious CRM since Siebel, is the **forecast category**: each opportunity is tagged as `commit` (the rep will stake their quarter on it), `best_case` (likely with luck), `pipeline` (real but uncertain), `closed_won`, or `closed_lost`. These categories let a sales manager produce a credible weekly number — "commit + 60% of best_case" — and let a VP of sales roll that number up the org tree (rep → team → division → company) for board reporting.

This spec adds three things to pipelinq: (1) a forecast category field on every deal, with workflow rules around when it can change, (2) a weekly snapshot mechanism that freezes the forecast at a point in time so trend lines and "did we hit what we said we'd hit" accuracy scores become possible, and (3) hierarchical roll-up with override capability at every level — a sales manager can override their reps' commits without destroying the underlying data, and a VP can override the manager. Quota tracking sits alongside: each rep/team/division has a quarterly quota, and the forecast is always rendered against attainment.

The non-goal is replacing the deal stage workflow (`pipeline` spec owns that). Forecast category is orthogonal to stage — a deal can be in stage "negotiation" and forecast category "best_case" or "commit" depending on the rep's confidence.

## Data Model

**Forecast category** (enum on `deal`): `commit | best_case | pipeline | closed_won | closed_lost | omitted`. `omitted` is for deals the rep is parking (long-shot, dormant, paused) so they don't pollute the roll-up.

**Forecast snapshot** (new schema `forecast_snapshot`): captures forecast state at a moment in time. Fields: `period_id` (links to fiscal quarter), `as_of_date`, `owner_id` (rep/team/division/whole-company), `level` (rep|team|division|company), `commit_amount`, `best_case_amount`, `pipeline_amount`, `closed_won_amount`, `quota_amount`, `deal_snapshot_ids[]` (which deals contributed). Generated automatically every Monday 06:00 by a scheduled job, plus on-demand on request.

**Forecast override** (new schema `forecast_override`): a manager-supplied number that replaces the calculated roll-up at a given level. Fields: `period_id`, `owner_id`, `level`, `category` (commit|best_case), `override_amount`, `original_amount`, `reason`, `created_by`, `created_at`. Overrides cascade upward — when a manager overrides a rep's commit, the team commit recalculates using the override, not the underlying deals.

**Quota** (new schema `sales_quota`): per `owner_id` × `period_id` × `level`. Fields: `quota_amount`, `currency`, `effective_from`, `effective_to`, `set_by`. Quotas can be set per-rep, per-team, per-division — and the rep-level sum should equal team quota (with a tolerance), but this is advisory, not enforced.

**Forecast accuracy** (derived, stored as `forecast_accuracy_score` on snapshot): once a period closes, the snapshot from `as_of_date = period_start + N weeks` is compared to actual closed_won. Accuracy = `1 - abs(commit - actual) / actual`. A rep who commits to $1M and closes $950K scores 0.95; a rep who commits to $1M and closes $500K scores 0.50.

## Requirements

### REQ-001: Forecast category field on deal

**GIVEN** a deal exists in the pipeline
**WHEN** the rep opens the deal detail view
**THEN** a `forecast_category` selector is visible with the six options
**AND** the default for a new deal is `pipeline`
**AND** changing the category writes an audit-log entry capturing previous → new value, who changed it, and timestamp.

### REQ-002: Category transition rules

**GIVEN** a deal has `closed_won` or `closed_lost` set
**WHEN** any user attempts to change the forecast category back to an open category
**THEN** the system rejects the change with a clear error message
**AND** the only way to reopen is to first reopen the deal at the stage level (which then resets forecast to `pipeline`).

### REQ-003: Commit category requires confidence justification

**GIVEN** a rep sets a deal's forecast category to `commit`
**WHEN** the deal value exceeds the org-configured "commit threshold" (default €50K)
**THEN** the system prompts for a free-text justification (close date confidence, decision-maker engaged, etc.)
**AND** the justification is stored on the deal and surfaced in the manager's pipeline review.

### REQ-004: Weekly snapshot generation

**GIVEN** Monday 06:00 in the org's configured timezone arrives
**WHEN** the scheduled job runs
**THEN** a `forecast_snapshot` row is generated for every active rep, team, division, and the whole company, for the currently-open fiscal period
**AND** the snapshot freezes `commit_amount`, `best_case_amount`, `pipeline_amount`, and the list of contributing deal IDs at that moment
**AND** if the job fails for a level, an alert is raised to the pipelinq admin without blocking other levels.

### REQ-005: Hierarchical roll-up

**GIVEN** snapshots exist for individual reps in a team
**WHEN** the team-level snapshot is generated
**THEN** the team `commit_amount` equals the sum of rep `commit_amount` values (with currency normalisation via the org's reporting currency)
**AND** if any rep snapshot is missing, the team snapshot records a `partial: true` flag and lists missing reps
**AND** the same rollup applies team → division → company.

### REQ-006: Manager override at any level

**GIVEN** a sales manager views the forecast for their team
**WHEN** the manager enters a different commit number and a reason
**THEN** a `forecast_override` row is created
**AND** subsequent roll-ups to the division level use the override, not the calculated rep sum
**AND** the original calculated number remains visible as "rep submitted: €X" alongside "manager commit: €Y" with a visual diff indicator.

### REQ-007: Forecast accuracy scoring

**GIVEN** a fiscal period has closed and all deals are settled
**WHEN** a user opens the period's accuracy view
**THEN** the system displays, per rep/team/division: the commit snapshot from each week of the period, the final closed_won, and the accuracy score
**AND** scores are colour-coded (>0.9 green, 0.75-0.9 amber, <0.75 red)
**AND** a "commitment reliability" trailing-four-quarters average is shown per rep.

### REQ-008: Quota attainment display

**GIVEN** a rep, team, or division has an active quota for the open period
**WHEN** the forecast view is rendered for that owner
**THEN** the quota, closed_won amount, commit amount, and "gap to close" (`quota - closed_won - commit`) are visible at the top of the page
**AND** a progress bar shows closed_won (solid) + commit (hatched) against the quota line
**AND** if the projected attainment (closed_won + commit + 0.5×best_case) falls below 90% of quota with less than 30 days left, a warning banner is shown.

### REQ-009: Snapshot comparison and trend

**GIVEN** at least two snapshots exist for a given owner within a period
**WHEN** the user opens the trend view
**THEN** a line chart shows commit, best_case, and pipeline over time (X = snapshot date, Y = amount)
**AND** a "delta vs last week" panel shows which deals moved in or out of each category
**AND** the user can click a deal in the delta panel to navigate to its detail page.

### REQ-010: API export for board reporting

**GIVEN** a snapshot or override exists
**WHEN** an authorised user calls `GET /api/forecast/snapshots?period_id=X&level=division`
**THEN** the response returns JSON with snapshot rows, override applied if any, and a `calculation_audit` array showing how the number was built
**AND** the endpoint supports `format=csv` for spreadsheet download
**AND** the endpoint requires the `forecast:read` permission, which is scoped to the requester's owned hierarchy (a rep can't read the whole company unless granted explicit access).

## Standards

- **Currency normalisation** via ISO 4217 codes and the rate source configured at org level (default ECB daily rates).
- **Audit log** writes follow the OpenRegister `audit_log` schema (ADR-009) — never delete or mutate audit rows.
- **Fiscal period** definition uses the company's configured fiscal calendar (Q1-Q4, custom start month); falls back to calendar quarters if not configured.
- **Permission model** follows ADR-012 role-based access: `forecast:read`, `forecast:override`, `forecast:quota:set` are granular permissions assignable per role.

## Cross-App

- **openregister**: snapshots, overrides, and quotas are OR schemas in the `pipelinq` register; depends on OR's `cron-trigger` extension for the Monday job.
- **launchpad**: forecast snapshots are a primary widget source for the sales leadership dashboard; launchpad reads via the `/api/forecast/snapshots` endpoint, no install-time coupling.
- **openconnector**: exporting snapshots to external BI tools (Tableau, Power BI) is handled by the `bi-export-and-data-warehouse-sink` spec, which reads snapshot rows as a source.
- **openzaak / zaakafhandelapp**: out of scope — government workflow apps do not produce sales forecasts.

## Target Users

- **Sales rep**: sets forecast category on their deals each Friday before commit cutoff; sees their personal quota attainment.
- **Sales manager / team lead**: reviews rep submissions, overrides commit numbers where reps are over- or under-calling, accountable for team commit.
- **VP of sales / sales director**: rolls up division numbers, presents the company commit to the executive team, tracks rep and manager accuracy over time.
- **CFO / finance**: consumes the snapshot history via export for revenue planning, cash-flow modelling, and board reporting.
- **RevOps / sales operations**: configures quotas, fiscal periods, commit thresholds, and accuracy-scoring policies; investigates roll-up discrepancies.
