# Tasks: data-portability-full-export

## 1. Built part: tag and test
- [ ] 1.1 Add `@spec openspec/changes/data-portability-full-export/specs/data-portability/spec.md#REQ-DPE-001` to `ExportDataService::extractSchema()`, `format()` and `ExportSinkRegistry` (Enterprise)
- [ ] 1.2 Unit test: the list of exportable schemas equals the schemas declared in `lib/Settings/pipelinq_register.json` and `lib/Settings/register.d/*.json` (none excluded) (Enterprise)

## 2. Export all data
- [ ] 2.1 First check OpenRegister for a register export with objects (`lib/Service/Configuration*`, `lib/Controller/RegistersController.php` export) and reuse it through DI; document in `design.md` what is reused and what is added (ADR-011) (Enterprise)
- [ ] 2.2 `lib/Service/Export/FullExportService.php`: per schema JSON Lines and CSV through `ExportDataService::format()`, schema definitions as JSON Schema, attached files copied from Nextcloud Files, `manifest.json`; written as a ZIP into the administrator's Files (Enterprise)
- [ ] 2.3 `lib/BackgroundJob/FullExportJob.php` (QueuedJob) runs 2.2 and sends a notification with the file link through `OCP\Notification\IManager` (Enterprise)
- [ ] 2.4 Route `POST /api/export/full` with `#[AuthorizedAdminSetting]`-level guard (administrators only); returns 202 with the job id (Enterprise)
- [ ] 2.5 Unit tests: manifest counts, one file per schema per format, attached files present, non-admin refused (Enterprise)

## 3. Page
- [ ] 3.1 A section "Take your data with you" on the BI export page with the Export all data button and the state of the last export, shown to administrators only (Enterprise)
- [ ] 3.2 l10n nl and en (Enterprise)
- [ ] 3.3 (live pass) Run it on :8080 against the seed data and open the archive (Enterprise)
