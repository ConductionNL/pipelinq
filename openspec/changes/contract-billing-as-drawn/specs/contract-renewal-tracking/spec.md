## MODIFIED Requirements

### Requirement: Contract to invoicing handoff emit

The system MUST provide a "Send to invoicing" action on a running contract (status `active` or `expiring`) that emits it to whichever installed app implements `https://openregister.app/ns#Invoice`, via OR's `SemanticTypeResolver` and the `x-openregister-handoff` dialect with the declared field mappings. The emit path MUST be kind-addressed, with no hard-coded app id. When no implementer is installed, the action MUST be hidden and the endpoint MUST refuse cleanly. A handoff failure MUST NOT change the contract. A successful handoff MUST record the invoice reference, when the contract was sent (`invoicedAt`) and who sent it (`invoicedBy`).

#### Scenario: An expiring contract is handed to the invoice implementer
- GIVEN an expiring contract and an installed invoice implementer
- WHEN the owner triggers "Send to invoicing"
- THEN the invoice is created through OR's handoff engine
- AND the contract records the invoice reference, invoicedAt and invoicedBy

#### Scenario: A draft contract is not sent
- GIVEN a draft contract
- WHEN "Send to invoicing" is called
- THEN it is refused with invalid-status and nothing is handed off

## ADDED Requirements

### Requirement: The contract page shows its billing as drawn

The contract's Overview SHALL show the billing fields (period, value per period, value, currency), the invoice reference with a copy button and the line "On {date} sent to invoicing by {user}", and the line items as a table of product, description, quantity and unit value.

#### Scenario: a contract that went to invoicing
- GIVEN CON-2026-0031 with invoice reference INV-2026-0187, sent on 2 January 2026 by Pieter Jansen
- WHEN its page opens
- THEN Facturering shows INV-2026-0187 with a copy button and "Op 2 januari 2026 naar facturering gestuurd door Pieter Jansen"
