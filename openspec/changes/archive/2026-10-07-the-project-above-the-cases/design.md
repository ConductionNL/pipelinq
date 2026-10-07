# Design: the project above the cases

## D1. Why a project is not a pipeline and not a case

pipelinq has a `pipeline` and a `lead`. dossiq has a `zaak`. Neither is a programme.

| | holds | ends when | owner |
|---|---|---|---|
| `lead` | one opportunity with one client | won or lost | pipelinq |
| `zaak` | one statutory case with a termijn | a besluit, then archiving | dossiq |
| `project` | many of both, plus tasks and a team | the programme is delivered | pipelinq, this change |

The distinguishing property is that a project holds work it does not own. "Sloop
Kerkstraat" holds a sloopvergunning zaak, a handhavingszaak, a contractor lead and
six tasks nobody else wants. Modelling it as a case would give it a termijn and an
archiving obligation it does not have. Modelling it as a tag, which is the shape the
sweep explicitly rejected, gives it no plan, no team and no progress.

## D2. The link points outward, and the project does not own what it points at

A `projectWorkItem` is a reference: the project, plus `domainObjectType` as the
`<app>:<schema>` literal and `domainObjectRef` as the uuid. The same shape
`hours-leaf` uses, and the same shape `contact-moments-on-pipelinq-schema` uses for
its case reference.

Three consequences, all deliberate.

- Deleting a project deletes references, never cases. A zaak's lifecycle is dossiq's.
- A case may sit under one project at a time, which keeps roll-up unambiguous. A
  second link is refused.
- pipelinq resolves a referenced object's title through the owning app, and when that
  app is absent the reference renders as an unresolved item rather than disappearing.
  A vanished link and an empty project look the same otherwise.

## D3. Progress says where its number came from

OpenProject administers this at `/admin/settings/progress_tracking`: progress is
either set by hand or computed. The sweep's note on the candidate is the sharper
half: "One asks for the field, the other asks whether it is derived or typed."

So `project.progressMode` takes one of three values, and the project always exposes
which one produced the figure it shows.

| mode | number is | changes when |
|---|---|---|
| `manual` | typed by the owner | somebody types it |
| `fromTasks` | closed tasks over all tasks | a task closes |
| `fromEffort` | hours booked over hours estimated | an hour is booked |

`fromEffort` needs humaniq. When humaniq is absent the project reports no progress
and says the mode cannot be computed, rather than reporting zero. The dossiq case
already derives its own progress from the phase, and this change does not touch that:
a project's progress is the project's.

## D4. The estimation scale is an object with one active set

Plane ships preset ladders and lets a project choose one. Hard-coding a ladder means
one organisation's "3" is another's "M", and a report over a portfolio adds them.

`estimationScale` holds an ordered set of points with a label and a numeric weight,
plus an `active` flag scoped to an org unit or the whole instance. One active scale
per scope. Retiring a scale keeps old estimates readable, exactly as a retired leave
type does in humaniq: the values already written keep resolving.

## D5. The estimate is per role, and the total is derived

Taiga's RolePoints are the passer, and the lane's clause is the reason: a bezwaar
costs juridisch time and vakafdeling time and today both are one number.

`projectEstimate` holds the work item reference, a role, and a value on the active
scale. The total is the sum, derived on read. Storing a total would let it drift from
its parts, and the parts are what a planner argues about.

This is the same shape humaniq's `estimate-spent-and-remaining-on-an-hours-leaf`
takes for hours, deliberately: an estimate per role, and a derived total. A reader
moving between the two apps meets one model.

## D6. A snapshot is a row, not a recomputation

Plane stores `progress_snapshot` beside a `version` on the cycle. The failure it
prevents is specific: a chart drawn from live data is redrawn every time somebody
edits history, so the burndown a steering group saw in March no longer exists in
April.

So closing a cycle writes the figures as they stood, with a version, and the chart
reads the snapshot. The live figures stay available beside it. The sweep calls this
"the same idea as an as-at report and a much cheaper one", and that is exactly the
trade: one row per cycle close against a reporting subsystem.

## D7. Carry-over is one action and it is recorded

Plane's transfer-issues route moves unfinished work into the next cycle. The
alternative a gemeente uses today is a filter, which leaves no record that the work
slipped.

So carry-over is an act: it names the source cycle, the target cycle and the items
moved, and each moved item keeps a trail of the cycles it has been through. A piece
of work carried three times is a fact worth being able to find.

## D8. The roll-up reads humaniq and never copies it

Request Tracker rolls a child's time up to its parent in
`UpdateParentTimeWorked.pm`, by writing the parent's field. pipelinq does not, for
the reason `time-entry-core` already gives: hours are humaniq's under ADR-022 and
decision D19, and a copied total is a total that can be wrong.

The project asks humaniq for the hours booked against each of its work items and
sums them on read. When humaniq is absent the roll-up reports that hours cannot be
read, not zero. `hours-leaf` makes the same argument for its own tile, and the reason
is the same: a real zero and a missing app must not look alike.
