## 1. Confirm the existing pieces

- [ ] 1.1 Confirm `ReportingService::generateCsv(array $headers, array $rows): string`
      (`lib/Service/ReportingService.php:464-477`) still produces semicolon-separated, UTF-8-BOM
      CSV per spec — no change expected, just confirm it fits this endpoint's data shape too.
- [ ] 1.2 Confirm `ReportingController`'s existing `resolvePeriodRange()` /
      `isValidDate()` helpers (used by `getKpis()`/`getChannels()`) can be reused verbatim for
      `exportCsv()`'s `from`/`to`/`period` params.

## 2. Build the export table

- [ ] 2.1 In `ReportingController::exportCsv()`, resolve `[$from, $to]` via
      `resolvePeriodRange()` and validate them the same way `getKpis()` does (401/400 on
      auth/param failures — keep parity with the sibling endpoints).
- [ ] 2.2 Call `$this->reportingService->getKpis($from, $to)`,
      `getChannelDistribution($from, $to)`, and `getAgentPerformance($from, $to)` — the same
      three calls already backing the dashboard's JSON views — and flatten them into a single
      headers row + data rows table covering all three sections (spec: "all displayed data
      points including headers").
- [ ] 2.3 Call `$this->reportingService->generateCsv(headers: ..., rows: ...)` to produce the
      CSV string.

## 3. Return the file

- [ ] 3.1 Replace the `501` stub response with
      `new DataDownloadResponse(data: $csv, filename: sprintf('rapportage-%s-%s.csv', $from, $to),
      contentType: 'text/csv; charset=UTF-8')`, matching the existing pattern in
      `ForecastController::snapshots()` (`lib/Controller/ForecastController.php:129-136`).
- [ ] 3.2 Wrap the service calls in the same `try { } catch (\Throwable) { }` → 500 pattern the
      other methods in this controller use (ADR-005: no stack traces cross the wire).

## 4. Verify

- [ ] 4.1 Add/update a PHPUnit test for `ReportingController::exportCsv()` asserting: 401 with no
      session, 400 on invalid dates, and a 200 `DataDownloadResponse` with `text/csv` content
      type and a UTF-8 BOM (`\xEF\xBB\xBF`) prefix on the happy path.
- [ ] 4.2 Manually click "Exporteer als CSV" in `ChannelDistributionSection.vue` against a local
      instance and confirm the browser downloads a real `.csv` file (opens correctly in Excel:
      semicolon-separated, Dutch characters intact) instead of hitting a JSON 501 page.
- [ ] 4.3 Fix any pre-existing lint/test warnings encountered in
      `ReportingController.php`/`ReportingService.php` while touching them (CLAUDE.md rule).
