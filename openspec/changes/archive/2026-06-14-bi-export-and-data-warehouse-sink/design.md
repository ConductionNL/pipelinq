# Design: bi-export-and-data-warehouse-sink

## Architecture

### Data Layer

#### New Schema: `export_destination`

Represents a configured sink for data exports. Credentials are stored in OpenConnector; this schema only references the connector source.

| Property | Type | Required | Description |
|---|---|---|---|
| `name` | string | Yes | Friendly name for the destination (e.g., "Production S3", "BigQuery Analytics") |
| `type` | string | Yes | Destination type: `s3`, `azure_data_lake`, `gcs`, `bigquery`, `snowflake`, `sftp`, `postgres` |
| `connector_source_id` | string | Yes | UUID reference to an OpenConnector source holding credentials |
| `path_template` | string | Yes | Template for destination path, e.g., `s3://my-bucket/pipelinq/{schema}/{partition}/` or `gs://dataset/{schema}/` |
| `compression` | string | No | Compression type: `none` (default), `gzip`, `snappy`, `zstd` |
| `encryption_enabled` | boolean | No | Enable server-side encryption for S3/Azure/GCS (default: true) |
| `naming_convention` | string | No | File naming pattern, e.g., `{schema}_{run_id}_{timestamp}.parquet`. Default: auto-generated |
| `validation_status` | string | No | Last validation result: `valid`, `invalid`, `untested`. Default: `untested` |
| `last_validated_at` | string | No | ISO 8601 timestamp of last connection test |

**Index**: `(type, connector_source_id)` for fast filtering by destination type and connector.

---

#### New Schema: `export_job`

Represents a configured, repeatable export task. Immutable once created; modifications produce a new version.

| Property | Type | Required | Description |
|---|---|---|---|
| `name` | string | Yes | Job name for display (e.g., "Daily leads to Snowflake") |
| `description` | string | No | Free-text job description |
| `source_schemas` | array | Yes | List of pipelinq schema names to export (e.g., ["contact", "lead", "deal"]) |
| `destination_id` | string | Yes | UUID reference to an `export_destination` |
| `format` | string | Yes | Output format: `csv`, `parquet`, or `jsonl` |
| `mode` | string | Yes | Export mode: `full` (re-export all) or `incremental` (CDC via watermark) |
| `incremental_watermark_column` | string | No | Column name for incremental tracking (e.g., `updated_at`). Required if `mode = incremental` |
| `schedule_cron` | string | Yes | UNIX cron expression (5-field) or shortcut (`@hourly`, `@daily`, `@weekly`). Example: `0 2 * * *` for daily at 02:00 |
| `enabled` | boolean | No | Whether the job runs on schedule (default: false until tested) |
| `partition_by` | string | No | Optional partitioning, e.g., `created_at:day` to create daily subdirectories |
| `row_filter_expression` | string | No | Optional SQL-style filter, e.g., `status != 'deleted'`. Applied before export |
| `column_allowlist` | array | No | If set, only these columns are exported; all others dropped. Enables PII redaction. Example: `["id", "name", "created_at"]` |
| `created_by` | string | Yes | Nextcloud user UID of job creator |
| `created_at` | string | Yes | ISO 8601 timestamp when job was created |

**Index**: `(enabled, schedule_cron, destination_id)` for fast scheduling lookup.

**Note**: When column_allowlist is set, the UI warns if a known-sensitive column (email, phone, ssn, bsn, iban, etc.) is NOT in the allowlist.

---

#### New Schema: `export_run`

Records one execution of an export job. Immutable once completed.

| Property | Type | Required | Description |
|---|---|---|---|
| `job_id` | string | Yes | UUID reference to the `export_job` |
| `started_at` | string | Yes | ISO 8601 timestamp when the run started |
| `ended_at` | string | No | ISO 8601 timestamp when the run completed (null if still running) |
| `status` | string | Yes | Run status: `pending`, `running`, `succeeded`, `failed`, `partial`, `skipped_overlap` |
| `mode_used` | string | Yes | Mode used for this run: `full` or `incremental` |
| `watermark_from` | string | No | For incremental runs: start of watermark range (previous run's `watermark_to`) |
| `watermark_to` | string | No | For incremental runs: end of watermark range (max value of watermark column observed this run) |
| `row_count` | integer | No | Number of rows exported in this run |
| `byte_count` | integer | No | Total bytes written to destination |
| `file_count` | integer | No | Number of files written |
| `file_manifest_json` | object | No | JSON array of files written, each with: `path`, `size_bytes`, `sha256`, `rows_in_file`, `compression_used`, `upload_status` |
| `error_message` | string | No | Error description if `status = failed` or `partial` |
| `destination_ack` | string | No | Destination's acknowledgement (e.g., S3 ETag, BigQuery load-job ID, Snowflake query ID) |

**Index**: `(job_id, started_at)` for fast run history lookup. `(status)` for filtering pending/failed runs.

**Retention**: 365 days by default (configurable). Older runs are archived.

---

#### New Schema: `export_schema_snapshot`

Records the structure of a pipelinq schema at export time, enabling drift detection.

| Property | Type | Required | Description |
|---|---|---|---|
| `run_id` | string | Yes | UUID reference to the `export_run` |
| `pipelinq_schema_name` | string | Yes | Name of the pipelinq schema captured (e.g., "contact", "lead") |
| `column_definitions_json` | object | Yes | JSON object mapping column names to their types and nullability. Example: `{"id": "uuid", "name": "string", "email": "string|null", "created_at": "timestamp"}` |
| `compared_to_previous` | array | No | Array of changes from prior run: `["added: phone", "removed: legacy_field", "changed: status (string -> enum)"]` |

**Index**: `(run_id, pipelinq_schema_name)` for fast lookups.

---

### Backend

#### `lib/Service/ExportDestinationService.php`

CRUD and validation for export destinations.

**Method: `createDestination(array $data): ExportDestination`**

Validates that the referenced OpenConnector source exists and is accessible. Attempts a test connection. Sets `validation_status = "valid"` on success, `"invalid"` on failure.

**Method: `testConnection(string $destination_id): bool`**

Connects to the destination using the referenced OpenConnector source credentials. Returns true if successful, false otherwise. Updates `last_validated_at`.

---

#### `lib/Service/ExportJobService.php`

CRUD and scheduling for export jobs.

**Method: `createJob(array $data): ExportJob`**

Validates:
- Referenced destination exists and is valid
- If `mode = incremental`, `incremental_watermark_column` is non-empty
- Cron expression is valid
- Source schemas exist in pipelinq
- If `column_allowlist` is set, validates column names against source schemas
- If `column_allowlist` contains known-sensitive columns without explicit confirmation, raises warning (logged, not blocking)

Sets `enabled = false` by default.

**Method: `enableJob(string $job_id): void`**

Enables the job for scheduled execution. Called after admin has tested the job.

---

#### `lib/Job/ExportWorkerJob.php`

Background worker that processes pending export runs. Runs continuously or is triggered by cron.

**Execution flow:**

1. Query `export_run` table for rows with `status = "pending"`, ordered by `created_at`
2. For each pending run:
   a. Acquire a distributed lock on `job_id` (prevent overlapping executions)
   b. If lock already held and run age > 60 seconds, update status to `"skipped_overlap"` and skip
   c. Update status to `"running"`, set `started_at = now()`
   d. Call `ExportDataService.extractData()` for each source schema
   e. Call `ExportUploadService.uploadFiles()` to destination
   f. Update `export_run` with `status = "succeeded"` (or `"failed"` / `"partial"`), `ended_at = now()`, row counts, checksums
   g. Write `export_schema_snapshot` records for each source schema
   h. Release lock
3. On any error, log to observability (Sentry / datadog) and trigger notification to pipelinq admin within 5 minutes

---

#### `lib/Service/ExportDataService.php`

Extracts data from pipelinq schemas and formats for output.

**Method: `extractData(ExportJob $job, ?ExportRun $previous_run): array`**

1. For each schema in `source_schemas`:
   a. If `mode = full`: SELECT * FROM schema (respecting filters and allowlist)
   b. If `mode = incremental`: SELECT * FROM schema WHERE watermark_column > previous_run.watermark_to (respecting filters and allowlist)
   c. Detect schema changes vs. `previous_run.export_schema_snapshot` for this schema
   d. Format rows according to `job.format` (CSV/Parquet/JSONL)
   e. Apply compression if `job.compression` is set
2. Return list of file objects: `{path, size_bytes, rows, sha256, compression_used}`

**Column allowlist logic**:
- If `job.column_allowlist` is set, filter columns: keep only listed columns, drop all others
- If `mode = incremental`, always include soft-delete marker: add `_deleted: true/false` column so warehouse can apply tombstones

**Schema evolution**:
- Detect added columns: export them automatically; log as "new column X detected"
- Detect removed columns: export as null; log as warning "column X no longer exists"
- Detect type changes (e.g., int → string): log warning, use new type
- Store comparison in `export_schema_snapshot.compared_to_previous`

---

#### `lib/Service/ExportUploadService.php`

Uploads files to the destination with retries and failure handling.

**Method: `uploadFiles(ExportRun $run, array $files): UploadResult`**

1. For each file:
   a. Determine destination path using `job.path_template` and `job.naming_convention`, substituting `{schema}`, `{partition}`, `{run_id}`, `{timestamp}`
   b. Get credentials from OpenConnector source
   c. Attempt upload with exponential backoff: 5 attempts (delays: 1s, 2s, 4s, 8s, 16s)
   d. On success: record file in `file_manifest_json` with `upload_status = "success"`, destination ack
   e. On final failure: record `upload_status = "failed"`, error message
2. If all files succeeded: set `status = "succeeded"`, `destination_ack` to last file's ack
3. If some succeeded, some failed: set `status = "partial"`, list failures in `error_message`
4. If all failed: set `status = "failed"`

---

#### `lib/Service/SchemaEvolutionService.php`

Detects schema changes between runs.

**Method: `compareSchemas(string $schema_name, ExportSchemaSnapshot $previous): array`**

Returns change list: `["added: col1", "removed: col2", "changed: col3 (type1 -> type2)"]`

---

#### `lib/Controller/ExportJobController.php`

REST API for job management.

**Endpoint: `POST /api/export/jobs`** (requires `export:create` permission)

Request body:
```json
{
  "name": "Daily contacts to S3",
  "description": "Full refresh of all contacts daily",
  "source_schemas": ["contact"],
  "destination_id": "...",
  "format": "parquet",
  "mode": "full",
  "schedule_cron": "@daily",
  "row_filter_expression": "status != 'deleted'",
  "column_allowlist": null
}
```

Returns: created `export_job` object.

**Endpoint: `GET /api/export/jobs/:id`**

**Endpoint: `PUT /api/export/jobs/:id`** (same body as POST)

**Endpoint: `DELETE /api/export/jobs/:id`**

**Endpoint: `POST /api/export/jobs/:id/test-run`**

Executes a dry-run (same as a real run, but with `row_count` capped at 100 for inspection). Returns sample file download link and validation report.

**Endpoint: `POST /api/export/jobs/:id/enable`**

Enables the job for scheduled execution.

---

#### `lib/Controller/ExportRunController.php`

REST API for run history and auditing.

**Endpoint: `GET /api/export/runs`** (requires `export:read` permission)

Query params:
- `job_id` (optional): filter to specific job
- `status` (optional): filter to `pending|running|succeeded|failed|partial|skipped_overlap`
- `date_from`, `date_to` (optional): filter by date range

Returns paginated list of `export_run` objects with basic fields (status, row_count, started_at).

**Endpoint: `GET /api/export/runs/:id`**

Returns full run details including `file_manifest_json` and schema snapshots.

**Endpoint: `POST /api/export/runs/:id/retry`** (requires `export:write` permission)

Re-runs a failed export. Creates a new `export_run` in `pending` state.

---

### Frontend

#### Export Jobs Page

- **List view**: table with columns [Name, Destination, Format, Mode, Schedule, Enabled?, Last Run, Status]
  - Row actions: Edit, Delete, Test, Enable/Disable, View Runs
- **Create/Edit form**:
  - Job name (text input)
  - Description (textarea)
  - Source schemas (multi-select dropdown, searchable)
  - Destination (dropdown, shows names + connection status)
  - Format (radio: CSV / Parquet / JSON-lines)
  - Mode (radio: Full / Incremental)
  - If Incremental: Watermark column (dropdown, filtered to timestamp/sequence columns)
  - Schedule (cron expression input with human-readable preview, e.g., "Every day at 02:00")
  - Filter expression (optional text input with syntax hint)
  - Column allowlist (optional multi-select; warns if known-sensitive columns are excluded)
  - Save / Cancel buttons
- **Test run modal**: shows validation status, sample file download, sample row count

#### Export Destinations Page

- **List view**: table [Name, Type, Connector, Validated?, Last Test, Status]
  - Row actions: Edit, Delete, Test Connection
- **Create/Edit form**:
  - Destination name (text input)
  - Type (dropdown: S3, Azure Data Lake, GCS, BigQuery, Snowflake, SFTP, Postgres)
  - Connector source (dropdown, filtered to matching type)
  - Path template (text input with placeholders hint: `{schema}`, `{partition}`, `{run_id}`, `{timestamp}`)
  - Compression (dropdown: None, Gzip, Snappy, Zstd)
  - Encryption enabled (checkbox, default true)
  - Naming convention (optional text input)
  - Test connection button
  - Save / Cancel buttons

#### Export Runs Page

- **List view**: table [Job, Status, Rows, Bytes, Started, Ended, Errors]
  - Filter by Job (dropdown), Status (multi-select), Date (date-range picker)
  - Sortable by Started (newest first)
  - Row actions: View Details, Retry (if failed)
  - Status badge colors: green (succeeded), grey (pending), blue (running), red (failed), orange (partial)
- **Run detail view**:
  - Header: Job name, status, duration, rows/bytes
  - File manifest: table [Filename, Size, Rows, SHA256, Status]
  - Schema snapshots: list of schemas, with "Changes detected" section (if any)
  - Error log (if `status = failed` or `partial`)
  - Retry button
  - Audit trail: who created this run, when, links to job config

#### Admin Settings

New section: "BI Export Configuration"

- Retention policy: number of days to keep export runs (default 365)
- Default compression: dropdown
- Failure notification email: text input
- At-risk run warning: trigger if no successful run in last N hours (configurable)

#### i18n

Dutch and English labels for all UI elements:

**Dutch**:
- `export.job.create` = "Exporttaak maken"
- `export.job.name` = "Taaknaam"
- `export.job.mode.full` = "Volledige vernieuwing"
- `export.job.mode.incremental` = "Incrementeel"
- `export.run.status.pending` = "In wachtrij"
- `export.run.status.running` = "Bezig"
- `export.run.status.succeeded` = "Voltooid"
- `export.run.status.failed` = "Mislukt"
- `export.destination.test.success` = "Verbinding geslaagd"
- `export.destination.test.failed` = "Verbinding mislukt"
- `export.column.pii_warning` = "⚠️ Dit kolom bevat mogelijk gevoelige gegevens (PII)"

**English**: (standard translations)

---

### Seed Data

#### Export Destination 1: S3
```json
{
  "name": "Production S3 - Analytics",
  "type": "s3",
  "connector_source_id": "oc-source-s3-prod-uuid",
  "path_template": "s3://my-analytics-bucket/pipelinq/{schema}/{partition}/",
  "compression": "snappy",
  "encryption_enabled": true,
  "naming_convention": "{schema}_{run_id}_{timestamp}.parquet",
  "validation_status": "valid",
  "last_validated_at": "2026-05-20T14:30:00Z"
}
```

#### Export Destination 2: BigQuery
```json
{
  "name": "Production BigQuery - Data Warehouse",
  "type": "bigquery",
  "connector_source_id": "oc-source-bq-prod-uuid",
  "path_template": "gs://my-staging-bucket/bq-loads/{schema}/",
  "compression": "gzip",
  "encryption_enabled": true,
  "validation_status": "valid",
  "last_validated_at": "2026-05-20T14:30:00Z"
}
```

#### Export Job 1: Full Refresh Contacts
```json
{
  "name": "Dagelijkse contacten naar S3",
  "description": "Volledige export van alle contacten, dagelijks om 02:00",
  "source_schemas": ["contact"],
  "destination_id": "export-dest-s3-uuid",
  "format": "parquet",
  "mode": "full",
  "schedule_cron": "0 2 * * *",
  "enabled": true,
  "partition_by": "created_at:day",
  "row_filter_expression": null,
  "column_allowlist": null,
  "created_by": "admin",
  "created_at": "2026-05-15T10:00:00Z"
}
```

#### Export Job 2: Incremental Leads with PII Redaction
```json
{
  "name": "Incrementele leads naar BigQuery (geanonimiseerd)",
  "description": "Alleen naam, bedrijf, en datums (geen email/phone)",
  "source_schemas": ["lead"],
  "destination_id": "export-dest-bq-uuid",
  "format": "parquet",
  "mode": "incremental",
  "incremental_watermark_column": "updated_at",
  "schedule_cron": "@daily",
  "enabled": true,
  "partition_by": null,
  "row_filter_expression": "status != 'deleted'",
  "column_allowlist": ["id", "title", "value", "expected_close_date", "created_at", "updated_at"],
  "created_by": "admin",
  "created_at": "2026-05-16T09:00:00Z"
}
```

#### Export Run 1: Succeeded
```json
{
  "job_id": "export-job-1-uuid",
  "started_at": "2026-05-20T02:00:15Z",
  "ended_at": "2026-05-20T02:05:30Z",
  "status": "succeeded",
  "mode_used": "full",
  "watermark_from": null,
  "watermark_to": null,
  "row_count": 1247,
  "byte_count": 3456789,
  "file_count": 1,
  "file_manifest_json": [
    {
      "path": "s3://my-analytics-bucket/pipelinq/contact/2026-05-20/contact_run-uuid_20260520T020530Z.parquet",
      "size_bytes": 3456789,
      "rows_in_file": 1247,
      "sha256": "abc123def456...",
      "compression_used": "snappy",
      "upload_status": "success"
    }
  ],
  "error_message": null,
  "destination_ack": "s3-etag-hash"
}
```

#### Export Run 2: Failed
```json
{
  "job_id": "export-job-2-uuid",
  "started_at": "2026-05-20T00:00:15Z",
  "ended_at": "2026-05-20T00:02:45Z",
  "status": "failed",
  "mode_used": "incremental",
  "watermark_from": "2026-05-19T00:00:00Z",
  "watermark_to": null,
  "row_count": null,
  "byte_count": null,
  "file_count": 0,
  "file_manifest_json": [],
  "error_message": "Connection timeout to BigQuery after 5 retries. Check credentials in OpenConnector.",
  "destination_ack": null
}
```

#### Export Schema Snapshot
```json
{
  "run_id": "export-run-1-uuid",
  "pipelinq_schema_name": "contact",
  "column_definitions_json": {
    "id": "uuid",
    "name": "string",
    "email": "string|null",
    "phone": "string|null",
    "address": "string|null",
    "website": "string|null",
    "industry": "string|null",
    "notes": "string|null",
    "contacts_uid": "string|null",
    "created_at": "timestamp",
    "updated_at": "timestamp"
  },
  "compared_to_previous": []
}
```
