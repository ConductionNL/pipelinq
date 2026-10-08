# project-portfolio

## ADDED Requirements

### Requirement: A project SHALL be a record with a plan, a team and tasks (REQ-PRJ-001)

pipelinq SHALL provide a `project` schema in the pipelinq register: a name, an
optional client (`schema:Organization` through the existing party model), an optional
org unit, a period, a status, an owner and a description. A `projectTask` SHALL hold
one task under one project, and a `projectTeamMember` SHALL hold one person in one
role on one project.

A project SHALL NOT be modelled as a tag, a label or a field on another record.

The schemas are named `programme`, `programmeTask` and `programmeTeamMember`
rather than `project`, `projectTask` and `projectTeamMember`. A schema slug is
GLOBAL per organisation and `SchemaMapper::find()` matches `LOWER(slug)`, and
`project` is already planninq's billable delivery project: pipelinq's own
four-level project WBS was retired for exactly that collision, and this app
carries three `Rename*SchemaSlug` repair steps that exist to undo others like
it. The object this requirement describes is the programme above the cases, not
the delivery project, so the two are named apart rather than folded together. A
planninq project is reachable from a programme as an ordinary work item
reference, so nothing this requirement asks for is lost by the name.

Candidate C-tasks-and-phases-7 (`tasks-and-phases.tsv:8`), relevance `could`, driven
passers glpi and itop. GLPI's evidence: Projects with `front/project.php`,
`projecttask.php`, `projectteam.php`, `projectcost.php` and `itil_project.php`.

#### Scenario: A programme holds a team and its own tasks
- **GIVEN** a project "Omgevingswet implementatie" with three team members and four
  tasks
- **WHEN** the project is read
- **THEN** the team and the tasks resolve from the project, and neither is stored on
  a case
- @e2e exclude covered by PHPUnit on the portfolio reads

#### Scenario: A project is a record
- **WHEN** the pipelinq register is inspected
- **THEN** `project` is a schema with its own identity, and no capability expresses a
  project as a tag on another object
- @e2e exclude a register inspection; covered by the register fragment

### Requirement: A project SHALL hold work it does not own, by reference (REQ-PRJ-002)

A `projectWorkItem` SHALL link a project to one piece of work owned by any app, using
`domainObjectType` as the `<app>:<schema>` literal and `domainObjectRef` as the
object's uuid. pipelinq SHALL NOT copy the referenced object, and deleting a project
SHALL delete its references and nothing else.

One work item SHALL belong to at most one project, so effort and progress roll up
unambiguously. A second link for the same object SHALL be refused, naming the project
that already holds it.

When the app owning a referenced object is absent, the reference SHALL render as an
unresolved item naming its type and id, and SHALL NOT be hidden. A link that
disappears and a project with no links must not look the same.

#### Scenario: A zaak, a lead and a task hang under one programme
- **GIVEN** a project and three work items referencing a `dossiq:zaak`, a
  `pipelinq:lead` and a `pipelinq:crmTask`
- **WHEN** the project is read
- **THEN** all three appear, each naming the app that owns it
- e2e: `tests/e2e/programme-portfolio.spec.ts`

#### Scenario: Deleting a project leaves the cases alone
- **GIVEN** a project holding a reference to a case
- **WHEN** the project is deleted
- **THEN** the reference is gone and the case is unchanged
- @e2e exclude covered by the reference-only model: no work item write touches the referenced object

#### Scenario: A case cannot be in two programmes at once
- **GIVEN** a case already linked to project A
- **WHEN** it is linked to project B
- **THEN** the write is refused and the refusal names project A
- e2e: `tests/e2e/programme-portfolio.spec.ts`

#### Scenario: An unresolvable reference is shown, not swallowed
- **GIVEN** a work item referencing `dossiq:zaak` on an instance without dossiq
- **WHEN** the project is read
- **THEN** the item is listed as unresolved with its type and id
- e2e: `tests/e2e/programme-portfolio.spec.ts`

### Requirement: Progress SHALL declare which mode produced it (REQ-PRJ-003)

A project SHALL carry `progressMode` with one of `manual`, `fromTasks` or
`fromEffort`, administered per instance with a per-project override. The progress
figure a project reports SHALL always name the mode that produced it.

`fromTasks` SHALL be closed tasks over all tasks. `fromEffort` SHALL be hours booked
over hours estimated, read from humaniq. When `fromEffort` is set and humaniq cannot
be resolved, the project SHALL report that progress cannot be computed, and SHALL NOT
report zero.

Candidate C-tasks-and-phases-23 (`tasks-and-phases.tsv:18`), relevance `could`,
driven passers openproject (`/admin/settings/progress_tracking`) and vikunja.

#### Scenario: A derived number moves when its source does
- **GIVEN** a project in `fromTasks` mode with two of four tasks closed
- **WHEN** a third task closes
- **THEN** the reported progress moves to 75 per cent without anybody typing it
- e2e: `tests/e2e/programme-portfolio.spec.ts`

#### Scenario: The reader can tell a typed number from a derived one
- **WHEN** any project's progress is read
- **THEN** the answer carries the mode that produced it
- e2e: `tests/e2e/programme-portfolio.spec.ts`

#### Scenario: An uncomputable progress is said, not shown as zero
- **GIVEN** a project in `fromEffort` mode on an instance without humaniq
- **WHEN** the project is read
- **THEN** it reports that progress cannot be computed, and no percentage is shown
- @e2e exclude needs an instance without humaniq; covered by PHPUnit on `progressFor()`

### Requirement: An estimation scale SHALL be administered, with one active set per scope (REQ-PRJ-004)

pipelinq SHALL provide an `estimationScale` schema holding an ordered set of points,
each with a label and a numeric weight, plus a scope (instance or org unit) and an
`active` flag. At most one scale SHALL be active per scope, and a second active scale
in one scope SHALL be refused.

A scale set inactive SHALL keep resolving on estimates already written, so a historic
figure keeps its meaning.

Candidate C-tasks-and-phases-18 (`tasks-and-phases.tsv:23`), relevance `could`,
driven passer plane: `db/models/estimate.py:18`, `EstimatePoint` at `:43`, preset
ladders at `estimates/create/stage-one.tsx:95-111`.

#### Scenario: One scale, one meaning
- **GIVEN** an org unit with an active scale of 1, 2, 3, 5, 8
- **WHEN** a second scale is activated for the same unit
- **THEN** the activation is refused
- @e2e exclude covered by PHPUnit on `mayActivate()`

#### Scenario: A retired scale keeps old estimates readable
- **GIVEN** estimates written on a scale later set inactive
- **WHEN** those estimates are read
- **THEN** they still resolve their point label and weight
- @e2e exclude covered by PHPUnit on `weightOf()`

### Requirement: A work item SHALL be estimable per role, with a derived total (REQ-PRJ-005)

pipelinq SHALL provide a `projectEstimate` holding a work item reference, a role and
a value on the active scale. A work item MAY carry one estimate per role. The total
estimate SHALL be derived on read as the sum of its per-role estimates, and SHALL NOT
be stored.

Candidate C-reporting-24 (`reporting.tsv:20`), relevance `could`, driven passer
taiga: RolePoints per role per record. The lane's clause: a bezwaar costs juridisch
time and vakafdeling time and today both are one number.

#### Scenario: Two roles, two numbers, one total
- **GIVEN** a work item estimated at 5 juridisch and 3 vakafdeling
- **WHEN** the estimate is read
- **THEN** both roles are reported and the total reads 8
- @e2e exclude covered by PHPUnit on `totalFor()`

#### Scenario: The total cannot drift from its parts
- **GIVEN** the same work item
- **WHEN** the juridisch estimate changes to 8
- **THEN** the next read reports a total of 11, with nothing recomputed on write
- @e2e exclude covered by PHPUnit on `totalFor()`; nothing stores a total

### Requirement: A cycle SHALL snapshot its progress when it closes (REQ-PRJ-006)

pipelinq SHALL provide a `projectCycle`: a named time box under a project, with a
period and a status. Closing a cycle SHALL write a progress snapshot carrying the
figures as they stood and a version. A chart of a closed cycle SHALL read the
snapshot, so a later edit to the work beneath it cannot rewrite what the chart
showed. The live figures SHALL stay readable beside the snapshot.

Candidate C-reporting-11 (`reporting.tsv:14`), relevance `could`, driven passer
plane: `cycle.py:74 progress_snapshot` and `:80 version`.

#### Scenario: March's burndown still exists in April
- **GIVEN** a cycle closed with six of ten items done
- **WHEN** an item in it is edited afterwards
- **THEN** the closed cycle's chart still reads six of ten, and the live figures
  report the new state separately
- @e2e exclude covered by PHPUnit on `closeCycle()` and `chartFor()`

#### Scenario: Each close gets its own version
- **GIVEN** a cycle closed, reopened and closed again
- **WHEN** the snapshots are read
- **THEN** two snapshots exist, each with its own version
- @e2e exclude covered by PHPUnit on the appended snapshots

### Requirement: Unfinished work SHALL be carried into the next cycle in one act (REQ-PRJ-007)

Closing a cycle SHALL offer to move its unfinished work items into a named next
cycle, in one action. The act SHALL record the source cycle, the target cycle and the
items moved, and each moved item SHALL keep a trail of the cycles it has passed
through.

Candidate C-tasks-and-phases-34 (`tasks-and-phases.tsv:37`), relevance `could`,
driven passer plane: Cycles, `db/models/cycle.py:60`, transfer-issues route.

#### Scenario: The werkvoorraad crosses the year in one action
- **GIVEN** a closing cycle with four unfinished items
- **WHEN** carry-over runs into the next cycle
- **THEN** all four move in one act, and the act names both cycles and the four items
- @e2e exclude covered by the PHPUnit suites of this change

#### Scenario: Work carried three times can be found
- **GIVEN** an item carried over from three consecutive cycles
- **WHEN** the item is read
- **THEN** its trail names all three
- @e2e exclude covered by the PHPUnit suites of this change

### Requirement: Effort SHALL roll up by reading humaniq, never by copying it (REQ-PRJ-008)

A project SHALL report the hours booked against its work items as a sum, read from
humaniq's hours capability at read time. pipelinq SHALL NOT store a rolled-up total
on the project and SHALL NOT write to any object of humaniq's.

When humaniq cannot be resolved, the roll-up SHALL report that hours cannot be read,
and SHALL NOT report zero.

Candidate C-reporting-16 (`reporting.tsv:18`), relevance `could`, driven passer
request-tracker: `lib/RT/Action/UpdateParentTimeWorked.pm`, which writes the parent's
field. pipelinq deliberately does not, per hydra ADR-022 and decision D19.

#### Scenario: A booking on a child shows on the parent at once
- **GIVEN** a project with three work items and two hours booked on one of them
- **WHEN** an hour is booked on a second
- **THEN** the project's next read reports three hours, with no roll-up job in
  between
- @e2e exclude covered by the PHPUnit suites of this change

#### Scenario: No total is stored
- **WHEN** the `project` schema is inspected
- **THEN** it carries no hours total, and the figure exists only as a read
- @e2e exclude covered by the PHPUnit suites of this change

#### Scenario: A missing humaniq is said, not shown as zero
- **GIVEN** an instance without humaniq
- **WHEN** a project is read
- **THEN** it reports that hours cannot be read, and shows no hours figure
- @e2e exclude covered by the PHPUnit suites of this change

### Requirement: A consuming app SHALL place a leaf and SHALL NOT hold a project (REQ-PRJ-009)

pipelinq SHALL register an OpenRegister integration leaf rendering the project a host
object belongs to, with its name, status and progress. A consuming app SHALL place
the leaf rather than query pipelinq's register, and SHALL NOT declare a project
schema of its own.

When pipelinq is absent the leaf SHALL NOT be registered, so a host renders no
project surface rather than an empty one.

#### Scenario: A case page shows its programme without reading the pipelinq register
- **WHEN** a consuming app places the leaf on a case detail page
- **THEN** the widget shows the project holding that case, and the consuming app's
  manifest contains no query against the pipelinq register
- @e2e exclude covered by the PHPUnit suites of this change

#### Scenario: The surface is absent when pipelinq is
- **WHEN** the consuming app is installed and pipelinq is not
- **THEN** no project leaf is registered, and the host renders no project panel
- @e2e exclude covered by the PHPUnit suites of this change
