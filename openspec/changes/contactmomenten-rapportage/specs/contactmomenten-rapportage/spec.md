# Contactmomenten Rapportage - Delta Spec

## Status: planned

## ADDED Requirements

### Requirement: KPI Dashboard

The system MUST provide a real-time dashboard showing key performance indicators for the KCC.

#### Scenario: Display daily KPI overview
- GIVEN contactmomenten have been registered today
- WHEN a KCC manager opens the rapportage dashboard
- THEN the system MUST display: total contacts today, contacts per channel, average handling time, agents currently active
- AND each KPI MUST show a trend indicator compared to the same day last week

#### Scenario: Display first-call resolution rate
- GIVEN contactmomenten today with resolution data
- WHEN the KCC manager views the dashboard
- THEN the system MUST display first-call resolution rate as a percentage
- AND compare against the configured target

#### Scenario: Display SLA compliance
- GIVEN an SLA target configured in admin settings
- WHEN the KCC manager views the dashboard
- THEN the system MUST display SLA compliance with color-coded gauge

### Requirement: SLA Configuration

The system MUST allow admins to configure SLA targets for reporting.

#### Scenario: Configure SLA targets
- GIVEN an admin on the settings page
- WHEN they configure SLA targets (e.g., 90% of phone calls answered within 30 seconds)
- THEN the targets MUST be stored and used for compliance calculations
