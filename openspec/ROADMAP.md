# Pipelinq Roadmap

## Implemented (MVP Complete)

All MVP specs have been implemented and archived.

| Spec | Archived | Summary |
|------|----------|---------|
| mvp-foundation | 2026-02-25 | Data model expansion, repair step, store registration |
| pipeline-foundation | 2026-02-25 | Pipeline/stage CRUD, default pipelines, kanban board |
| client-management | 2026-02-25 | Client CRUD, validation, filtering, contact management |
| lead-crud | 2026-02-26 | Lead create/edit/detail, pipeline stage integration |
| request-management | 2026-02-26 | Request lifecycle, status enforcement, assignment |
| pipeline-enhancements | 2026-02-26 | List view toggle, quick actions on kanban cards |
| pipeline-insights | 2026-02-26 | Stage metrics, conversion rates, value tracking |
| dashboard | 2026-02-26 | KPI cards, status chart, workload preview, quick actions |
| my-work | 2026-02-26 | Personal workload view with grouped urgency, filters |
| contacts-sync | 2026-02-26 | Nextcloud Contacts app bi-directional sync |
| entity-notes | 2026-02-26 | Notes/comments on clients, contacts, leads, requests |
| notifications-activity | 2026-02-26 | Assignment notifications, activity feed, audit trail |
| admin-settings | — | Already implemented (AdminSettings.php, Settings.vue, PipelineManager.vue, lead sources, request channels) |
| openregister-integration | — | Already implemented (pipelinq_register.json, InitializeSettings, object store, 5 schemas) |

## V1 Features (Roadmap)

### admin-settings V1

The admin settings MVP is implemented (panel registration, register status, pipeline management, re-import, default pipelines, settings persistence). V1 adds:

| Requirement | Description | Complexity |
|-------------|-------------|------------|
| REQ-AS-050 | Lead source configuration (CRUD for lead origin labels) | Low |
| REQ-AS-060 | Request channel configuration (CRUD for intake channel labels) | Low |

Note: Lead sources and request channels already have API routes and basic TagManager UI — V1 polishes these.

### Potential Future Features

These are not yet specified but may be needed:

- **Email integration**: Link emails to clients/leads, send from within Pipelinq
- **Automation/workflows**: Auto-assign leads, stage change triggers, SLA alerts
- **Reporting/analytics**: Win rates, pipeline velocity, revenue forecasting
- **Import/export**: CSV import for bulk client/lead migration, export for reporting
- **Procest integration**: Convert won leads to Procest cases, link requests to case types
- **Calendar integration**: Schedule follow-ups, meetings linked to leads/clients
- **Custom fields**: Admin-configurable fields per entity type
- **Team management**: Sales team views, territory assignment, quota tracking
