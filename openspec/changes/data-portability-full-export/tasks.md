# Tasks: data-portability-full-export

## 1. Built part: tag and test
- [x] 1.1 Add `@spec openspec/changes/data-portability-full-export/specs/data-portability/spec.md#REQ-DPE-001` to `ExportDataService::extractSchema()`, `format()` and `ExportSinkRegistry` (Enterprise)
- [x] 1.2 Unit test: the list of exportable schemas equals the schemas declared in `lib/Settings/pipelinq_register.json` and `lib/Settings/register.d/*.json` (none excluded) (Enterprise)

## 2. Export all data
- [x] 2.1 First check OpenRegister for a register export with objects (`lib/Service/Configuration*`, `lib/Controller/RegistersController.php` export) and reuse it through DI; document in `design.md` what is reused and what is added (ADR-011) (Enterprise)
- [x] 2.2 `lib/Service/Export/FullExportService.php`: per schema JSON Lines and CSV through `ExportDataService::format()`, schema definitions as JSON Schema, attached files copied from Nextcloud Files, `manifest.json`; written as a ZIP into the administrator's Files (Enterprise)
  - Built: every declared schema as `data/<schema>.jsonl` and `.csv` read with `_rbac: false, _multitenancy: false` (a failed read fails the export), `schemas/<schema>.schema.json`, every object's attached files as `files/<object id>/<file name>` through OpenRegister's published `ObjectFileReaderInterface` (ConductionNL/openregister#10559; a failed file read fails the export), `manifest.json` (per schema: configured, object count, attached file count, files; whether attached files are included; export time), stored as `pipelinq-export-<date>.zip` in the administrator's Files. On an OpenRegister without the reader the rest is exported and the manifest says `attachedFilesIncluded: false`.
- [x] 2.3 `lib/BackgroundJob/FullExportJob.php` (QueuedJob) runs 2.2 and sends a notification with the file link through `OCP\Notification\IManager` (Enterprise)
- [x] 2.4 Route `POST /api/export/full` with `#[AuthorizedAdminSetting]`-level guard (administrators only); returns 202 with the job id (Enterprise)
- [x] 2.5 Unit tests: manifest counts, one file per schema per format, attached files present, non-admin refused (Enterprise)
  - `FullExportServiceTest`, `FullExportAttachedFilesTest` (attached files present under their object identifier, reader absent, failing file read, unsafe identifiers and names), `FullExportControllerTest`, `FullExportJobTest`, `NotifierExportSubjectTest`.

## 3. Page
- [x] 3.1 A section "Take your data with you" on the BI export page with the Export all data button and the state of the last export, shown to administrators only (Enterprise)
  - Built: `FullExportSection.vue` on `ExportJobs.vue`; the state comes from `GET /api/export/full` (`FullExportState`, written by the controller and the job), which only administrators get an answer to, so the section stays hidden for anyone else.
- [x] 3.2 l10n nl and en (Enterprise)
- [ ] 3.3 (live pass) Run it on :8080 against the seed data and open the archive (Enterprise)
