# client-retention Specification (delta)

## Purpose

A client and its contact persons leave the CRM once their retention term has
run out, after a records officer approves the list. From pipelinq matrix row
`plat-retention-destroy`.

## ADDED Requirements

### Requirement: A client's retention starts when the relationship ends (REQ-PCR-001)

The system SHALL record the moment a client's account status becomes inactive,
and SHALL clear it when the status returns to active. The client schema SHALL
declare a retention term counted from that moment, and a client whose
relationship has not ended SHALL have no destruction date.

#### Scenario: An account manager ends a relationship

- GIVEN an active client on ClientDetail
- WHEN the account manager sets Account status to inactive and saves
- THEN the client shows the date its relationship ended
- AND its retention metadata carries a destruction date two years later

#### Scenario: An active client is never scheduled

- GIVEN a client created five years ago whose account status is still active
- WHEN OpenRegister's destruction check runs
- THEN that client is on no destruction list

#### Scenario: A client that comes back is taken off the schedule

- GIVEN an inactive client with a destruction date next month
- WHEN an account manager sets its account status to active again
- THEN the client has no relationship end date and no destruction date

### Requirement: Contact persons follow their client (REQ-PCR-002)

A contact person linked to a client SHALL carry the same destruction date as
that client. A contact person with no client SHALL have no destruction date.

#### Scenario: Contact persons leave with their organisation

- GIVEN an inactive client with two contact persons
- WHEN the client's destruction date is set
- THEN both contact persons carry the same destruction date

### Requirement: A records officer approves each destruction (REQ-PCR-003)

The system SHALL list OpenRegister's destruction lists that hold clients or
contact persons on a Retention review page in the settings section. A records
officer SHALL approve or reject each list, and nothing SHALL be destroyed
before approval.

#### Scenario: A records officer approves a list

- GIVEN a destruction list with three clients past their term
- WHEN the records officer opens Retention review, checks the linked tickets count and presses Approve, then confirms
- THEN after OpenRegister's execution job runs, the three clients are gone from the Clients list
- AND the destruction certificate names the records officer

#### Scenario: A client on legal hold survives an approved list

- GIVEN a client on the approved list that is under a legal hold
- WHEN OpenRegister's execution job runs
- THEN that client is still on the Clients list

### Requirement: Client and contact schemas declare their personal data (REQ-PCR-004)

The client and contact schemas SHALL declare which properties hold personal
data as an anonymisation profile, so a records officer can answer anonymise
instead of destroy. This requirement depends on OpenRegister offering
anonymise as a retention outcome for schemas that use the `archive` path.

#### Scenario: A records officer keeps a client for statistics

- GIVEN a client on a destruction list and OpenRegister offering anonymise
- WHEN the records officer answers anonymise for that client
- THEN the client stays on the Clients list with its industry and segment
- AND its name, email addresses and phone numbers are gone
