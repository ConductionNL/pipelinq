# Contactmomenten: delta for specify-capability-rows

## Purpose

Say how a new contact moment is filed on a request that is still open, instead of starting a new one.

## ADDED Requirements

### Requirement: A contact moment is filed on an open request

The quick-log form SHALL offer the client's requests to pick from. A contact moment saved with a request picked SHALL be stored on that request, and SHALL NOT create a new request.

#### Scenario: A client calls again about the same question
- GIVEN client Jansen has an open request "Parking permit"
- WHEN an agent logs a call for Jansen and picks "Parking permit" under Request
- THEN the contact moment is saved with that request as its parent
- AND the request's timeline shows the call
- AND the number of requests for Jansen stays the same
- @e2e exclude unit test to add in task 31.1
