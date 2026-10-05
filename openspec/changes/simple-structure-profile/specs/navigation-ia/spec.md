## ADDED Requirements

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

#### Scenario: The sales tour in the simple structure
@e2e exclude Asserted in structureProfile.spec.js on the built manifest: the tour names four entries the simple menu lacks and is held back; the existing e2e helpers dismiss the tour on the full structure, where it still starts.
- **GIVEN** the simple structure
- **WHEN** somebody opens pipelinq for the first time
- **THEN** the getting-started tour MUST NOT start
- **AND** in the full structure it MUST start as before
