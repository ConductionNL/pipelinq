# Design: contactmomenten-rapportage

## Status: in-progress

## Architecture Overview

This implementation provides comprehensive contact moment reporting and analytics for KCC (Knowledge-Centered Service) management across the Pipelinq platform. The reporting system aggregates data from contact moments registered through various channels (phone, email, balie, chat) and provides real-time dashboards, SLA monitoring, KPI tracking, and advanced analytics.

## Implementation Strategy

The implementation follows a phased approach:

**Phase 1: Core Data APIs & Dashboard Foundation** (Tasks 2-6)
- KPI Dashboard API with real-time metrics
- Channel Analytics API with time granularity options  
- Agent Performance Metrics API with team comparison
- Wait Time Monitoring API with real-time and historical data
- Trend Analysis API with forecasting capabilities

**Phase 2: Configuration & Export** (Tasks 7-10)
- SLA Configuration with per-channel targets and color-coded thresholds
- CSV Export with filtering and UTF-8 BOM support
- PDF Report Generation with dashboard visualizations
- Scheduled Report Delivery via background jobs

**Phase 3: Advanced Analytics & Data Management** (Tasks 11-15)
- Contact Duration Tracking with automatic phone timers
- Contact Categorization by subject with trending detection
- WOO-compliant anonymized reporting for public transparency
- Data Retention & Archival with indefinite aggregated stats preservation
- Cross-App Data Aggregation for multi-app reporting

**Phase 4: Frontend & Integration** (Tasks 16-21)
- Vue Dashboard Component with auto-refresh and empty states
- Channel Analytics Charts with interactive drill-down
- Agent Performance Table with workload visualization
- SLA Configuration UI for administrators
- Export Controls with progress indicators
- Navigation integration with permission checks

## Key Design Decisions

1. **OpenRegister Integration**: All contact moment data aggregation leverages OpenRegister's existing API, using the `contactmoment` schema as the primary data source.

2. **International-First Data Model**: Per ADR-001, all contact data uses schema.org and vCard properties as primary storage, with Dutch API mapping as a separate layer.

3. **Three-Color SLA Coding**: Green (>= target), Orange (target-5% to target), Red (< target-5%) provides clear visual feedback for performance status.

4. **Stateless Aggregation**: KPI calculations are computed on-demand from contactmoment data, with optional caching of aggregated statistics for trend analysis.

5. **Asynchronous Report Generation**: PDF and scheduled reports use background jobs to avoid blocking the UI.

6. **GDPR-First Data Handling**: Data retention is configurable with default 2-year retention; detailed records are deleted but aggregated statistics are preserved indefinitely.

## API Endpoints (Phase 1-2)

```
GET    /api/reporting/kpi-dashboard         - Real-time KPI metrics
GET    /api/reporting/channels              - Channel analytics with filters
GET    /api/reporting/channels/{channel}    - Per-channel metrics
GET    /api/reporting/agents                - Team performance ranked list
GET    /api/reporting/agents/{userId}       - Individual agent metrics
GET    /api/reporting/queue                 - Real-time queue statistics
GET    /api/reporting/queue/history         - Historical wait time reports
GET    /api/reporting/trends                - Trend analysis with date ranges
GET    /api/reporting/sla                   - Current SLA configuration
POST   /api/reporting/sla                   - Update SLA targets (Admin)
GET    /api/reporting/export-csv            - CSV export with filters
POST   /api/reporting/export-pdf            - PDF report generation
GET    /api/reporting/woo-report            - Anonymized WOO reporting
POST   /api/reporting/report-schedule       - Configure scheduled reports
```

## Standards & References

Implements requirements from:
- VNG Klantinteracties API — Contactmoment entity specification
- ISO 18295 — Customer contact centre service requirements  
- WOO (Wet open overheid) — Public transparency requirements
- KCS (Knowledge-Centered Service) — FCR tracking methodology
- WCAG AA — Dashboard accessibility standards
- GDPR/AVG — Data retention and anonymization requirements
