# Contactmomenten Rapportage — CSV Export Delta

**Spec refs**: `contactmomenten-rapportage`
**Standards**: Dutch Excel CSV conventions (semicolon separator, UTF-8 BOM)

## MODIFIED Requirements

### Requirement: CSV export

The system MUST support exporting the reporting dashboard's data (KPIs, channel distribution,
agent performance) as a downloadable CSV file, generated server-side by
`ReportingController::exportCsv()` via the existing `ReportingService::generateCsv()` helper. The
endpoint MUST require an authenticated session, MUST validate the `from`/`to` (or `period`)
parameters the same way the dashboard's JSON endpoints do, and MUST return the file as a
`DataDownloadResponse` with `text/csv; charset=UTF-8` content type — not a `501 Not Implemented`
stub.

**Feature tier**: MVP

#### Scenario: Authenticated user exports the dashboard as CSV

- GIVEN an authenticated user viewing the contactmomenten reporting dashboard for a date range
- WHEN they click "Exporteer als CSV"
- THEN the system MUST generate a CSV file covering the KPI, channel-distribution, and
  agent-performance data currently displayed, with a header row
- AND the CSV MUST use semicolon separators and a UTF-8 BOM prefix
- AND the browser MUST receive a file download, not a JSON error response

#### Scenario: Unauthenticated request is rejected

- GIVEN no active Nextcloud session
- WHEN a request hits the export endpoint
- THEN the system MUST respond `401 Unauthorized`
- AND MUST NOT return any report data

#### Scenario: Invalid date range is rejected

- GIVEN an authenticated session
- WHEN the export endpoint is called with a missing or malformed `from`/`to` pair
- THEN the system MUST respond `400 Bad Request`
- AND MUST NOT attempt to generate a CSV
