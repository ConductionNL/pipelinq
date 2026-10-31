# tickets-bulk-assign Delta: unify-ticket-supertype

## ADDED Requirements

### Requirement: The tickets list assigns a selection to one handler

The tickets list SHALL offer "Assign to" first in its bulk band. It SHALL open CnAssignDialog with the selection, a user search, a required reason and `bulkJob: { register: "pipelinq", schema: "ticket" }`, so OpenRegister's assign job writes `assignee` on each selected ticket after checking the user may update it.

#### Scenario: Three tickets go to Fatma Yildiz

- **GIVEN** three tickets selected on the tickets list
- **WHEN** the user clicks "Toewijzen aan", picks Fatma Yildiz, writes a reason and applies the rehearsed job
- **THEN** the three tickets name Fatma Yildiz as handler, each with the reason in its audit trail

#### Scenario: A ticket the user may not change

- **GIVEN** a selection with one ticket whose schema update rule does not include the user
- **WHEN** the job runs
- **THEN** that ticket is reported as refused and keeps its handler
