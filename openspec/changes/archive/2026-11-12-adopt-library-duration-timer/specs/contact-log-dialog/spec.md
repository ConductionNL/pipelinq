## ADDED Requirements

### Requirement: The ticket's contact dialog offers the duration as a clock

The Log contact dialog MUST show the duration as a clock with Start, Stop and Reset. The agent MUST also be able to type the time. The dialog MUST save the time as an ISO 8601 duration.

#### Scenario: The agent times the call

- GIVEN the Log contact dialog is open on a ticket
- WHEN the agent presses Start, then Stop at 04:23, and saves
- THEN the contact moment MUST store `PT4M23S` as its duration
