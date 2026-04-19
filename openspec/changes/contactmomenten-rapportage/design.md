# Design: contactmomenten-rapportage

**Status**: pr-created
**PR**: https://github.com/ConductionNL/pipelinq/pull/288

## Architecture Overview

See specs/contactmomenten-rapportage/spec.md for detailed requirements and scenarios.

## Implementation Summary

Contactmomenten-rapportage adds comprehensive KPI dashboards and reporting to pipelinq:

- **KPI Calculations**: Real-time metrics for total contacts, FCR rate, SLA compliance, handling time
- **Channel Analytics**: Contact distribution and metrics per communication channel
- **Agent Performance**: Individual agent and team-level metrics with trend analysis
- **Queue Management**: Real-time queue statistics with historical analytics
- **Trend Reporting**: Monthly trends, peak hours heatmap, subject category analysis
- **WOO Reporting**: Anonymized aggregated statistics for public transparency
- **Vue Dashboard**: Real-time dashboard component with 60-second auto-refresh

## Key Design Decisions

1. **OpenRegister Integration**: All contact moment data is stored and queried via OpenRegister ObjectService
2. **Aggregation Layer**: Reporting calculations are performed in pipelinq service layer; OpenRegister provides raw data access
3. **API-First**: All reporting functionality exposed via REST API for flexibility and cross-app integration
4. **Anonymization**: WOO reports contain no PII — only aggregated statistics
5. **Real-Time Refresh**: Dashboard auto-updates every 60 seconds with configurable interval
6. **Schema.org Alignment**: Contact data uses international standards (schema.org) as primary vocabulary per ADR-001
