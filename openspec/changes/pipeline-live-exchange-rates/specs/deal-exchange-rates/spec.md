# deal-exchange-rates Specification (delta)

## Purpose

A foreign-currency deal is converted at a rate from the fleet's rate store,
with the rate's source and date visible. From pipelinq matrix row
`pipeline-multi-currency`.

## ADDED Requirements

### Requirement: Rates come from the fleet's rate store before the hand-kept table (REQ-DXR-001)

The system SHALL resolve a conversion rate from, in order: an administrator
override, shillinq's newest rate for the pair when shillinq is installed, the
ECB daily reference rate through integriq when that source exists, and the
hand-kept table. A reporting currency other than the euro SHALL be served by a
cross rate through the euro.

#### Scenario: shillinq has today's ECB rate

- GIVEN shillinq is installed with an ECB rate for USD to EUR dated today, and pipelinq's table holds an older typed rate
- WHEN a sales manager opens the forecast with a 10,000 USD deal
- THEN the deal is converted at shillinq's ECB rate
- AND the forecast shows source ECB via shillinq and today's date

#### Scenario: No rate store is available

- GIVEN neither shillinq nor an integriq ECB source is installed
- WHEN the forecast converts a GBP deal
- THEN it uses the typed rate from Forecast settings and shows source manual with the date it was typed

### Requirement: A stale rate is shown as stale (REQ-DXR-002)

A rate older than the configured number of days SHALL still convert the deal
and SHALL be marked stale with its date on LeadDetail and in the forecast.

#### Scenario: A rate from last month

- GIVEN the newest USD rate is 20 days old and the limit is 7 days
- WHEN a sales rep opens LeadDetail of a USD deal
- THEN the converted value shows the rate marked stale with its date

### Requirement: An administrator can override a rate on purpose (REQ-DXR-003)

Forecast settings SHALL show each currency's current source and date, and SHALL
let an administrator set an override with a reason that wins over every source.

#### Scenario: A fixed contract rate

- GIVEN an administrator on Forecast settings
- WHEN they set an override of 0.92 for USD with reason contract rate 2026
- THEN the forecast converts USD deals at 0.92 with source override
