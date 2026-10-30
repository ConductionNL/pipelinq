# Tasks: reports-compare-periods-and-scheduled-delivery

## 1. Period comparison (built; tag and test)
- [ ] 1.1 Add `@spec openspec/changes/reports-compare-periods-and-scheduled-delivery/specs/report-periods-and-delivery/spec.md#REQ-RPD-001` to `AnalyticsService::getOverview()` and `aggregateOverviewWindow()` (V1)
- [ ] 1.2 `tests/Unit/Service/AnalyticsServiceTest.php`: the previous window has the same length as the current one and ends where it starts, for week, month, quarter and year (V1)
- [ ] 1.3 Stat tiles with `previousField` in `src/manifest.json`: declare `goodDirection` on every KPI tile that has a previous value (resolution time `down`), and show "No earlier period to compare with" when the previous value is null or 0 (nextcloud-vue stat widget prop if it lacks one; build it there, not here) (V1)

## 2. Mail delivery for export jobs
- [ ] 2.1 `lib/Service/Export/ReportMailDelivery.php`: given a job and its formatted rows per recipient, send through `OCP\Mail\IMailer` with the file attached; subject and body from l10n; generic for any export job (V1)
- [ ] 2.2 `ExportDestinationService`: accept delivery type `mail` with `recipients: [{type: user|group, id}]`; validate that every recipient exists (V1)
- [ ] 2.3 `ExportExecutionService`: for a `mail` job, resolve groups to users and extract once per recipient as that user, so OpenRegister's RBAC filters the rows (V1)
- [ ] 2.4 Record each send through `ExportJobService::recordRun()` with outcome `delivered` or `failed` and the reason; notify the job owner through `OCP\Notification\IManager` on failure (V1)
- [ ] 2.5 Unit tests for 2.1-2.4 with a fake mailer: one mail per recipient, RBAC-filtered rows, failure recorded and notified (V1)

## 3. Schedule dialog
- [ ] 3.1 The schedule dialog on the BI export page (PqBiExport): delivery choice Mail, a recipients picker (NcSelect over users and groups, with `inputLabel`), and the schedule in words from `ExportJobService::describeSchedule()` (V1)
- [ ] 3.2 l10n nl and en for the dialog and the mail (V1)
- [ ] 3.3 (live pass) Create a job mailed every minute to yourself on :8080, see the mail arrive in the dev mail catcher, then disable the job (V1)
