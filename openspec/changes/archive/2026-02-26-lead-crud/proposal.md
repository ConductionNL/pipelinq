# Proposal: lead-crud

## Problem

Pipelinq has pipelines and stages configured, but no way to create, view, edit, or manage leads — the core entity of a CRM. Without leads, the pipeline infrastructure has no content to flow through it, and users cannot track sales opportunities.

## Proposed Solution

Add lead management to Pipelinq: a lead list view with search/filter/sort, a lead detail view with pipeline progress and linked entities, a create/edit form with validation, and navigation integration. Leads are stored as OpenRegister objects using the existing `lead` schema and are assigned to pipelines and stages.

## Scope

### In scope
- Lead list view with search, sort (value, priority, close date), and filters (stage, source, assignee)
- Lead detail view with core info panel, pipeline progress indicator, client/contact links
- Lead create/edit form with validation (title required, value >= 0, probability 0-100)
- Hash-based routing for leads (`#/leads`, `#/leads/{id}`)
- MainMenu "Leads" navigation item
- Auto-assignment to default pipeline on creation (first non-closed stage)
- Delete with confirmation dialog

### Out of scope
- Kanban board view (separate `kanban-board` change)
- Drag-and-drop between stages (kanban feature)
- Activity timeline / notes (V1 feature)
- CSV import/export (V1 feature)
- Stale lead detection (V1 feature)
- Quick actions on lead cards (kanban feature)
- Lead assignment user picker (V1 — use text field for now)

## Impact

- **Users**: Can create and manage sales leads for the first time
- **Pipeline**: Leads become assignable to pipelines/stages, making the pipeline infrastructure functional
- **Navigation**: New "Leads" section in the main app menu
- **Dashboard**: Future dashboard KPIs will pull from lead data
- **Procest integration**: No impact — leads are Pipelinq-only entities

## Dependencies

- **pipeline-foundation** (completed) — Pipelines and stages must exist for lead-to-pipeline assignment
- **client-management** (completed) — Clients must exist for client linking on leads
- **OpenRegister** — `lead` schema already defined in `pipelinq_register.json`
- **Object store** — `lead` type already registered in `store.js`
