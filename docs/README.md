# Pipelinq Documentation

Pipelinq is a CRM (Customer Relationship Management) application for Nextcloud, designed for sales teams and government service desks. It stores all data as OpenRegister objects and provides pipeline-based workflow management.

## Documentation Structure

```
docs/
  features/         -- Feature documentation with screenshots
    README.md        -- Feature index and spec-to-feature mapping
    dashboard.md     -- Dashboard overview and KPI cards
    client-management.md -- Client and contact person management
    lead-management.md   -- Lead tracking and sales pipeline
    request-management.md -- Service request intake and tracking
    pipeline-kanban.md   -- Kanban boards and pipeline management
    product-catalog.md   -- Product catalog and lead-product linking
    my-work.md           -- Personal workload view
    collaboration.md     -- Notes, notifications, and activity streams
    administration.md    -- Admin settings and OpenRegister integration
    kcc-werkplek.md      -- KCC frontoffice workspace (planned)
    integrations.md      -- Workflow automation and external integrations (planned)
  screenshots/       -- UI screenshots from the running application
    dashboard.png
    client-management.png
    contacts.png
    lead-management.png
    request-management.png
    pipeline.png
    product-catalog.png
    my-work.png
    admin-settings.png
```

## Quick Links

- [Feature Documentation](features/README.md) -- All features with screenshots
- [OpenSpec Specifications](../openspec/specs/) -- Detailed spec files for each capability
- [Admin Settings](features/administration.md) -- Configuration guide

## Application Overview

### Navigation Structure

Pipelinq provides the following navigation items in its sidebar:

| Item | Route | Status |
|------|-------|--------|
| Dashboard | `/apps/pipelinq/` | Implemented |
| Clients | `/apps/pipelinq/clients` | Implemented |
| Contacts | `/apps/pipelinq/contacts` | Implemented |
| Leads | `/apps/pipelinq/leads` | Implemented |
| Requests | `/apps/pipelinq/requests` | Implemented |
| Products | `/apps/pipelinq/products` | Implemented |
| Pipeline | `/apps/pipelinq/pipeline` | Implemented |
| My Work | `/apps/pipelinq/my-work` | Implemented |
| Documentation | External link | Link |
| Settings | `/settings/admin/pipelinq` | Implemented |

### Technology Stack

- **Backend**: PHP (Nextcloud app), no own database tables
- **Data storage**: OpenRegister (register + schema-based object storage)
- **Frontend**: Vue.js 2 with Nextcloud Vue components
- **State management**: Pinia stores with generic object store pattern
- **Pipelines**: n8n workflow automation (via ExApp)
- **Standards**: Schema.org, VNG Klantinteracties, vCard RFC 6350
