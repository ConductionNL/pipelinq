# Klachtenregistratie — Wire Complaint SLA Service Delta

**Spec refs**: `klachtenregistratie` (Backend SLA Deadline Service, Background Job for SLA
Monitoring), `sla-engine-and-escalation` (the timed sweep job that now also performs the
per-category complaint overdue check)
**Standards**: OWASP A01:2021 (no dead auth/validation code — ADR-005); ADR-022 (consume OR
abstractions)

## MODIFIED Requirements

### Requirement: Background Job for SLA Monitoring

A timed background job MUST periodically check for overdue complaints and log warnings. The check
MUST use the backend complaint SLA service's overdue detection
(`ComplaintSlaService::isOverdue()`) against each open complaint's category-derived `slaDeadline`.
The monitoring MAY be folded into the app's existing timed SLA sweep job rather than a separate
standalone job, provided overdue open complaints are surfaced as warnings on every run. The check
MUST be read-only with respect to the complaint object — it logs/surfaces the overdue state and
MUST NOT mutate the complaint's deadline or status.

**Feature tier**: V1

#### Scenario: Job warns on overdue open complaints

- GIVEN register and complaint_schema are configured
- AND an open complaint (status `new` or `in_progress`) has a `slaDeadline` in the past
- WHEN the timed SLA sweep job runs
- THEN it MUST log a warning identifying the overdue complaint (uuid, category, status, deadline)
- AND it MUST NOT log a warning for complaints whose status is `resolved` or `rejected`

#### Scenario: Job skips when not configured

- GIVEN register or complaint_schema is empty
- WHEN the timed SLA sweep job runs
- THEN it MUST skip complaint processing without raising an error
