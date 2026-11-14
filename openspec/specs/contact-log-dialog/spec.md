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

### Requirement: The ticket's contact dialog offers the duration as a clock

The Log contact dialog MUST show the duration as a clock with Start, Stop and Reset. The agent MUST also be able to type the time. The dialog MUST save the time as an ISO 8601 duration.

#### Scenario: The agent times the call

- GIVEN the Log contact dialog is open on a ticket
- WHEN the agent presses Start, then Stop at 04:23, and saves
- THEN the contact moment MUST store `PT4M23S` as its duration

### Requirement: The ticket's contact dialog keeps a draft

The contact dialog on a ticket SHALL keep what the agent types as a private draft, in the same way and through the same code as the client page's quick log (contact-moments-keep-draft, REQ-CMD2-001 and REQ-CMD2-002): a `ticket` of type `interaction` in OpenRegister's status `draft`, written two seconds after the last change and when the tab goes hidden, visible only to its author, and made definitive on Save under the same id. Opening the dialog on the same ticket again SHALL offer the draft with the time it was last changed, with Restore draft and Discard (board PqTicketContactLoggen). The dialog's preset channel, direction, time and customer SHALL NOT make a draft on their own.

#### Scenario: The tab closes halfway through a contact on a ticket
@e2e exclude unit; tests/vitest/contactLogDialog.spec.js covers the offer, the restore and the finish through the owner action

- GIVEN an agent typed a subject and notes in the ticket's contact dialog
- WHEN the tab closes
- AND the agent opens the contact dialog on the same ticket again
- THEN the dialog offers the unsaved contact moment with the time it was last changed
- AND Restore draft puts the subject and notes back
- AND Save makes the draft the contact moment under the same id

#### Scenario: Discard starts from an empty form
@e2e exclude unit; tests/vitest/contactLogDialog.spec.js

- GIVEN the dialog offers a draft
- WHEN the agent presses Discard
- THEN the draft ticket is removed and the form stays as it opened

#### Scenario: Opening the dialog writes nothing
@e2e exclude unit; tests/vitest/contactLogDialog.spec.js

- GIVEN the agent opens the contact dialog and changes nothing
- THEN no draft is written
