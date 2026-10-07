# Design: exports-switch-off-after-repeated-failure

## Context (read at pipelinq development 920f14e5f)

- `lib/BackgroundJob/ExportSchedulerJob.php:97` skips a job whose `enabled`
  is not true, and enqueues a pending run for every due job.
- `lib/Service/Export/ExportRunService.php:178` `completeRun()` writes the
  run's final `status` (`succeeded`, `partial` or `failed`) and its
  `errorMessage`. It is the one place every run ends.
- `lib/Service/Export/ExportJobService.php:198` `enableJob()` and `:215`
  `disableJob()` flip `enabled`. A new job starts disabled until a test run
  passes (`:129`).
- `lib/Notification/Notifier.php:239-300` already renders failure subjects
  for other integrations. None covers export runs.
- The job schema is `exportJob` in `lib/Settings/register.d/40-bi-export.json:104`.

## Board

Canvas `5NkFW28vZUUij43xzxHg5a`, board `PqBiExport` (BI export, advanced).
The board draws the end state of this change:

- the job "Leads and pipeline value" with last run `Failed`, "6 Oct, 01:00 ·
  connection refused", next run "Not scheduled" and the Enabled switch off;
- the view chips "All", "Enabled" and "Last run failed";
- the footnote "A new job stays off until a test run has passed."

This change keeps those labels. It adds one line under a switched-off job:
"Switched off after 3 failed runs in a row", shown in the job's Last run cell.
The board has no room drawn for the reason elsewhere, so the cell carries it.

## Decisions

**D1. Count on the job, not by reading run history.** `completeRun()` sets
`consecutiveFailures` to zero on `succeeded` and adds one on `failed`. A
`partial` run leaves the count as it is: some data arrived, so it is not a
dead destination. Reading the last N runs instead would cost a query on every
run and breaks when runs are cleaned up.

**D2. Three by default.** A nightly job then stops after three nights. The
threshold is an app config value, `export_failure_threshold`, between 1 and
10. Zero is not allowed: an administrator who wants no switch-off sets 10.

**D3. Switch off through `disableJob()`.** The same path a person uses, so the
scheduler needs no new rule. `pausedReason` and `pausedAt` are written in the
same save.

**D4. Notify once.** The notification fires on the run that crosses the
threshold. A job that is off has no further runs, so there is no repeat.

**D5. Enable clears the state.** `enableJob()` sets `consecutiveFailures` to
0 and removes `pausedReason` and `pausedAt`. The existing rule stays: a job
is enabled by a person, never by the system.

## Risks

- A destination that is down for a weekend switches off a nightly job by
  Monday. That is the intent; the notification says how to switch it back on.
- `consecutiveFailures` is written by the run path while an administrator may
  edit the job. The run path saves only the three fields it owns, through
  `saveObjectData` with the job's current data, as `disableJob()` does today.
