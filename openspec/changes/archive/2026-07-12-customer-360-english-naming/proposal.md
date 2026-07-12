---
kind: code
---

## Why

Pipelinq's project rule is international/English canonical naming, with Dutch
surfaced only via i18n/l10n. The just-shipped `klantbeeld-360-activation`
change (merged 2026-07-12) introduced `KlantbeeldSummaryService`,
`KlantbeeldController`, the `GET /api/klantbeeld/summary` route, and the
`openspec/specs/klantbeeld-360` capability — all named in Dutch. This is
inconsistent with every other Pipelinq surface (`ClientDetail`, `LeadDetail`,
`RequestDetail`, etc.) and should be corrected to English canonical naming
(`customer-360`) before more code/spec references accrete on the Dutch name.

## What Changes

- Rename `lib/Service/KlantbeeldSummaryService.php` → `Customer360SummaryService.php` (class + file, via `git mv`)
- Rename `lib/Controller/KlantbeeldController.php` → `Customer360Controller.php` (class + file, via `git mv`)
- Rename route `GET /api/klantbeeld/summary` → `GET /api/customer-360/summary` in `appinfo/routes.php`
- Update `src/manifest.json` stat-widget `source.url` bindings on `ClientDetail` from `/apps/pipelinq/api/klantbeeld/summary` → `/apps/pipelinq/api/customer-360/summary`
- Rename `tests/Unit/Service/KlantbeeldSummaryServiceTest.php` → `Customer360SummaryServiceTest.php` and `tests/Unit/Controller/KlantbeeldControllerTest.php` → `Customer360ControllerTest.php` (class + file)
- Rename spec dir `openspec/specs/klantbeeld-360/` → `openspec/specs/customer-360/` (via `git mv`) and rename the "Klantbeeld"-titled requirements/prose inside to "Customer 360"
- Update all live references to the old capability/class/route names across docs, `@spec` tags, and the one other in-flight change (`customer-satisfaction-closed-loop`) that targets this capability
- No behavior change: pure rename — same aggregation logic, same RBAC guard, same doelbinding access-log entry

**BREAKING**: none externally (endpoint is same-origin, same-session, not a published/versioned API; no external consumers).

## Capabilities

### New Capabilities

(none)

### Modified Capabilities

- `klantbeeld-360`: capability renamed to `customer-360` — same MVP requirements (consolidated summary, declarative Client 360 rendering, quick actions, doelbinding access logging, MVP scope boundary), English canonical service/controller/route names instead of Dutch

## Impact

- `lib/Service/KlantbeeldSummaryService.php`, `lib/Controller/KlantbeeldController.php` (renamed)
- `appinfo/routes.php` (route name + path)
- `src/manifest.json` (5 stat-widget endpoint URLs on `ClientDetail`)
- `tests/Unit/Service/KlantbeeldSummaryServiceTest.php`, `tests/Unit/Controller/KlantbeeldControllerTest.php` (renamed)
- `openspec/specs/klantbeeld-360/` → `openspec/specs/customer-360/`
- `openspec/changes/customer-satisfaction-closed-loop/specs/klantbeeld-360/` (in-flight change's delta spec dir, targets the renamed capability)
- `docs/Features/klantbeeld-360.md`, `docs/static/screenshots/**/09-klantbeeld.png` references
- `CHANGELOG.md` (Unreleased/Changed entry)
- No database/schema changes — OpenRegister objects are untouched by this rename
