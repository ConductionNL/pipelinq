# navigation-ia delta: simple-tour-and-readable-labels

## MODIFIED Requirements

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

## ADDED Requirements

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
