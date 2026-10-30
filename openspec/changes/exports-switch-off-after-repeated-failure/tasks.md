# Tasks: exports-switch-off-after-repeated-failure

## 1. Schema

- [x] 1.1 Add `consecutiveFailures` (integer, minimum 0, default 0), `pausedReason` (string, max 255) and `pausedAt` (date-time) to `exportJob` in `lib/Settings/register.d/40-bi-export.json` [V1] Done as fragment `register.d/43-export-job-switch-off.json` (exportJob 1.2.0), beside the 1.1.0 fields of `42-bi-export-job-runs.json`.
  - Verify: `occ openregister:import` finishes with no `PARTIAL IMPORT` in the log; a job read through the OpenRegister API returns the three fields

## 2. Counting and switching off

- [x] 2.1 In `ExportRunService::completeRun()`, after the run is saved, update the job: `succeeded` sets `consecutiveFailures` to 0, `failed` adds 1, `partial` leaves it [V1] Built in `ExportJobService::recordRun()` through `ExportFailureSwitch::countRun()`: recordRun is where every finished run is written to its job (ExportExecutionService::recordOnJob), so the count, the summary and the switch-off share one save.
  - Verify: PHPUnit `tests/Unit/Service/Export/ExportRunServiceTest.php` covers the three outcomes against a real job array
- [x] 2.2 When `consecutiveFailures` reaches `export_failure_threshold` (IAppConfig, default 3), call `ExportJobService::disableJob()` and write `pausedReason` and `pausedAt` in the same save [V1] The off state is written in the same save as the count (what `disableJob()` writes, plus the reason and moment), so no second write races the run path.
  - Verify: PHPUnit asserts the job is disabled on the third failure and not on the second; the reason text names the count
- [x] 2.3 `ExportJobService::enableJob()` resets `consecutiveFailures` to 0 and removes `pausedReason` and `pausedAt` [V1]
  - Verify: PHPUnit enables a switched-off job and asserts the three fields

## 3. Notification

- [x] 3.1 Add subject `export_job_switched_off` to `lib/Notification/Notifier.php`, with job name, failure count, last error and a link to the ExportJobs route; send it to every member of the admin group from the switch-off path in 2.2 [V1]
  - Verify: PHPUnit for the Notifier renders the subject with the three values; PHPUnit for 2.2 asserts one notification per administrator and none on the next scheduler pass

## 4. BI export page

- [x] 4.1 In ExportJobsView show "Not scheduled" as next run for a disabled job and the `pausedReason` under the Failed status in the Last run cell, as on board `PqBiExport` [V1] The Next run column already reads "Not scheduled" for a job with no nextRunAt; it starts hidden in the Columns menu since f5f243888 (the schedule column states it). The reason is appended to the Last run line (`switchedOffReason`).
  - Verify: Playwright `tests/e2e/export-switch-off.spec.ts` seeds a switched-off job and reads both texts
- [x] 4.2 Make sure the "Last run failed" view lists a job whose last run failed, whether it is enabled or not [V1] Already so: the view filters on `lastRunStatus: failed` only.
  - Verify: the same Playwright spec selects the view and finds the job
- [x] 4.3 Switching the job on from the page clears the reason on screen [V1]
  - Verify: the Playwright spec switches it on and the reason is gone after a reload

## 5. Admin setting

- [x] 5.1 Add "Switch off an export job after this many failed runs in a row" (1 to 10, default 3) to the admin settings, stored as `export_failure_threshold` [V1]
  - Verify: PHPUnit for the settings service refuses 0 and 11; Playwright saves 1 and reads it back

## 6. Text

- [x] 6.1 Add every new string to `l10n/en.json` and `l10n/nl.json` [V1]
  - Verify: `npm run check:l10n-js` exit 0; no new English literal in `src/` (`npm run lint`)
