# Contactmomenten Rapportage - Design

## Approach
1. Build KPI aggregation service querying contactmoment objects
2. Create dashboard view with CnStatsBlock KPI cards and charts
3. Add SLA target configuration to admin settings
4. Implement historical trend storage via cached aggregations

## Files Affected
- `lib/Service/ReportingService.php` - New KPI aggregation service
- `src/views/Rapportage.vue` - New reporting dashboard view
- `src/components/rapportage/` - KPI widgets, charts, filters
- `src/router/index.js` - Add reporting route
- `lib/Settings/AdminSettings.php` - SLA target configuration
