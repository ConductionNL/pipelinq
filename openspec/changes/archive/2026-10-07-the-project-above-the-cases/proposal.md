---
kind: code
---

# Proposal: the-project-above-the-cases

Round 4 discovery sweep, cluster 69 "the project above the cases"
(`procest/_round4/discovery/build-plan.md` in ConductionNL/market-intelligence,
2026-09-14). Seven candidates, seven passers, **all seven driven**, no documented
ones. Owner pipelinq, size L, decision **D5**. Umbrella:
`competitor-parity-2026-09`.

## Summary

A gemeente programme sits above the zaken: "Omgevingswet implementatie", "Sloop
Kerkstraat", "Verkiezingen 2027". Each holds cases, tasks, a team and a plan, and
none of that is a case. pipelinq gains a `project` object, a `projectTask`, a
`projectTeamMember` and a cycle with a snapshotted progress. dossiq links a case to a
project and does not own one.

## Why

dossiq has a board over cases (`WorkflowBoard.vue`) and no project object. The sweep
read that as `partial` on the cluster's lead candidate and `no` on six of seven. The
lane's clause is the argument: "a gemeente programme sits above the zaken and round 4
already rejected the budget-on-a-case row".

pipelinq is the owner because it already holds the work above the transaction: a
`pipeline`, a `lead`, a `crmTask`, a product catalogue and a forecast. A project is
the same shape with a different vocabulary, and pipelinq's `time-entry-core` already
names "hour capture against clients, leads, **projects** and requests" as its purpose
while no project object exists to capture against.

Two systems prove the shape rather than the field. GLPI makes the project a record
with its own tasks, team and costs (`front/project.php`, `projecttask.php`,
`projectteam.php`, `itil_project.php`), and it links its ITIL tickets to it, which is
exactly the case-to-project link dossiq needs. iTop does the same. The sweep's own
note: "Five systems by the lanes' own count. Both make the project a record, not a
tag."

## The candidates, with their lane citations

| id | capability | relevance | driven passers | lane |
|---|---|---|---|---|
| C-tasks-and-phases-7 | A project sits above the cases, with its own plan, team and tasks. | could | glpi, itop | `tasks-and-phases.tsv:8` |
| C-tasks-and-phases-23 | How far along the case is, as a number, and where that number comes from is administered. | could | openproject, vikunja | `tasks-and-phases.tsv:18` |
| C-tasks-and-phases-18 | An administered scale for estimating effort, one active set per domain. | could | plane | `tasks-and-phases.tsv:23` |
| C-tasks-and-phases-34 | When a time box closes, the work not finished is carried into the next one in one action. | could | plane | `tasks-and-phases.tsv:37` |
| C-reporting-11 | A time box snapshots its own progress, so a later edit cannot rewrite what the chart showed. | could | plane | `reporting.tsv:14` |
| C-reporting-16 | Effort booked on a sub-case rolls up to its parent. | could | request-tracker | `reporting.tsv:18` |
| C-reporting-24 | The same record is estimated separately per role, and totalled. | could | taiga | `reporting.tsv:20` |

Plane proves three of the seven: `db/models/estimate.py:18` with `EstimatePoint` at
`:43` and preset ladders at `estimates/create/stage-one.tsx:95-111`;
`db/models/cycle.py:60` with the transfer-issues route; and `cycle.py:74
progress_snapshot` beside `:80 version`. Request Tracker proves the roll-up with
`lib/RT/Action/UpdateParentTimeWorked.pm`. Taiga proves the per-role estimate with
RolePoints. OpenProject proves the administered progress mode at
`/admin/settings/progress_tracking`.

Every candidate in this cluster has at least one driven passer, so decision D21's
documented label is needed nowhere in it.

## What pipelinq builds

- **A project as a record, not a tag.** `project`: a name, a client or org unit, a
  period, a status, an owner. `projectTask` beneath it. `projectTeamMember` holding a
  person in a role on the project.
- **Work from anywhere linked to the project.** A project holds references to work
  owned by other apps, in the `<app>:<schema>` and uuid shape the fleet already uses.
  A dossiq case, a pipelinq lead and a crmTask can all hang under one programme.
- **A progress number whose source is administered.** Percent complete is either
  typed, derived from the tasks beneath it, or derived from effort. Which one is a
  setting, and the project says which mode produced the number it shows.
- **An estimation scale, administered, one active set.** A ladder of points or hours,
  maintained rather than hard-coded, so "3" means one thing across a portfolio.
- **An estimate per role, and a total.** The lane's clause: a bezwaar costs juridisch
  time and vakafdeling time and today both are one number.
- **A cycle that snapshots and carries over.** A time box with a stored progress
  snapshot and a version, so a later edit cannot rewrite the chart, and one action
  that moves unfinished work into the next cycle.
- **Effort rolls up.** Hours booked on a child roll up to the parent project, read
  through humaniq's hours leaf rather than through a second time model.

## How dossiq consumes it

1. A case detail page places a pipelinq leaf showing which project the case belongs
   to, and the project's progress. dossiq holds a reference, not a project.
2. dossiq's own case hierarchy is untouched. A sub-case still rolls up to its parent
   case in dossiq. Rolling up to a **project** is this change's, and the two do not
   compete because a project is not a case.
3. A gemeente reporting on "Omgevingswet implementatie" reads the project in
   pipelinq, and every case under it keeps its own term, its own zaaktype and its own
   archiving obligation in dossiq.

## The existing specs this extends

- `time-entry-core`. It already names projects as something hours are captured
  against. This change supplies the object that sentence assumes, and it does **not**
  build a time subsystem: capture stays with the leaf, per hydra ADR-022.
- `pipeline` and `lead-management` supply the shape a project follows. Unchanged.
- `activity-timeline` and `notifications-activity` carry a project's events the way
  they carry a lead's. Unchanged.
- `master-data-management` keeps the client on a project a golden record. Unchanged.

## Size and dependencies

**Size: L.** Four new schemas, an administered scale, a progress mode, a cycle with a
snapshot, a roll-up read and a leaf.

**Depends on:** nothing in this umbrella. The effort roll-up reads humaniq's
`hours-leaf`, which ships today, and degrades to no figure when humaniq is absent
rather than to zero.

## What this change does not do

- It does not build a second time model. Hours belong to humaniq under decision D19,
  and `time-entry-core` already delegates capture. The roll-up is a read.
- It does not put a budget on a case. Round 4 rejected that row and this change does
  not reopen it.
- It does not become a planning tool. There is no Gantt, no critical path and no
  auto-scheduling here. Cluster 70 puts "work is planned on a board, a whiteboard or
  a Gantt" in the `not` bucket, and C-deadlines-19 proposing dates from capacity is
  humaniq's capacity answer plus the owning app's dependency graph.
