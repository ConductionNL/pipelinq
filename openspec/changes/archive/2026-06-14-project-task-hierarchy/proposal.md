# Proposal: project-task-hierarchy

## Problem

Pipelinq has no work breakdown structure (WBS) for managing billable client work. Users cannot organise a client engagement into projects, break projects into phases, define tasks per phase, and register time entries per task — each level with its own billable flag and rate. 23 of 26 analysed competitors provide at least a three-level hierarchy with a billable flag; 8 provide the full four-level Client → Project → Phase → Task → Activity structure required by professional services firms.

Without a project hierarchy:
- Time cannot be tracked against structured work items
- Billable vs non-billable work cannot be separated at a granular level
- Budget vs actuals cannot be reported per project or phase
- Invoicing is disconnected from delivered work

## Solution

Implement a 4-level work breakdown structure (WBS) as four new OpenRegister schemas within the pipelinq register:

1. **project** — A body of work for a client with optional budget (hours and EUR) and a billable flag
2. **projectPhase** — An ordered phase or milestone within a project
3. **projectTask** — A deliverable within a phase, with estimated hours and assignee
4. **projectActivity** — A time entry registered against a task, with date, duration, and billable override

Each level carries an independent `billable` flag; lower levels inherit from their parent when not explicitly set.

## Scope

- Four new schemas in `pipelinq_register.json`: project, projectPhase, projectTask, projectActivity
- Project list view with client filter and status badges
- Project detail view with a collapsible WBS tree (phases → tasks)
- Phase and task management within the project detail view (inline add/edit)
- Time entry registration form linked to a task
- Billable flag inheritance (child inherits parent's billable value unless overridden)
- Budget summary per project: estimated hours, logged hours, billable hours
- Navigation entry for Projects in MainMenu
- Seed data: 3–5 objects per schema with Dutch values

## Out of scope

- Time reporting / invoice generation (separate billing module)
- Gantt chart or calendar view (V1)
- Sub-task nesting beyond phase → task (V1)
- External calendar sync for project deadlines (V1)
- Multi-currency rates (Enterprise)
- Resource capacity planning (Enterprise)

## Impact

- **New files**: 4 Vue view files, 1 component, router and nav changes
- **Modified files**: `pipelinq_register.json` — 4 new schemas
- **Risk**: Low — additive; no existing schemas are modified
