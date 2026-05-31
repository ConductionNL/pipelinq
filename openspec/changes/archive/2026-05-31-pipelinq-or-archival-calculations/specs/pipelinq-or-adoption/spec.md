# Capability — pipelinq-or-adoption (archival + calculation slice)

## ADDED Requirements

### Requirement: Schemas with retention needs declare archival annotation

pipelinq schemas with implicit Archiefwet retention (kennisbank versions, task
history, callback logs) SHALL declare `x-openregister-archival.retention` once
the apply phase confirms scope with the DPO.

#### Scenario: Kennisbank versions retain per Archiefwet

- **GIVEN** the kennisbank schema declares `x-openregister-archival.retention`
- **WHEN** a kennisbank version exceeds its retention period
- **THEN** the archival runtime SHALL handle disposition per ADR-024.

#### Scenario: Callback logs and task history follow retention policy

- **GIVEN** the callback-log and task-history schemas declare
  `x-openregister-archival.retention`
- **WHEN** records exceed their retention period
- **THEN** the archival runtime SHALL handle disposition per ADR-024
- **AND** the DPO-confirmed retention period SHALL be recorded in the schema
  annotation.

### Requirement: Lead-management computed fields use calculation annotation

Lead-management computed fields SHALL be declared as `x-openregister-calculations`
annotations on the lead schema. This covers the `lead-management` spec's qualification
score (line 1024 open question), staleness (line 505), aging (line 519), and lead-value
(line 924).

#### Scenario: Qualification score is a backend calculation

- **GIVEN** the lead schema declares
  `x-openregister-calculations.qualification_score`
- **WHEN** a frontend store reads a lead
- **THEN** the score SHALL be present in the response
- **AND** the score SHALL be computed by the calculation annotation, not by ad-hoc
  service code
- **AND** the spec at `openspec/specs/lead-management/spec.md:1024` SHALL be updated to
  remove the open question and cite this Requirement.

#### Scenario: Staleness, aging, lead-value are calculations

- **GIVEN** the lead schema declares calculation annotations for staleness, aging, and
  lead-value
- **WHEN** any of these fields is read
- **THEN** the value SHALL be derived from the calculation expression, not written by
  service code.
