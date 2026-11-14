# Delta: walkthrough

## ADDED Requirements

### Requirement: The tour says how to reach the modules page
The getting-started tour's last step MUST tell the user how to reach "Modules and more" from the menu as the menu is drawn. When the item sits in the Advanced foldout at the bottom of the navigation, the step MUST say to open Advanced first.

#### Scenario: The last tour step
@e2e exclude Asserted in tests/vitest/r6ContactActivityRelationsCopy.spec.js on src/menu-layout.simple.json and the en and nl catalogues.
- **GIVEN** the simple menu, where "Modules and more" is in the footer section
- **WHEN** the tour reaches the "Sales and more" step
- **THEN** the step MUST say to open Advanced at the bottom of the menu and then click Modules and more
