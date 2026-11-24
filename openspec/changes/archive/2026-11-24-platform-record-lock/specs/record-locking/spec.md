# record-locking Specification (delta)

## Purpose

A user locks a finished client, lead or ticket so nobody changes it by
accident, and unlocks it when a real change is needed. From pipelinq matrix row
`plat-record-lock`.

## ADDED Requirements

### Requirement: A user can lock a client, a finished lead or a finished ticket (REQ-PRL-001)

The ClientDetail, LeadDetail and TicketDetail pages SHALL offer Lock in the
Actions menu. On a lead or a ticket the system SHALL offer Lock only when the
record is in a final lifecycle state. Lock SHALL freeze the record through
OpenRegister, and the system SHALL refuse every later edit to it while it is
locked.

#### Scenario: A sales manager locks a won lead

- GIVEN a sales manager on the LeadDetail page of a lead with status won
- WHEN they choose Lock in the Actions menu and confirm
- THEN a banner on the page says the lead was locked by them, with today's date
- AND the Actions menu offers Unlock instead of Lock

#### Scenario: An open ticket cannot be locked

- GIVEN a KCC employee on the TicketDetail page of a ticket with status in progress
- WHEN they open the Actions menu
- THEN Lock is not offered

#### Scenario: An edit to a locked client is refused

- GIVEN a client that a colleague locked yesterday
- WHEN a user opens Edit on ClientDetail, changes the phone number and saves
- THEN the dialog shows a refusal naming the colleague who locked the client
- AND after a reload the client still shows the old phone number

### Requirement: A user can unlock a locked record (REQ-PRL-002)

The Actions menu of a locked record SHALL offer Unlock to a user who may edit
the record. Unlock SHALL remove the freeze, and the audit trail SHALL record who
locked and who unlocked the record.

#### Scenario: A team lead unlocks a client to correct an address

- GIVEN a locked client and a team lead who may edit clients
- WHEN the team lead chooses Unlock on ClientDetail
- THEN the banner is gone and Edit saves a new address
- AND the client's audit trail lists the lock and the unlock with both users

### Requirement: A locked record cannot be deleted (REQ-PRL-003)

The system SHALL refuse to delete a locked client, lead or ticket from any
screen, and SHALL say that the record is locked. This requirement depends on
OpenRegister refusing deletion of a frozen object.

#### Scenario: A deleted locked client stays

- GIVEN a locked client on the Clients list
- WHEN a user chooses Delete on its row and confirms
- THEN a message says the client is locked
- AND the client is still on the list
