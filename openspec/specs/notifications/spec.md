# notifications Specification

## Purpose
Tells the people responsible for a record when it changes. An update to a client, lead, ticket or task notifies the users in its owner and assignee fields through OpenRegister's notification rules, so pipelinq sends nothing imperatively.

## Requirements

### Requirement: An update notifies the object's owner and assignee (REQ-RAF-060)

When a client, lead, ticket or task is updated, the system SHALL send an in-app
notification to the users in its owner and assignee fields: `accountOwner` on a
client, `assignee` on a lead and a ticket, `assigneeUserId` on a task. The rules
SHALL use the canonical `x-openregister-notifications` dialect with a subject in
English and Dutch. The person who made the change MAY be notified too, until
OpenRegister can leave the actor out (Ruben, 7 October 2026). A schema without
an owner or assignee field gets no update rule.

#### Scenario: A colleague changes a client

- GIVEN a client whose account owner is Marieke
- WHEN Pieter changes the client's phone number
- THEN Marieke gets the notification "Client changed: <name>"

#### Scenario: A lead is reassigned

- GIVEN a lead
- WHEN its assignee is set to Joris and the lead is saved
- THEN Joris gets the notification "Lead changed: <title>"
