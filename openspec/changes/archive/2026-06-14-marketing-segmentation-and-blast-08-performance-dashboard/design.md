# Design: 08 Performance Dashboard

## Scope

`src/views/blasts/PerformanceDashboard.vue`. Reads blast list + attribution
endpoints (member 06) + AttributionService sums (member 04).

## Tabs

- **Overview** — table of recent blasts (name, segment, status, sent,
  delivered, open rate %, click rate %, unsubscribed) with sortable columns.
- **A/B Testing** — when `abVariantOf` exists, side-by-side variant metrics;
  once each arm has ≥500 delivered and 24h elapsed, compute chi-square on
  click-rate and display significance ("Not significant (p>0.05)" or
  "Variant B significantly higher (p<0.05)"). When N<500, show
  not-yet-available with current counts.
- **Attribution** — table of blasts with attributed deal count + attributed
  value (EUR) from `GET /api/blasts/:id/attribution`.

## Patterns

ADR-004 NC Vue + axios; ADR-007 nl + en i18n; ADR-010 CSS variables. The
chi-square computation runs client-side over the delivered/clicked counts
returned by the blast totals.
