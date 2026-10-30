# ticket-detail-page Specification (delta)

## ADDED Requirements

### Requirement: A finished ticket can be locked

The ticket page SHALL offer Lock in the menu More for a ticket in a finished status (resolved, completed, rejected, converted, closed) that carries no lock, SHALL lock it through OpenRegister without an expiry, and SHALL offer Unlock while it is locked. A locked ticket SHALL show who locked it and when, and that nobody can change or delete it until it is unlocked.

#### Scenario: Pieter locks a finished request
@e2e exclude configuration of OpenRegister's lock and nextcloud-vue's held-lock page; tests/vitest/ticketLockWhenFinished.spec.js asserts the actions, their conditions and the sentence

- GIVEN ticket PQ-2026-0417 is completed
- WHEN Pieter chooses Vergrendelen in the menu More
- THEN the ticket shows Vergrendeld beside Afgerond, Bewerken is disabled, and the card names Pieter Jansen with the date and time
- AND Ontgrendelen unlocks it

#### Scenario: A ticket in progress cannot be locked
@e2e exclude same as above

- GIVEN a ticket in progress
- THEN the menu More offers no Vergrendelen
