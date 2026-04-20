# Design: contactmomenten-rapportage

**Status**: pr-created
**PR**: https://github.com/ConductionNL/pipelinq/pull/295 (Retry cycle 21 — security fixes)

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

## Security Fixes (Retry Cycle 21)

The following security findings from previous review cycles have been addressed:

1. **SLA Configuration Authorization** (CRITICAL F-01 / SEC-01): Changed `getSla()` and `updateSla()` from `@NoAdminRequired` to `@RequireAdmin` to prevent unauthorized users from reading or modifying system-wide SLA configuration.

2. **SLA Target Key Validation** (WARNING F-02 / SEC-02): Added allowlist validation in `setSlaTarget()` that checks both channel and metric against `DEFAULT_SLA_TARGETS` before constructing config keys. Prevents arbitrary key-value pair injection into pipelinq app-config namespace.

3. **Average Handling Time Calculation** (WARNING F-03): Fixed `calculateAverageHandlingTime()` to properly accumulate hours from ISO 8601 duration strings. Previously only summed minutes and seconds, causing durations ≥1 hour to produce understated averages.

4. **CSV Formula Injection Prevention** (SUGGESTION SEC-03): Added `escapeCSVField()` private method that prefixes formula characters (=, +, -, @) with apostrophe and applies proper CSV quoting. Applied to both headers and data rows.

5. **CSV Header Quoting** (SUGGESTION F-06): Fixed header row to use same escaping as data rows. Prevents semicolon-containing translated headers from corrupting CSV structure.
