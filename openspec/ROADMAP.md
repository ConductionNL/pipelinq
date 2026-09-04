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

### Marketing suite

Decided 2026-09-04. The design, data model and decisions are on
`docs/Technical/marketing-architecture.md`; the user-facing summary is
`docs/Features/marketing.md`. Phases are ordered by value, carry no dates,
and each names the openspec changes to open.

| Phase | Scope | Openspec changes |
|-------|-------|------------------|
| 0 | Platform prerequisites: OAuth2 token-set kind with refresh in the OpenRegister credential broker, connect relay, RFC 8058 header path on IMailer, portaliq landing-page action, Matomo in dev compose | `credential-oauth2-token-set`, `credential-oauth2-connect-flow` (openregister); `contribution-landing-page-action` (portaliq); Matomo profile (.github); ADR-064 amendment (hydra) |
| 1 | Lists and mailings: segment UI repair (pipelinq#773), mailing lists with double opt-in, preference centre, RFC 8058 headers, transports, newsletter composer, typed contact channels | `marketing-segments-ui-repair`, `marketing-lists-and-double-opt-in`, `marketing-mail-transports`, `marketing-rfc8058-headers`, `marketing-newsletter-composer`, `contact-channel-details` |
| 2 | Content hub and hermiq: article objects, writing skill export, marketing agent template, repurpose actions, companion context | `marketing-article-hub`, `marketing-agent-template` (hermiq), `writing-skill-agentskills-export` (hydra), `marketing-companion-context` |
| 3 | Social publishing: account connection, adapters (fediverse, LinkedIn, X, Meta), composer and calendar, advocacy flow, metrics pull | `social-accounts-and-connect`, `social-post-composer-and-calendar`, `social-adapter-fediverse`, `social-adapter-linkedin`, `social-adapter-x`, `social-adapter-meta`, `social-advocacy-share-flow`, `social-metrics-pull` |
| 4 | Campaigns and attribution: campaign object and UTM vocabulary, landing pages via portaliq, touchpoint attribution, attribution on paid invoices, campaign report | `marketing-campaigns-and-utm`, `marketing-landing-pages-via-portaliq`, `marketing-touchpoint-attribution`, `shillinq-attribution-on-paid-invoice` (shillinq), `marketing-campaign-report` |
| 5 | Search and competitor intelligence: Search Console and Matomo connectors, keyword analysis, competitor watches, connection audit | `search-console-and-matomo-connectors`, `keyword-intelligence`, `competitor-watches`, `social-connection-audit` |
| 6 | Integrated campaigns: shillinq signals, standard audiences, journeys on OR flows, weekly review agent | `shillinq-marketing-signals` (shillinq), `marketing-standard-audiences`, `marketing-journeys-on-or-flows`, `marketing-weekly-review-agent` (hermiq) |

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
