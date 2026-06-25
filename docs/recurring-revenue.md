# Recurring revenue: why pipelinq and shillinq show different numbers

**Spec:** [`contract-renewal-tracking`](../openspec/specs/contract-renewal-tracking/spec.md) — `MRR KPI Card` requirement (re-sourced by change `recurring-revenue-runrate-widget`).
**Source:** a plain OpenRegister aggregation — `SUM(maandWaarde)` over shillinq's `SalesOrderLine` schema, filtered to `nature == "RECURRING"`, read from the shared `shillinq` register. No custom endpoint, no recognition service, no pipelinq component.

## The two numbers are different on purpose

pipelinq's CRM dashboard tile shows the **recurring run-rate** — the sum of the
monthly-normalized value (`maandWaarde`) of every *active* recurring sales order line, ignoring
period and term boundaries entirely. The run-rate answers **"what are we billing per month right
now?"**. It is point-in-time and steady: it does not move when you look at a different period.

shillinq's accounting suite shows **recognized recurring turnover** instead — the IFRS-15
over-time figure: each `RECURRING` line's monthly rate is recognized **only for the whole calendar
months its term overlaps the fiscal period you are looking at**. It deliberately *excludes*
revenue not yet earned and *prorates* partial periods, so it ties back to the books for that
period. The recognized figure answers **"how much recurring turnover did we actually earn in this
period?"**.

Both build on the same per-line monthly normalization. `maandWaarde` is computed once in
OpenRegister as a declarative calc on `SalesOrderLine` (`amount × frequencyFactor`, with `0` for
`ONE_OFF` lines so one-offs never count toward a run-rate). pipelinq sums `maandWaarde` (filtered
to `nature == "RECURRING"`) as a plain OpenRegister aggregation; shillinq takes the same monthly
rate and multiplies it by the number of whole months the line's term overlaps the period.

The two will not match whenever a term starts or ends inside the reporting window, when a line is
not yet in-term, or when the period is shorter or longer than a single month — and that divergence
is correct, not a bug. pipelinq deliberately shows the run-rate (the CRM/commercial view) and
leaves recognized turnover to shillinq (the accounting view). See shillinq's
[`docs/recurring-revenue.md`](../../shillinq/docs/recurring-revenue.md) for the recognition side
and a worked example.

## Why this lives in OpenRegister, not in a pipelinq service

pipelinq owns no booking data. The recurring order lines and their monthly normalization live in
shillinq's OpenRegister register, which is the shared data layer for the fleet. Reading the
run-rate is therefore a plain cross-app OpenRegister `SUM` aggregation (ADR-022 — apps consume OR
abstractions rather than re-deriving them locally), the same shape pipelinq's `revenue` and
`won-value` tiles already use. The tile carries `requiresApp: "shillinq"`, so it shows an "Install
shillinq" call-to-action when shillinq is absent, and €0 once shillinq is installed but no
recurring order lines have been seeded yet — never a stale locally-computed number.
