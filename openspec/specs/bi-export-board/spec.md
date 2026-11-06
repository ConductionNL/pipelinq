# bi-export-board Specification

## Purpose
The BI export pages: the shared tab strip over jobs, runs and destinations, and how a job shows its format, mode, schedule, last run and next run.

## Requirements

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

### Requirement: A job reads its schedule in words

The jobs list SHALL show a job's schedule in words ("Elk uur", "Elke nacht om 02:00", "Maandag om 03:00", "Op dag 1
van elke maand om 04:00") with the expression beneath it, and an expression it cannot put in words as written. A job's
datasets SHALL show as chips, and its destination with the destination's type beneath the name. A partial last run
SHALL say how many of its expected files it wrote ("2 van 3 bestanden"): a run records one expected file per dataset.

#### Scenario: Board PqBiExport

- **GIVEN** a weekly job on `0 3 * * 1` with datasets `appointmentBooking`, writing to "Regionaal datameer" (Azure Data Lake), whose last run wrote 2 of 3 files
- **THEN** its row reads "Maandag om 03:00" over `0 3 * * 1`, the chip "appointmentBooking", "Regionaal datameer" over "Azure Data Lake", and "Gedeeltelijk" over "5 okt, 03:12 · 2 van 3 bestanden"
