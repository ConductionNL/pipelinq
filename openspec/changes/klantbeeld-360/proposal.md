# Proposal: Klantbeeld 360

## Problem

Pipelinq manages clients, leads, requests, and contactmomenten in separate silos. CRM users lack a
unified view of a customer's full history and current status across all touchpoints. Three gaps stand
out from market analysis:

1. **No sales pipeline analytics** — 550 tenders (demand score 1664) explicitly require CRM with
   pipeline management and opportunity tracking. Pipeline boards exist but there are no KPI cards for
   pipeline value, win rate, stage conversion, or average deal size. Sales managers cannot assess team
   performance at a glance.

2. **No cross-module analytics dashboard** — 79 tenders (demand score 240) require unified analytics
   with a single data model. Data is spread across leads, contactmomenten, and requests but no
   aggregated reporting surface exists. Managers must visit each list view separately to build a
   mental picture.

3. **Contact–organisation links lack UX** — The `contact.client` relation exists in the data model
   but the detail views do not surface it. Agents cannot navigate from a contact to its parent
   organisation, and clients do not show their associated contacts without a search.

## Solution

Implement three capabilities under the "Klantbeeld 360" umbrella:

1. **Client 360° View** — Enhance `ClientDetail.vue` with a summary statistics card and relation
   sections for leads, contactmomenten, requests, and contacts. Agents get the complete customer
   picture without leaving the client page.

2. **Sales Pipeline Analytics** — New `PipelineAnalyticsView.vue` at `/pipeline-analytics` with
   four KPI cards (total pipeline value, win rate, average deal size, active opportunities) and a
   stage funnel bar chart per pipeline.

3. **Cross-module Analytics Dashboard** — New `AnalyticsDashboard.vue` at `/analytics` using
   `CnDashboardPage` with KPI blocks aggregating leads, requests, and contactmomenten in real time,
   with a time-period filter (this week / this month / this quarter).

4. **Contact–Organisation Management** — Add a "Parent Organisation" card to `ContactDetail.vue`
   with a quick-link action, and enhance `ClientDetail.vue` with a "Contacts" section showing all
   associated contact persons.

## Scope

### In Scope

- Summary statistics card on client detail (open/won leads, open requests, contactmomenten count)
- Leads section on client detail (up to 10 most recent, with EUR value and stage)
- Contactmomenten section on client detail (up to 10 most recent)
- Requests section on client detail (up to 5 most recent)
- Contacts section on client detail (all contact persons where `contact.client` = this client)
- Parent Organisation card on contact detail with navigation and quick-link action
- `PipelineAnalyticsView.vue` with pipeline selector, four KPI cards, `CnChartWidget` stage funnel
- `AnalyticsDashboard.vue` with four cross-module KPI blocks and time-period filter
- Opportunity tracking in `LeadList.vue`: expected-close warning indicator, probability badge
- Analytics nav item in `MainMenu.vue`

### Out of Scope

- AI-powered deal scoring or win-probability prediction (V1)
- KVK / LinkedIn contact enrichment (V1)
- Scheduled report delivery via email (V1)
- Multi-pipeline conversion funnel (V1)
- CTI / telephony integration (Enterprise)
- PDF export of analytics reports (V1)

## Impact

- **New files**: 2 Vue views
- **Modified files**: 4 existing Vue views, 1 router file, 1 navigation file
- **New schemas**: None — uses existing `client`, `contact`, `lead`, `contactmoment`, `request`,
  `pipeline` schemas from ADR-000
- **Risk**: Low — all data fetched from existing OpenRegister API via `objectStore`; no backend
  schema changes
