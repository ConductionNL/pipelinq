## ADDED Requirements

### Requirement: The contact moment duration runs as a clock

The quick log on the client page MUST show the duration as a running clock that starts when the form opens. The agent MUST be able to stop, reset or type the time. Saving MUST stop the clock first and store the time it shows as an ISO 8601 duration.

#### Scenario: The clock runs while the agent logs the contact

- GIVEN an agent opens the quick log on a client page
- WHEN the form appears with no duration yet
- THEN the duration clock MUST start at 00:00 and count up

#### Scenario: Saving stops the clock

- GIVEN the duration clock shows 04:23
- WHEN the agent saves the contact moment
- THEN the clock MUST stop
- AND the contact moment MUST store `PT4M23S` as its duration
