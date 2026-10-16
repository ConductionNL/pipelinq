## ADDED Requirements

### Requirement: The wizard has a till step and an integrations step
The setup wizard MUST show the steps Welcome, Example data, Currency, Organisation, Point of sale, Integrations and Done, in that order. The point of sale step MUST ask only optional fields the till reads (the receipt sender address and the receipt printer's address and port). The integrations step MUST ask for nothing and MUST say that the integrations are detected. The status endpoint MUST report both steps as done.

#### Scenario: The status reports the two new steps
- **GIVEN** an administrator
- **WHEN** they read GET /api/setup/status
- **THEN** `steps.pos.done` and `steps.integrations.done` MUST be true
