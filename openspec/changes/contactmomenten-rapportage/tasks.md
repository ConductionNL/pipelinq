# Tasks: contactmomenten-rapportage

## Task 1: Implementation planning
- **Spec ref**: specs/contactmomenten-rapportage/spec.md
- **Status**: completed ✓
- **Acceptance criteria**: Requirements from spec are decomposed into implementable tasks
- **Notes**: Spec covers 12 requirements across KPI dashboards, analytics, export, SLA config, and data retention. Pipelinq's role is to provide reporting APIs and dashboards; OpenRegister provides data aggregation layer.

## Task 2: ReportingService enhancements — KPI calculations
- **Spec ref**: specs/contactmomenten-rapportage/spec.md#requirement-1
- **Status**: completed ✓
- **Description**: Extend ReportingService with methods to calculate KPI metrics from contactmoment data
- **Implementation**: Added 11 methods to ReportingService:
  - getContactMoments() - fetch from OpenRegister with filters
  - getTotalContacts() - count for date/range
  - getContactsByChannel() - channel distribution
  - getAverageHandlingTime() - handling time calculation
  - getFcrRate() - first-call resolution rate
  - getAgentMetrics() - per-agent statistics
  - getQueueStatistics() - queue data
  - getMonthlyTrends() - 6-month trend data
  - getPeakHoursHeatmap() - day/hour heatmap
  - getSubjectTrends() - subject category trends
  - generateWooReport() - anonymized WOO reporting

## Task 3: KPI Dashboard API endpoints
- **Spec ref**: specs/contactmomenten-rapportage/spec.md#scenario-1.1
- **Status**: completed ✓
- **Implementation**: Added to ReportingController:
  - kpiDaily() - GET /api/rapportage/kpi/daily
  - Returns: total contacts, channel breakdown, FCR%, queue length, trend indicators

## Task 4: Channel Analytics API
- **Spec ref**: specs/contactmomenten-rapportage/spec.md#requirement-2
- **Status**: completed ✓
- **Implementation**: 
  - channelAnalytics() - GET /api/rapportage/channels/analytics
  - Supports startDate/endDate/granularity params
  - Returns per-channel metrics with comparison data

## Task 5: Wait Time Monitoring API
- **Spec ref**: specs/contactmomenten-rapportage/spec.md#requirement-3
- **Status**: completed ✓
- **Implementation**:
  - queueStatistics() - GET /api/rapportage/queue/statistics
  - Returns: current queue stats (waiting, longest wait, avg wait, estimated wait)
  - Ready for integration with queue management system

## Task 6: Agent Performance Metrics API
- **Spec ref**: specs/contactmomenten-rapportage/spec.md#requirement-4
- **Status**: completed ✓
- **Implementation**:
  - agentMetrics() - GET /api/rapportage/agents/metrics
  - Accepts: agentId, startDate, endDate parameters
  - Returns: contacts handled, avg handling time, FCR%, contacts/hour

## Task 7: Trend Reporting API
- **Spec ref**: specs/contactmomenten-rapportage/spec.md#requirement-5
- **Status**: completed ✓
- **Implementation**:
  - trends() - GET /api/rapportage/trends
  - Supports: monthly, peakHours, subjects trend types
  - Returns: monthly breakdown, heatmap data, subject frequency trends

## Task 8: CSV/PDF Export enhancement
- **Spec ref**: specs/contactmomenten-rapportage/spec.md#requirement-6
- **Status**: in-progress
- **Notes**: CSV export existing (generateCsv), PDF generation requires external library integration (future phase)

## Task 9: SLA Configuration admin endpoints
- **Spec ref**: specs/contactmomenten-rapportage/spec.md#requirement-7
- **Status**: completed ✓
- **Implementation**: Endpoints already exist
  - getSla() - GET /api/rapportage/sla
  - updateSla() - PUT /api/rapportage/sla
  - Supports per-channel targets and thresholds

## Task 10: WOO-compliant anonymized reporting
- **Spec ref**: specs/contactmomenten-rapportage/spec.md#requirement-8
- **Status**: completed ✓
- **Implementation**:
  - wooReport() - GET /api/rapportage/woo
  - generateWooReport() service method
  - Returns anonymized aggregated statistics

## Task 11: Contact Moment duration and categorization
- **Spec ref**: specs/contactmomenten-rapportage/spec.md#requirement-9,#requirement-10
- **Status**: completed ✓
- **Notes**: Schema already supports duration and subject fields in OpenRegister

## Task 12: Vue Dashboard Component
- **Spec ref**: specs/contactmomenten-rapportage/spec.md#requirement-1
- **Status**: completed ✓
- **Implementation**:
  - ReportingDashboard.vue component created
  - Displays KPI metrics with visual widgets
  - 60-second auto-refresh with timestamp
  - Trend indicators, channel breakdown, responsive layout
  - Uses @nextcloud/axios for API calls

## Task 13: Testing — ReportingService unit tests
- **Spec ref**: specs/contactmomenten-rapportage/spec.md
- **Status**: completed ✓
- **Implementation**: ReportingServiceTest.php with 12 test methods:
  - testCalculateFcrWithData
  - testCalculateFcrWithNoContacts
  - testCalculateSlaCompliance
  - testCalculateSlaComplianceOrange
  - testCalculateSlaComplianceRed
  - testGetSlaTargetDefault
  - testGenerateCsvFormat
  - testCalculateAverageHandlingTime
  - testCalculateAverageHandlingTimeEmpty
  - testGetAllSlaTargets
  - testSetSlaTarget
  - testGetQueueStatistics
- **Note**: Full PHPUnit execution requires Nextcloud environment; syntax checks pass

## Task 14: Testing — ReportingController API tests
- **Spec ref**: specs/contactmomenten-rapportage/spec.md
- **Status**: in-progress
- **Notes**: Manual testing of endpoints will be performed via API calls

## Task 15: Quality gates — static analysis and formatting
- **Spec ref**: ADR-000
- **Status**: in-progress
- **Implementation**:
  - PHP syntax checks: PASSED ✓
  - All new files have EUPL-1.2 license headers ✓
  - All classes/methods have @spec PHPDoc tags ✓
  - Routes registered in appinfo/routes.php ✓
  - Note: Full composer check:strict requires Nextcloud environment
