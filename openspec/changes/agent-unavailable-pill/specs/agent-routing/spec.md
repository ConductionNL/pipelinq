# agent-unavailable-pill Delta: agent-routing

## ADDED Requirements

### Requirement: An unavailable agent carries a warning pill

In the agent profiles settings, an agent whose `isAvailable` is false SHALL carry the status badge "Unavailable" in the
warning tone next to the name.

#### Scenario: An agent on leave

- **GIVEN** an agent profile with `isAvailable` false
- **WHEN** an administrator opens the agent profiles
- **THEN** the row SHALL show "Niet beschikbaar" as a warning pill
