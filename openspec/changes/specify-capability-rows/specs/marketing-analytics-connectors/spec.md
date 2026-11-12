# marketing-analytics-connectors Specification (delta)

## Purpose

Requirements for capability matrix rows whose behaviour no requirement stated yet. Rows: `rep-website-traffic`.

## ADDED Requirements

### Requirement: Website traffic shows next to the pipeline for the same period

When an analytics source is configured, the system SHALL show the site visits it reports next to the pipeline figures for the same date range.

#### Scenario: Visits and pipeline for the same month

- GIVEN a Matomo source is configured and answers
- WHEN a user opens the sales overview for last month
- THEN the page shows the site visits Matomo reports for last month
- AND it shows them beside the new leads and the pipeline value for that same month
