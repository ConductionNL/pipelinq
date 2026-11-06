# data-portability Specification (delta)

## Purpose

Everything a customer keeps in pipelinq can leave the product in open formats, in one action, when they decide to leave. Covers row take-your-data. Board PqBiExport.

## ADDED Requirements

### Requirement: Every schema can leave in an open format (REQ-DPE-001)

The system SHALL be able to export the objects of every schema in the pipelinq register in at least one open, documented format (CSV, JSON Lines or Parquet), with all their properties, including relations as object identifiers. No schema SHALL be excluded from export.

#### Scenario: An export job over a schema
- GIVEN an export job for schema `lead` with format JSON Lines
- WHEN the job runs
- THEN every lead the job's owner may read is written as one JSON object per line with all its properties

#### Scenario: A schema added later
- GIVEN a new schema added to the pipelinq register in a release
- WHEN an administrator lists the schemas an export can include
- THEN the new schema is in the list

### Requirement: An administrator can download all data in one archive (REQ-DPE-002)

An administrator SHALL be able to start "Export all data" from the BI export page. The system SHALL build, in a background job, one ZIP archive containing for every schema of the pipelinq register a JSON Lines file and a CSV file of all its objects, the schema definitions as JSON Schema, the files attached to objects under their object identifier, and a `manifest.json` listing each schema with its object count and the export time. When the archive is ready the administrator SHALL get a Nextcloud notification with a download link, and the archive SHALL be stored in the administrator's Nextcloud Files. Only administrators SHALL be able to start the export, and the archive SHALL hold all objects regardless of per-user read rules.

#### Scenario: A customer leaves
- GIVEN an administrator on the BI export page
- WHEN they click Export all data
- THEN a background job starts and the page says the archive is being prepared
- AND when it is done the administrator gets a notification and finds `pipelinq-export-<date>.zip` in their Files

#### Scenario: The archive is complete
- GIVEN a register with 1,200 clients, 340 leads and 90 attached files
- WHEN the archive is opened
- THEN `manifest.json` lists client 1,200 and lead 340, and the 90 files are present under their object identifiers

#### Scenario: A user who is not an administrator
- GIVEN a user without administrator rights
- WHEN they open the BI export page
- THEN there is no Export all data action, and the API refuses the request with 403
