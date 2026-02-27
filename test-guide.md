# Pipelinq — Test Guide

> **Agentic testing (experimental)**: This guide is used by automated browser testing agents. Results are approximate and should be verified manually for critical findings.

## App Access

- **App URL**: `http://localhost:8080/index.php/apps/pipelinq`
- **Admin Settings**: `http://localhost:8080/settings/admin/pipelinq`
- **Login**: admin / admin

## What to Test

Read the feature documentation for the complete feature map:

- **Feature index**: [docs/features/README.md](docs/features/README.md) — lists all feature groups with links
- **Feature docs**: [docs/features/](docs/features/) — detailed description of each feature group

### Feature Groups

| Feature Group | Doc File | Key Pages to Visit |
|---------------|----------|-------------------|
| Dashboard | [dashboard.md](docs/features/dashboard.md) | `/#/dashboard` — KPI cards, request chart, quick actions |
| Clients | [client-management.md](docs/features/client-management.md) | `/#/clients` — list, detail, contacts, import from NC Contacts |
| Lead Management | [lead-management.md](docs/features/lead-management.md) | `/#/leads` — list with stage/source filters, detail view |
| Request Management | [request-management.md](docs/features/request-management.md) | `/#/requests` — list with quick status change, detail view |
| Pipeline & Kanban | [pipeline-kanban.md](docs/features/pipeline-kanban.md) | `/#/pipeline` — kanban board with drag-and-drop, list view toggle |
| My Work | [my-work.md](docs/features/my-work.md) | `/#/my-work` — assigned leads/requests grouped by deadline |
| Collaboration | [collaboration.md](docs/features/collaboration.md) | Entity notes on detail pages |
| Administration | [administration.md](docs/features/administration.md) | `/settings/admin/pipelinq` — register status, pipelines, sources, channels |

### Navigation Structure

The app uses hash-based routing. The sidebar has these menu items:
1. **Dashboard** → `/#/dashboard`
2. **Clients** → `/#/clients` (detail: `/#/clients/{id}`)
3. **Contacts** → `/#/contacts` (detail: `/#/contacts/{id}`)
4. **Leads** → `/#/leads` (detail: `/#/leads/{id}`)
5. **Requests** → `/#/requests` (detail: `/#/requests/{id}`)
6. **Pipeline** → `/#/pipeline`
7. **My Work** → `/#/my-work`
8. **Documentation** → opens external link (pipelinq.app)

### Key Interactions to Test

- **New Client**: Click "New client" → form → fill name, type (person/organization), save
- **New Lead**: Click "New lead" → form → fill title, value, stage, priority, save
- **New Request**: Click "New request" → form → fill title, status, channel, save
- **Quick Status Change**: On request list, click status dropdown arrow → change status inline
- **Pipeline Board**: Switch between Kanban and List view, drag cards between stages
- **Import Contacts**: On clients page, click "Import from Contacts" button
- **Admin Pipelines**: In admin settings, create/edit pipelines with stages

## What NOT to Test

Check [openspec/ROADMAP.md](openspec/ROADMAP.md) for features that are planned but NOT yet implemented. Skip these during testing.

## Test Data

The app needs OpenRegister configured to function. If the admin settings show "Not configured", click "Re-import configuration" first.
