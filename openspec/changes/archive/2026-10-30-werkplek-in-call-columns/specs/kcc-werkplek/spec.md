# kcc-werkplek Delta

## ADDED Requirements

### Requirement: The in-call page draws three columns

While a call is on, the contact centre page SHALL draw who is it in the left column, the contact form and the client's
requests in the middle column, and the knowledge base, the client's tasks and callback requests and the earlier contact
in the right column. Without a call the page SHALL keep its own layout.

#### Scenario: Board PqWerkplekKlant

- **WHEN** the agent takes a call from a known client
- **THEN** "Wie is het" heads the left column, the contact form heads the middle column and the knowledge base heads the
  right column
