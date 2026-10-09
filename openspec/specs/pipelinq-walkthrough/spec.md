# pipelinq-walkthrough Specification

## Purpose
Declare pipelinq's empty-environment getting-started walkthrough — the end-to-end
sales journey product → contact → lead → pipeline → quote → contract → bill in
shillinq — as a `manifest.walkthrough` tour driving the real app.

## Requirements

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

The final sales step SHALL carry a cross-app `handoff` to shillinq. Its primary
action SHALL read "Continue in Shillinq" and navigate there with a
`cn_resume_tour` / `cn_resume_step` token, through the engine's cross-app hand-off
primitive. The token SHALL name a tour shillinq ships (`shillinq:getting-started`)
and the step where billing starts (`open-quick-draft`), so the billing leg
continues in shillinq instead of landing on a page with nothing to follow.

#### Scenario: Billing hand-off deep-links to shillinq

- **GIVEN** the final `send-to-shillinq` step
- **WHEN** the user activates "Continue in Shillinq"
- **THEN** the engine SHALL deep-link to shillinq carrying a resume token for `shillinq:getting-started` at `open-quick-draft`

### Requirement: REQ-WALK-PQ-005 — Targeted Elements Are Instrumented And Localised

Every element a step targets SHALL have a stable identity: a menu entry (the
`data-cn-route` the shared navigation renders), the shared Add button
(`data-walkthrough-id="index-add"`), or a `data-walkthrough-id` on a pipelinq
component (the pipeline board). All tour copy SHALL be English source strings
that `t()` renders, with an `en` and an `nl` catalogue entry for each.

#### Scenario: A targeted add button is resolvable and localised

- **GIVEN** the "create a product" step targeting `{ kind: "element", ref: "index-add" }`
- **WHEN** the tour runs in a Dutch session on the Products page
- **THEN** the engine SHALL resolve `data-walkthrough-id="index-add"` and render the Dutch catalogue entry of the step's copy
