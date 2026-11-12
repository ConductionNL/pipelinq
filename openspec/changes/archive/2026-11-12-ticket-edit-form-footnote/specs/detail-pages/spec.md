# detail-pages Delta: ticket-edit-form-footnote

## ADDED Requirements

### Requirement: The ticket edit form says where the status changes

In the simple profile the ticket edit form SHALL close on a note that the status is not edited there and moves
through the ticket's steps, naming only steps the ticket page offers.

#### Scenario: Board PqTicketBewerken

- **WHEN** the agent opens "Ticket bewerken"
- **THEN** the form ends on "De status wijzig je hier niet: die verandert via de stappen van het ticket, ..."
