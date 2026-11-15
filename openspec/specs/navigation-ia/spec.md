# navigation-ia Specification

## Purpose
How pipelinq's menu, pages and tours are laid out: which entries each menu profile shows, where a tour may start, and what the user settings show about the installed app.

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

### Requirement: The Pipeline menu item shows a board icon (REQ-PNT-020)

The Pipeline menu item, the Pipelines settings item and the Pipeline module
card SHALL use a column board icon (`ViewColumnOutline`), so the icon matches
the board the item opens.

#### Scenario: A user finds the pipeline board

- GIVEN the full menu structure
- WHEN a user scans the navigation
- THEN the Pipeline item shows a column board icon

### Requirement: The appVersion define comes from the shared library (REQ-NIA-109)
pipelinq's webpack config MUST define `appVersion` with `appVersionDefine`
from `@conduction/nextcloud-vue/webpack`, with the app id `pipelinq` and the
`appinfo/info.xml` version as the fallback. pipelinq MUST NOT carry its own
copy of that helper.

#### Scenario: The webpack config builds the define
@e2e exclude Asserted in tests/vitest/appVersionDefine.spec.js: the library export is replaced by a spy and the config is loaded fresh.
- **GIVEN** pipelinq depends on @conduction/nextcloud-vue 2.73.1 or later
- **WHEN** webpack loads `webpack.config.js`
- **THEN** the config MUST call the library's `appVersionDefine('pipelinq', <info.xml version>)`
- **AND** the user settings footer MUST read the installed version, as REQ-NIA-108 asks

### Requirement: Every tour step has a title (REQ-NAV-TOUR-TITLES)
Every step of every tour pipelinq ships, in the simple and the full structure,
MUST declare a title. Every title MUST have an en and an nl catalogue entry.

#### Scenario: The simple menu tour
@e2e exclude Asserted in tests/vitest/tourStepTitles.spec.js on the shipped manifest and menu layouts; the library renders the step title.
- **GIVEN** a user of the simple menu starts the tour
- **WHEN** they reach step 2 of 8
- **THEN** the dialog MUST show the title "Questions and reports" after "Step 2 of 8:"

#### Scenario: A tour added later
@e2e exclude Asserted in tests/vitest/tourStepTitles.spec.js, which walks every tour in the manifest, its fragments and both menu layouts.
- **GIVEN** a developer adds a step without a title to any tour
- **WHEN** the unit tests run
- **THEN** they MUST fail and name the tour and the step

### Requirement: The simple structure is the default and an administrator can bring the full one back (REQ-NIA-101)
Pipelinq MUST show the simple structure unless the app setting `menu_structure`
holds the word `full`. Admin settings MUST offer the choice between Simple and
Full. The page controller MUST provide the setting as initial state, so the menu
is right on the first render.

#### Scenario: A contact centre agent opens pipelinq on a new instance
- **GIVEN** an instance where `menu_structure` was never set
- **WHEN** a contact centre agent opens pipelinq
- **THEN** the main menu MUST show nine entries under the captions Start,
  Klantcontact, Relaties and Meer
- **AND** the entries MUST be Dashboard, Mijn werk, Wachtrij, Vragen en
  meldingen, Contactmomenten, Afspraken, Inwoners en bedrijven, Organisaties and
  Rapportages, in that order

#### Scenario: An administrator brings the full menu back
@e2e exclude The e2e instance is shared by six workers, so no spec may change the setting; MenuStructureTest drives the real settings write and the real page controller, and structureProfile.spec.js covers the save and the choice of layout file.
- **GIVEN** an administrator on the admin settings page
- **WHEN** they choose Full under Menu structure
- **THEN** `menu_structure` MUST be stored as `full`
- **AND** the next time somebody opens pipelinq the menu MUST be the full one

#### Scenario: A stored value that is not a structure
@e2e exclude A unit rule on a string, covered by MenuStructureTest and structureProfile.spec.js.
- **GIVEN** `menu_structure` holds `ful`
- **WHEN** somebody opens pipelinq
- **THEN** the menu MUST be the simple one

### Requirement: One manifest carries both structures (REQ-NIA-102)
Both structures MUST be built from the same manifest and the same fragments.
The full structure MUST be exactly what it was before profiles existed. Neither
structure may remove a page.

#### Scenario: The full structure is unchanged
@e2e exclude An equality between two built manifests, asserted in structureProfile.spec.js against the library's real buildManifest; the rest of the e2e suite runs on the full structure.
- **GIVEN** `menu_structure` is `full`
- **WHEN** the manifest is built
- **THEN** it MUST equal what `buildManifest` makes from the manifest, the
  fragments and `src/menu-layout.json`
- **AND** the menu MUST count 47 entries

#### Scenario: Every page stays
@e2e exclude A comparison of two page lists, asserted in structureProfile.spec.js.
- **GIVEN** either structure
- **WHEN** the manifest is built
- **THEN** it MUST hold the same 97 pages, with the same ids

### Requirement: What leaves the simple menu stays one step away (REQ-NIA-103)
An entry the full menu offers MUST, in the simple structure, be in the menu, in
settings or in the footer, or be opened by a card on a page the simple menu
opens. Moving an entry MUST NOT change who may open its page.

#### Scenario: A page of a module that is off is one card away
- **GIVEN** the simple structure with no module switched on
- **WHEN** somebody opens Modules and more
- **THEN** the page MUST show a card for Leads
- **AND** the card MUST open the Leads page
- **AND** Leads MUST NOT be in the menu

#### Scenario: The contact centre reports open from one entry
@e2e exclude A reading of the built Reports page, asserted in structureProfile.spec.js.
- **GIVEN** the simple structure
- **WHEN** somebody opens Rapportages
- **THEN** the page MUST offer Reporting, Channel analytics, Agent performance
  and SLA attainment

#### Scenario: Appointment set-up moves to settings
@e2e exclude A reading of the built menu, asserted in structureProfile.spec.js.
- **GIVEN** the simple structure
- **WHEN** the menu is built
- **THEN** Services and Resources MUST be in settings
- **AND** every entry the full structure has in settings MUST still be there

#### Scenario: Contact moments and organisations are views of a list
@e2e exclude The link targets are asserted in structureProfile.spec.js against the seeded schema; the list reading a filter from the address is library behaviour.
- **GIVEN** the simple structure
- **WHEN** somebody chooses Contactmomenten
- **THEN** the tickets list MUST open narrowed to ticket type `interaction`
- **AND** Organisaties MUST open the clients list narrowed to type `organization`

### Requirement: An administrator switches a module into the simple menu (REQ-NIA-104)
Sales, Marketing, Point of sale, Products, Contracts and Loyalty MUST be out of
the simple menu by default. The app setting `menu_modules` MUST hold the modules
that are switched on, as a comma separated list of `sales`, `marketing`, `pos`,
`products`, `contracts` and `loyalty`. A word that is not a module MUST be
ignored.

#### Scenario: An administrator switches the sales module on
- **GIVEN** the simple structure and `menu_modules` holding `sales`
- **WHEN** somebody opens pipelinq
- **THEN** the menu MUST show Sales overview, Leads, Prospects and Pipeline
  under the caption Modules, between Relaties and Meer
- **AND** the entries of the other modules MUST NOT be in the menu

#### Scenario: A module that is off keeps its pages
@e2e exclude Asserted in structureProfile.spec.js for every entry of every module: a card on the Modules page opens its route.
- **GIVEN** the simple structure and a module that is off
- **WHEN** the manifest is built
- **THEN** every menu entry of that module MUST have a card on the Modules page

#### Scenario: The full structure ignores modules
@e2e exclude Asserted in structureProfile.spec.js: the module step returns a file without modules unchanged.
- **GIVEN** `menu_structure` is `full`
- **WHEN** `menu_modules` holds any value
- **THEN** the menu MUST be the full one, unchanged

### Requirement: The simple structure opens on the contact centre dashboard (REQ-NIA-105)
In the simple structure the app root MUST open the contact centre dashboard.
The Sales overview MUST keep an address of its own. The full structure MUST
keep the app root as it was.

#### Scenario: The app root in the simple structure
@e2e exclude Covered by the first e2e test of simple-structure-menu.spec.ts (the address after opening the app root) under the scenario "A contact centre agent opens pipelinq on a new instance", and by structureProfile.spec.js for the moved address.
- **GIVEN** the simple structure
- **WHEN** somebody opens `/apps/pipelinq/`
- **THEN** the address MUST become `/apps/pipelinq/werkplek`
- **AND** the Sales overview MUST open at `/apps/pipelinq/sales-overview`

#### Scenario: The app root in the full structure
@e2e exclude The existing e2e suite runs on the full structure and opens the app root on the Sales overview in every spec that calls openApp().
- **GIVEN** the full structure
- **WHEN** somebody opens `/apps/pipelinq/`
- **THEN** the Sales overview MUST show, at `/`

### Requirement: A tour only starts where the menu can carry it (REQ-NIA-106)
A walkthrough tour that sends the reader to a menu entry MUST NOT start in a
structure whose menu does not have that entry. The tour MUST stay in the
manifest, and MUST start as before in a structure that has every entry.

A profile file MAY declare `tours`: walkthrough tours of its own, added to
`walkthrough.tours` when that profile is built. The simple profile MUST declare
a contact centre tour that points only at entries of the simple menu, so the
simple structure always keeps a tour and the user settings always offer the
Walkthrough section (start, continue, start over).

#### Scenario: The sales tour in the simple structure
@e2e exclude Asserted in structureProfile.spec.js on the built manifest: the tour names four entries the simple menu lacks and is held back; the existing e2e helpers dismiss the tour on the full structure, where it still starts.
- **GIVEN** the simple structure
- **WHEN** somebody opens pipelinq for the first time
- **THEN** the getting-started tour MUST NOT start
- **AND** in the full structure it MUST start as before

#### Scenario: The simple structure has a tour of its own
@e2e exclude Asserted in structureProfile.spec.js on the built manifest: after holdUnreachableTours the simple structure keeps the contact centre tour, every nav-item it names is in the simple menu, and the walkthrough stays enabled, which is the condition CnAppRoot uses for the Walkthrough section.
- **GIVEN** the simple structure, with or without modules switched on
- **WHEN** the manifest is built
- **THEN** the contact centre tour MUST remain
- **AND** every menu entry it points at MUST be in the simple menu
- **AND** the user settings MUST offer to start the tour, continue it and start over

#### Scenario: The full structure keeps only the sales tour
@e2e exclude Asserted in structureProfile.spec.js: the full profile file declares no tours.
- **GIVEN** the full structure
- **WHEN** the manifest is built
- **THEN** the walkthrough MUST hold the getting-started tour only

### Requirement: The simple navigation names the instance it belongs to (REQ-NIA-107)
The simple profile MUST declare a brand block (`nav.brand`) at the top of the
navigation: the app's name over the instance's own name, beside the instance's
mark. The name MUST come from the theming capabilities through `@theming.name`.
The mark MUST be the active set's emblem (`nldesign.logos.emblem`) and fall back
to the theming logo only when the set ships no emblem
(`@theming.emblem|@theming.logo`), so the brand block never draws a wordmark next
to the app's own name when an emblem exists. `structureProfile.js` resolves the
placeholders; the app MUST NOT name a municipality. The full profile MUST stay
as it is.

#### Scenario: A municipality's instance
@e2e exclude The e2e instance carries no theming name worth asserting; structureProfile.spec.js drives the resolver with a theming answer and without one.
- **GIVEN** an instance whose theming capabilities answer `name` and `logo`,
  and whose design set ships an emblem
- **WHEN** somebody opens pipelinq in the simple structure
- **THEN** the navigation MUST open with the app's name, the instance's name
  under it and the set's emblem beside it, not the wordmark
- **AND** on a set without an emblem the theming logo MUST stand in

#### Scenario: An instance that answers nothing
@e2e exclude A unit rule on the resolver, covered by structureProfile.spec.js.
- **GIVEN** an instance whose theming capabilities hold no `name` and no `logo`
- **WHEN** somebody opens pipelinq in the simple structure
- **THEN** the brand block MUST show the app's name alone
- **AND** no placeholder text MUST reach the screen

### Requirement: The user settings show the installed app version (REQ-NIA-108)
The user settings footer MUST show the version of pipelinq that is installed.
The page controller MUST provide it as the `version` initial state, and the
bundle MUST read it at run time. A page without that state MUST show the
version the bundle was built from, never the `package.json` version.

#### Scenario: The footer after a release
@e2e exclude Asserted in tests/vitest/appVersionDefine.spec.js (the define evaluated against a page with and without the initial state) and tests/Unit/Controller/DashboardControllerTest.php (the controller provides the state).
- **GIVEN** pipelinq 0.5.13-beta is installed
- **WHEN** a user opens the user settings
- **THEN** the footer MUST read "pipelinq 0.5.13-beta"
