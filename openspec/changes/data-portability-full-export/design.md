# Design: data-portability-full-export

## What OpenRegister already offers (task 2.1, ADR-011)

Read at openregister `main` on 14 November 2026.

- `GET /api/registers/{id}/export?format=configuration&includeObjects=true`
  (`RegistersController::export()` through `Controller/Support/RegisterDownloads::configuration()`,
  built by `Service/ConfigurationService` with `Configuration/ConfigurationExportRun`) answers one
  OpenAPI configuration document: the register, its schemas and, with `includeObjects`, its objects.
  It is an HTTP download for the caller, not an in-process service, and its one JSON document is not
  the per-schema JSON Lines and CSV the spec asks for.
- `format=excel`, `csv` and `pdf` export one schema as a table for a download.
- Object files live behind `Service/FileService` (`getFiles()`, `getFile()`). OpenRegister publishes
  only `lib/Contract/ObjectServiceInterface`, `ObjectEntityInterface`, `RegisterSlugResolverInterface`
  and `EmailLinkReaderInterface`; `FileService` is not among them.

## What is reused

- `OCA\OpenRegister\Contract\ObjectServiceInterface` (published) to read each schema's objects with
  `_rbac: false, _multitenancy: false`; a failed read fails the export.
- Pipelinq's own `ExportDataService::format()` (the BI export) for the JSON Lines and CSV rows, so the
  archive and a BI export write a record the same way.
- The schema definitions as pipelinq declares them (`lib/Settings/pipelinq_register.json` and
  `lib/Settings/register.d/*.json`), written as JSON Schema.

## What is added

- `Service/Export/FullExportService`: the archive (`data/<schema>.jsonl` and `.csv`,
  `schemas/<schema>.schema.json`, `manifest.json`) stored in the administrator's Files.
- `BackgroundJob/FullExportJob`: builds it outside the request and notifies the administrator.
- `Controller/FullExportController`: `POST /api/export/full` starts it, `GET /api/export/full` says how
  the last one went; both for Nextcloud administrators only.
- `Service/Export/FullExportState`: the last export's state (queued, ready with the archive's name and
  file id, failed) in the app config, for the page.
- `src/components/FullExportSection.vue`: "Take your data with you" on the BI export page, shown only
  when the server answers the state (administrators).

## Not reused, and why

- The configuration export: one document per register over HTTP, not callable through a published
  contract, and not the format the spec asks for. Calling it would also bypass the archive's manifest.
- Object files: copying them needs OpenRegister's `FileService`, which has no published contract. The
  attached files stay out of the archive until OpenRegister publishes one (tasks 2.2 and 2.5 keep that
  part open).
