---
status: draft
app: pipelinq
spec: bi-export-and-data-warehouse-sink
depends_on:
  - pipelinq
  - openconnector
---

# BI Export and Data Warehouse Sink

## Purpose

Pipelinq is operational software — it runs the day-to-day sales and contact-centre workflow. But every serious organisation eventually wants to ask questions pipelinq's UI cannot answer: "what is the cohort retention curve of customers we acquired in Q3 2024 by lead source?", "join sales pipeline to support ticket volume to forecast at-risk renewals", "feed deal data into our pricing-optimisation model in Snowflake". For all of these, the operational database is the wrong place — long-running analytical queries kill OLTP performance, and the joins span systems pipelinq doesn't own.

The industry-standard answer is a **data warehouse sink**: pipelinq ships its data, on a schedule, into the customer's chosen analytical store (Snowflake, BigQuery, Azure Synapse / Data Lake, AWS S3 + Athena, on-prem Postgres warehouse, etc.). From there, the customer's BI team owns the modelling, dashboards, and joins to other systems.

This spec adds a configurable, per-table export pipeline that:

1. Lets an admin select which pipelinq schemas (contact, lead, pipeline, deal, contactmoment, forecast_snapshot, etc.) to export.
2. Supports multiple destinations and formats (CSV / Parquet / JSON-lines) per export job.
3. Runs on a schedule (cron-style) or on-demand.
4. Supports both **full refresh** (re-export everything) and **incremental / CDC** (only rows changed since the last successful run).
5. Handles schema evolution gracefully — adding a column to a pipelinq schema should not break tomorrow's export.
6. Produces a per-run audit record with row counts, byte sizes, checksums, errors, and destination acknowledgement.

The non-goal is being a real-time streaming pipeline (Kafka / CDC log replication); minimum cadence is hourly, typical is daily. For real-time, customers should use the `webhook-events` spec.

## Data Model

**Export job** (new schema `export_job`): a configured, repeatable export. Fields: `name`, `description`, `source_schemas[]` (which pipelinq schemas to export), `destination_id` (FK to `export_destination`), `format` (csv|parquet|jsonl), `mode` (full|incremental), `incremental_watermark_column` (e.g. `updated_at`), `schedule_cron`, `enabled`, `partition_by` (optional, e.g. `created_at:day`), `row_filter_expression` (optional, e.g. `status != 'deleted'`), `column_allowlist[]` (optional, PII-redaction support), `created_by`, `created_at`.

**Export destination** (new schema `export_destination`): a sink config. Fields: `name`, `type` (s3|azure_data_lake|gcs|bigquery|snowflake|sftp|postgres), `connector_source_id` (FK to OpenConnector source — credentials live there), `path_template` (e.g. `s3://my-bucket/pipelinq/{schema}/{partition}/`), `compression` (none|gzip|snappy|zstd), `encryption_enabled` (server-side-encryption flag for S3/Azure), `naming_convention` (e.g. `{schema}_{run_id}_{timestamp}.parquet`).

**Export run** (new schema `export_run`): one execution of an export job. Fields: `job_id`, `started_at`, `ended_at`, `status` (pending|running|succeeded|failed|partial), `mode_used` (full|incremental), `watermark_from`, `watermark_to`, `row_count`, `byte_count`, `file_count`, `file_manifest_json` (list of files written with paths + sha256), `error_message`, `destination_ack` (e.g. BigQuery load-job ID, S3 ETags).

**Export schema snapshot** (new schema `export_schema_snapshot`): records the pipelinq schema structure at each run, to detect drift. Fields: `run_id`, `pipelinq_schema_name`, `column_definitions_json`, `compared_to_previous` (added|removed|changed|unchanged).

## Requirements

### REQ-001: Job configuration UI

**GIVEN** an admin opens the BI export settings page
**WHEN** they click "New export job"
**THEN** a form is rendered allowing them to name the job, pick one or more pipelinq schemas, pick a destination, format, mode, schedule (cron expression with human-readable preview), optional row filter, and optional column allowlist
**AND** "Save" persists the job in `disabled` state by default
**AND** "Test run" executes a dry-run that validates schema reachability, destination reachability, and produces a small sample file (max 100 rows) for inspection.

### REQ-002: Scheduled execution via cron

**GIVEN** an export job is enabled with `schedule_cron = "0 2 * * *"` (daily at 02:00)
**WHEN** the scheduled time arrives
**THEN** an `export_run` row is created in `pending` state
**AND** the run is picked up by the export worker within 60 seconds
**AND** the worker uses a distributed lock (per `job_id`) to ensure overlapping runs cannot execute simultaneously
**AND** if a previous run is still active when the schedule fires, the new run is skipped with `status = "skipped_overlap"` recorded.

### REQ-003: Full refresh mode

**GIVEN** an export job is configured with `mode = "full"`
**WHEN** the run executes
**THEN** every row in the configured source schemas (respecting `row_filter_expression` and `column_allowlist`) is extracted and written to the destination
**AND** the destination path template uses the run timestamp to avoid overwriting prior runs
**AND** the row count in `export_run.row_count` equals the queryable row count in the source at run start.

### REQ-004: Incremental / CDC mode

**GIVEN** an export job is configured with `mode = "incremental"` and `incremental_watermark_column = "updated_at"`
**WHEN** the run executes
**THEN** the run reads only rows where `updated_at > watermark_from` (the last successful run's `watermark_to`)
**AND** `watermark_to` is set to the maximum `updated_at` observed during the run
**AND** if the run fails, `watermark_from` for the next run is unchanged so no data is lost
**AND** soft-deleted rows are included with a `_deleted` marker column so the warehouse can apply tombstones.

### REQ-005: Format support

**GIVEN** a job is configured with `format = "parquet"` (or csv, jsonl)
**WHEN** files are written
**THEN** the chosen format is produced with appropriate type fidelity (parquet preserves native types; CSV stringifies everything; jsonl preserves JSON types)
**AND** for parquet, the schema is embedded in the file footer
**AND** for CSV, the first row is a header
**AND** compression (if configured) is applied per file.

### REQ-006: Destination upload with retries

**GIVEN** a run has produced one or more files locally (or in a staging area)
**WHEN** the upload step runs
**THEN** each file is uploaded to the destination using the OpenConnector source credentials
**AND** upload failures retry with exponential backoff (5 attempts, 1s/2s/4s/8s/16s)
**AND** after final failure, the run is marked `failed` with the error captured
**AND** partial successes (some files uploaded, others not) are marked `partial` and the manifest records per-file status.

### REQ-007: Schema evolution detection

**GIVEN** a previous run captured the source schema in `export_schema_snapshot`
**WHEN** the next run starts
**THEN** the current schema is compared to the previous snapshot
**AND** added columns are exported automatically (new column appears in next file)
**AND** removed columns are exported as null for the run, with a warning in the run log
**AND** type changes (e.g. int → string) raise a warning but do not block the run; the new type is used.

### REQ-008: PII redaction via column allowlist

**GIVEN** a job has `column_allowlist = ["id", "company_name", "industry", "created_at"]` configured
**WHEN** the run executes
**THEN** only the listed columns appear in the output (all other columns, including PII like email/phone, are dropped before write)
**AND** the run audit records which columns were dropped
**AND** an admin attempting to add a known-sensitive column (email, phone, bsn, etc.) without explicit confirmation receives a warning prompt.

### REQ-009: Audit and observability per run

**GIVEN** any run completes (success, failure, or partial)
**WHEN** the run finishes
**THEN** the `export_run` row contains start/end times, row counts, byte counts, sha256 checksums per file, watermark values, and any error messages
**AND** the run is visible in an "Export runs" admin list view with filtering by job, status, and date range
**AND** failed runs trigger a notification to the configured admin email / webhook within 5 minutes
**AND** the run audit retains 365 days by default (configurable).

### REQ-010: Destination type coverage

**GIVEN** an admin configures a destination
**WHEN** they choose the destination type
**THEN** the supported types are at minimum: AWS S3, Azure Data Lake Gen2, Google Cloud Storage, BigQuery (direct load), Snowflake (via stage + COPY), SFTP, generic Postgres (via COPY FROM)
**AND** each type's adapter validates connection on save
**AND** each adapter produces the destination-native acknowledgement (S3 ETag, BigQuery load-job ID, Snowflake query ID) stored in `destination_ack`.

## Standards

- **File formats** follow Apache Parquet 2.x, RFC 4180 CSV, and RFC 7464 JSON-text-sequences (`application/x-jsonlines`).
- **Cron expressions** follow standard UNIX cron with extensions (`@hourly`, `@daily`, `@weekly`); five-field format.
- **Watermark column** must be a monotonically non-decreasing timestamp or sequence; the spec does not attempt general CDC log replication.
- **Encryption-at-rest** for S3/Azure/GCS uses server-side encryption (SSE-S3 / SSE-KMS / GCS-managed); client-side encryption is out of scope.
- **GDPR**: column allowlist + soft-delete tombstones support data-minimisation and right-to-erasure propagation downstream.

## Cross-App

- **openconnector**: every destination is backed by an OpenConnector source; credentials never live in pipelinq itself. New destination types may require new OC connector types (e.g. BigQuery, Snowflake) — these are added in openconnector first.
- **openregister**: all four new schemas (`export_job`, `export_destination`, `export_run`, `export_schema_snapshot`) live in the `pipelinq` register.
- **launchpad**: can read `export_run` summary metrics to show export health on the ops dashboard.
- **docudesk**: the file manifest written to the destination is conceptually compatible with docudesk's "archive package" — future spec could turn an export run into a sealed archival package.
- **forecast-roll-up-and-categories**: forecast snapshots are a primary candidate source schema for export to BI tools.

## Target Users

- **BI engineer / data engineer**: configures the export jobs, consumes the resulting files/tables in their warehouse, builds the downstream models.
- **Pipelinq admin / IT operations**: owns destination connectivity, credentials rotation (via OC), monitors run health, responds to failures.
- **Data protection officer (DPO) / compliance**: reviews column allowlists to ensure PII flowing to the warehouse is justified and proportionate; uses run audit logs for accountability.
- **CFO / commercial analytics team**: ultimate consumer of the warehouse data, joining pipelinq sales/contact data to finance, marketing, and product data for cross-functional reporting.
- **External BI consultant**: receives a stable, well-documented file/table contract from the customer's pipelinq tenant without needing direct pipelinq access.
