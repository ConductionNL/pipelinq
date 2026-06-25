## Context

Pipelinq's commercial dashboard (`src/manifest.json`, page `Dashboard`, `type: "dashboard"`)
renders a "Recurring revenue (MRR)" tile (`widget id "mrr"`, `type: "custom"`,
`requiresApp: "shillinq"`, slot `widget-mrr` → `MrrKpiWidget`). `MrrKpiWidget.vue` computes a
naive **run-rate** client-side via `src/services/recurringRevenue.js` over local `contract`
objects. A sibling tile `renewals-due` (`RenewalsDueWidget`) lists `expiring` contracts.

The shared OpenRegister `shillinq` register now owns the canonical recurring data:
`order-revenue-recognition` (shillinq config head) registers a `SalesOrderLine` schema with a
**declarative `maandWaarde`** property (monthly-normalized recurring amount; 0 for `ONE_OFF`
lines) and seed order lines. Because the per-line monthly normalization is computed once in the
data layer, any app can read the run-rate as a plain aggregation — no per-app maths.

ADR-022 is the framing rule: a Conduction app consumes OR/sister-app **abstractions** rather than
re-deriving them locally. The naive run-rate in `recurringRevenue.js` is exactly the local
re-derivation ADR-022 forbids; `maandWaarde` is the abstraction that retires it.

## Goals / Non-Goals

**Goals:**
- The recurring-revenue tile shows the recurring **run-rate** = `SUM(maandWaarde)` over
  `SalesOrderLine` filtered to `nature == "RECURRING"`, read directly from OpenRegister as a
  plain `type: "stat"` aggregation — no bespoke component, no endpoint, no nc-vue change.
- Keep the existing `requiresApp: "shillinq"` "Install shillinq" CTA when shillinq is absent.
- Retire `MrrKpiWidget.vue`, `RenewalsDueWidget.vue`, and `recurringRevenue.js` and their
  registry/manifest entries.

**Non-Goals:**
- Recognized turnover / IFRS-15 proration — owned entirely by shillinq's recognition engine; this
  change shows run-rate only and never reads or re-implements the period figure.
- Any new endpoint, recognition service, integration-registry widget, or nextcloud-vue change.
- Any pipelinq schema, register, or seed change (pipelinq owns no booking data).
- The local `contract` schema, the contract list, or the contract detail view — unchanged. Only
  the dashboard tile + the retired widgets/helper are in scope.

## Decisions

### Decision 1 — Source shape: plain OR `SUM(maandWaarde)` aggregation

The tile becomes a plain `type: "stat"` widget whose `source` is the same
`{ register, schema, metric, field, filter }` aggregation shape pipelinq's existing `revenue` and
`won-value` tiles already use — only pointed at shillinq's register:

```jsonc
"source": {
  "register": "shillinq",
  "schema":   "SalesOrderLine",
  "metric":   "sum",
  "field":    "maandWaarde",
  "filter":   { "nature": "RECURRING" }
}
```

- **Register slug** `shillinq` — confirmed from `../shillinq/lib/Settings/shillinq_register.json`
  (`x-openregister.app: "shillinq"`) and from how shillinq's own manifest addresses its register
  (`"register": "shillinq"`). It mirrors pipelinq's convention where the register slug equals the
  app name (`"register": "pipelinq"`).
- **Schema slug** `SalesOrderLine` — the schema's `slug` in the shillinq register.
- **`metric: "sum"`, `field: "maandWaarde"`** — `maandWaarde` is the declarative
  monthly-normalized recurring amount (0 for one-off lines), so summing it across recurring lines
  is precisely the run-rate.
- **`filter: { nature: "RECURRING" }`** — `nature` is an enum (`RECURRING` | `ONE_OFF`); filtering
  to `RECURRING` excludes one-off implementation fees. (`maandWaarde` is already 0 for `ONE_OFF`,
  so the filter is belt-and-suspenders correctness, not strictly required for the sum, but it keeps
  the intent explicit and the `lineCount` honest if a count tile is ever derived.)
- **Optional active-validity filter** — if shillinq's `SalesOrderLine` exposes a status/validity
  field (e.g. an active/in-term flag) and run-rate should exclude expired lines, the `filter` can
  be extended (e.g. `{ nature: "RECURRING", status: "active" }`). Provisional: filter on `nature`
  only, since `maandWaarde` already encodes the recurring monthly rate; tightening to in-term lines
  is a follow-up once the seed exposes a validity field. *Affected artifact:* `src/manifest.json`
  `source.filter`.

The tile keeps `requiresApp: "shillinq"` (install CTA when absent), its title, EUR currency
`format`, icon, valueColor, and route.

### Decision 2 — Why no endpoint, service, or nextcloud-vue change

The earlier approach made pipelinq consume shillinq's recognition endpoint via a *new* generic
`endpoint` stat source in nextcloud-vue. That is abandoned because:

- Pipelinq shows **run-rate**, which is a plain `SUM` over a declarative field — an aggregation the
  existing OR stat source already supports. There is nothing the current grammar cannot express, so
  no nc-vue change is warranted (no `kind: "endpoint"`, no param interpolation, no `valuePath`).
- The **recognition** figure (which *does* need a runtime-period proration the OR grammar can't
  express) is shillinq's concern and is not shown by pipelinq, so pipelinq needs neither the
  endpoint nor the engine.
- OpenRegister is the shared data layer; reading a sister app's register via a plain aggregation is
  the ADR-022-blessed cross-app read. No integration registry, no registered component.

### Decision 3 — Retire `renewals-due`

`RenewalsDueWidget` is a local `expiring`-contract list, not a recurring-revenue figure; it is
coupled to this change only because both bespoke widgets share the retired
`recurringRevenue.js`/`contract` neighbourhood. The simpler outcome is to **retire** it (drop the
widget, layout, slot, and component) rather than re-source it to a declarative `object-list`:
renewal-window information already remains discoverable via the contract list (ordered by endDate)
and the per-contract detail view, so a dedicated dashboard tile is redundant. Re-sourcing it to an
`object-list` over `SalesOrder`/`SalesOrderLine` was considered and rejected as gold-plating — it
would add a tile for data already reachable, over a sister-app register whose term/endDate field
shape pipelinq does not own.

### Decision 4 — Retirement plan + parity

Order of operations (reflected in tasks.md): land the OR-sourced `type: "stat"` tile, **verify
parity**, then delete the bespoke files:

- **`MrrKpiWidget.vue`** + its `registry.js` import/entry + the `widget-mrr` slot — removed once
  the `type: "stat"` aggregation tile renders. Parity here = the tile renders, formats as EUR,
  gates on `requiresApp`, and reads `SUM(maandWaarde)` — **not** numeric equality with the old
  contract-based run-rate (the provenance intentionally moves from pipelinq `contract` objects to
  shillinq `SalesOrderLine` rows).
- **`RenewalsDueWidget.vue`** + its `registry.js` import/entry + the `renewals-due`
  widget/layout/slot entries — removed (retired per Decision 3).
- **`src/services/recurringRevenue.js`** — deleted. A repo-wide grep
  (`computeMrr`/`computeArr`/`computeClientMrr`/`normalizeToMonthly`) confirmed the only consumers
  are the two retired widgets, so the helper has no remaining consumer and is removed outright.

### Why this differs from shillinq's recognized turnover

| | pipelinq (this tile) | shillinq |
|---|---|---|
| Metric | recurring **run-rate** | IFRS-15 **recognized turnover** |
| Question | "billing per month right now?" | "recurring earned in this period?" |
| Source | OR `SUM(maandWaarde)` over `SalesOrderLine` | recognition engine (per-line monthly rate × whole-months overlap) |
| Period | point-in-time, ignores term boundaries | prorated to the fiscal period |
| Result | a single steady run-rate | a period-dependent figure |

Both build on the same per-line `maandWaarde`; they diverge whenever a term starts/ends inside a
window or the window is not a whole month — and that divergence is correct, not a bug. See
`docs/recurring-revenue.md` (mirrors shillinq's `docs/recurring-revenue.md`).

### ADR framing summary

| Concern | Decision | ADR |
|---|---|---|
| Re-derive run-rate locally? | No — read shillinq's `maandWaarde` via OR aggregation | ADR-022 |
| Tile shape | Declarative `type: "stat"` OR `SUM` source | ADR-036 |
| Custom endpoint / nc-vue change? | None — plain aggregation, OR is the shared data layer | ADR-022 |
| Config edit + code deletion | Single `code` change, not `mixed` | ADR-032 |

## Risks / Trade-offs

- [The displayed number changes provenance (local `contract` run-rate → shillinq `SalesOrderLine`
  run-rate), surprising users] → Mitigation: BREAKING is flagged in the proposal; the metric is
  still "run-rate"; `docs/recurring-revenue.md` explains the source.
- [shillinq register not imported into the running OR → tile shows €0] → Mitigation: `requiresApp:
  "shillinq"` shows the CTA when shillinq is absent; once installed the tile reads €0 until the
  `SalesOrderLine` seed lands — a true empty state, never a stale local number (the local helper is
  gone). Documented as the live-verification precondition.
- [Active-validity filtering] → Mitigation: provisionally filter on `nature` only; tighten to
  in-term lines once the seed exposes a validity field (Decision 1).
- [Cross-app RBAC] → Mitigation: the OR aggregation is RBAC-scoped server-side by OpenRegister; no
  pipelinq-side authorization logic is added.

## Migration Plan

- **Deploy:** flip the `mrr` widget to `type: "stat"` + the OR aggregation source in
  `src/manifest.json`; remove the `renewals-due` entries; delete the two Vue widgets,
  `recurringRevenue.js`, and their registry entries; add `docs/recurring-revenue.md`. No schema,
  no seed, no data migration. The tile is gated by `requiresApp: "shillinq"` so installs without
  shillinq are unaffected (CTA).
- **Rollback:** revert the manifest `mrr`/`renewals-due` entries and restore the deleted Vue files
  + registry entries from git history. No data/schema state to unwind. shillinq's register is
  independent and remains valid.

## Open Questions

- **Active-validity filter on `SalesOrderLine`.** Provisional: filter on `nature == "RECURRING"`
  only (`maandWaarde` already encodes the monthly rate). Tighten to in-term lines if/when the seed
  exposes a status/validity field. *Affected artifact:* `src/manifest.json` `source.filter`.
