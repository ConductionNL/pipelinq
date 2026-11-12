# contact-log-dialog Specification (delta)

## ADDED Requirements

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
