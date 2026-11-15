# client-detail

## ADDED Requirements

### Requirement: The overview ends with the activity card

The client overview SHALL end with an "Activity" card, the library's timeline over `/api/timeline`, newest first, as feed rows. It SHALL contain:
- the meta line: moment, kind, author;
- chips: Contact moments, Notes, Tasks, Email, Calendar, Messages;
- a box that adds an internal note to the client;
- "Link email" and "Compose in Mail" in its header.

#### Scenario: a note from the card
- **WHEN** the user types a note and presses "Add note"
- **THEN** the note is saved on the client and heads the feed as a note

### Requirement: The contact strip sends a message

The contact strip SHALL end with "Send a message". It opens the Messages section's composer for that client. A sent message SHALL make the client's activity card read again.

#### Scenario: Send a message
- **WHEN** the user presses "Send a message" on Anna de Vries's page
- **THEN** the message composer opens for her contact
