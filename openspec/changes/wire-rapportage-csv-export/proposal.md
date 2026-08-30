---
kind: code
---

## Why

The `contactmomenten-rapportage` spec requires CSV export of the reporting dashboard:

> "The system MUST support exporting report data for use in external BI tools and management
> presentations. … WHEN they click 'Exporteer als CSV' … THEN the system MUST generate a CSV
> file with all displayed data points including headers … the CSV MUST use semicolon separators
> and UTF-8 with BOM."
(`openspec/specs/contactmomenten-rapportage/spec.md:221-231`)

The frontend ships this button live: `src/components/rapportage/ChannelDistributionSection.vue:19-20,116`
navigates `window.location.href` to `/apps/pipelinq/api/rapportage/export`. That route
(`appinfo/routes.php:103`) resolves to `ReportingController::exportCsv()`
(`lib/Controller/ReportingController.php:260-273`), which unconditionally returns:

```php
// CSV export requires OpenRegister data integration.
// Returning 501 until OR contactmoment retrieval is wired.
return new JSONResponse(
    ['message' => 'Export not yet implemented'],
    Http::STATUS_NOT_IMPLEMENTED,
);
```

Clicking "Exporteer als CSV" on the reporting dashboard navigates the browser to a JSON 501
error page — a shipped, spec'd MUST requirement that silently does nothing useful.

The fix is smaller than the stub comment suggests: `ReportingService::generateCsv(array $headers,
array $rows): string` (`lib/Service/ReportingService.php:464-477`) **already implements** the
exact semicolon + UTF-8-BOM format the spec requires, and carries the identical `@spec
openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-50` tag as the controller
stub — it was built for this exact endpoint and never wired in. It has exactly one caller today:
`ForecastExportService::toCsv()` (`lib/Service/ForecastExportService.php:138`), used by a
*different* export flow (`ForecastController::snapshots()`,
`lib/Controller/ForecastController.php:129-136`, which returns a `DataDownloadResponse` the same
way this change should). All the reporting dashboard data (`getKpis`, `getChannelDistribution`,
`getChannelTrend`, `getAgentPerformance`) is already served as JSON by this same controller —
`exportCsv()` only needs to reuse those existing service calls and feed the rows to the already-
built `generateCsv()`.

## What Changes

- Implement `ReportingController::exportCsv()` for real: accept the same `from`/`to`(/`period`)
  query params as `getKpis()`/`getChannels()`, call the existing `ReportingService` getters
  (`getKpis`, `getChannelDistribution`, `getAgentPerformance`), build a headers+rows table
  covering "all displayed data points" per the spec, pass them to the existing
  `ReportingService::generateCsv()`, and return a `DataDownloadResponse` (mirroring
  `ForecastController::snapshots()`'s CSV branch) instead of the 501 stub.
- No new service method is required — `generateCsv()` already exists and is spec-conformant;
  this only wires the controller to build headers/rows and call it.
- Not BREAKING: the endpoint currently returns 501 to every caller: no existing consumer depends
  on that response.

## Capabilities

### Modified Capabilities
- `contactmomenten-rapportage`: the CSV Export requirement moves from unimplemented (501 stub) to
  implemented, sourced from the same `ReportingService` data the JSON dashboard endpoints already
  serve.

## Impact

- `lib/Controller/ReportingController.php` — `exportCsv()` rewritten to build the export table
  and return `DataDownloadResponse`.
- `lib/Service/ReportingService.php` — no change (its `generateCsv()` is reused as-is); may gain
  a small helper to assemble the combined KPI + channel-distribution + agent-performance row set
  if that's cleaner than inlining it in the controller.
- No schema, no manifest, no route change (the route already exists and is exercised by the live
  UI button).
