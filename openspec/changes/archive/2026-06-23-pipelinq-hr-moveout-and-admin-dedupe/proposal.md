# Proposal: Pipelinq HR move-out and Administration / Settings dedupe

## Why

Two HR features — **Timesheet approval** and **Expenses** — were just
re-homed to the dedicated **hrmq** app. Pipelinq still hosted them: the
"Timesheet approval" nav entry deep-linked to shillinq (resolved at
runtime through an initial-state `shillinq_app_url`), and Expenses had a
full set of pipelinq-owned pages (list / detail / new), Vue views and a
registry. A CRM should not own HR surfaces — pipelinq should point users
at hrmq, the app that now owns them.

Separately, the left-nav carried an **Administration** top-level group
that duplicated the Nextcloud-native **Settings** section: it was the
relocation target for AVG-verzoeken, the three MDM steward views, the
Marketing group, the (now-removed) Expenses group and the (now-removed)
Timesheet-approval entry. Genuinely-admin/config items belong under
Settings, not in a parallel top-level group.

## What Changes

- **Timesheet approval → hrmq deep-link.** Remove the runtime
  `applyRegistryBillingHref()` resolver (and its `shillinq_app_url`
  initial-state provision in `Application.php`). Replace the
  `BillingApproval` menu entry with a static `TimesheetApproval`
  deep-link to `/index.php/apps/hrmq/timesheets/approval`.
- **Expenses → hrmq deep-link.** Delete the `30-expenses.json` manifest
  fragment, the three `src/views/expenses/*.vue` views, their registry
  `kind: page` entries + imports, and the `finance` object-type group +
  `expense` schema entry. Replace with a static `Expenses` deep-link to
  `/index.php/apps/hrmq/expenses`. The shillinq-AP **backend** dispatch
  (`ShillinqApController` / `ShillinqApService` / `ExpenseApprovalListener`)
  is intentionally left in place — only the pipelinq-owned UI is retired.
- **Administration → Settings dedupe.** Dissolve the `Administration`
  top-level group. AVG-verzoeken + the three MDM views move into the
  `section: "settings"` foldout (a new `sections` map in
  `menu-layout.json`, applied by `applyMenuSections()` in `main.js`).
  Marketing/Blasts is operational (campaigns) and stays a top-level
  group.

## Deferred (recorded, not done now)

- **AVG-verzoeken → OpenRegister.** OR's AVG/DSAR subsystem is
  admin-gated and a different data model; a prior seam found pipelinq
  cannot cleanly consume it yet. AVG stays in pipelinq (now under
  Settings) until that OR seam lands.
- **Data quality + Duplicates → OR-MDM.** The OpenRegister MDM
  abstraction is not built yet. Both stay in pipelinq (under Settings)
  until it exists.
