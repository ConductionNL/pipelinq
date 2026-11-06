# ticket-detail-page Delta: ticket-edit-header

## ADDED Requirements

### Requirement: The ticket edit dialog names the ticket

The ticket edit dialog SHALL show "Bewerken · {reference}" over its title and "{reference} · {type}" under it, the type
in the reader's language.

#### Scenario: Board PqTicketBewerken

- **WHEN** the agent opens Bewerken on request PQ-2026-0417
- **THEN** the dialog reads "Bewerken · PQ-2026-0417" over "Ticket bewerken" and "PQ-2026-0417 · Verzoek" under it
