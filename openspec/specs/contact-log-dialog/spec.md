# contact-log-dialog Specification

## Purpose
Logging a contact moment from a ticket in its own dialog, including changing the customer it belongs to.

## Requirements

### Requirement: A contact moment is logged from the ticket in its own dialog

Log contact on a ticket MUST open a dialog with the ticket it belongs to, a channel, inbound or outbound, a date and a time filled with now, the duration, a subject, notes and the customer. Saving MUST create a ticket of type `interaction` with `parentTicket` set to the ticket and `client` set to the ticket's customer.

#### Scenario: Pieter logs a call-back
- GIVEN ticket PQ-2026-0417 for Sanne de Vries
- WHEN Pieter logs an outbound phone contact "Teruggebeld over het podium"
- THEN a contact moment for Sanne is saved under PQ-2026-0417 and appears in its Contact moments tab

### Requirement: The customer of a contact moment can be changed in the dialog

The Customer field of the contact-log dialog MUST be a picker that starts on the ticket's customer and searches the customers by name. Saving MUST set the contact moment's `client` to the customer picked in the dialog, so a call from someone other than the ticket's customer is logged for the right person.

#### Scenario: A neighbour calls about Sanne's ticket
- GIVEN ticket PQ-2026-0417 for Sanne de Vries
- WHEN Pieter opens Log contact, the Customer picker shows Sanne de Vries with her initials
- AND he picks Bakkerij De Graaf and saves
- THEN the contact moment is saved under PQ-2026-0417 for Bakkerij De Graaf
