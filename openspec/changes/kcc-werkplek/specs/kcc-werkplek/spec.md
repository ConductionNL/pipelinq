# KCC Werkplek - Delta Spec

## Status: planned

## ADDED Requirements

### Requirement: Agent Dashboard Landing

The system MUST provide a dedicated KCC agent landing screen with queue overview and quick actions.

#### Scenario: Agent opens KCC werkplek
- GIVEN a KCC agent with appropriate role permissions
- WHEN they navigate to the KCC werkplek
- THEN the system MUST display: active queue count, recent contactmomenten, quick-action buttons

### Requirement: Citizen/Business Identification

The system MUST allow agents to identify citizens by BSN (via BRP) or businesses by KVK number.

#### Scenario: Identify citizen by BSN
- GIVEN an agent handling an incoming call
- WHEN the agent enters a BSN in the identification panel
- THEN the system MUST query the BRP source via OpenConnector and display citizen details

### Requirement: Contact Moment Registration

The system MUST allow agents to register contactmomenten during or after a contact.

#### Scenario: Register a phone contact
- GIVEN an agent has identified a citizen
- WHEN the agent fills in channel, subject, notes, and outcome
- THEN a contactmoment MUST be created as an OpenRegister object linked to the client
