# Spec: nav-ia-cleanup

## ADDED Requirements

### Requirement: Admin configuration lives on the admin page

Configuration an administrator sets up once SHALL live on the Nextcloud admin page
(`/settings/admin/pipelinq`), not in the operator's app navigation — not as a
top-level entry and not in the in-app gear foldout, which is still the app's own
shell.

Six surfaces move: Messaging (WhatsApp/SMS providers, send budgets, templates), CTI
telephony, Payment providers (PSP), POS tender types, POS staff, POS roles. Their
in-app pages and nav entries are removed; the Vue views render as sections on the
admin page instead.

#### Scenario: Configuration is absent from the app navigation

- **WHEN** an operator opens the Pipelinq app
- **THEN** the navigation contains no Messaging, CTI, Betalingsmethoden, POS
  betaalmethoden, POS medewerkers or POS rollen entry — in the main list or in the
  gear foldout

#### Scenario: Configuration is present on the admin page

- **WHEN** an administrator opens `/settings/admin/pipelinq`
- **THEN** it renders sections for messaging providers, send budgets, templates, CTI
  integration, the CTI webhook event log, payment providers, POS tender types, POS
  staff and POS roles

#### Scenario: A moved list works without a router

- **GIVEN** the admin page is its own webpack entry and has no vue-router
- **WHEN** an administrator opens a POS staff member or POS role from its list
- **THEN** the form opens in a dialog and saving or cancelling returns to the list,
  with no navigation and no console error

### Requirement: The two payment surfaces name themselves

A payment **provider** (the PSP that processes the money) and a POS **tender type**
(how a customer pays at the till, and the GL account it posts to) are different
things. They SHALL be named so, and each SHALL describe itself relative to the
other.

#### Scenario: Neither reads as "payment methods" twice

- **WHEN** an administrator opens the admin page
- **THEN** one section is "Payment providers (PSP)" and another is "POS tender
  types", each describing what it is and how it differs from the other
- **AND** neither is labelled "Betalingsmethoden" or "POS betaalmethoden"

### Requirement: Navigation carries no empty groups or duplicate surfaces

A navigation group SHALL describe a real domain and hold more than one page. A page
SHALL NOT exist solely to answer a question an existing search already answers.

#### Scenario: Reports & Compliance and Billing categories are gone

- **WHEN** an operator opens the app
- **THEN** there is no "Reports & Compliance" group and no "Billing categories" page
- **AND** the `billingCategory` schema and its objects still exist, because
  `ShillinqWipService` and the "Hours by billing category" widget read them

#### Scenario: Barcode lookup is answered by the Products search

- **WHEN** an operator types or scans a barcode into the Products index search
- **THEN** the matching product is returned
- **AND** there is no separate "Barcode lookup" page
- **AND** `barcode` is a column on the index, so the value is visible on the row

#### Scenario: Product catalog does not wrap a single page

- **WHEN** an operator opens the app
- **THEN** Products is a top-level entry and there is no "Product catalog" group
  around it

#### Scenario: AVG-verzoeken is not a pipelinq menu entry

- **GIVEN** OpenRegister owns the data-subject-request engine (ADR-047 Phase 3) and
  pipelinq only contributes evidence to it
- **WHEN** an operator opens the app
- **THEN** there is no "AVG-verzoeken" entry deep-linking out of the app

### Requirement: Messaging is a marketing channel, not a peer of marketing

Messaging SHALL NOT be a top-level navigation concept. Its configuration is
administration; what an operator does with it is send campaigns, which is Marketing.

#### Scenario: Marketing holds the operator-facing messaging surfaces

- **WHEN** an operator opens the Marketing group
- **THEN** it holds Blasts and Blast performance
- **AND** there is no top-level "Messaging (WhatsApp/SMS)" entry beside it
