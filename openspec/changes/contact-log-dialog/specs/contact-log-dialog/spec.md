## ADDED Requirements

### Requirement: A contact moment is logged from the ticket in its own dialog

Log contact on a ticket MUST open a dialog with the ticket it belongs to, a channel, inbound or outbound, a date and a time filled with now, the duration, a subject, notes and the customer. Saving MUST create a ticket of type `interaction` with `parentTicket` set to the ticket and `client` set to the ticket's customer.

#### Scenario: Pieter logs a call-back
- GIVEN ticket PQ-2026-0417 for Sanne de Vries
- WHEN Pieter logs an outbound phone contact "Teruggebeld over het podium"
- THEN a contact moment for Sanne is saved under PQ-2026-0417 and appears in its Contact moments tab
