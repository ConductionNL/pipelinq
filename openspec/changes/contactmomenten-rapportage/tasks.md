# Tasks: contactmomenten-rapportage

## Task 1: Implementation planning
- **Spec ref**: specs/contactmomenten-rapportage/spec.md
- **Status**: [x]
- **Acceptance criteria**: Requirements from spec are decomposed into implementable tasks

## Phase 1: Core Data APIs & Dashboard Foundation

## Task 2: Implement KPI Dashboard API endpoint
- **Spec ref**: specs/contactmomenten-rapportage/spec.md#requirement-1
- **Status**: [x]
- **Description**: Create `/api/reporting/kpi-dashboard` endpoint returning daily KPI metrics
- **Acceptance criteria**:
  - Returns total contacts, contacts per channel, avg handling time, queue length, active agents
  - Includes trend comparison to same day last week
  - Empty state handling for no data days
  - Requires @NoAdminRequired auth (manager role or above)
- **Implementation**: Add endpoint to ReportingController, extend ReportingService
- **Tests**: PHPUnit with >3 scenarios

## Task 3: Implement Channel Analytics API
- **Spec ref**: specs/contactmomenten-rapportage/spec.md#requirement-2
- **Status**: [x]
- **Description**: Create endpoints for channel-based analytics with configurable time granularity
- **Acceptance criteria**:
  - `GET /api/reporting/channels` with daily/weekly/monthly filters
  - `GET /api/reporting/channels/{channel}` for per-channel metrics
  - Returns volume, avg handling time, FCR rate, SLA compliance per channel
  - Supports 30-day and monthly comparison views
  - Highlights channels with >5% change
- **Implementation**: ChannelAnalyticsService + ReportingController methods
- **Tests**: PHPUnit test coverage

## Task 4: Implement Agent Performance Metrics API
- **Spec ref**: specs/contactmomenten-rapportage/spec.md#requirement-4
- **Status**: [ ]
- **Description**: Create endpoints for per-agent and team-level performance tracking
- **Acceptance criteria**:
  - `GET /api/reporting/agents` returns ranked list with contacts, avg handling time, FCR, status
  - `GET /api/reporting/agents/{userId}` for individual agent stats
  - Flags agents >20% above average workload
  - Highlights significant outliers (above/below team averages)
  - 30-day trend data available
- **Implementation**: AgentPerformanceService + ReportingController
- **Tests**: PHPUnit test coverage

## Task 5: Implement Wait Time Monitoring API
- **Spec ref**: specs/contactmomenten-rapportage/spec.md#requirement-3
- **Status**: [ ]
- **Description**: Create real-time queue statistics and historical wait time reporting
- **Acceptance criteria**:
  - Real-time: number waiting, longest wait, average wait, estimated wait for new callers
  - Historical: average wait per day, peak hours, percentage within SLA
  - Configurable refresh interval (default 30 seconds)
  - Staffing recommendations based on patterns
  - SLA breach alerts with thresholds
- **Implementation**: WaitTimeService + ReportingController + BackgroundJob for alerts
- **Tests**: PHPUnit test coverage

## Task 6: Implement Trend Analysis API
- **Spec ref**: specs/contactmomenten-rapportage/spec.md#requirement-5
- **Status**: [ ]
- **Description**: Create endpoints for historical trend analysis and forecasting
- **Acceptance criteria**:
  - `GET /api/reporting/trends` with configurable date ranges (6 months, 12 months default)
  - Monthly aggregation with trend indicators
  - Peak hours heatmap (day-of-week × hour-of-day)
  - Subject/category trending with emerging topic detection
  - Supports 1-12 month date ranges
- **Implementation**: TrendAnalysisService + ReportingController
- **Tests**: PHPUnit test coverage

## Phase 2: Configuration & Export

## Task 7: Implement SLA Configuration Admin Interface
- **Spec ref**: specs/contactmomenten-rapportage/spec.md#requirement-7
- **Status**: [ ]
- **Description**: Extend SLA configuration with per-channel, per-contact-type targets and warning thresholds
- **Acceptance criteria**:
  - Admin endpoint to update SLA targets per channel (telefoon, email, balie, chat)
  - Separate targets: response time, handling time, resolution time, wait time
  - Three-color coding: green (>= target), orange (target-5% to target), red (< target-5%)
  - Warning thresholds (default 85% warn, 80% critical) configurable
  - Changes take effect immediately
  - Persistent storage in app configuration
- **Implementation**: Extend ReportingController + ReportingService
- **Tests**: PHPUnit test coverage

## Task 8: Implement CSV Export with filtering
- **Spec ref**: specs/contactmomenten-rapportage/spec.md#requirement-6
- **Status**: [ ]
- **Description**: Create CSV export for reporting data with UTF-8 BOM and semicolon separators
- **Acceptance criteria**:
  - Extends existing `exportCsv` endpoint with date range, channel, agent filters
  - UTF-8 BOM for Excel compatibility
  - Semicolon separators per spec
  - Filename includes report date: `contactmomenten-rapport-YYYY-MM-DD.csv`
  - 200+ records paginated efficiently
- **Implementation**: Extend ReportingService.generateCsv + ReportingController
- **Tests**: PHPUnit export scenarios

## Task 9: Implement PDF Report Generation
- **Spec ref**: specs/contactmomenten-rapportage/spec.md#requirement-6
- **Status**: [ ]
- **Description**: Create PDF export for dashboard views with charts and KPI values
- **Acceptance criteria**:
  - `POST /api/reporting/export-pdf` endpoint
  - Generates PDF with dashboard layout, charts, KPI values
  - Includes report date, timestamp, municipality name
  - Uses Nextcloud library or external PDF generation
  - File stored with download link
- **Implementation**: Create PdfReportService + ReportingController endpoint
- **Tests**: PHPUnit test coverage

## Task 10: Implement Scheduled Report Delivery Background Job
- **Spec ref**: specs/contactmomenten-rapportage/spec.md#requirement-6
- **Status**: [ ]
- **Description**: Background job for scheduled weekly report generation and delivery
- **Acceptance criteria**:
  - Configurable schedule (e.g., Monday 08:00)
  - Generates report via background job
  - Delivers via Nextcloud notification with download link
  - Stores file in user's Nextcloud Files under "Rapporten/" folder
  - Supports multiple schedules per user
  - Handles failures gracefully
- **Implementation**: Create ScheduledReportJob class + configuration service
- **Tests**: PHPUnit test coverage

## Phase 3: Advanced Analytics & Data Management

## Task 11: Implement Contact Duration Tracking
- **Spec ref**: specs/contactmomenten-rapportage/spec.md#requirement-9
- **Status**: [ ]
- **Description**: Add automatic timer for phone contacts and manual duration for other channels
- **Acceptance criteria**:
  - Auto-timer starts on phone contact registration, visible to agent
  - Manual duration input for email/other channels (default empty)
  - Duration correction allowed within 24 hours
  - Correction logged in audit trail
  - Duration aggregated for handling time calculations
- **Implementation**: Extend ContactmomentService + database schema updates
- **Tests**: PHPUnit test coverage

## Task 12: Implement Contact Categorization by Subject
- **Spec ref**: specs/contactmomenten-rapportage/spec.md#requirement-10
- **Status**: [ ]
- **Description**: Add subject category selection during contact registration
- **Acceptance criteria**:
  - Configurable subject categories (Burgerzaken, Belastingen, Vergunningen, Afval, etc.)
  - Single primary category selection during registration
  - Categories manageable via app's tag system
  - Subject analytics in dashboard (count, percentage, avg handling time, FCR rate)
  - Trending indicators for spikes (>20% increase from weekly average)
  - Notifications for trending topics
- **Implementation**: Extend ContactmomentService + create SubjectTrendingService
- **Tests**: PHPUnit test coverage

## Task 13: Implement WOO Anonymized Reporting
- **Spec ref**: specs/contactmomenten-rapportage/spec.md#requirement-8
- **Status**: [ ]
- **Description**: Create WOO-compliant anonymized reports for public transparency
- **Acceptance criteria**:
  - `GET /api/reporting/woo-report` endpoint for quarterly/annual reports
  - Removes all PII (agent names, client data, message content)
  - Aggregates: total contacts per channel, avg wait times, SLA compliance, FCR rates
  - Follows VNG recommended KCC reporting template
  - Annual year-over-year comparisons available
  - Benchmark comparison against VNG averages per municipality size category
- **Implementation**: Create WooReportingService + ReportingController
- **Tests**: PHPUnit test coverage

## Task 14: Implement Data Retention & Archival
- **Spec ref**: specs/contactmomenten-rapportage/spec.md#requirement-12
- **Status**: [ ]
- **Description**: Implement configurable retention with archival of aggregated stats
- **Acceptance criteria**:
  - Configurable retention period (default 2 years, configurable 1-10 years)
  - Detailed records deleted after retention expiry
  - Aggregated statistics (monthly totals, averages) preserved indefinitely
  - GDPR-compliant anonymization on citizen data deletion request
  - Background job for automated archival/cleanup
  - Maintains reporting accuracy during deletion
- **Implementation**: Create DataRetentionService + DataArchivalJob
- **Tests**: PHPUnit test coverage

## Task 15: Implement Cross-App Data Aggregation
- **Spec ref**: specs/contactmomenten-rapportage/spec.md#requirement-11
- **Status**: [ ]
- **Description**: Aggregate reporting data from multiple apps (Pipelinq + Procest)
- **Acceptance criteria**:
  - API endpoint for combined KPI dashboard showing data from multiple apps
  - Source app indicated in responses
  - Filtering by app source available
  - Deduplication logic for contacts appearing in multiple apps (by linked entity references)
  - Cross-app channel distribution charts
  - Per-app drill-down breakdown
- **Implementation**: Create CrossAppAggregationService + ReportingController
- **Tests**: PHPUnit test coverage

## Phase 4: Frontend & Integration

## Task 16: Create Vue Dashboard Component
- **Spec ref**: specs/contactmomenten-rapportage/spec.md
- **Status**: [ ]
- **Description**: Main dashboard component with KPI widgets, charts, and auto-refresh
- **Acceptance criteria**:
  - Real-time KPI widgets (contacts today, per channel, avg handling time, queue length, active agents)
  - FCR rate with target comparison (highlight if below)
  - SLA compliance with color coding (green/orange/red)
  - Auto-refresh every 60 seconds without flickering
  - "Last updated" timestamp displayed
  - Empty state message ("Nog geen contactmomenten geregistreerd vandaag")
  - Mobile-responsive layout
  - Accessible (WCAG AA)
- **Implementation**: Create Dashboard.vue component + supporting child components
- **Tests**: Vue test coverage (if test framework exists)

## Task 17: Create Channel Analytics Chart Component
- **Spec ref**: specs/contactmomenten-rapportage/spec.md#requirement-2
- **Status**: [ ]
- **Description**: Interactive charts for channel distribution over time
- **Acceptance criteria**:
  - Chart library integration (Chart.js or similar)
  - Daily, weekly, monthly granularity selector
  - Consistent color coding per channel
  - Channel comparison table with MoM indicators
  - Shift analysis highlighting >5% changes
  - Responsive sizing
- **Implementation**: Create ChannelAnalytics.vue + related components
- **Tests**: Vue test coverage

## Task 18: Create Agent Performance Table Component
- **Spec ref**: specs/contactmomenten-rapportage/spec.md#requirement-4
- **Status**: [ ]
- **Description**: Team overview and individual agent performance displays
- **Acceptance criteria**:
  - Ranked list of agents with metrics (contacts today, avg time, FCR, status)
  - Highlighting for above/below-average performers
  - Individual agent detail view with 30-day trends
  - Workload distribution bar chart with >20% flagging
  - Sortable and filterable by team/department
- **Implementation**: Create AgentPerformance.vue component
- **Tests**: Vue test coverage

## Task 19: Create SLA Configuration UI
- **Spec ref**: specs/contactmomenten-rapportage/spec.md#requirement-7
- **Status**: [ ]
- **Description**: Admin interface for managing SLA targets and thresholds
- **Acceptance criteria**:
  - Per-channel target configuration (telefoon, email, balie, chat)
  - Multiple metrics per channel (response time, handling time, resolution time)
  - Warning threshold sliders (85% warn, 80% critical)
  - Real-time preview of color coding
  - Save/apply with validation
  - Confirmation dialog for changes
- **Implementation**: Create SlaConfiguration.vue component
- **Tests**: Vue test coverage

## Task 20: Create Export UI Controls
- **Spec ref**: specs/contactmomenten-rapportage/spec.md#requirement-6
- **Status**: [ ]
- **Description**: UI buttons and dialogs for export functionality
- **Acceptance criteria**:
  - "Exporteer als CSV" button with date range selector
  - "Exporteer als PDF" button for dashboard view
  - Filter UI (date range, channel, agent)
  - Download progress indicator
  - Scheduled report configuration UI
  - Report history view
- **Implementation**: Create ExportControls.vue + dialogs
- **Tests**: Vue test coverage

## Task 21: Integrate reporting into main navigation
- **Spec ref**: specs/contactmomenten-rapportage/spec.md
- **Status**: [ ]
- **Description**: Add reporting module to main app navigation with proper permissions
- **Acceptance criteria**:
  - "Rapportage" menu item in main navigation
  - Dashboard as default view
  - Sub-items for analytics views
  - Permission checks (manager role minimum)
  - Breadcrumb navigation
  - Keyboard shortcuts for navigation
- **Implementation**: Update app navigation/routing configuration
- **Tests**: Integration test coverage
