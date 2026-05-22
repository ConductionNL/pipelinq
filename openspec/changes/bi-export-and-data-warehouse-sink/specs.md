---
status: draft
---

# Specs: bi-export-and-data-warehouse-sink

**Feature tier**: MVP
**Spec refs**: `openspec/changes/bi-export-and-data-warehouse-sink/design.md`
**Standards**: OpenRegister CRUD API, OpenConnector source integration, Apache Parquet 2.x, RFC 4180 CSV, RFC 7464 JSON-text-sequences, ADR-009 (audit), ADR-005 (security)

---

## REQ-BIE-001: Destination configuration and validation

An admin can create, edit, and validate export destinations. Each destination references an OpenConnector source for credentials. Connection is tested before saving.

**Feature tier**: MVP
**Spec ref**: `design.md#ExportDestinationService`
**Files**: `lib/Service/ExportDestinationService.php`, `lib/Controller/ExportJobController.php` (REST endpoint)

### Scenario REQ-BIE-001-01: Create destination with OpenConnector credentials

- GIVEN an admin opens the "Export Destinations" page
- WHEN they click "New destination" and fill in: name, type (S3), connector source, path template
- AND they click "Test Connection"
- THEN the system MUST attempt to connect using the referenced OpenConnector source credentials
- AND if successful, MUST set `validation_status = "valid"`, `last_validated_at = now()`
- AND MUST display "Connection successful" (Dutch: "Verbinding geslaagd")
- AND the destination MUST be saveable with status "valid"

### Scenario REQ-BIE-001-02: Reject destination if credentials invalid

- GIVEN a destination with `validation_status = "invalid"`
- WHEN an admin attempts to enable an export job using this destination
- THEN the system MUST prevent job enablement
- AND MUST display error: "Destination is invalid. Test connection first."

### Scenario REQ-BIE-001-03: Support all destination types

- GIVEN a destination type dropdown
- WHEN the admin selects a type
- THEN the following types MUST be available:
  - `s3` (AWS S3)
  - `azure_data_lake` (Azure Data Lake Gen2)
  - `gcs` (Google Cloud Storage)
  - `bigquery` (BigQuery direct load)
  - `snowflake` (Snowflake stage + COPY)
  - `sftp` (SFTP)
  - `postgres` (PostgreSQL)

---

## REQ-BIE-002: Export job configuration

An admin can create and configure export jobs. A job specifies source schemas, destination, format, mode, and schedule. Jobs default to disabled.

**Feature tier**: MVP
**Spec ref**: `design.md#ExportJobService`
**Files**: `lib/Service/ExportJobService.php`, `lib/Controller/ExportJobController.php`

### Scenario REQ-BIE-002-01: Create job with full refresh mode

- GIVEN an admin opens "New Export Job"
- WHEN they fill in:
  - Name: "Daily contacts to S3"
  - Source schemas: [contact]
  - Destination: (valid S3 destination)
  - Format: Parquet
  - Mode: Full
  - Schedule: `@daily`
- AND they click "Save"
- THEN the job MUST be created with `enabled = false`
- AND the job MUST be visible in the job list with status "Disabled"

### Scenario REQ-BIE-002-02: Create job with incremental mode

- GIVEN a job with `mode = "incremental"`
- WHEN the form requires "Incremental watermark column"
- THEN a dropdown of timestamp and sequence columns from the source schema MUST be shown
- AND if the user leaves it empty, save MUST be rejected with error: "Watermark column is required for incremental mode"

### Scenario REQ-BIE-002-03: Cron schedule with human-readable preview

- GIVEN the schedule field in the job form
- WHEN the user enters `0 2 * * *`
- THEN the form MUST display: "Every day at 02:00" (Dutch: "Elke dag om 02:00")
- AND the form MUST accept shortcuts: `@hourly`, `@daily`, `@weekly`

### Scenario REQ-BIE-002-04: Filter expression validation

- GIVEN a job with optional row filter expression
- WHEN the user enters: `status != 'deleted'`
- AND they click "Validate"
- THEN the system MUST verify the filter is syntactically valid
- AND must check that all referenced columns exist in source schemas

### Scenario REQ-BIE-002-05: Column allowlist with PII warning

- GIVEN a column allowlist form field
- WHEN the user selects columns for export
- AND one of the unselected columns is known-sensitive (email, phone, ssn, bsn, iban)
- THEN the form MUST display a warning: "⚠️ The following columns contain sensitive data and are excluded: email, phone"
- AND if the user tries to include email without explicit confirmation, a modal MUST prompt: "Email is sensitive PII. Are you sure you want to export this?"

---

## REQ-BIE-003: Test run before enablement

Before enabling an export job, the admin can run a test that validates schemas, destination, and produces a sample file.

**Feature tier**: MVP
**Spec ref**: `design.md#ExportJobService`
**Files**: `lib/Service/ExportJobService.php`, `lib/Controller/ExportJobController.php`

### Scenario REQ-BIE-003-01: Test run downloads sample file

- GIVEN an unsaved or saved job with valid config
- WHEN the admin clicks "Test Run"
- THEN the system MUST:
  - Extract first 100 rows from source schemas
  - Format according to `job.format` (CSV/Parquet/JSONL)
  - NOT upload to destination
  - Display a modal with sample file download link
  - Show: "Test successful. 100 sample rows extracted."

### Scenario REQ-BIE-003-02: Test run validates destination connectivity

- GIVEN a job with a configured destination
- WHEN test run executes
- THEN the system MUST attempt to upload the sample file to the destination
- AND if upload fails, MUST display: "Destination connection failed: {error}"

### Scenario REQ-BIE-003-03: Test run validates schema references

- GIVEN a job with `source_schemas = ["contact", "nonexistent_schema"]`
- WHEN test run executes
- THEN the system MUST return error: "Schema 'nonexistent_schema' does not exist"
- AND MUST prevent job save

---

## REQ-BIE-004: Scheduled job execution with cron

Export jobs run on their configured cron schedule. A background worker polls for pending runs and executes them with distributed locking.

**Feature tier**: MVP
**Spec ref**: `design.md#ExportWorkerJob`
**Files**: `lib/Job/ExportWorkerJob.php`

### Scenario REQ-BIE-004-01: Cron triggers export run creation

- GIVEN a job with `schedule_cron = "0 2 * * *"` and `enabled = true`
- WHEN the scheduled time (02:00) arrives
- THEN an `export_run` row MUST be created with `status = "pending"`, `started_at = null`

### Scenario REQ-BIE-004-02: Worker picks up pending runs within 60 seconds

- GIVEN an `export_run` with `status = "pending"`
- WHEN 60 seconds have elapsed since creation
- THEN the worker MUST pick up the run and update `status = "running"`, `started_at = now()`

### Scenario REQ-BIE-004-03: Distributed lock prevents overlapping runs

- GIVEN a job with two consecutive scheduled runs (e.g., hourly)
- WHEN the first run is still executing when the second is scheduled
- THEN the second run's row MUST be created with `status = "pending"`
- AND when the worker attempts to acquire lock, it MUST find the first run locked
- AND MUST set the second run's status to `"skipped_overlap"` with reason logged
- AND MUST NOT execute the second run until the first completes

---

## REQ-BIE-005: Full refresh export mode

In full refresh mode, all rows from source schemas are exported, respecting filters and column allowlists.

**Feature tier**: MVP
**Spec ref**: `design.md#ExportDataService`
**Files**: `lib/Service/ExportDataService.php`

### Scenario REQ-BIE-005-01: Export all rows with full refresh

- GIVEN a job with `mode = "full"`, `source_schemas = ["contact"]`, no filter
- WHEN the export run executes
- THEN the system MUST SELECT * FROM contact (all rows)
- AND `export_run.row_count` MUST equal the actual row count in the table at run start

### Scenario REQ-BIE-005-02: Apply row filter expression

- GIVEN a job with `row_filter_expression = "status != 'deleted'"`
- WHEN export executes
- THEN rows with `status = 'deleted'` MUST be excluded from the export
- AND `export_run.row_count` MUST reflect the filtered count

### Scenario REQ-BIE-005-03: Apply column allowlist

- GIVEN a job with `column_allowlist = ["id", "name", "created_at"]`
- AND source schema has columns [id, name, email, phone, created_at, updated_at]
- WHEN export executes
- THEN the output MUST contain only [id, name, created_at]
- AND columns email, phone, updated_at MUST be dropped
- AND run audit MUST log: "Dropped columns: email, phone, updated_at"

---

## REQ-BIE-006: Incremental export mode with watermark

In incremental mode, only rows changed since the last successful run are exported, tracked via watermark column.

**Feature tier**: MVP
**Spec ref**: `design.md#ExportDataService`
**Files**: `lib/Service/ExportDataService.php`

### Scenario REQ-BIE-006-01: Incremental query via watermark column

- GIVEN a job with `mode = "incremental"`, `incremental_watermark_column = "updated_at"`
- AND the previous successful run had `watermark_to = "2026-05-19T23:59:59Z"`
- WHEN the new run executes
- THEN the system MUST SELECT * FROM schema WHERE updated_at > '2026-05-19T23:59:59Z'
- AND MUST set `watermark_to` to the maximum `updated_at` observed in this run
- AND `export_run.row_count` MUST be the incremental count, not the total

### Scenario REQ-BIE-006-02: Incremental run preserves watermark on failure

- GIVEN a job with `mode = "incremental"`, previous run had `watermark_to = "2026-05-19T12:00:00Z"`
- WHEN the new run fails partway through
- THEN `watermark_from` for the next run MUST still be `"2026-05-19T12:00:00Z"` (unchanged)
- AND no data is lost

### Scenario REQ-BIE-006-03: Soft-deleted rows include deletion marker

- GIVEN incremental export of a schema with soft-delete marker
- WHEN rows are deleted (marked with deletion timestamp)
- THEN the export MUST include those rows with a `_deleted: true` column
- AND downstream warehouse can apply tombstones for GDPR right-to-erasure

---

## REQ-BIE-007: Multiple output formats

The export supports CSV, Parquet, and JSON-lines formats with appropriate type handling and compression.

**Feature tier**: MVP
**Spec ref**: `design.md#ExportDataService`
**Files**: `lib/Service/ExportDataService.php`

### Scenario REQ-BIE-007-01: CSV format with header row

- GIVEN a job with `format = "csv"`
- WHEN export executes
- THEN the output file MUST follow RFC 4180:
  - First row: header with column names
  - Subsequent rows: CSV-formatted data (quoted where needed)
  - All values stringified

### Scenario REQ-BIE-007-02: Parquet format with embedded schema

- GIVEN a job with `format = "parquet"`
- WHEN export executes
- THEN the output file MUST:
  - Contain embedded schema in footer (Apache Parquet 2.x)
  - Preserve native types (timestamp, uuid, decimal, etc.)
  - Be readable without external schema registry

### Scenario REQ-BIE-007-03: JSON-lines format

- GIVEN a job with `format = "jsonl"`
- WHEN export executes
- THEN each row MUST be a JSON object on its own line (RFC 7464 compliant)
- AND preserve JSON types (strings, numbers, booleans, nulls, arrays, objects)

### Scenario REQ-BIE-007-04: Compression applied per format

- GIVEN a job with `format = "parquet"`, `compression = "snappy"`
- WHEN export executes
- THEN the output file MUST use Snappy compression
- AND `export_run.file_manifest_json` MUST record `compression_used = "snappy"`

---

## REQ-BIE-008: Destination upload with retries

Files are uploaded to the destination using OpenConnector credentials. Upload failures retry with exponential backoff.

**Feature tier**: MVP
**Spec ref**: `design.md#ExportUploadService`
**Files**: `lib/Service/ExportUploadService.php`

### Scenario REQ-BIE-008-01: Successful file upload

- GIVEN a formatted file and valid destination
- WHEN upload executes
- THEN the file MUST be written to the destination path (using path template and naming convention)
- AND the file MUST be readable and identical to source
- AND `file_manifest_json` MUST include path, size, rows, sha256, `upload_status = "success"`

### Scenario REQ-BIE-008-02: Exponential backoff retries

- GIVEN an upload that fails with transient error (e.g., timeout)
- WHEN retry logic executes
- THEN the system MUST retry with delays: 1s, 2s, 4s, 8s, 16s (5 attempts total)
- AND if all retries fail, MUST set `upload_status = "failed"` and log error

### Scenario REQ-BIE-008-03: Partial upload success

- GIVEN a job with multiple files
- WHEN some files upload successfully, others fail
- THEN `export_run.status` MUST be `"partial"`
- AND `error_message` MUST list failed files
- AND `file_manifest_json` MUST show per-file status (success/failed)

---

## REQ-BIE-009: Schema evolution detection

The system detects changes to pipelinq schemas between runs and handles gracefully.

**Feature tier**: MVP
**Spec ref**: `design.md#SchemaEvolutionService`
**Files**: `lib/Service/SchemaEvolutionService.php`, `lib/Listener/SchemaChangeListener.php`

### Scenario REQ-BIE-009-01: Detect added columns

- GIVEN a schema that had columns [id, name, email] in previous run
- WHEN the schema now has [id, name, email, phone] (phone added)
- THEN the system MUST:
  - Export the new `phone` column automatically
  - Record in `export_schema_snapshot.compared_to_previous`: "added: phone"
  - Log to run audit: "New column detected: phone"

### Scenario REQ-BIE-009-02: Detect removed columns

- GIVEN a schema that had [id, name, legacy_field] in previous run
- WHEN the schema now has [id, name] (legacy_field removed)
- THEN the system MUST:
  - Export `legacy_field` as null
  - Record: "removed: legacy_field"
  - Log warning to run: "Column removed: legacy_field (now null)"

### Scenario REQ-BIE-009-03: Detect type changes

- GIVEN a column `status` with type `string` in previous run
- WHEN it now has type `enum` in pipelinq
- THEN the system MUST:
  - Use the new type (`enum`)
  - Record: "changed: status (string -> enum)"
  - Log warning: "Type change detected for column status"

---

## REQ-BIE-010: Audit record per export run

Every export run produces an immutable audit record with row counts, file manifest, checksums, and error details.

**Feature tier**: MVP
**Spec ref**: `design.md#ExportRun`
**Files**: `lib/Service/ExportRunService.php`

### Scenario REQ-BIE-010-01: Complete audit record on success

- GIVEN a successful export run
- WHEN the run completes
- THEN `export_run` MUST include:
  - `started_at`, `ended_at` (ISO 8601 timestamps)
  - `status = "succeeded"`
  - `row_count`, `byte_count`, `file_count`
  - `file_manifest_json` with per-file: path, size, rows, sha256, compression_used
  - `watermark_to` (for incremental runs)
  - `destination_ack` (destination-specific acknowledgement)

### Scenario REQ-BIE-010-02: Error record on failure

- GIVEN a failed export run
- WHEN the run fails (e.g., destination unreachable)
- THEN `export_run` MUST include:
  - `status = "failed"`
  - `error_message`: descriptive error text
  - `file_manifest_json`: empty or partial list (only successfully uploaded files)

### Scenario REQ-BIE-010-03: Run retention policy

- GIVEN export runs in the database
- WHEN more than 365 days have passed since `started_at`
- THEN the run MUST be archived (moved to archive table or marked archived)
- AND the run MUST not appear in normal run list queries
- AND an admin can view archived runs separately

---

## REQ-BIE-011: Export run history and filtering

An admin can view export runs, filter by job/status/date, and drill into details.

**Feature tier**: MVP
**Spec ref**: `design.md#ExportRunController`
**Files**: `lib/Controller/ExportRunController.php`

### Scenario REQ-BIE-011-01: List runs with filtering

- GIVEN an admin opens "Export Runs"
- WHEN they filter by: Job = "Daily contacts", Status = "failed", Date = "last 7 days"
- THEN the list MUST show only runs matching all filters
- AND display columns: Job, Status, Rows, Bytes, Started, Ended, Errors
- AND sort by Started (newest first)

### Scenario REQ-BIE-011-02: View run details

- GIVEN a run in the list
- WHEN the admin clicks on it
- THEN the detail view MUST show:
  - Run header: job name, status, duration, row/byte counts
  - File manifest: table of files with paths, sizes, rows, sha256
  - Schema snapshots: list of schemas, change detection (if any)
  - Error log (if failed)
  - Retry button

### Scenario REQ-BIE-011-03: Retry failed run

- GIVEN a failed run
- WHEN the admin clicks "Retry"
- THEN a new `export_run` MUST be created with `status = "pending"`, same job config
- AND the worker MUST pick it up and execute it

---

## REQ-BIE-012: Observability and failure notification

Failed export runs trigger notifications to admins within 5 minutes. Failed runs are visible in observability dashboards.

**Feature tier**: MVP
**Spec ref**: `design.md#ExportWorkerJob`
**Files**: `lib/Job/ExportWorkerJob.php`, `lib/Service/NotificationService.php`

### Scenario REQ-BIE-012-01: Email notification on failure

- GIVEN a configured failure notification email in admin settings
- WHEN an export run fails
- THEN a notification email MUST be sent within 5 minutes to the configured address
- AND the email MUST include: job name, error message, link to run details

### Scenario REQ-BIE-012-02: Logging and observability

- GIVEN any export run (success or failure)
- WHEN the run completes
- THEN the worker MUST log to observability system (Sentry / datadog):
  - Job name, run ID, status, row count, duration
  - Any errors or schema changes detected
