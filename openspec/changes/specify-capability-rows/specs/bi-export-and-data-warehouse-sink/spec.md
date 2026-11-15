# bi-export-and-data-warehouse-sink Specification (delta)

## Purpose

Requirements for capability matrix rows whose behaviour no requirement stated yet. Rows: `req-conversation-export`, `rep-schema-evolution`.

## ADDED Requirements

### Requirement: Every service conversation can be copied to an outside archive

The system SHALL let an administrator set up an export job that copies ticket conversations, with their messages and contact moments, to a configured outside destination on a schedule.

#### Scenario: A new message reaches the archive

- GIVEN an export job with the ticket and contact moment schemas as sources and an archive destination
- WHEN a ticket gets a new message and the job runs
- THEN the destination holds that ticket with all its messages
- AND the run is recorded with the number of records it copied

### Requirement: An export keeps working when a field is added or removed

The system SHALL compare each source schema with the previous run, export an added field from then on, export a removed field as empty, and record the difference on the run.

#### Scenario: A field is added

- GIVEN a schema exported with the fields id, name and email
- WHEN the field phone is added and the export runs
- THEN phone is exported with the other fields
- AND the run records added: phone

#### Scenario: A field is removed

- GIVEN a schema exported with the fields id, name and legacy_field
- WHEN legacy_field is removed and the export runs
- THEN legacy_field is exported as empty
- AND the run records removed: legacy_field with a warning
