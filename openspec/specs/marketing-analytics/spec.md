---
status: done
---

# marketing-analytics Specification

## Purpose
Provides a marketing performance dashboard that reports per-blast delivery metrics, A/B click-rate significance via a chi-square test, and revenue attribution. It surfaces an overview table of recent blasts (open/click rates, unsubscribes), an A/B testing tab with statistical significance once each arm reaches sufficient volume, and an attribution tab summing attributed deal value per blast.
## Requirements
### Requirement: A/B Significance Reported in Dashboard

The PerformanceDashboard SHALL report A/B click-rate significance via a
chi-square test once each arm has at least 500 delivered and 24 hours have
elapsed.

#### Scenario: Significance test once N>=500 and 24h elapsed

- **GIVEN** Blast variants A and B each have at least 500 delivered and 24h have elapsed
- **WHEN** the A/B Testing tab is opened
- **THEN** the dashboard SHALL compute click rates per variant and a chi-square p-value
- **AND** display "not significant (p>0.05)" or "Variant B significantly higher (p<0.05)" accordingly

#### Scenario: Test unavailable if N<500

- **GIVEN** Variant A has 320 delivered and Variant B has 285 delivered
- **WHEN** the A/B Testing tab is opened
- **THEN** the dashboard SHALL display a "results not yet available (need >=500 per variant)" message with current counts and SHALL NOT compute significance

### Requirement: Attribution Dashboard Sums Revenue per Blast

The PerformanceDashboard Attribution tab SHALL show attributed revenue per
blast.

#### Scenario: Dashboard sums attributed revenue per blast

- **GIVEN** AttributionLink rows exist for a blast
- **WHEN** the Attribution tab is opened
- **THEN** the dashboard SHALL display the blast with its attributed deal count and summed attributed value in EUR (e.g. "Q4 Gemeente Outreach: Attributed value EUR 75,500 from 3 deals")

### Requirement: Overview Metrics Table

The PerformanceDashboard Overview tab SHALL list recent blasts with key
delivery metrics.

#### Scenario: Overview lists blasts with rates

- **GIVEN** recent blasts exist
- **WHEN** the Overview tab is opened
- **THEN** the table SHALL show name, segment, status, sent, delivered, open rate %, click rate %, and unsubscribed, with sortable columns

