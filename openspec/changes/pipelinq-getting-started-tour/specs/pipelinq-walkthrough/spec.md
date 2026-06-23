# pipelinq-walkthrough Specification

**Status:** proposed
**Scope:** pipelinq
**Tier:** V1
**Depends on:** `cn-walkthrough-engine` (manifest `walkthrough` schema + engine); pipelinq routes (`Products`, `Contacts`, `Leads`, `Pipeline`, `Contracts`); ADR-043.

## Purpose

Declare pipelinq's empty-environment getting-started walkthrough — the end-to-end
sales journey product → contact → lead → pipeline → quote → contract → bill in
shillinq — as a `manifest.walkthrough` tour driving the real app.

## ADDED Requirements

### Requirement: REQ-WALK-PQ-001 — Pipelinq Declares A Getting-Started Tour

pipelinq's manifest SHALL declare a `walkthrough` block with one `getting-started`
tour, `trigger: first-visit`, whose steps walk the user through, in order: a welcome,
creating a product, creating a contact, creating a lead, moving the lead through the
pipeline, creating a quote, recognising the signable quote as the contract, and
handing off to shillinq for billing. Every step SHALL carry `sinceVersion: "1.0.0"`.

#### Scenario: First visit on an empty env starts the journey

- **GIVEN** a fresh pipelinq user with no recorded walkthrough version
- **WHEN** the shell renders
- **THEN** the `getting-started` tour SHALL auto-start at the welcome step

### Requirement: REQ-WALK-PQ-002 — Each Journey Step Gates On The Real Action And Captures Ids

Each create step SHALL spotlight the real pipelinq element and gate advancement on
the real action — navigating to the route (`route-match`) or creating the object
(`object-created`) — capturing the created id into the tour context
(`productId`, `contactId`, `leadId`, `contractId`) for later steps, with a
manual-Next escape hatch where the user may legitimately deviate.

#### Scenario: Creating the lead advances and captures its id

- **GIVEN** the active step targets the Leads add action with
  `advanceOn: { type: "route-match", route: "LeadDetail", capture: { leadId: ":id" } }`
- **WHEN** the user creates a lead and lands on its detail page
- **THEN** the tour SHALL advance and `leadId` SHALL be captured for subsequent steps

#### Scenario: The pipeline step accepts a manual skip

- **GIVEN** the "move the lead through the pipeline" step with `allowManualNext: true`
- **WHEN** the user cannot or does not drag the lead
- **THEN** a manual Next escape hatch SHALL let them continue

### Requirement: REQ-WALK-PQ-003 — A Signable Quote Is Treated As The Contract

The tour SHALL model the quotation as a `contract` object (pipelinq has no separate
quote route) and SHALL make explicit to the user that a signable quotation *is* the
contract, capturing `contractId` on creation.

#### Scenario: Quote step captures a contract

- **GIVEN** the "create a quote" step
- **WHEN** the user creates the quotation
- **THEN** the engine SHALL capture it as `contractId` and the next step SHALL state the quote is the contract

### Requirement: REQ-WALK-PQ-004 — The Tour Hands Off To Shillinq For Billing

The final step SHALL target the contract's "send to billing" action and, on
activation, deep-link to shillinq with a `cn_resume_tour` / `cn_resume_step` resume
token via the engine's cross-app hand-off primitive, so the billing leg continues in
shillinq.

#### Scenario: Billing hand-off deep-links to shillinq

- **GIVEN** the final `send-to-shillinq` step
- **WHEN** the user activates the send-to-billing action
- **THEN** the engine SHALL deep-link to shillinq carrying a resume token for this tour

### Requirement: REQ-WALK-PQ-005 — Targeted Elements Are Instrumented And Localised

pipelinq SHALL add a stable `data-walkthrough-id` (reusing `data-testid` where
present) to every targeted element lacking a manifest identity (add buttons, the
pipeline board, the create-quote and send-to-billing actions), and SHALL provide all
tour copy as `pipelinq.tour.*` i18n keys in both `en` and `nl`.

#### Scenario: A targeted add button is resolvable and localised

- **GIVEN** the "create a product" step targeting `{ kind: "element", ref: "products-add" }`
- **WHEN** the tour runs in a Dutch session
- **THEN** the engine SHALL resolve `data-walkthrough-id="products-add"` and render the Dutch `pipelinq.tour.createProduct.*` copy
