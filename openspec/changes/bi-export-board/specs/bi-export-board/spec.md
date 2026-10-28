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

### Requirement: A job shows its last run and its next run

A job SHALL keep the summary of its last finished run (status, when it ended, rows, files, error) and when it runs next
(empty while disabled), set when a run finishes and when the job is enabled. The jobs list SHALL show the last run's
status with a line beneath it (when, then the rows, the files of a partial run, or the error), the next run or "Niet
ingepland", and SHALL offer a lens on a failed last run. Enabled SHALL be a switch per job, named for screen readers.

#### Scenario: A nightly job

- **GIVEN** an enabled job on `0 2 * * *` whose last run succeeded on 7 October at 02:04 with 12,480 rows
- **THEN** its row reads "Geslaagd", "7 okt, 02:04 · 12.480 rijen" and next run "8 okt, 02:00"

#### Scenario: A disabled job

- **GIVEN** a disabled job
- **THEN** its next run reads "Niet ingepland" and its switch is off
