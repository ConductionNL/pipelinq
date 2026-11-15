# first-time-setup Specification (delta)

## Purpose

Requirements for capability matrix rows whose behaviour no requirement stated yet. Rows: `auto-setup-wizard`.

## ADDED Requirements

### Requirement: The setup wizard opens by itself on the first visit

The system SHALL open the setup wizard when an administrator opens pipelinq while setup was never completed or dismissed.

#### Scenario: First visit after install

- GIVEN a fresh install where setup was never completed or dismissed
- WHEN an administrator opens pipelinq
- THEN the setup wizard opens at its first step without being asked for
