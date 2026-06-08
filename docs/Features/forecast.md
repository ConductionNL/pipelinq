# Forecast — Roll-up and Categories

**Status:** Implemented

## Overview

The forecast feature lets sales teams classify deals by confidence, freezes weekly snapshots for trend analysis, supports manager overrides with full audit, and renders quota attainment alongside an accuracy score per rep.

Where the raw pipeline number answers "how much could close?", forecast answers "how much will close, and how reliable is that estimate?"

## Concepts

### Forecast categories

Every deal carries a `forecast_category` field with six values:

| Value | Dutch label | Meaning |
|-------|-------------|---------|
| `commit` | Toezegging | The rep stakes their quarter on this deal — high confidence |
| `best_case` | Best-case | Likely to close with some luck |
| `pipeline` | Pipeline | Real opportunity, uncertain timing |
| `omitted` | Uitgesloten | Excluded from forecast totals |
| `closed_won` | Afgesloten - Won | Deal won (terminal, locked) |
| `closed_lost` | Afgesloten - Lost | Deal lost (terminal, locked) |

New deals default to `pipeline`. Once a deal reaches `closed_won` or `closed_lost`, the category locks — you must reopen the deal to change it.

### Snapshots

Every Monday at 06:00 (org-configured timezone), an automated job freezes the current commit / best-case / pipeline / closed-won totals for every rep, team, division, and the company as a whole. Each snapshot records the contributing deal IDs so you can drill back into the deals that made up the number at that moment in time.

Snapshots are immutable. They power the trend chart and the accuracy score.

### Overrides

A manager can override a rep's commit (or a director can override a team's, and so on up the hierarchy). The override stores the new amount, the original amount, and a free-text reason. The forecast view renders both:

- "Rep submitted: €75,000" — small, greyed
- "Manager commit: €60,000" — bold, with a down arrow and the override reason on hover

Overrides cascade upward: if you override a rep's commit, the team roll-up recalculates using the override.

### Quotas

Each rep, team, division, and the company has a quarterly `sales_quota`. The forecast view stacks closed-won (solid green), commit (hatched blue), and remaining-to-quota (light grey) in a single progress bar, and flags any team where `closed + commit + 0.5 × best_case` falls below 90 % of quota with less than 30 days remaining in the period.

### Accuracy score

Once a fiscal period closes, the system computes `1 − abs(commit − actual) / actual` per rep, team, and division — a value between 0 (totally wrong) and 1 (perfect). Scores colour-code as green (> 0.90), amber (0.75–0.90), or red (< 0.75). A trailing-four-quarters average per rep tracks long-term commitment reliability.

## How to use it

### Setting a forecast category on a deal (rep)

1. Open a deal from the pipeline view.
2. In the deal detail header, find the **Forecast category** dropdown.
3. Choose the value that best reflects your confidence.
4. If the category is `commit` and the deal value is above the configured threshold (default €50,000), a modal asks for a justification (at least 10 characters). Type the reason and click **Save**.
5. The audit log on the same page records the change with timestamp, old value, new value, and your user.

If the deal is closed, the selector is greyed and a lock icon appears. The tooltip explains: "Reopen the deal to change the forecast category."

### Reviewing rep submissions (manager)

1. Open the **Forecast** page from the pipelinq nav.
2. Pick a level (Company / Division / Team / Rep) — the page lets you drill down.
3. The team summary table lists each rep with columns: rep name, commit, override (if any), original, manager note, best case, pipeline, quota, status.
4. The summary panel at the top shows aggregated quota, closed-won, commit, and gap-to-close for the level you're viewing.
5. The quota progress bar visualises closed (solid) / commit (hatched) / remaining (light) — labelled with attainment percentage.
6. If your level is below 90 % attainment with less than 30 days left, an orange at-risk banner appears with the gap and days-to-close.

### Overriding a rep's commit (manager)

1. In the team summary table, click **Override** next to the rep's commit.
2. Edit the override amount (pre-filled with the current commit).
3. Enter a reason — this is required and visible to the rep and to higher levels.
4. Click **Save**. The table refreshes with the override badge and the visual diff (rep submitted / manager commit).

The override flows immediately into the team roll-up; the team's commit is now the sum of (override-adjusted) rep commits.

### Reading the trend chart

1. From the forecast view, open **Trend**.
2. The chart plots commit (blue), best-case (orange), and pipeline (grey) over snapshot dates.
3. Hover any point for amount and deal count.
4. Below the chart, the **Delta panel** lists deals that moved into or out of each category since the previous snapshot — click a deal name to jump to the deal detail.

### Reading the accuracy view (closed periods)

1. From the forecast view, open **Accuracy**.
2. Each rep row shows: name, week-1 commit, actual closed-won, accuracy %, trailing-4-quarter average.
3. Rows are sorted by accuracy descending; colours indicate the green / amber / red band.
4. Click a rep to drill into the weekly breakdown chart for that period.

## Export

Authorised users (with the `forecast:read` permission, scoped to their level) can export snapshots via the REST API:

```
GET /api/forecast/snapshots?period_id=Q2-2026&level=division&format=csv
GET /api/forecast/snapshots?period_id=Q2-2026&level=team&format=json&limit=50&offset=0
```

JSON includes a `calculation_audit` block per snapshot showing the roll-up breakdown. CSV adds an optional second section for the same audit data.

Pagination is supported via `limit` and `offset`; the response metadata exposes `total`, `limit`, `offset`.

## Admin configuration

Pipelinq admins can tune the feature under **Admin settings → Forecast configuration**:

| Setting | Default | Description |
|---------|---------|-------------|
| Commit threshold | €50,000 | Deals above this value require a justification when set to `commit` |
| Forecast generation timezone | UTC | Determines what "Monday 06:00" means for snapshots |
| Accuracy green threshold | 0.90 | Scores above this render green |
| Accuracy amber threshold | 0.75 | Scores between amber and green render amber; below render red |
| At-risk quota threshold | 90 % | Trigger banner when projected attainment falls below this |
| At-risk days remaining | 30 | Trigger banner when fewer days remain |

Changes save via `IAppConfig::setAppValue()` and take effect immediately for new computations.

## Permissions

| Permission | Who has it | What it allows |
|------------|------------|----------------|
| `forecast:read` | Reps (own data), managers (team), directors (division), VPs (company) | View snapshots, trends, accuracy at the scoped level |
| `forecast:override` | Managers and above | Create and delete overrides at the scoped level |
| `forecast:quota:set` | Admins, directors | Maintain `sales_quota` rows |

Out-of-scope requests return `403 Forbidden` and are logged.

## API reference

| Method | Path | Purpose |
|--------|------|---------|
| `GET` | `/api/forecast/snapshots` | Filtered snapshot export, JSON or CSV |
| `POST` | `/api/forecast/overrides` | Create an override (validates `forecast:override`) |
| `DELETE` | `/api/forecast/overrides/{id}` | Remove an override (managers and above) |

## Related files

- Specification: `openspec/changes/forecast-roll-up-and-categories/specs.md`
- Design: `openspec/changes/forecast-roll-up-and-categories/design.md`
- Tasks: `openspec/changes/forecast-roll-up-and-categories/tasks.md`
- Backend service: `lib/Service/ForecastService.php`
- Snapshot job: `lib/Job/ForecastSnapshotJob.php`
- Controller: `lib/Controller/ForecastController.php`
- Deal listeners: `lib/Listener/DealCreatedListener.php`, `lib/Listener/DealUpdatedListener.php`

## Screenshots

Screenshots will be added during the Hydra verify stage once the feature is exercised against a running app.
