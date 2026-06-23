## Why

The Commercial overview's date-range header used the default `CnDateRangePicker`
— a "Range preset" select plus two `YYYY-MM-DD` date inputs — which opened a
bulky, tall band above the KPI cards. The library now offers a compact pills
control for the same range (`CnDashboardPage` `dateRange.control: 'pills'`), and
the Commercial layout had a dead vertical gap: the KPI rows ended at grid row 4
but the charts started at row 8, leaving four empty rows of whitespace before
"Revenue over time" / "Pipeline by stage".

## What Changes

- **MODIFIED** the Commercial dashboard (`src/manifest.json`, page `Dashboard`)
  `dateRange` config: add `"control": "pills"` so the header renders the compact
  segmented preset toggle (Last 7 / 30 / 90 / 365 days) instead of the select +
  two date inputs. The active range and per-chart date chips are unchanged.
- **MODIFIED** the Commercial dashboard `layout`: pull the chart and table rows
  up so they sit directly below the two KPI rows (charts `gridY: 8 → 4`, the next
  chart pair `12 → 8`, the deal tables `16 → 12`, `total-leads` `20 → 16`),
  removing the four-row dead gap between the KPI strip and the charts.

This is a manifest-only (kind: code) change in pipelinq; the pills control and
the tile-fit behaviour are implemented in `@conduction/nextcloud-vue`.
