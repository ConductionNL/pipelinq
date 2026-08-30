## 1. Preconditions (dependency)

- [x] 1.1 Confirm `order-revenue-recognition` (shillinq config head) defines the `SalesOrderLine` schema with a declarative `maandWaarde` calc (0 for `ONE_OFF`) and a `nature` enum (`RECURRING`/`ONE_OFF`) in the shared OpenRegister `shillinq` register
- [x] 1.2 Confirm the shillinq register slug (`shillinq`) and `SalesOrderLine` schema slug, matching how pipelinq's existing stat tiles address `register`/`schema`

- Tier: MVP. ADR-022 (consume the OR abstraction), ADR-036 (declarative tile). No endpoint, no nc-vue change, no engine — pipelinq shows run-rate only.

## 2. Re-source the dashboard tile

- [x] 2.1 In `src/manifest.json`, change Dashboard widget `mrr` from `type: "custom"` to `type: "stat"` with `content.source = { register: "shillinq", schema: "SalesOrderLine", metric: "sum", field: "maandWaarde", filter: { nature: "RECURRING" } }`
- [x] 2.2 Keep `requiresApp: "shillinq"`, set the label, EUR currency `format` (decimals 0), icon (`Repeat`), valueColor; remove the `widget-mrr` slot mapping
- [x] 2.3 Verify the tile aggregation shape matches pipelinq's existing OR-sourced stat tiles (`revenue`/`won-value`)

- Reminder: use Nextcloud CSS variables only; no hard-coded colors (CLAUDE.md). `valueColor` uses the existing green hex consistent with the prior success-variant tile.

## 3. Retire renewals-due + parity verification (gate before deletion)

- [x] 3.1 Remove the `renewals-due` widget entry, its layout entry, and the `widget-renewals-due` slot from `src/manifest.json`
- [x] 3.2 Run a repo-wide grep for consumers of `src/services/recurringRevenue.js` (`computeMrr`/`computeArr`/`computeClientMrr`/`normalizeToMonthly`) and confirm the only consumers are the two retired dashboard widgets
- [x] 3.3 Confirm `npm run check:manifest` passes (tile sources resolve; no dangling slot mapping)

- "Parity" = the tile renders, formats as EUR, gates on `requiresApp`, and reads `SUM(maandWaarde)` — NOT numeric equality with the old contract-based run-rate (the provenance intentionally moves to shillinq's `SalesOrderLine`).

## 4. Retire bespoke widgets + helper

- [x] 4.1 Remove the `MrrKpiWidget` import and registry entry from `src/registry.js` and delete `src/views/dashboard/widgets/MrrKpiWidget.vue`
- [x] 4.2 Remove the `RenewalsDueWidget` import and registry entry from `src/registry.js` and delete `src/views/dashboard/widgets/RenewalsDueWidget.vue`
- [x] 4.3 Delete `src/services/recurringRevenue.js` (grep in 3.2 confirmed zero remaining consumers)
- [x] 4.4 Clear the webpack filesystem cache (`rm -rf node_modules/.cache`) and run `npm run build`; confirm the build drops the retired widgets and emits with no references to the removed files

- Deletions make this a `code` change (ADR-032). Fix any pre-existing lint/test warnings encountered in the touched files (CLAUDE.md).

## 5. Docs + specs + traceability

- [x] 5.1 Add `docs/recurring-revenue.md` explaining run-rate (Σ `maandWaarde`, point-in-time) vs. shillinq's recognized turnover (IFRS-15, period-prorated), mirroring shillinq's `docs/recurring-revenue.md`
- [x] 5.2 Run `openspec validate recurring-revenue-runrate-widget --strict` and resolve any errors
- [ ] 5.3 Live-verify on the running instance once shillinq's register + `SalesOrderLine` seed are imported into OpenRegister (tile shows €0 until then)
