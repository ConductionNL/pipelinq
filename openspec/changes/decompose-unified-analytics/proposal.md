# Decompose the Unified Analytics mega-widget

## Why

The Unified Analytics dashboard widget (REQ-DASH-010, built in the `dashboard`
change) is a self-contained mega-panel that violates the dashboard composition
model the rest of the page already follows:

- It renders its own `<h3>Unified Analytics</h3>` inside the widget body while
  the dashboard widget chrome renders the manifest title above it — a double
  title.
- It embeds four KPI cards (`CnStatsBlock` in a `CnKpiGrid`) inside one widget
  body, while the top row of the same dashboard correctly models KPIs as
  individual `showTitle: false` grid widgets the user can rearrange.
- It ships its own in-body `NcSelect` period selector, predating and bypassing
  the `CnDashboardPage` `dateRange` mechanism (header `CnDateRangePicker` +
  the `cnDashboardDateRange` provide consumed by descendant widgets).

## What Changes

- Remove the `unified-analytics` widget, `UnifiedAnalyticsWidget.vue`, and its
  registry entry.
- Promote the four analytics KPIs to individual dashboard KPI widgets
  (`lead-conversion`, `avg-resolution`, `contact-volume`, `satisfaction`),
  rendered exactly like the existing KPI row (`showTitle: false`,
  `CnStatsBlock`).
- Promote the two trend charts to individual chart widgets
  (`leads-over-time`, `requests-by-category`) whose titles come from the
  widget chrome, not an in-body heading.
- Enable the dashboard-level `config.dateRange` mechanism with the four
  backend-supported trailing windows (7/30/90/365 days), surfaced as
  date chips in the chart widgets' own title bars (`layout[].dateChip: true`
  + `showHeaderPicker: false` — no page-level picker); all six analytics
  widgets consume the injected `cnDashboardDateRange` and map it to the
  analytics API `period` parameter.
- Share one cached `GET /api/analytics/overview` call per period across the
  four KPI widgets via `dashboardData.js`.

No backend changes: `AnalyticsController` / `AnalyticsService` and their
`period` semantics stay as specified by REQ-DASH-011.

## Impact

- Affected specs: `dashboard` (REQ-DASH-010 reshaped; REQ-DASH-011 unchanged)
- Affected code:
  - `src/manifest.json` (Dashboard page widgets / layout / slots / dateRange)
  - `src/registry.js`
  - `src/views/dashboard/widgets/` (1 file removed, 6 added, 1 mixin added)
  - `src/services/dashboardData.js` (analytics fetchers + period mapping)
  - `tests/e2e/spec-coverage/`, `tests/vitest/`
- l10n: all user-facing strings reuse existing keys; the removed
  widget-local strings ("Unified Analytics", "Period", "This week", …) become
  unused.
