# CTI screen-pop adapter (delta)

## ADDED Requirements

### Requirement: Call dials through the telephony from the agent's own extension

POST /api/cti/click-to-dial SHALL dial from the extension on the signed-in user's agent profile when the request names none, and SHALL refuse with a message in the agent's language when the profile has none.

#### Scenario: no extension set
- GIVEN an agent whose profile has no extension
- WHEN they choose Call from my extension
- THEN no call is placed and they read "Your phone extension is not set. Ask an administrator to add it to your agent profile."
