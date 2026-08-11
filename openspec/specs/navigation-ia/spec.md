# navigation-ia Specification

## Purpose
TBD - created by archiving change pipelinq-hr-moveout-and-admin-dedupe. Update Purpose after archive.
## Requirements

@e2e exclude ⚠️ EVERY SCENARIO BELOW DESCRIBES NAVIGATION THAT NO LONGER EXISTS,
and it was removed deliberately — so these cannot be closed by a test, and the
right fix is to refresh this spec rather than to write one. Measured on the
current tree: `grep -rn hrmq` over `src/`, `lib/` and `appinfo/` returns
NOTHING, and `src/manifest.json` has no `Timesheet approval` or `Expenses`
entry among its 25 menu entries. Git says why: this capability was implemented
by `cd4788bf8` (archived change `2026-06-23-pipelinq-hr-moveout-and-admin-dedupe`)
and then **superseded three weeks later** by `9ca45213c` (2026-07-03), *"chore(nav):
drop Timesheet/Expenses/Restart-tutorial from the menu … these are static
deep-links into the hrmq app; they don't belong in pipelinq's nav."* REQ-3's
Settings list is stale for the same reason: the foldout is built from
`src/menu-layout.json`, whose `settingsSection` is now
`["Pipelines", "SettingsIntegrationsCaption", "ExportJobs"]`, and whose own
`_settingsSectionNote` records the current rule — *"Only entries that are still
app-side config live here"* — so `AVG-verzoeken`, `Master data`, `Data quality`
and `Duplicates` are out by decision, not by accident. A test written against
the text below would fail against three deliberate choices; an exclusion
claiming coverage would be false. Filed for a spec refresh as
[ConductionNL/pipelinq#765](https://github.com/ConductionNL/pipelinq/issues/765).
(Two clauses DO still hold and are worth keeping when
this is rewritten: there is no top-level `Administration` group, and pipelinq
renders no expenses or timesheet-approval page of its own.)

### Requirement: Timesheet approval MUST deep-link to the hrmq app

Pipelinq MUST NOT host a timesheet-approval surface. The left-nav MUST
expose a single `Timesheet approval` entry that links to the hrmq app at
`/index.php/apps/hrmq/timesheets/approval`. The previous runtime
shillinq-URL resolver for this entry MUST be removed.

#### Scenario: Clicking Timesheet approval opens hrmq
- GIVEN the user opens the Pipelinq app
- WHEN they click the `Timesheet approval` entry in the left-nav
- THEN the hrmq app MUST open at its timesheet-approval surface
- AND pipelinq MUST NOT render its own timesheet-approval page

### Requirement: Expenses MUST deep-link to the hrmq app

Pipelinq MUST NOT host an expenses surface in its navigation. The
left-nav MUST expose a single `Expenses` entry that links to the hrmq
app at `/index.php/apps/hrmq/expenses`. The pipelinq-owned expense list /
detail / new views and their routes MUST be removed.

#### Scenario: Clicking Expenses opens hrmq
- GIVEN the user opens the Pipelinq app
- WHEN they click the `Expenses` entry in the left-nav
- THEN the hrmq app MUST open at its expenses surface
- AND pipelinq MUST NOT render its own expense list or detail pages

### Requirement: Administration group MUST NOT duplicate the Settings section

The left-nav MUST NOT carry a top-level `Administration` group that
duplicates the Nextcloud-native Settings section. Genuinely-admin/config
entries (AVG-verzoeken, the MDM Master data / Data quality / Duplicates
views) MUST be placed in the `settings` section so they render inside the
NC gear-icon foldout. Operational entries (Marketing / Blasts) MUST stay
a top-level group.

#### Scenario: Admin entries live under Settings, not a duplicate group
- GIVEN the user opens the Pipelinq app
- WHEN they inspect the left-nav
- THEN there MUST be no top-level `Administration` group
- AND `AVG-verzoeken`, `Master data`, `Data quality` and `Duplicates` MUST appear in the Settings foldout
- AND `Marketing` MUST remain a top-level navigation group

