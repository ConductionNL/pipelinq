# lead-management Specification (delta)

## ADDED Requirements

### Requirement: The lead form has no probability input (REQ-RF-030)

The lead form SHALL NOT offer a probability input, because the win chance
is the qualification score. Editing a lead that has a stored probability
SHALL keep that value.

#### Scenario: A new lead form

- WHEN the user opens the create lead form
- THEN there is no probability field

#### Scenario: Editing keeps a stored probability

- GIVEN a lead with probability 40
- WHEN the user edits and saves it
- THEN the lead still has probability 40
