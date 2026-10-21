# Resident view preview (delta)

## ADDED Requirements

### Requirement: The server answers the preview as labelled rows

GET /api/tickets/{id}/resident-view SHALL answer `residentRows` (number, subject, category, status, date, description, the handler when the portal shows it, then each message as You or Resident), `portalRows` (one per organisation-portal field) and `internalRows` (one row naming the internal fields), labels in the reader's language and an empty value as a dash.

#### Scenario: a request with two messages
- GIVEN a request ticket with a message to the customer and a resident reply
- THEN `residentRows` ends with a "You" row and a "Resident" row

### Requirement: The ticket page draws the preview as facts cards

On a request ticket the page SHALL show the resident portal's rows with the note that everything else stays internal, the organisation portal's rows, and the internal fields; complaint and interaction tickets SHALL show none of them.

#### Scenario: a complaint
- GIVEN a complaint ticket
- THEN no resident-view card shows
