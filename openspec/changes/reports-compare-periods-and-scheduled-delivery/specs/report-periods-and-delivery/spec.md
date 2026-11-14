# report-periods-and-delivery Specification (delta)

## Purpose

Reports compare a period with the one before it, and a report can be delivered to people's inboxes on a schedule. Covers rows rep-compare-periods and rep-scheduled-export. Boards PqVerkoopoverzicht, PqOperationeel and PqBiExport.

## ADDED Requirements

### Requirement: A report KPI shows the previous period beside the current one (REQ-RPD-001)

For every KPI on the commercial and operational overviews, the system SHALL compute the value for the selected period (week, month, quarter or year) and for the window of equal length directly before it, and the tile SHALL show the current value, the difference with the previous window as a percentage, and whether that difference is good or bad for the KPI's declared direction. When the previous window has no data the tile SHALL show the current value and say there is nothing to compare with, rather than a difference of 100%.

#### Scenario: This month against last month
- GIVEN revenue of 40,000 in the last 30 days and 32,000 in the 30 days before
- WHEN a user opens the sales overview with period Month
- THEN the revenue tile shows 40,000 and an increase of 25%, marked as good

#### Scenario: A KPI where lower is better
- GIVEN the average resolution time fell from 10 hours to 8 hours
- WHEN the operational overview opens with period Month
- THEN the tile shows a decrease of 20%, marked as good

#### Scenario: No earlier data
- GIVEN an instance that started using pipelinq two weeks ago
- WHEN a user picks period Month
- THEN each tile shows its value and the line "No earlier period to compare with"

### Requirement: A scheduled report can be delivered by mail (REQ-RPD-002)

An export job SHALL support a `mail` delivery next to its warehouse destinations. A job with mail delivery SHALL, each time its cron schedule is due, extract its schemas with the job's filters, format the result in the job's format (CSV or XLSX) and send it as an attachment through `OCP\Mail\IMailer` to the job's recipients. Recipients SHALL be Nextcloud users or groups; each recipient SHALL receive only rows they may read through OpenRegister's access rules. Each send SHALL be recorded as a run of the job with its outcome.

#### Scenario: A weekly report on Monday morning
- GIVEN an export job "Open tickets" with schedule `0 7 * * 1`, format CSV and recipient group "Team leads"
- WHEN the scheduler runs on Monday at 07:00
- THEN every member of Team leads receives a mail with the CSV attached
- AND the job's run list shows the run as delivered

#### Scenario: A recipient may read fewer rows
- GIVEN a recipient who may only read tickets of their own team
- WHEN the report is sent
- THEN that recipient's attachment holds only their team's tickets

#### Scenario: Mail is not configured
- GIVEN the instance has no working mail server
- WHEN a mail delivery is due
- THEN the run is recorded as failed with the reason, and the job's owner gets a Nextcloud notification

### Requirement: The schedule dialog names its recipients (REQ-RPD-003)

The export schedule dialog on the BI export page SHALL let the user choose mail as delivery, pick recipients from the instance's users and groups, and show the schedule in words (for example "Every Monday at 07:00") before saving.

#### Scenario: Setting up a mailed report
- GIVEN a user on the BI export page
- WHEN they choose delivery Mail, add the group Team leads and set the schedule to every Monday at 07:00
- THEN the dialog reads "Every Monday at 07:00 to Team leads" and saving creates an enabled job
