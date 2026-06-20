---
kind: code
depends_on:
  - order-revenue-recognition     # shillinq (config head) — declares SalesOrder + SalesOrderLine schemas, the declarative `maandWaarde` calc, and seed lines in the shared OpenRegister `shillinq` register.
---

## Why

Pipelinq's commercial dashboard "Recurring revenue (MRR)" tile is a bespoke `MrrKpiWidget`
that re-derives a run-rate client-side from local `contract` objects via
`src/services/recurringRevenue.js` (monthly = value, quarterly = value/3, annual = value/12,
over `active`/`expiring` contracts). That is exactly the local re-derivation ADR-022 forbids:
the monthly-normalized recurring amount is already a **declarative `maandWaarde` calc** on
shillinq's `SalesOrderLine` schema, computed once in the shared OpenRegister data layer and 0
for one-off lines.

This change re-sources the tile to read that abstraction **directly from OpenRegister** as a
plain `type: "stat"` aggregation — `SUM(maandWaarde)` over `SalesOrderLine` where
`nature == "RECURRING"`. No custom endpoint, no recognition service, no nextcloud-vue change,
no integration registry: OpenRegister is the shared data layer and pipelinq's existing stat
tiles (`revenue`, `won-value`) already aggregate over an OR register/schema this exact way; the
only difference is the register is shillinq's, not pipelinq's.

### Data vs. recognition — the CRM/accounting split

This is the **CRM run-rate consumer**. Pipelinq (CRM) shows recurring **run-rate** — the
point-in-time sum of the monthly-normalized value of every active recurring order line, ignoring
period and term boundaries: "what are we billing per month right now?". shillinq (accounting)
**separately** shows IFRS-15 **recognized turnover** — the same per-line monthly rate, but
prorated to the whole calendar months a line's term overlaps a fiscal period: "how much
recurring turnover did we actually earn in this period?". Both build on the same per-line
`maandWaarde` normalization but answer different questions, so the two numbers legitimately
differ. shillinq's recognition engine is already built and is **not pipelinq's concern**; this
change reads run-rate only and never re-implements proration.

The agreed architecture supersedes the earlier (now-deleted, uncommitted)
`adopt-recognized-recurring-revenue-widget` change, which wrongly had pipelinq *consume*
shillinq's recognition endpoint via a new nextcloud-vue `endpoint` stat source. That approach is
abandoned: pipelinq does not show recognized turnover and needs no endpoint, no engine, and no
lib change.

## What Changes

- **Re-source the recurring-revenue tile to a plain OR `type: "stat"` aggregation.** The
  Dashboard `mrr` widget changes from `type: "custom"` (bespoke `MrrKpiWidget`) to `type: "stat"`
  whose `source` is `{ register: "shillinq", schema: "SalesOrderLine", metric: "sum", field:
  "maandWaarde", filter: { nature: "RECURRING" } }` — the same aggregation shape pipelinq's
  `revenue`/`won-value` tiles already use, just over shillinq's register. The tile keeps
  `requiresApp: "shillinq"` so it shows the "Install shillinq" CTA when shillinq is absent.
  Its `widget-mrr` slot mapping is removed (the tile is now declarative, not a custom slot).
- **BREAKING (dashboard metric semantics):** the tile stops showing run-rate derived from local
  pipelinq `contract` objects and starts showing the run-rate of shillinq's recurring order lines
  (Σ `maandWaarde` where `nature == "RECURRING"`). The provenance and the displayed number change;
  the metric is still "run-rate", not recognized turnover. UI placement is unchanged.
- **Retire `renewals-due`.** The bespoke `RenewalsDueWidget` (a local `expiring`-contract list,
  not a recurring-revenue figure, coupled to the same retired client-side neighbourhood) is
  removed: its widget entry, its layout entry, its `widget-renewals-due` slot, and its Vue
  component. Renewal-window information remains discoverable via the contract list and contract
  detail.
- **Retire the dead code:** delete `src/views/dashboard/widgets/MrrKpiWidget.vue`,
  `src/views/dashboard/widgets/RenewalsDueWidget.vue`, and `src/services/recurringRevenue.js`
  (no remaining consumer — repo-wide grep confirmed), plus their `src/registry.js` imports +
  registry entries. The deletions make this a `code` change.
- **No new schema, no register edit, no seed, no endpoint, no nc-vue change.** Pipelinq owns no
  booking data — the run-rate is read cross-app from shillinq's OR register via a plain
  aggregation.

### Mixed-spec rationale

This change edits `src/manifest.json` (config-shaped) **and** deletes Vue/JS files +
`src/registry.js` entries (code-shaped). Per ADR-032 a config edit coupled to code deletion is a
single `code` change, not a `mixed` envelope: the deletions are the substance and cannot be split
from the manifest swap without leaving dead imports referencing a removed slot, so this is
declared `kind: code`.

## Capabilities

### New Capabilities
<!-- None. No new pipelinq capability — this re-sources an existing dashboard requirement. -->

### Modified Capabilities
- `contract-renewal-tracking`: the **MRR KPI Card** requirement changes its data source — the
  dashboard tile is the recurring run-rate sourced as a plain OpenRegister `SUM(maandWaarde)`
  aggregation over shillinq's `SalesOrderLine` (filtered to `nature == "RECURRING"`), not a local
  run-rate roll-up over pipelinq `contract` objects via `recurringRevenue.js`. The **Renewals Due
  Widget** requirement is removed (bespoke tile retired). The dashboard leg of the **Recurring
  Revenue Roll-Up** requirement is removed (the local client-side roll-up no longer feeds the
  dashboard tile).

## Impact

- **`src/manifest.json`** — Dashboard page: `mrr` widget `type: "custom"` → `type: "stat"` with
  an OR `SUM(maandWaarde)` source over `shillinq`/`SalesOrderLine`; remove the `renewals-due`
  widget + layout entries and the `widget-mrr` / `widget-renewals-due` slot mappings.
- **`src/registry.js`** — remove the `MrrKpiWidget` + `RenewalsDueWidget` imports and registry
  entries.
- **`src/views/dashboard/widgets/MrrKpiWidget.vue`**, **`RenewalsDueWidget.vue`** — deleted.
- **`src/services/recurringRevenue.js`** — deleted (no remaining consumer).
- **`docs/recurring-revenue.md`** (new) — short "why this differs from shillinq" note, mirroring
  shillinq's framing.
- **Depends on `order-revenue-recognition`** (shillinq config head) for the `SalesOrderLine`
  schema + `maandWaarde` calc + seed lines in the shared register. This change is inert (CTA only)
  until shillinq is installed and its register is imported into the running OpenRegister — the
  tile shows €0 until the `SalesOrderLine` seed lands.
- **No new external dependency, no DB table, no direct SQL, no custom endpoint, no nextcloud-vue
  change** (ADR-022). The cross-app read is a plain OpenRegister aggregation over a sister app's
  register.
