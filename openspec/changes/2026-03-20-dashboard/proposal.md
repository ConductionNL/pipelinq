# Proposal: Dashboard Widget Integration

## Problem
ProspectWidget and ProductRevenue components exist but are not integrated into the main Dashboard layout.

## Solution
Add both widgets to the Dashboard's CnDashboardPage grid layout as new widget definitions with render slots. Place them in a new row below the client overview.

## Scope
- `src/views/Dashboard.vue` — add imports, components, widget defs, layout entries, and render slots
