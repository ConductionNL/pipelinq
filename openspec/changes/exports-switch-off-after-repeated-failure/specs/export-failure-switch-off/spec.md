# export-failure-switch-off Specification (delta)

## Purpose

A scheduled BI export that keeps failing switches itself off and tells the
administrators. From pipelinq matrix row `auto-failure-alerts`.

## ADDED Requirements

### Requirement: An export job switches off after repeated failed runs (REQ-EFS-001)

The system SHALL count the failed runs in a row of every export job. When the
count reaches the threshold, the system SHALL switch the job off and record
why and when. A succeeded run SHALL reset the count to zero. A partial run
SHALL leave the count unchanged.

#### Scenario: A job that fails three nights in a row is switched off

- GIVEN an enabled export job with the default threshold of 3
- AND its last two runs failed with "connection refused"
- WHEN its third run in a row ends as failed
- THEN the job is no longer enabled
- AND the job records the reason "Switched off after 3 failed runs in a row" and the time

#### Scenario: A successful run resets the count

- GIVEN an export job whose last two runs failed
- WHEN its next run succeeds
- THEN the job's failure count is 0
- AND the job stays enabled

#### Scenario: A partial run neither counts nor resets

- GIVEN an export job whose last two runs failed
- WHEN its next run ends as partial
- THEN the job's failure count is still 2

### Requirement: Administrators are told once when a job is switched off (REQ-EFS-002)

The system MUST send every Nextcloud administrator one notification when it
switches an export job off. The notification MUST name the job, the number of
failed runs and the last error message.

#### Scenario: The notification names the job and the error

- GIVEN the job "Leads and pipeline value" is switched off after its third failed run
- WHEN an administrator opens their notifications
- THEN one notification reads that "Leads and pipeline value" was switched off after 3 failed runs
- AND it shows the last error "connection refused"
- AND it links to the BI export page

#### Scenario: No repeat while the job is off

- GIVEN a job that was switched off yesterday
- WHEN the scheduler runs tonight
- THEN no run is enqueued for the job
- AND no new notification is sent

### Requirement: The BI export page shows a switched-off job and lets an administrator switch it back on (REQ-EFS-003)

The BI export page SHALL show a switched-off job with its Enabled switch off,
its next run as "Not scheduled" and its reason in the Last run cell. The view
"Last run failed" SHALL list it. Switching the job on SHALL clear the reason
and reset the count.

#### Scenario: The reason shows in the list

- GIVEN a job switched off after 3 failed runs
- WHEN an administrator opens the BI export page
- THEN the job's Last run cell shows "Failed" with "Switched off after 3 failed runs in a row"
- AND its Next run cell shows "Not scheduled"

#### Scenario: Switching the job back on starts over

- GIVEN a switched-off job whose destination was fixed
- WHEN an administrator switches it on
- THEN the job is enabled with a failure count of 0 and no reason
- AND the scheduler enqueues its next run when it is due

### Requirement: An administrator sets the threshold (REQ-EFS-004)

The admin settings SHALL offer the number of failed runs in a row after which
an export job is switched off. The value MUST be a whole number from 1 to 10,
and the default SHALL be 3.

#### Scenario: A stricter threshold

- GIVEN an administrator sets the threshold to 1
- WHEN an enabled job's next run fails
- THEN the job is switched off after that one run

#### Scenario: An out-of-range value is refused

- GIVEN the admin settings page
- WHEN an administrator enters 0 and saves
- THEN the value is refused with "Enter a number from 1 to 10"
- AND the threshold stays as it was
