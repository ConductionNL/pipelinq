# walkthrough Specification

## Purpose
Shapes the getting-started tour around the order a new user builds a CRM in: a client first, then a contact on that client, a product and a lead linked to both. Each create dialog signals the tour, so a step advances when the record exists.

## Requirements

### Requirement: REQ-WT-001 — The tour starts with a client

The getting-started tour SHALL ask the user to create a client first, then a contact on that client, then a product, then a lead linked to the client and contact. The create client dialog SHALL signal `cn-walkthrough:object-created` with register `pipelinq` and schema `client`, so the create client step advances.

#### Scenario: Create client advances the tour

- GIVEN the tour is on the create client step
- WHEN the user saves a client in the create client dialog
- THEN the tour SHALL move on to the Contacts step

@e2e exclude a tour run needs a fresh walkthrough state per test; checked live on the review instance.

### Requirement: The tour says how to reach the modules page
The getting-started tour's last step MUST tell the user how to reach "Modules and more" from the menu as the menu is drawn. When the item sits in the Advanced foldout at the bottom of the navigation, the step MUST say to open Advanced first.

#### Scenario: The last tour step
@e2e exclude Asserted in tests/vitest/r6ContactActivityRelationsCopy.spec.js on src/menu-layout.simple.json and the en and nl catalogues.
- **GIVEN** the simple menu, where "Modules and more" is in the footer section
- **WHEN** the tour reaches the "Sales and more" step
- **THEN** the step MUST say to open Advanced at the bottom of the menu and then click Modules and more
