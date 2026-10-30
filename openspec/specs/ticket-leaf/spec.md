# ticket-leaf Specification

## Purpose
The ticket leaf other apps use to read a party's open tickets by its master entity.

## Requirements

### Requirement: a host app reads a party's open tickets by master entity

The ticket leaf MUST return, for every master entity it is asked about, the open requests and complaints of the clients whose `masterEntityRef` names it. A reference with no client MUST be present with an empty list. The reads MUST run as the calling user. An unconfigured instance MUST answer 503, not an empty list.

#### Scenario: an organisation with open tickets
- GIVEN client Slagerij with `masterEntityRef` m-slagerij and an open request and an open complaint
- WHEN a host app asks for m-slagerij
- THEN it receives both tickets under m-slagerij

#### Scenario: a closed ticket and a contact moment are not open tickets
- GIVEN a resolved request and an interaction for the same client
- THEN neither is returned

#### Scenario: too many references
- WHEN a host app asks for more than 200 master entities
- THEN the leaf answers 400

#### Scenario: a caller outside the ticket schema's read groups
- GIVEN a signed-in user in none of the ticket and client schemas' read groups
- WHEN a host app asks for any master entity on their behalf
- THEN both reads run with OpenRegister's RBAC and organisation scoping on, and the leaf returns no tickets
