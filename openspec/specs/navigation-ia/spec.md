# navigation-ia Specification

## Purpose
TBD - created by archiving change pipelinq-hr-moveout-and-admin-dedupe. Update Purpose after archive.
## Requirements
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

