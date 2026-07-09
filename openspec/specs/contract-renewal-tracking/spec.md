# Spec: Contract & Renewal Tracking

**Status:** implemented (seed contracts, client Contracts tab, and the pipeline-insights block deferred — see the change tasks)
**Spec refs**: ADR-000 (data model), ADR-022 (apps consume OR abstractions), ADR-031 (x-openregister-notifications dialect), `client-management`, `lead-management`, `pipeline`, `product-service-catalog`, `my-work`, `customer-portal` (consumer)
**Standards**: SaaS metrics conventions (MRR/ARR/churn), Dutch BW 6:236/237 (stilzwijgende verlenging consumer notice rules — copy guidance only), peer practice: HubSpot renewal pipelines, Pipedrive recurring revenue, Salesforce contract/renewal opportunities

## Purpose

Track customer contracts and their renewal lifecycle in Pipelinq: register a `contract` schema linked to existing clients and catalog line items, surface a Contracts tab on the client, and drive renewal pipelines and recurring-revenue metrics (MRR/ARR/churn) without duplicating client identity.
## Requirements
### Requirement: Contract Schema Registration

The system MUST register a `contract` schema in the pipelinq register with contractNumber, clientRef (existing `client` object — client identity is never duplicated into the contract), title, lineItems referencing the existing product/service catalog, billingInterval (`monthly`, `quarterly`, `annual`, `one-off`), valuePerInterval, currency, startDate, endDate, autoRenew, noticePeriodDays, lifecycle status (`draft`, `active`, `expiring`, `renewed`, `churned`, `cancelled`), ownerId, renewalLeadRef, and predecessorContractRef. The field names `contractNumber`, `startDate`, `endDate`, `value`, and `status` MUST be readable by the customer-portal contract reader without mapping. The schema MUST additionally declare that it implements the semantic kind `https://openregister.app/ns#Contract` per the ADR-051 declaration dialect (form governed by the hydra `semantic-object-handoff` contract), so downstream apps can discover pipelinq contracts by kind rather than by app id.

**Feature tier**: MVP

#### Scenario: Schema registration

- WHEN the repair step runs
- THEN the `contract` schema MUST exist in the pipelinq register with all listed properties and status enum
- AND the schema ID mapping and settings config key MUST be registered

#### Scenario: Semantic kind declaration present

- WHEN the registered `contract` schema is inspected
- THEN it MUST carry the `ns#Contract` implements declaration in the ADR-051 dialect form

### Requirement: Contract Lifecycle Management

Users MUST be able to create, view, edit, and transition contracts through guarded lifecycle states: `renewed` MUST require a won renewal lead, `expiring` MUST only be set by the renewal engine, `cancelled` MUST require a reason, and terminal states (`renewed`, `churned`, `cancelled`) MUST reject further transitions. Contract numbers MUST be auto-generated (`C-{year}-{seq}`) and unique. Contracts MUST be visible in a contracts list view, a contract detail view, and a Contracts tab on the client view.

**Feature tier**: MVP

#### Scenario: Create a contract from the client view

- GIVEN a client detail view
- WHEN a user creates a contract "Support & maintenance" at €750 per month starting 2026-07-01 ending 2027-06-30 with a 60-day notice period
- THEN the contract MUST be saved with status `draft`, an auto-generated unique contractNumber, ownerId defaulting to the client's owner
- AND it MUST appear in the client's Contracts tab and the contracts list

#### Scenario: Guarded transition rejected

- GIVEN an `active` contract with no renewal lead
- WHEN a user attempts to set its status to `renewed`
- THEN the transition MUST be rejected with a validation error

#### Scenario: Terminal state is immutable

- GIVEN a `churned` contract
- WHEN any status transition is attempted
- THEN it MUST be rejected

---

### Requirement: Renewal Window Detection

A nightly background job MUST transition `active` contracts with an endDate to `expiring` when the current date reaches endDate minus the renewal window (the larger of noticePeriodDays and the admin-configured default renewal lead time, fallback 60 days). The job MUST be idempotent: re-running it MUST NOT duplicate transitions, leads, or reminders.

**Feature tier**: MVP

#### Scenario: Contract enters its renewal window

- GIVEN an `active` contract ending 2026-09-30 with noticePeriodDays 60 and default lead time 60
- WHEN the nightly job runs on 2026-08-01
- THEN the contract status MUST become `expiring`

#### Scenario: Idempotent re-run

- GIVEN an `expiring` contract that already has a renewalLeadRef
- WHEN the nightly job runs again
- THEN no second renewal lead and no duplicate state transition MUST occur

---

### Requirement: Renewal Lead Automation

On the `active → expiring` transition the system MUST create exactly one renewal lead through the existing lead-management capability — title "Renewal: {contract title}", value set to the contract's annualized value, linked to the contract's client, assigned to the contract owner, tagged `renewal` — and link it bidirectionally via renewalLeadRef. Winning the renewal lead MUST set the contract to `renewed` and draft a successor contract (startDate = predecessor endDate + 1 day, predecessorContractRef set, status `draft`). Losing the lead, or the endDate passing while `expiring`, MUST set the contract to `churned`.

**Feature tier**: MVP

#### Scenario: Renewal lead created in the existing pipeline

- GIVEN a contract entering its renewal window
- WHEN the engine processes it
- THEN one lead MUST exist in the pipeline with the `renewal` tag, the contract's client, owner, and annualized value
- AND the contract's renewalLeadRef MUST point at that lead

#### Scenario: Won renewal drafts the successor

- GIVEN an `expiring` contract ending 2027-06-30 whose renewal lead is moved to won
- WHEN the engine reconciles
- THEN the contract MUST become `renewed`
- AND a successor contract MUST exist with status `draft`, startDate 2027-07-01, and predecessorContractRef pointing at the renewed contract

#### Scenario: Lost renewal churns the contract

- GIVEN an `expiring` contract whose renewal lead is marked lost
- WHEN the engine reconciles
- THEN the contract MUST become `churned`

#### Scenario: Silent expiry churns the contract

- GIVEN an `expiring` contract whose endDate has passed with the renewal lead still open
- WHEN the nightly job runs
- THEN the contract MUST become `churned`

---

### Requirement: Renewal Reminders and Notifications

Renewal notifications MUST be declared as schema rules in the x-openregister-notifications dialect (ADR-031) on the `contract` schema — notifying the contract owner on the transition to `expiring` — with no imperative notification dispatch in app code. Additionally, when the notice deadline (endDate − noticePeriodDays) is reached for an `expiring` contract, a My Work entry MUST exist for the owner; for autoRenew contracts the entry MUST state that the contract renews automatically unless cancelled by the deadline.

**Feature tier**: MVP

#### Scenario: Owner notified when the window opens

- GIVEN a contract owned by `maria` entering its renewal window
- WHEN the transition to `expiring` is saved
- THEN `maria` MUST receive a Nextcloud notification produced by the OpenRegister notification engine from the declared schema rule

#### Scenario: Notice deadline lands in My Work

- GIVEN an `expiring` autoRenew contract whose notice deadline is today
- WHEN the nightly job runs
- THEN a My Work entry MUST exist for the owner stating the contract auto-renews unless cancelled by the deadline

---

### Requirement: Recurring Revenue Roll-Up

The system MUST compute recurring-revenue metrics from `active` and `expiring` contracts: MRR (monthly = value, quarterly = value/3, annual = value/12, one-off excluded), ARR (MRR × 12), per-client recurring value, and per-period renewal rate (renewed ÷ (renewed + churned) among contracts whose window closed in the period) and churned MRR. Metrics MUST be exposed to the dashboard, the client view, and a recurring-revenue block in pipeline insights.

**Feature tier**: MVP

#### Scenario: Interval normalization

- GIVEN active contracts of €750 monthly, €3,000 quarterly, and €12,000 annual, plus a €5,000 one-off
- WHEN MRR is computed
- THEN MRR MUST equal €2,750 (750 + 1,000 + 1,000) and ARR €33,000
- AND the one-off contract MUST NOT contribute

#### Scenario: Renewal rate per period

- GIVEN a quarter in which 4 contracts were renewed and 1 churned
- WHEN pipeline insights loads that period
- THEN the renewal rate MUST display 80% and churned MRR MUST equal the churned contract's normalized monthly value

#### Scenario: Per-client recurring value

- GIVEN a client with two active contracts of €750/month and €12,000/year
- WHEN the client's Contracts tab loads
- THEN the recurring value summary MUST show €1,750 MRR for that client

---

### Requirement: MRR KPI Card

The main dashboard MUST include an MRR KPI card showing current MRR and ARR, computed by the recurring-revenue roll-up over active + expiring contracts.

**Feature tier**: MVP

#### Scenario: MRR card reflects contract changes

- GIVEN a dashboard showing MRR €2,000
- WHEN a new €500/month contract is activated
- THEN the MRR card MUST show €2,500 on next load

---

### Requirement: Renewals Due Widget

The main dashboard MUST include a "Renewals due" widget listing `expiring` contracts ordered by endDate, each deep-linking to the contract detail view, with an empty state when no contracts are in their renewal window.

**Feature tier**: MVP

#### Scenario: Expiring contracts listed by urgency

- GIVEN three `expiring` contracts with different end dates
- WHEN the dashboard loads
- THEN the widget MUST list them ordered by soonest endDate first
- AND clicking an entry MUST open that contract's detail view

#### Scenario: Renewals widget empty state

- GIVEN no contracts in their renewal window
- WHEN the dashboard loads
- THEN the widget MUST show an explanatory empty state instead of an empty list

### Requirement: Contract-to-Invoicing Handoff Emit

The system MUST provide a "Send to invoicing" action on an `active` contract that emits it to whichever installed app implements `https://openregister.app/ns#Invoice` (shillinq's abstract-order-primitive today), via OR's `SemanticTypeResolver` + the `x-openregister-handoff` dialect with field mappings per the hydra contract (lineItems→lines, valuePerInterval+billingInterval→amount/interval, currency, clientRef→customer, contractNumber + uuid→provenance). The emit path MUST be kind-addressed with no hard-coded app id. When no implementer is installed the action MUST be hidden and the endpoint MUST refuse cleanly. Handoff failure MUST NOT mutate the contract.

**Feature tier**: V1

#### Scenario: Active contract handed to the invoice implementer

- GIVEN an installed app implementing `ns#Invoice`
- WHEN the user triggers "Send to invoicing" on an `active` contract
- THEN the target invoice object MUST be created through OR's handoff engine with the mapped fields
- AND the contract MUST record the handoff provenance link

#### Scenario: Hidden without an invoice implementer

- GIVEN no installed app implements `ns#Invoice`
- WHEN the user views an `active` contract
- THEN the "Send to invoicing" action MUST NOT be rendered
- AND a direct endpoint call MUST be refused with a not-available error

#### Scenario: Failed handoff leaves the contract untouched

- GIVEN an implementer whose target creation fails
- WHEN the handoff is triggered
- THEN the contract MUST remain unchanged and the failure MUST be reported to the user

