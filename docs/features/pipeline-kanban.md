# Pipeline & Kanban

Configurable kanban-style boards where leads and requests flow through ordered stages. The primary visual workflow tool for managing sales and service pipelines.

## Specs

- `openspec/specs/pipeline/spec.md`
- `openspec/specs/pipeline-insights/spec.md`

## Features

### Pipeline CRUD (MVP)

Administrators create and configure pipelines with ordered stages. Each pipeline has a title, description, entity type scope, and ordered list of stages.

- Pipeline entity types: `lead`, `request`, or `mixed`
- Default Sales Pipeline: New → Contacted → Qualified → Proposal → Negotiation → Won → Lost (7 stages)
- Default Service Pipeline: New → In Progress → Completed → Rejected → Converted (5 stages)
- One pipeline marked as default (isDefault: true)

### Stage Management (MVP)

Stages are ordered steps within a pipeline. Each stage has a name and order number. Stages can be added, reordered, and removed via the admin settings.

- Stage validation: name required, order must be unique within pipeline
- Drag-and-drop reordering in admin UI

### Kanban Board View (MVP)

Visual board layout with pipeline stages as columns. Leads and/or requests appear as cards in their current stage column.

- Stage column headers with entity counts
- Pipeline selector dropdown to switch between pipelines
- Add entity directly from a stage column

### Pipeline View Toggle (MVP)

Users can switch between kanban (visual board) and list (data-dense table) views of the same pipeline data.

### Quick Actions on Cards (MVP)

Common actions available directly on pipeline cards without opening the detail view — move to next/previous stage, assign to user.

### Pipeline Selection on Entity (MVP)

When creating or editing a lead/request, users can select which pipeline and stage to place it on.

### Pipeline on Admin Settings (MVP)

Pipeline management is accessible from the Nextcloud admin settings page with full CRUD and stage management.

### Planned (V1) — Pipeline Insights

Temporal and financial context overlays for pipeline views:

- **Stage Revenue Summary**: Total EUR value displayed in each stage column header
- **Stale Lead Detection**: Visual indicator when a lead has had no activity for X days
- **Aging Indicator**: Shows days in current stage on each lead card
- **Overdue Item Highlighting**: Red indicators for items past their due/expected close date

### Planned (V1) — Analytics

- Stage probability mapping (auto-populates lead probability from stage config)
- Pipeline analytics (conversion rates, stage duration)
- Pipeline funnel visualization (dashboard chart)
- Multiple pipelines per team

### Planned (Enterprise)

- Pipeline templates
- Automation on stage change (notifications, field updates)
- Sales forecast summary (weighted pipeline value)
