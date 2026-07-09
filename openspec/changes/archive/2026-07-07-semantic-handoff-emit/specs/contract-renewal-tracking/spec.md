# Contract Renewal Tracking — Semantic Emit Delta

**Spec refs**: ADR-051 + hydra change `semantic-object-handoff` (verify contract against HEAD at apply time), ADR-048 (`ns#Vendor` precedent in `92-product-supply-master.json`), shillinq abstract-order-primitive (`ns#Invoice` consumer)
**Standards**: Semantic kind URIs `https://openregister.app/ns#Contract`, `https://openregister.app/ns#Invoice`

## MODIFIED Requirements

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

## ADDED Requirements

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
