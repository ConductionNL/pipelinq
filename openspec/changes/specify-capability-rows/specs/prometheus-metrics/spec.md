# prometheus-metrics Specification (delta)

## Purpose

Requirements for capability matrix rows whose behaviour no requirement stated yet. Rows: `plat-health-check`.

## ADDED Requirements

### Requirement: One check reports the state of every configured connection

The system SHALL offer one health check that lists each configured connection, says whether it answers, and gives the reason when it does not.

#### Scenario: One connection refuses its token

- GIVEN OpenRegister, mail and a Matomo source are configured
- AND the Matomo source refuses its token
- WHEN an administrator runs the connection health check
- THEN every configured connection is listed
- AND Matomo is marked failing with the refusal as its reason
- AND the other connections are marked working
