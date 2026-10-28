# bi-export-board Delta

## ADDED Requirements

### Requirement: The BI export lists share one tab strip

The jobs, destinations and runs lists SHALL each show one tab strip linking all three, each tab with the count of its
list, the open list's tab marked.

#### Scenario: Board PqBiExport

- **WHEN** an admin opens BI-export
- **THEN** the tabs read "Taken", "Bestemmingen" and "Uitvoeringen" with their counts, and "Taken" is open

### Requirement: A job reads its format and mode in words

A job's row SHALL show its format and mode in words under its name, for every value the schema allows.

#### Scenario: An incremental Parquet job

- **GIVEN** a job with format `parquet` and mode `incremental`
- **THEN** its row reads "Parquet · incrementeel" in Dutch
