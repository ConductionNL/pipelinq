# Tasks: contactmomenten-rapportage

## Task 1: Implementation planning
- [ ] Break down all 12 requirements into implementable backend and frontend tasks
- [ ] Create API endpoint list for dashboard, analytics, and export
- [ ] Define database schemas for aggregated KPI data and report snapshots
- [ ] Identify existing patterns in codebase to copy (controllers, services, Vue components)

## Task 2: Extend ReportingService with KPI dashboard endpoints
- [ ] Implement `getDailyKpiSummary()` — returns today's KPIs (total contacts, per channel, FCR%, SLA%, queue length, active agents)
- [ ] Implement `getKpiTrend(channel, metric, days)` — returns KPI values with trend indicators for a date range
- [ ] Add tests (min 3): test getDailyKpiSummary with data, test trend calculation, test empty state
- [ ] Add route: GET /api/rapportage/dashboard/kpi-summary
- [ ] Add route: GET /api/rapportage/dashboard/trends?channel=telefoon&metric=fcr&days=7

## Task 3: Implement channel analytics endpoints
- [ ] Implement `getChannelDistribution(dateFrom, dateTo, granularity)` — returns contact volumes per channel by day/week/month
- [ ] Implement `getChannelComparison(monthYearCurrent)` — returns per-channel metrics vs previous month
- [ ] Implement `getChannelShiftAnalysis(months)` — identifies channels with >5% distribution changes
- [ ] Add tests (min 3): test distribution calculation, test comparison logic, test shift detection
- [ ] Add route: GET /api/rapportage/analytics/channel-distribution
- [ ] Add route: GET /api/rapportage/analytics/channel-comparison
- [ ] Add route: GET /api/rapportage/analytics/channel-shifts

## Task 4: Implement wait time monitoring endpoints
- [ ] Implement `getQueueStatistics()` — returns real-time queue stats (count, longest wait, avg wait, estimated wait for new callers)
- [ ] Implement `getHistoricalWaitTimes(dateFrom, dateTo)` — returns wait time data with SLA breach indicators
- [ ] Implement `getWaitTimeSlaAlertStatus()` — checks if SLA compliance < 80% and returns alert status
- [ ] Add tests (min 3): test queue stats calculation, test historical data aggregation, test alert detection
- [ ] Add route: GET /api/rapportage/queue/statistics
- [ ] Add route: GET /api/rapportage/queue/historical
- [ ] Add route: GET /api/rapportage/queue/sla-alert

## Task 5: Implement agent performance metrics endpoints
- [ ] Implement `getAgentStatistics(agentId)` — returns per-agent metrics (contacts today, avg handling time, FCR%, contacts/hour)
- [ ] Implement `getTeamOverview()` — returns ranked list of team members with workload distribution
- [ ] Implement `getAgentWorkloadDistribution()` — returns bar chart data with >20% above-average flagging
- [ ] Implement `getAgentPerformanceTrend(agentId, days)` — returns 30-day trend of contacts, FCR, handling time
- [ ] Add tests (min 3): test agent stats calculation, test team ranking logic, test trend data
- [ ] Add route: GET /api/rapportage/agents/{agentId}/statistics
- [ ] Add route: GET /api/rapportage/agents/team-overview
- [ ] Add route: GET /api/rapportage/agents/workload-distribution
- [ ] Add route: GET /api/rapportage/agents/{agentId}/trends

## Task 6: Implement trend reporting endpoints
- [ ] Implement `getMonthlyTrendReport(months)` — returns per-month KPIs with trend lines for 6 months
- [ ] Implement `getPeakHoursHeatmap(weeks)` — returns contact volume by day-of-week and hour-of-day
- [ ] Implement `getSubjectTrendAnalysis(months)` — returns subject/category frequency changes with trending detection
- [ ] Add tests (min 3): test monthly trend calculation, test heatmap data structure, test trending detection
- [ ] Add route: GET /api/rapportage/trends/monthly
- [ ] Add route: GET /api/rapportage/trends/peak-hours
- [ ] Add route: GET /api/rapportage/trends/subjects

## Task 7: Implement WOO/Open Data reporting
- [ ] Implement `generateWooReport(dateFrom, dateTo)` — returns anonymized quarterly report (no PII)
- [ ] Implement `generateAnnualServiceStatistics(year)` — returns year-over-year comparison with VNG template
- [ ] Implement `getBenchmarkComparison(municipalitySize)` — returns KPIs vs VNG benchmark averages
- [ ] Add tests (min 3): test WOO anonymization (no agent/client names), test annual stats, test benchmark lookup
- [ ] Add route: GET /api/rapportage/woo/report
- [ ] Add route: GET /api/rapportage/woo/annual-statistics
- [ ] Add route: GET /api/rapportage/woo/benchmark-comparison

## Task 8: Extend export functionality (PDF, scheduled delivery)
- [ ] Enhance `exportCsv()` to respect date range and channel filters
- [ ] Implement `exportPdf()` — generates PDF with dashboard layout, charts, and KPI values
- [ ] Implement scheduled report delivery via background job (weekly reports)
- [ ] Update routes: GET /api/rapportage/export with ?format=csv|pdf&from=&to=&channel=
- [ ] Update route for scheduled delivery configuration
- [ ] Add tests (min 3): test CSV export with filters, test PDF generation, test schedule creation

## Task 9: Implement API-based data extraction for BI tools
- [ ] Implement `getContactmomentsData(filters)` — returns contactmoment data with date range, channel, pagination
- [ ] Implement aggregate KPI endpoints for common calculations (FCR%, SLA%, avg handle time)
- [ ] Add OpenAPI spec comments for BI tool documentation
- [ ] Add tests (min 3): test data extraction with filters, test pagination, test aggregate endpoints
- [ ] Add routes: GET /api/rapportage/data/contactmomenten, GET /api/rapportage/data/kpi-aggregates

## Task 10: Extend SLA configuration (per-channel, warning thresholds)
- [ ] Review existing `setSlaTarget()` and `getSlaTarget()` methods
- [ ] Add support for warning thresholds (orange at 5% below, red at 10% below target)
- [ ] Add per-channel configuration persistence
- [ ] Add tests (min 3): test SLA target storage, test threshold logic, test default values
- [ ] Add route: PUT /api/rapportage/sla with warning thresholds

## Task 11: Extend ReportingService for contact moment aggregation
- [ ] Implement background job to aggregate daily contactmoment snapshots (for trend analysis)
- [ ] Implement aggregation logic for: total contacts, per-channel, FCR%, SLA%, avg handling time
- [ ] Store snapshots in app config or persistent cache (configurable 1-10 year retention)
- [ ] Add tests (min 3): test aggregation job execution, test data persistence, test retention policy

## Task 12: Implement cross-app reporting (Pipelinq + Procest integration)
- [ ] Implement data source abstraction for querying multiple apps' contactmoment data
- [ ] Implement deduplication logic (linked entity references) to avoid double-counting
- [ ] Implement per-app breakdown filtering
- [ ] Add tests (min 3): test multi-app aggregation, test deduplication logic, test app filtering

## Task 13: Add contact moment categorization support
- [ ] Extend ContactmomentService to support optional `subject` categorization
- [ ] Validate subject against configurable category list (Burgerzaken, Belastingen, Vergunningen, Afval, etc.)
- [ ] Implement `getSubjectAnalytics()` — returns per-category metrics (count, %, avg handle time, FCR%)
- [ ] Add tests (min 3): test category validation, test analytics per category, test trending alerts

## Task 14: Implement contact moment duration tracking
- [ ] Review existing `duration` field in contactmoment data model
- [ ] For phone contacts: validate auto-timer functionality (duration auto-populated)
- [ ] For email: allow manual duration entry (minutes)
- [ ] Add duration correction capability within 24 hours with audit logging
- [ ] Add tests (min 3): test duration validation, test timer logic, test correction audit trail

## Task 15: Quality assurance and testing
- [ ] Run composer check:strict on all modified PHP files
- [ ] Run all new service tests (min 3 per service)
- [ ] Run existing test suite to ensure no regressions
- [ ] Fix any quality issues (up to 3 cycles)
- [ ] Update PHPDoc with @spec tags for all new classes/methods

## Task 16: Final integration and PR preparation
- [ ] Create frontend dashboard Vue component to consume KPI endpoints (optional if frontend framework exists)
- [ ] Verify all API routes are functional and return correct data structures
- [ ] Ensure all endpoints require proper authentication (@NoAdminRequired where appropriate)
- [ ] Document any new OpenAPI specs or API changes
- [ ] Open draft PR with mandatory "Closes #187" on first line
