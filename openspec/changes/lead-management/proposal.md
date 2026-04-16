# Proposal: Lead Management

## Summary

Implement three high-demand sales pipeline features for Pipelinq based on market intelligence: **V1 pipeline enhancements** (1,664 demand score, 550 tender mentions), **contract portfolio analytics** (188 demand score, 48 tender mentions), and **non-admin pipeline access** (40 demand score, 13 tender mentions).

The lead management foundation (CRUD, pipeline board, stage lifecycle) is already implemented. This change adds the next layer: V1 pipeline efficiency features, a dedicated analytics/reporting view, and verified non-admin access for day-to-day pipeline work.

## Demand Evidence

### CRM with Sales Pipeline Management and Opportunity Tracking (demand: 1664, 550 tender mentions)

The most-demanded feature cluster covers the full CRM sales pipeline experience — deal tracking, opportunity management, visual pipeline progression, and pipeline efficiency tools. The following V1 features from the existing lead-management spec are still unimplemented:

- Quick actions on kanban cards (move stage, assign, set priority without opening detail view)
- Stale lead detection (visual badge after configurable days of inactivity)
- Aging indicator ("X days in stage" on kanban cards and detail view)
- Overdue lead indicators on list, detail, and kanban views
- Lead CSV import/export (bulk migration and reporting)

### Contract Portfolio Analytics and Reporting (demand: 188, 48 tender mentions)

There is currently no dedicated analytics view in Pipelinq. Sales managers need:

- Pipeline value per stage (total and weighted by probability)
- Lead source performance (conversion rate, average deal value, time to close)
- Lead aging report (pipeline bottleneck analysis)
- Win/loss analysis (ratio, trend, most common lost stage)

### Sales Pipeline Management for Non-Admin Users (demand: 40, 13 tender mentions)

Day-to-day sales operations (creating leads, moving stages, using the pipeline board) must not require Nextcloud admin access. This change audits and fixes RBAC so non-admin sales representatives can perform all operational pipeline tasks while admin access remains reserved for pipeline configuration only.

## Inferred Stakeholders

| Stakeholder | Role | Goal |
|---|---|---|
| Sales Representative | Day-to-day user | Manage their pipeline, track opportunities, hit quota |
| Sales Manager | Team lead / reporting | Monitor pipeline health, coach team, forecast revenue |
| CRM Administrator | Config owner | Configure pipelines, manage settings |
| Marketing Team | Campaign owner | Track lead sources, measure campaign ROI |

## Inferred User Stories

1. As a **sales representative**, I want quick actions on kanban cards so I can update leads without navigating to the detail view.
2. As a **sales representative**, I want to see how long leads have been stuck in a stage so I can prioritize follow-ups.
3. As a **sales representative**, I want overdue leads highlighted so I can act before losing them.
4. As a **sales manager**, I want to see pipeline value per stage so I can forecast revenue accurately.
5. As a **sales manager**, I want source performance analytics so I can optimize marketing spend.
6. As a **sales manager**, I want a win/loss analysis so I can understand deal outcomes and coach the team.
7. As a **non-admin sales rep**, I want to create leads and move pipeline stages without needing admin rights.
8. As a **sales representative**, I want to export my lead list to CSV so I can do offline analysis or reporting.

## Scope

### In scope

- **Quick actions** on kanban lead cards: move to stage, assign to user, set priority — via CnRowActions card menu
- **Stale lead detection**: visual badge on leads with no activity for X days (default 14), configurable via admin settings (IAppConfig)
- **Aging indicator**: "X days in current stage" on kanban cards and in the lead detail pipeline progress section, computed from `_dateModified`
- **Overdue lead indicators**: red visual treatment on lead list rows, detail view banner, and kanban cards when `expectedCloseDate` is past and lead is not closed
- **Lead CSV import/export**: using platform `CnMassImportDialog` / `CnMassExportDialog` (no custom parsers or controllers)
- **Analytics view** ("Rapportage"): new page using `CnDashboardPage` with pipeline funnel, source performance, lead aging, and win/loss widgets backed by `GET /api/rapportage/pipeline-stats`
- **Non-admin access audit**: verify and fix backend RBAC so lead CRUD and stage transitions work for non-admin users

### Out of scope

- Lead qualification scoring (complex scoring model, separate V1 change)
- Lead deduplication and merge interface (separate V1 change)
- Lead assignment rules / round-robin automation (separate V1 change)
- Lead nurturing workflows (Enterprise tier, requires n8n integration)
- Marketing automation (separate feature)
- Prospect discovery and external lead capture (separate change)

## Acceptance Criteria

1. **GIVEN** a sales rep views a kanban card, **WHEN** they open the card action menu, **THEN** they can move the lead to another stage, assign it, or change its priority without opening the detail view.
2. **GIVEN** a lead has had no activity for 14+ days, **WHEN** the lead appears on the kanban board or list, **THEN** a stale badge is visible with the count of days since last activity.
3. **GIVEN** a lead is past its `expectedCloseDate` and not closed, **WHEN** it appears in any view, **THEN** it is visually highlighted as overdue with the number of overdue days.
4. **GIVEN** a sales manager opens the "Rapportage" section, **WHEN** they view the analytics dashboard, **THEN** they see pipeline value per stage, source performance, lead aging distribution, and win/loss analysis.
5. **GIVEN** a non-admin Nextcloud user, **WHEN** they create a lead, move it between pipeline stages, or view the analytics dashboard, **THEN** all operations succeed without any 403 Forbidden responses.

## Dependencies

- `archive/2026-02-26-lead-crud` — Lead CRUD, list, detail, and pipeline board are already implemented
- `archive/2026-02-25-pipeline-foundation` — Pipeline and stage infrastructure is in place
- `2026-03-20-lead-product-link` — LeadProducts component is implemented
- OpenRegister schemas `lead`, `pipeline`, `client`, `contact`, `product` are already defined in `pipelinq_register.json`
