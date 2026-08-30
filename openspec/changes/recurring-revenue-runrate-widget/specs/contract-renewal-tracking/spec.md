# Contract & Renewal Tracking — Recurring-Revenue Run-Rate Delta

**Spec refs**: `contract-renewal-tracking`, `order-revenue-recognition` (shillinq — `SalesOrderLine` + `maandWaarde`), ADR-022 (apps consume OR abstractions), ADR-036 (declarative manifest tile)
**Standards**: SaaS metrics conventions (recurring run-rate vs. recognized turnover)

## MODIFIED Requirements

### Requirement: MRR KPI Card

The main dashboard MUST include a recurring-revenue KPI tile that shows the recurring **run-rate**
— the point-in-time sum of the monthly-normalized value of every active recurring sales order line
— read directly from OpenRegister as a plain `type: "stat"` aggregation, NOT a local run-rate
roll-up over pipelinq `contract` objects. The tile's `source` MUST be the standard OpenRegister
aggregation shape `{ register: "shillinq", schema: "SalesOrderLine", metric: "sum", field:
"maandWaarde", filter: { nature: "RECURRING" } }`, where `maandWaarde` is shillinq's declarative
monthly-normalized recurring amount (0 for one-off lines). The tile MUST keep
`requiresApp: "shillinq"` and show the "Install shillinq" call-to-action when shillinq is absent.
The tile MUST NOT depend on any bespoke pipelinq widget component or on
`src/services/recurringRevenue.js`, and MUST NOT call any custom recognition endpoint — the figure
is a plain cross-app OpenRegister read.

**Feature tier**: MVP

#### Scenario: Tile shows the recurring run-rate from shillinq's order lines

- GIVEN shillinq is installed and its register (with recurring `SalesOrderLine` rows) is imported
- WHEN the commercial dashboard loads
- THEN the recurring-revenue tile MUST aggregate `SUM(maandWaarde)` over `SalesOrderLine` filtered
  to `nature == "RECURRING"` from the `shillinq` OpenRegister register
- AND MUST display that run-rate formatted as EUR currency

#### Scenario: shillinq absent shows the install CTA

- GIVEN shillinq is not installed
- WHEN the dashboard loads
- THEN the recurring-revenue tile MUST show the "Install shillinq" call-to-action
- AND MUST NOT display a locally-computed run-rate number

#### Scenario: No recurring order lines yet shows an empty figure

- GIVEN shillinq is installed but no recurring `SalesOrderLine` rows exist in its register
- WHEN the dashboard loads
- THEN the tile MUST display €0 (the true aggregate)
- AND MUST NOT fall back to a locally-computed `contract`-based number

## REMOVED Requirements

### Requirement: Renewals Due Widget

**Reason**: The bespoke `RenewalsDueWidget` (a local `expiring`-contract list, not a
recurring-revenue figure) is retired together with the bespoke MRR widget because both were coupled
to the retired client-side recurring-revenue helper (`src/services/recurringRevenue.js`). The
`renewals-due` widget entry, its layout entry, its `widget-renewals-due` slot mapping, and the
`RenewalsDueWidget.vue` component are removed from the dashboard manifest and registry.

**Migration**: Renewal-window information remains discoverable via the contract list (ordered by
endDate) and the per-contract detail view (`Renewal Window Detection` / `Contract Lifecycle
Management`); the dashboard no longer carries a dedicated bespoke renewals tile. No data migration.

---

### Requirement: Recurring Revenue Roll-Up — dashboard MRR leg

**Reason**: The dashboard's recurring-revenue figure is no longer computed by the local
client-side roll-up (`src/services/recurringRevenue.js`, naive run-rate over `active`/`expiring`
pipelinq `contract` objects); it is replaced by the recurring run-rate read directly from
shillinq's `SalesOrderLine` `maandWaarde` via a plain OpenRegister `SUM` aggregation (see the
modified `MRR KPI Card` requirement). Only the **dashboard tile** leg of the roll-up is removed
here; the client-view per-client recurring-value summary and the pipeline-insights
renewal-rate/churned block (if present) are out of scope and unchanged.

**Migration**: The dashboard reads `SUM(maandWaarde)` over `SalesOrderLine` (filtered to
`nature == "RECURRING"`) from the `shillinq` register via the abstract stat widget's standard
OpenRegister aggregation source. `src/services/recurringRevenue.js` is deleted because a repo-wide
grep confirmed its only consumers were the two retired dashboard widgets.
