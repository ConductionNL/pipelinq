# Tasks: contactmomenten-rapportage

## Task 1: Implementation planning
- **Spec ref**: specs/contactmomenten-rapportage/spec.md
- **Status**: in-progress
- **Acceptance criteria**: Requirements from spec are decomposed into implementable tasks
- **Notes**: Spec covers 12 requirements across KPI dashboards, analytics, export, SLA config, and data retention. Pipelinq's role is to provide reporting APIs and dashboards; OpenRegister provides data aggregation layer.

## Task 2: ReportingService enhancements — KPI calculations
- **Spec ref**: specs/contactmomenten-rapportage/spec.md#requirement-1
- **Status**: todo
- **Description**: Extend ReportingService with methods to calculate KPI metrics from contactmoment data
- **Acceptance criteria**:
  - Method to calculate total contacts for a date/date range
  - Method to calculate contacts per channel distribution
  - Method to calculate average handling time
  - Method to calculate first-call resolution (FCR) rate
  - Method to calculate SLA compliance percentage
  - All methods accept date range, optional channel, optional agent filters
  - Methods return data suitable for dashboard rendering

## Task 3: KPI Dashboard API endpoints
- **Spec ref**: specs/contactmomenten-rapportage/spec.md#scenario-1.1
- **Status**: todo
- **Description**: Implement REST API endpoints in ReportingController for dashboard metrics
- **Acceptance criteria**:
  - GET /api/rapportage/kpi/daily — returns today's KPI metrics
  - GET /api/rapportage/kpi/summary — returns aggregated summary
  - GET /api/rapportage/kpi/today-vs-lastweek — returns trend indicators
  - All endpoints require @NoAdminRequired with proper authorization
  - Response includes JSON with total contacts, per-channel breakdown, FCR%, SLA%, queue length, active agents

## Task 4: Channel Analytics API
- **Spec ref**: specs/contactmomenten-rapportage/spec.md#requirement-2
- **Status**: todo
- **Description**: Implement channel distribution and comparison endpoints
- **Acceptance criteria**:
  - GET /api/rapportage/channels/distribution — returns contact volume per channel over time
  - GET /api/rapportage/channels/comparison — returns per-channel metrics vs previous period
  - Supports daily/weekly/monthly granularity via ?granularity= param
  - Returns: total contacts, avg handling time, FCR rate, SLA compliance per channel
  - Includes month-over-month comparison data

## Task 5: Wait Time Monitoring API
- **Spec ref**: specs/contactmomenten-rapportage/spec.md#requirement-3
- **Status**: todo
- **Description**: Implement real-time and historical wait time reporting
- **Acceptance criteria**:
  - GET /api/rapportage/queue/real-time — returns current queue statistics
  - GET /api/rapportage/queue/historical — returns weekly wait time analytics
  - Real-time: number waiting, longest/avg wait time, estimated wait for new callers
  - Historical: avg wait per day, peak hours, % within SLA
  - Supports configurable update interval (default 30s)

## Task 6: Agent Performance Metrics API
- **Spec ref**: specs/contactmomenten-rapportage/spec.md#requirement-4
- **Status**: todo
- **Description**: Implement per-agent and team performance endpoints
- **Acceptance criteria**:
  - GET /api/rapportage/agents/individual/:userId — individual agent stats
  - GET /api/rapportage/agents/team — ranked team list with avg comparison
  - GET /api/rapportage/agents/workload — bar chart data for workload distribution
  - GET /api/rapportage/agents/:userId/trend — 30-day trend data
  - Returns: contacts handled, avg handling time, FCR rate, contacts/hour, deviation from team avg

## Task 7: Trend Reporting API
- **Spec ref**: specs/contactmomenten-rapportage/spec.md#requirement-5
- **Status**: todo
- **Description**: Implement monthly trends and peak hours heatmap endpoints
- **Acceptance criteria**:
  - GET /api/rapportage/trends/monthly — 6-month monthly breakdown
  - GET /api/rapportage/trends/peak-hours — heatmap data (day-of-week × hour)
  - GET /api/rapportage/trends/subjects — category frequency trends
  - Returns trend direction indicators, subject emergence alerts

## Task 8: CSV/PDF Export enhancement
- **Spec ref**: specs/contactmomenten-rapportage/spec.md#requirement-6
- **Status**: todo
- **Description**: Complete export functionality for CSV and PDF with proper formatting
- **Acceptance criteria**:
  - CSV: semicolon-separated, UTF-8 BOM, all data points, proper date formatting
  - PDF: includes dashboard layout, charts, KPI values, date/timestamp, municipality name
  - POST /api/rapportage/export/csv — downloads CSV report
  - POST /api/rapportage/export/pdf — downloads PDF report
  - Both support date range and optional filters (channel, agent)

## Task 9: SLA Configuration admin endpoints
- **Spec ref**: specs/contactmomenten-rapportage/spec.md#requirement-7
- **Status**: todo
- **Description**: Implement admin-only SLA configuration endpoints
- **Acceptance criteria**:
  - GET /api/admin/rapportage/sla — retrieve all SLA targets
  - PUT /api/admin/rapportage/sla — update SLA targets
  - Per-channel targets: phone (30s), email (8h), counter (5m), chat (30s)
  - Support warning thresholds (green/orange/red color coding)
  - Changes take effect immediately in dashboard calculations

## Task 10: WOO-compliant anonymized reporting
- **Spec ref**: specs/contactmomenten-rapportage/spec.md#requirement-8
- **Status**: todo
- **Description**: Implement WOO-compliant report generation without PII
- **Acceptance criteria**:
  - GET /api/rapportage/woo/quarterly — anonymized quarterly report
  - GET /api/rapportage/woo/annual — year-over-year service statistics
  - GET /api/rapportage/woo/benchmark — KPI comparison to VNG benchmarks
  - No agent names, client names, or contact details included
  - Supports benchmark data configuration
  - Returns aggregated statistics only

## Task 11: Contact Moment duration and categorization
- **Spec ref**: specs/contactmomenten-rapportage/spec.md#requirement-9,#requirement-10
- **Status**: todo
- **Description**: Ensure contactmoment schema supports duration tracking and subject categorization
- **Acceptance criteria**:
  - Contactmoment schema in OpenRegister has required fields:
    - duration (ISO 8601 format)
    - subject (primary category)
  - Duration timer functionality in frontend (auto-start for phone, manual for email)
  - Duration correction within 24 hours with audit trail
  - Subject selection from configurable category list
  - Subject analytics available in dashboard

## Task 12: Vue Dashboard Component
- **Spec ref**: specs/contactmomenten-rapportage/spec.md#requirement-1
- **Status**: todo
- **Description**: Implement Vue.js frontend component for KPI dashboard
- **Acceptance criteria**:
  - Dashboard displays today's KPI metrics with visual widgets
  - 60-second auto-refresh with "Last updated" timestamp
  - Trend indicators (vs last week) on each metric
  - Responsive layout, WCAG AA accessible
  - Color-coded KPI status (green/orange/red based on SLA)
  - Empty state message when no data available
  - Uses @nextcloud/axios for API calls

## Task 13: Testing — ReportingService unit tests
- **Spec ref**: specs/contactmomenten-rapportage/spec.md
- **Status**: todo
- **Description**: Write PHPUnit tests for ReportingService KPI calculation methods
- **Acceptance criteria**:
  - Minimum 5 test methods covering:
    - KPI calculation with sample data
    - FCR rate calculation
    - SLA compliance calculation
    - Channel distribution
    - Trend calculation
  - All tests pass with ./vendor/bin/phpunit
  - Coverage includes edge cases (empty data, single contact, etc.)

## Task 14: Testing — ReportingController API tests
- **Spec ref**: specs/contactmomenten-rapportage/spec.md
- **Status**: todo
- **Description**: Write PHPUnit tests for ReportingController endpoints
- **Acceptance criteria**:
  - Minimum 5 test methods covering:
    - KPI endpoints return valid JSON
    - Authorization checks work correctly
    - Channel analytics endpoint with granularity param
    - Export endpoints generate proper content
    - Error handling for invalid date ranges
  - All tests pass

## Task 15: Quality gates — static analysis and formatting
- **Spec ref**: ADR-000
- **Status**: todo
- **Description**: Pass all quality checks before PR submission
- **Acceptance criteria**:
  - composer check:strict passes with no errors
  - All new PHP files have SPDX-License-Identifier header
  - All new classes/methods have @spec PHPDoc tags
  - npm run lint passes for any Vue/JS changes
  - No trailing whitespace or style violations
