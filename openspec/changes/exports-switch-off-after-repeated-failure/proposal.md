---
kind: code
depends_on: []
---

# Proposal: exports-switch-off-after-repeated-failure

## Summary

A scheduled BI export that fails three nights in a row switches itself off.
The administrators get one notification that names the job and the last
error. The job shows as off on the BI export page, with its last failed run,
until an administrator fixes the destination and switches it back on.

## Motivation

One row of the pipelinq capability matrix (`openspec/parity/capabilities.json`).

**`auto-failure-alerts`**, "Be told when an automation keeps failing, and have
it switched off before it does more harm". Rated partial, built.state none.
Matrix evidence: some integrations already tell people when they fail
(`lib/Notification/Notifier.php:239` `forecast_snapshot_partial_failure`,
`:250` `wip_sync_failed`, `:261` `billing_handoff_failed`, `:289`
`social_relink_needed`), and failed export runs show on ExportRuns with a
retry. "Nothing switches an automation off after repeated failure."

The 28 September OpenSpec pass moved the flow half to OpenRegister: flows run
on OpenRegister's flow engine, so pausing a failing flow is that engine's job,
and OpenRegister deferred it (`openregister openspec/parity/gap-decisions.json`).
That leaves one automation pipelinq owns and runs on a schedule by itself: the
BI export job (`exportJob`, `lib/BackgroundJob/ExportSchedulerJob.php`). It
already has an `enabled` switch, and nothing turns it off when every run fails.
This change covers that half. The flow half stays with OpenRegister.

Competitors, from the matrix: pipedrive yes, odoo-crm partial, espocrm no,
kiss no, hubspot-crm unknown.

## What changes

- After each finished export run, pipelinq counts the job's failed runs in a
  row. At the threshold (three by default) it switches the job off.
- The job records why it was switched off: `pausedReason` and `pausedAt`.
- Pipelinq sends every administrator one Nextcloud notification,
  `export_job_switched_off`, naming the job, the number of failures and the
  last error.
- The BI export page shows the job as off, with Next run "Not scheduled" and
  its last run as Failed, and the "Last run failed" view lists it.
- Switching the job back on clears `pausedReason` and starts the count again.
- An administrator sets the threshold in the admin settings.

## Out of scope

- Flows. A failing OpenRegister flow is paused by OpenRegister's engine, or not
  at all; pipelinq does not wrap it.
- The integrations that already notify (forecast snapshot, WIP sync, billing
  handoff, social relink). They retry by design and are not schedules a user
  switches on.
- Retrying a failed run automatically. ExportRuns keeps its manual retry.

## Impact

- `lib/Settings/register.d/40-bi-export.json`: `exportJob` gains
  `consecutiveFailures`, `pausedReason` and `pausedAt`.
- `lib/Service/Export/ExportRunService.php` (`completeRun`) and
  `lib/Service/Export/ExportJobService.php` (`enableJob`, `disableJob`).
- `lib/Notification/Notifier.php`: one new subject.
- `src/views/export/` (ExportJobsView): the off state and its reason.
- Admin settings: one number field.
- No new storage. The job lives in OpenRegister like every other pipelinq
  object (ADR-022).
