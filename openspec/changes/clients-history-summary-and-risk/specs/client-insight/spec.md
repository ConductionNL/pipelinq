# client-insight Specification (delta)

## Purpose

An account manager sees a written summary of a client's recent history and a
risk level with its reasons. From pipelinq matrix rows `clients-ai-summary` and
`clients-churn-risk`.

## ADDED Requirements

### Requirement: Every client carries a risk level with its reasons (REQ-CIN-001)

The system SHALL give every client a risk level of low, medium or high, computed
from named signals: expiring or churned contracts, detractor survey answers,
open complaints, breached SLAs and time since the last contact. Each level SHALL
come with the reasons that produced it, and each reason SHALL name the record
behind it where there is one. The system SHALL recompute it daily and
when a contract status or a survey classification changes.

#### Scenario: A churned contract puts a client at risk

@e2e exclude the browser run waits for the live pass (decision 139); covered by ClientRiskServiceTest::testRecomputeWritesAChurnedClientAsHigh and Customer360ControllerTest::testSummaryCarriesThePresentedChurnRisk.

- GIVEN a client whose contract turned churned yesterday
- WHEN an account manager opens the Clients list and chooses the At risk filter
- THEN the client is listed with risk high
- AND ClientDetail shows churn risk high in its row of figures, with the reason churned contract under it

#### Scenario: A quiet, happy client stays low

@e2e exclude a nightly job has no browser surface; covered by ClientRiskServiceTest::testRecomputeLeavesAnUnchangedClientAlone.

- GIVEN a client with an active contract, no complaints and a contact moment last week
- WHEN the daily recompute runs
- THEN the client's risk is low and its record is not rewritten

### Requirement: A client's history can be summarised on request (REQ-CIN-002)

ClientDetail SHALL offer Summarise when hermiq is available. The summary SHALL be
written from the records of the last 90 days that the viewer may read, SHALL
say how many records it read and when it was written, and SHALL NOT be stored.
Without hermiq the card SHALL NOT be shown.

#### Scenario: An account manager prepares a call

@e2e exclude the History summary card is not drawn on a board yet (task 2.3); covered by ClientHistorySummaryServiceTest::testSummaryNamesRecordsReadAndTime.

- GIVEN an account manager on ClientDetail of a client with twelve contact moments and two open tickets this quarter
- WHEN they press Summarise
- THEN the card shows a few sentences about those contacts and tickets
- AND it says it read 14 records and the time it was written

#### Scenario: Records the viewer cannot read stay out

@e2e exclude the History summary card is not drawn on a board yet (task 2.3); covered by ClientHistorySummaryServiceTest::testRecordsTheViewerCannotReadStayOut.

- GIVEN a complaint ticket on the client that the viewer is not allowed to read
- WHEN the viewer presses Summarise
- THEN the summary does not mention that complaint
