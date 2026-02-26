# Proposal: pipeline-foundation

## Problem

Pipelinq has `pipeline` and `stage` concepts in its data model (the pipeline schema is already registered in OpenRegister), but there is no UI to manage pipelines, no default pipelines are created on install, and the pipeline schema lacks `isClosed`/`isWon` stage properties needed for lead lifecycle tracking. Without pipelines, leads cannot flow through stages — a core CRM requirement.

## Proposed Change

Set up the pipeline foundation: update the schema, create default pipelines on install, and build an admin settings UI for pipeline and stage CRUD. This is the prerequisite for the kanban board and lead management features.

### Scope

**Schema update:**
- Add `isClosed`, `isWon`, `color` properties to the pipeline schema's embedded stage objects
- These properties are required by the pipeline spec for lead lifecycle (won/lost tracking)

**Default pipelines (repair step):**
- Create a "Sales Pipeline" with 7 stages (New → Contacted → Qualified → Proposal → Negotiation → Won → Lost)
- Create a "Service Requests" pipeline with 5 stages (New → In Progress → Completed → Rejected → Converted to Case)
- Idempotent — skip creation if pipelines already exist

**Admin settings UI:**
- Pipeline list showing all pipelines with stage count, entity type, and stage preview
- Pipeline create/edit form with title, description, entity type, default toggle
- Inline stage management: add, edit, reorder, delete stages within a pipeline
- Stage properties: name, order, probability, isClosed, isWon, color
- Validation: title required, at least one non-closed stage, isWon requires isClosed

**Navigation:**
- Add "Pipelines" section to the admin settings page (existing Settings.vue)
- Pipeline management lives in admin settings, not the main app navigation

### Out of Scope
- Kanban board view (separate `kanban-board` change)
- Lead CRUD and pipeline assignment on leads (separate `lead-crud` change)
- Drag-and-drop stage reordering (V1 enhancement)
- Pipeline analytics and funnel visualization (V1)
- Pipeline selector dropdown in the main app view

## Impact

- **Schema change**: Update `pipelinq_register.json` — adds 3 properties to stage items
- **PHP change**: New or updated repair step for default pipeline creation
- **Frontend files**: ~3-4 new Vue components in admin settings
- **Risk**: Low — admin settings only, no impact on existing client/contact views
