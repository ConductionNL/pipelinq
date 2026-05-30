# Proposal: bi-export-and-data-warehouse-sink

## Problem

Pipelinq is operational software — it captures day-to-day sales and customer interaction workflow. However, serious organizations need to answer questions the pipelinq UI cannot address: "What is our customer acquisition cost by lead source?", "Which customer cohorts have the highest churn risk?", "How does sales pipeline health correlate with support ticket volume?" To answer these, data must flow from pipelinq's operational database to analytical warehouses (Snowflake, BigQuery, Azure Synapse, AWS S3+Athena, on-prem Postgres), where BI teams can model, join, and analyze across systems.

Current gaps:

1. **No batch export capability** — Data is trapped in pipelinq's operational database. Long-running analytical queries degrade OLTP performance. No standard mechanism to reliably push pipelinq schemas to external warehouses.

2. **No support for multiple destinations or formats** — Different organizations use different warehouses and BI tools. No flexibility to export the same data in CSV, Parquet, or JSON to different targets.

3. **No incremental/CDC support** — Each export re-exports everything, wasting bandwidth and compute. No watermark tracking for efficient incremental updates. Soft-deleted rows cannot be properly propagated downstream.

4. **No schema evolution handling** — When a pipelinq schema gains a new column, exports break or silently drop the new data. No mechanism to detect and gracefully handle schema changes.

5. **No audit trail for compliance** — Data exports are not logged. Row counts, checksums, and error states are unknown. No accountability for data that flows to external systems, violating GDPR and compliance requirements.

6. **No PII redaction** — Entire schemas (including emails, phone numbers, sensitive identifiers) are exported. No column-level controls to enforce data minimization and support right-to-erasure.

## Solution

Add a configurable, scheduled export pipeline that:

1. Lets an admin select which pipelinq schemas (contact, lead, pipeline, deal, contactmoment, forecast_snapshot, etc.) to export.
2. Supports multiple destinations (S3, Azure Data Lake, GCS, BigQuery, Snowflake, SFTP, Postgres) and formats (CSV / Parquet / JSON-lines).
3. Runs on a cron schedule (hourly, daily, weekly) or on-demand.
4. Supports both **full refresh** (re-export everything) and **incremental/CDC** (only rows changed since the last successful run).
5. Handles schema evolution gracefully — adding or removing columns does not break the export.
6. Produces a per-run audit record with row counts, byte sizes, checksums, errors, and destination acknowledgement.
7. Supports column allowlists for PII redaction and data minimization.

The non-goal is real-time streaming (Kafka, CDC log replication); minimum cadence is hourly, typical is daily. For real-time use cases, customers should use the `webhook-events` spec.

## Scope

### Data Schema

- **export_job** (new): configured, repeatable export. Fields: `name`, `description`, `source_schemas[]`, `destination_id` (FK to `export_destination`), `format` (csv|parquet|jsonl), `mode` (full|incremental), `incremental_watermark_column`, `schedule_cron`, `enabled`, `partition_by`, `row_filter_expression`, `column_allowlist[]`, `created_by`, `created_at`.
- **export_destination** (new): sink configuration. Fields: `name`, `type` (s3|azure_data_lake|gcs|bigquery|snowflake|sftp|postgres), `connector_source_id` (FK to OpenConnector), `path_template`, `compression` (none|gzip|snappy|zstd), `encryption_enabled`, `naming_convention`.
- **export_run** (new): one execution. Fields: `job_id`, `started_at`, `ended_at`, `status` (pending|running|succeeded|failed|partial), `mode_used`, `watermark_from`, `watermark_to`, `row_count`, `byte_count`, `file_count`, `file_manifest_json`, `error_message`, `destination_ack`.
- **export_schema_snapshot** (new): schema structure at each run. Fields: `run_id`, `pipelinq_schema_name`, `column_definitions_json`, `compared_to_previous` (added|removed|changed|unchanged).

### Backend

- `lib/Service/ExportJobService.php` — CRUD for export jobs, validation, scheduling
- `lib/Service/ExportDestinationService.php` — manage destination configs, OpenConnector integration
- `lib/Job/ExportWorkerJob.php` — background worker that executes pending export runs
- `lib/Service/ExportRunService.php` — create, update, query export run records
- `lib/Service/ExportDataService.php` — extract data from pipelinq schemas, apply filters, format (CSV/Parquet/JSONL)
- `lib/Service/ExportUploadService.php` — upload files to destination with retries
- `lib/Service/SchemaEvolutionService.php` — detect schema changes, manage snapshots
- `lib/Controller/ExportJobController.php` — REST API for job CRUD and test runs
- `lib/Controller/ExportRunController.php` — REST API for run history, audit logs, retry failed runs
- Extended `lib/Settings/pipelinq_register.json` with new schemas
- Admin UI: export job configuration, destination setup, run history, audit view
- Cron integration: scheduled job execution via OpenRegister cron-trigger

### Frontend

- Export jobs list page: create/edit/delete/enable/disable jobs, "Test run" button
- New export job form: schema picker (multi-select), destination picker, format, mode, cron editor with human-readable preview, filter expression, column allowlist with PII warnings
- Export destinations list page: create/edit/delete destinations, connection test
- Export runs page: list view with filtering by job/status/date, drill-down to run details (row counts, files, checksums, errors)
- Test run modal: shows sample file (first 100 rows) for inspection before enabling
- i18n: Dutch + English

### Cross-App Integration

- **openconnector**: destination credentials stored in OpenConnector sources; each destination references an OC source. New connector types (BigQuery, Snowflake, Azure Data Lake) are added to OC first.
- **openregister**: all four new schemas live in the `pipelinq` register
- **launchpad**: reads `export_run` summary metrics (success rate, last run time, row volumes) for ops dashboard
- **docudesk**: export file manifests are compatible with docudesk's archival package format (future integration)

### Seed Data

- 2 example `export_destination` objects (S3, BigQuery)
- 2 example `export_job` objects (full refresh of contacts, incremental leads)
- 2 example `export_run` objects (one succeeded, one failed)
- 1 example `export_schema_snapshot`

**Depends on:** pipelinq, openconnector, openregister

## Out of Scope

- Real-time streaming or CDC log replication (use webhook-events spec instead)
- Reverse sync (warehouse → pipelinq)
- Data transformation or modeling (warehouse team responsibility)
- Tableau/Power BI dashboard generation (BI team responsibility)
- Mobile app UI for export management (browser-first)
- Bulk retroactive export for historical periods

## Success Criteria

- An admin can create an export job via a form, selecting schema(s), destination, format, and schedule
- The form includes a "Test run" button that validates destination connectivity and produces a sample file (≤100 rows)
- Once enabled, the export job runs on schedule (cron) at the specified time
- Full refresh mode exports all rows from selected schemas, respecting filters and column allowlists
- Incremental mode exports only rows changed since the last successful run, tracked via watermark column
- Exports support CSV (header + rows), Parquet (embedded schema), and JSON-lines formats
- Exported files are uploaded to the destination with exponential backoff retries on transient failures
- Each export run produces an immutable audit record with row counts, byte counts, checksums, file manifest, and any error messages
- Schema evolution is detected: added columns are exported automatically; removed columns are logged as warnings; type changes are logged
- Column allowlist prevents PII columns (email, phone, SSN, etc.) from being exported without explicit confirmation
- An admin can view export runs in a filterable list (job, status, date), drill into details, and re-run failed exports
- `npm run build` and test suite pass with zero errors

## Standards

- **File formats**: Apache Parquet 2.x (with embedded schema), RFC 4180 CSV (with header), RFC 7464 JSON-text-sequences
- **Cron expressions**: standard UNIX cron, five-field format, with `@hourly`, `@daily`, `@weekly` shortcuts
- **Watermark column**: monotonically non-decreasing timestamp (ISO 8601) or sequence (bigint); the spec does not implement general CDC
- **Encryption-at-rest**: S3/Azure/GCS use server-side encryption (SSE-S3 / SSE-KMS / GCS-managed); client-side encryption out of scope
- **GDPR**: column allowlist + soft-delete tombstones enable data minimization and right-to-erasure propagation downstream
- **Audit**: all exports logged per OpenRegister `audit_log` schema (ADR-009)
- **Naming convention**: file paths respect `{schema}_{run_id}_{timestamp}` pattern; destination path template is configurable
