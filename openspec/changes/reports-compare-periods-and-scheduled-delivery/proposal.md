---
kind: code
---

# Proposal: reports-compare-periods-and-scheduled-delivery

## Summary

Two report capabilities are built without a requirement. Period comparison exists: `AnalyticsService::getOverview()` (`lib/Service/AnalyticsService.php:285`) returns every KPI for the chosen period and for the equal-length window before it (`previousPeriod`), and four stat tiles in `src/manifest.json` show the difference (`previousField`). Scheduled exports exist too: `ExportSchedulerJob` runs every export job whose cron expression is due, but its destinations (`ExportDestinationService::TYPES`) are warehouses and file stores (S3, Azure Data Lake, GCS, BigQuery, Snowflake, SFTP, Postgres). A report that lands in somebody's inbox, which is what the row promises, is not built.

This change writes both down and plans the missing part: a scheduled report delivered by mail, generic over any saved report.

## The rows this covers

Source: pipelinq `openspec/parity/capabilities.json`.

- **rep-compare-periods** Compare this month with the last one (built: `lib/Service/AnalyticsService.php:465`, `getOverview()`). Boards: PqVerkoopoverzicht, PqOperationeel.
- **rep-scheduled-export** Have a report land in your inbox on a schedule (built for warehouse destinations; mail delivery not built). Board: PqBiExport.

## What changes

1. Spec `report-periods-and-delivery`: the comparison rule (equal-length previous window, absolute and relative difference, the direction that counts as good) and scheduled delivery by mail.
2. Build: a `mail` delivery for export jobs, sending the formatted report (CSV or XLSX) as an attachment through Nextcloud's `OCP\Mail\IMailer` to Nextcloud users or groups, on the job's existing cron schedule. Generic code named by what it does (`ReportMailDelivery`), usable by any export job.
3. The schedule dialog on PqBiExport gets the recipients field the board draws.

## Screens

PqVerkoopoverzicht, PqOperationeel, PqBiExport (see `screens.md`).

## Out of scope

- New KPIs. The comparison applies to the KPIs that exist.
- Mailing to addresses outside the instance's users and groups (data leaves the instance; a later decision).
