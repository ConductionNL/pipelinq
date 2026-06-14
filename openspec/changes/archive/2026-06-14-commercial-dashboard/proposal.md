# Proposal: commercial-dashboard

`kind: feature` — backend (extend AnalyticsService) + frontend (new
dashboard page + widgets + dashboard split).

The single Dashboard (`/`) today is service/CRM-skewed: open leads,
open requests, contact-moment volume, satisfaction, complaints,
resolution time. It does not surface the **commercial** truth an
owner reads first — money won, what's in the pipeline, win rate,
average deal size, the weighted forecast, who the top customers are,
and which product categories sell.

## Summary

Split the dashboard into two and make **Commercial** the default
landing:

- **Commercial overview** (`/`, default): KPI strip + sales charts +
  deal tables.
- **Operational overview** (`/operational`, new route): every widget
  the current Dashboard has today, moved verbatim — nothing lost.

**Commercial KPI strip (6):** revenue (period), won value (period),
win rate (period), average deal size, weighted forecast (open
pipeline × probability), open pipeline value.

**Commercial charts (4):**
- Revenue over time (line) — settled POS turnover + won-deal value.
- Pipeline by stage (horizontal bar funnel) — open-lead value summed
  per stage, ordered by stage.
- Revenue by product category (donut) — POS line revenue joined to
  product categories.
- Top customers by revenue (horizontal bar) — won-deal + POS revenue
  grouped by client, top 8.

**Commercial tables (2):** deals closing soon (open leads by
expected close date) and recently won/lost.

## How

Per the existing pipelinq dashboard pattern (decompose-unified-
analytics), new metrics are **server-side** on the PHP
`AnalyticsService`, consumed through the established
`/api/analytics/*` endpoints + the cached `dashboardData.js` fetchers
+ the `analyticsPeriodMixin` / `dashboardRefreshMixin` plumbing, so
the new widgets inherit the dashboard date-range and Refresh action
for free.

- New `AnalyticsService::getCommercialOverview(period)` → the six KPI
  figures + a `previousPeriod` block for trend arrows.
- New `getTrends()` metrics: `revenue`, `pipeline-by-stage`,
  `revenue-by-product-category`, `top-customers`.
- New endpoint `GET /api/analytics/commercial`.
- The two deal tables read the already-cached `getLeads()` client-
  side (no new endpoint).

A committed, idempotent seed script
(`scripts/seed-demo-commercial.py`) builds a coherent commercial
story — clients, a staged pipeline with leads of varying value /
probability / status, product catalogue with categories, POS
transactions with product-linked lines settled across the trailing
year — so the Commercial dashboard demonstrates meaningfully on a
fresh environment.

## Non-goals

- No change to the existing operational widgets' behaviour (they
  move pages unchanged).
- No new register schemas; all metrics derive from existing
  lead / posTransaction / posTransactionLine / product / client
  schemas.
- No per-widget date pickers — the dashboard-level date range drives
  every windowed metric.
