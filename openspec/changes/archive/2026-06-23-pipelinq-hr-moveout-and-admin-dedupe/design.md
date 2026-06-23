# Design: Pipelinq HR move-out and Administration / Settings dedupe

This is a navigation / information-architecture change (kind: code). It
removes HR surfaces pipelinq no longer owns, deep-links to the hrmq app
instead, and removes a top-level nav group that duplicated Settings. No
feature contract, data model or API changes — the affected feature logic
moved to hrmq, and the deferred items keep their current pipelinq pages.

## Timesheet approval → hrmq

**Before.** The `BillingApproval` menu entry
(`label: "Timesheet approval"`, `href: /index.php/apps/shillinq/`) had
its href rewritten at boot by `applyRegistryBillingHref()` in
`src/main.js`, reading the `shillinq_app_url` initial state provided by
`Application.php`. The intent was to point the entry at a configured
shillinq deployment.

**After.** hrmq now owns timesheet approval. The entry is a *static*
deep-link:

```json
{ "id": "TimesheetApproval", "label": "Timesheet approval",
  "icon": "icon-category-monitoring",
  "href": "/index.php/apps/hrmq/timesheets/approval", "order": 218 }
```

`applyRegistryBillingHref()`, its call site, the `loadState` import in
`main.js`, and the `shillinq_app_url` initial-state provision in
`Application.php` are removed. The `shillinq_app_url` *app config*
(SettingsService / SetupController / first-run wizard config-field) is
left in place — it still backs the broader bookkeeping-to-shillinq
integration (recurring-revenue / ledger), which is unrelated to the
retired menu resolver.

hrmq runs `history`-mode routing with a server-side SPA catch-all, so
`/index.php/apps/hrmq/timesheets/approval` resolves the hrmq app shell.
The CnAppNav library opens `href` entries via
`window.open(href, '_blank')` (same path as the existing "Documentation"
external link), so clicking the entry opens hrmq.

## Expenses → hrmq

hrmq owns expenses. Removed from pipelinq:

- `src/manifest.d/30-expenses.json` (ExpensesGroup + Expenses/
  ExpenseDetail/ExpenseNew pages),
- `src/views/expenses/ExpenseList.vue`, `ExpenseDetail.vue`,
  `ExpenseShillinqApCard.vue`,
- the `ExpenseListView` / `ExpenseDetailView` registry entries + imports,
- the `finance` object-type group + the `expense` schema entry in
  `src/config/objectTypes.js`.

Replaced with a static top-level deep-link:

```json
{ "id": "Expenses", "label": "Expenses", "icon": "icon-toggle",
  "href": "/index.php/apps/hrmq/expenses", "order": 219 }
```

**Kept-as-note:** the expense → shillinq accounts-payable **backend**
(`ShillinqApController`, `ShillinqApService`, `ExpenseApprovalListener`,
the `30-expense-shillinq-ap.json` register seed) stays in pipelinq. The
AP coupling is not ported to hrmq; only the pipelinq-owned expense *UI*
is retired. The orphaned e2e route scenarios for the removed views are
converted to `@e2e exclude` annotations (the surface is hrmq's to cover);
the admin-settings + backend scenarios remain.

## Administration → Settings dedupe

The `Administration` top-level group was the relocation target for
AVG-verzoeken, the three MDM views, Marketing, Expenses and Timesheet
approval. After the HR move-out, the decision:

| Item                    | Decision | Rationale                              |
| ----------------------- | -------- | -------------------------------------- |
| AVG-verzoeken           | Settings | Admin/compliance config (DSAR intake). |
| Master data (MDM)       | Settings | Data-steward / admin surface.          |
| Data quality (MDM)      | Settings | Admin monitoring of master data.       |
| Duplicates (MDM)        | Settings | Admin de-dupe surface.                 |
| Marketing / Blasts      | Top-level group | Operational (campaigns), not config. |
| Expenses / Timesheet    | (removed) | Now hrmq deep-links.                  |

With the four admin items moved to Settings and Marketing staying a
top-level group, `Administration` is empty and is removed. The
`applyMenuRelocations` filter already drops a group with no children, so
deleting the group definition + its relocation mappings dissolves it.

### `sections` mechanism

`menu-layout.json` previously decided WHERE entries live via
`relocations` (sourceId → targetGroupId) and `removals`. Putting a leaf
under the NC Settings foldout needs `section: "settings"` on the
top-level entry — which `relocations` cannot express. A new `sections`
map (`{ menuEntryId: sectionName }`) keeps that WHERE decision in the
single canonical layout file (ADR-037: fragments declare WHAT exists,
menu-layout decides WHERE). `applyMenuSections()` in `main.js` runs after
relocations and sets `node.section` on matching top-level entries;
CnAppNav renders `section: "settings"` items inside the NC gear-icon
foldout. Only top-level entries are sectioned; unknown ids are inert.

## Deferred — recorded in `menu-layout.json#sections._note`

- **AVG → OR AVG/DSAR.** Admin-gated, different model; prior seam found
  pipelinq cannot cleanly consume it. Kept under Settings for now.
- **Data quality + Duplicates → OR-MDM.** OR MDM abstraction not built.
  Kept under Settings for now.
